<?php
namespace Counter\Rest;

use Counter\Db;
use Counter\Install;
use Counter\Pos\Queue;
use Counter\Pos\Shifts;
use Counter\Pos\Registers;
use Counter\Pos\Customers;
use Counter\Orders\Builder;
use Counter\Orders\Channel;
use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * INVARIANT IV — one write per sale, and it is idempotent. This is the hot
 * path and the one place a bug costs money directly.
 *
 * process() is the testable core (called directly by the self-test); handle()
 * is a thin REST wrapper that extracts the same arguments from a
 * WP_REST_Request. Both exist so the self-test never has to simulate a full
 * authenticated HTTP round trip to exercise this logic.
 */
class Sale {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', [ self::class, 'suppress_pos_customer_email' ], 10, 2 );
	}

	/**
	 * P1.12's own "On deferring emails" direction, applied because P1.18
	 * measured this hot path over budget (p95 419.50ms on peapip.com against
	 * a 400ms target — see docs/decisions.md for the measurement): disable
	 * the CUSTOMER completed-order email for POS sales only. That is a
	 * correct product decision, not a deferral — the walk-in customer got
	 * paper. Webhooks and every other order-status action stay on
	 * unconditionally; a slow one is a finding about that plugin, never a
	 * reason to suppress it silently.
	 */
	public static function suppress_pos_customer_email( $enabled, $order ) {
		if ( ! $enabled || ! $order instanceof \WC_Order ) {
			return $enabled;
		}
		return 'pos' !== (string) $order->get_meta( '_cntr_channel' );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/sale',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ], true ),
				'args'                => [
					'uuid'          => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && (bool) preg_match( '/^[0-9a-f-]{36}$/i', $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'register_id'   => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'shift_id'      => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'receipt_no'    => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && '' !== $v,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'rung_at'       => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'customer'      => [ 'required' => false, 'type' => 'object' ],
					'lines'         => [
						'required'          => true,
						'type'              => 'array',
						'validate_callback' => static fn( $v ) => is_array( $v ) && ! empty( $v ),
					],
					'tenders'       => [ 'required' => false, 'type' => 'array' ],
					'cart_discount' => [ 'required' => false, 'type' => 'string' ],
					'offline'       => [ 'required' => false, 'type' => 'boolean' ],
				],
			]
		);
	}

	public static function handle( \WP_REST_Request $req ) {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : [];

		return self::process(
			(string) $req->get_param( 'uuid' ),
			(int) $req->get_param( 'register_id' ),
			(int) $req->get_param( 'shift_id' ),
			(string) $req->get_param( 'receipt_no' ),
			$body
		);
	}

	public static function process( string $uuid, int $register_id, int $shift_id, string $receipt_no, array $body ) {
		$payload_json = wp_json_encode( $body );

		// 1. Gate on the queue.
		$gate               = Queue::gate( $uuid, $register_id, $shift_id, (string) $payload_json );
		$existing_order_id  = 0;

		if ( 'existing' === $gate['status'] ) {
			$row = $gate['row'];
			switch ( $row['status'] ?? '' ) {
				case 'done':
					// The STORED receipt, byte for byte — never a regenerated one.
					return rest_ensure_response( json_decode( (string) $row['receipt_json'], true ) );
				case 'processing':
					$resp = rest_ensure_response( [ 'status' => 'processing', 'retry_after' => 2 ] );
					$resp->set_status( 202 );
					return $resp;
				case 'failed_permanent':
					return new \WP_Error( 'cntr_sale_failed', $row['error'] ?: __( 'Sale failed.', 'counter' ), [ 'status' => 422 ] );
				case 'queued':
				case 'failed_retry':
					// A previous attempt died before finishing — resume it,
					// reusing the order it already built if one exists, so a
					// retry after a mid-transaction crash never creates a
					// second order for the same uuid.
					$existing_order_id = (int) ( $row['order_id'] ?? 0 );
					break;
				default:
					return new \WP_Error( 'cntr_sale_unknown_state', __( 'Unexpected sale state.', 'counter' ), [ 'status' => 500 ] );
			}
		}

		// 2. Mark processing.
		Queue::mark_processing( $uuid );

		try {
			// 3. require_open.
			$shift_check = Shifts::require_open( $register_id );
			if ( is_wp_error( $shift_check ) ) {
				Queue::fail_permanent( $uuid, $shift_check->get_error_message() );
				return $shift_check;
			}

			// P7.4 — audit finding G6. $shift_id (the function parameter) is
			// the shift that was open when this sale was RUNG, carried in
			// the terminal's own payload; $current_shift_id is whichever
			// shift is open on this register RIGHT NOW, which can differ if
			// the intended one has since closed and been counted (a sale
			// that drained late, after the cashier already cashed up).
			// Tenders/shift_sales attach to $current_shift_id below, never
			// the closed one — writing a new tender against an already-
			// counted shift would silently change a figure a human already
			// signed off on.
			$current_shift_id = (int) $shift_check;
			$is_late           = $current_shift_id !== $shift_id;

			if ( $existing_order_id ) {
				$order = wc_get_order( $existing_order_id );
				if ( ! $order ) {
					$msg = __( 'Order vanished after a previous attempt.', 'counter' );
					Queue::fail_permanent( $uuid, $msg );
					return new \WP_Error( 'cntr_sale_order_missing', $msg, [ 'status' => 500 ] );
				}
				$order_receipt_no = (string) $order->get_meta( '_cntr_receipt_no' );
			} else {
				// 4. Resolve or create the customer. A walk-in with no phone —
				// or no match — is customer 0; never create a WordPress user
				// for every walk-in.
				$customer_id = self::resolve_customer( $body['customer'] ?? [] );

				// 5. Build the order.
				$lines = self::build_lines( $body['lines'] ?? [] );
				if ( is_wp_error( $lines ) ) {
					Queue::fail_permanent( $uuid, $lines->get_error_message() );
					return $lines;
				}

				$register       = Registers::get( $register_id );
				$context        = [
					'register_id' => $register_id,
					'shift_id'    => $shift_id,
					'uuid'        => $uuid,
					'location_id' => (int) ( $register['location_id'] ?? Locations::default_id() ),
					// P6.5 — attribution follows the PIN, not the terminal
					// account: whoever last switched in at THIS register
					// (Pos\Pin::switch_operator()) is who actually rang
					// this sale, which can differ from the WordPress
					// account authenticating the request (the shared
					// cntr_terminal credential, or a supervisor's own
					// login). Falls back to the authenticated WP user only
					// when no PIN switch has ever happened at this
					// register — every self-test fixture and every real
					// sale that predates P6.1 still resolves exactly as it
					// did before.
					'operator_id' => \Counter\Pos\Pin::current_operator( $register_id ) ?: get_current_user_id(),
					'customer_id' => $customer_id,
					'receipt_no'  => $receipt_no,
					// B2 — whichever group (if any) the till had active when
					// this sale was rung, straight from the terminal's own
					// cart.priceGroupId; Builder::build() records it on the order.
					'price_group_id' => (int) ( $body['price_group_id'] ?? 0 ),
					// P7.2 — the terminal's own outbox sets this on a sale it
					// queued while offline and drains later; a sale posted
					// directly (online, no outbox involved) never sets it.
					'offline'     => ! empty( $body['offline'] ),
				];

				// P2.6: refuse a credit sale that would exceed the customer's
				// limit BEFORE the order exists, not after — this closure
				// runs inside Builder::build(), after totals are final but
				// before save(), and its exception is caught immediately
				// below without ever reaching Queue::fail_retry() (a limit
				// breach is not a transient failure worth retrying).
				$credit_guard = function ( \WC_Order $built_order ) use ( $body, $customer_id ) {
					if ( 0 === $customer_id || ! current_user_can( 'cntr_credit_sale' ) ) {
						return;
					}
					$order_total = wc_format_decimal( $built_order->get_total(), 4 );
					$tendered    = '0.0000';
					foreach ( (array) ( $body['tenders'] ?? [] ) as $t ) {
						$amount    = wc_format_decimal( $t['amount'] ?? 0, 4 );
						$is_change = ! empty( $t['is_change'] );
						$tendered  = $is_change ? bcsub( $tendered, $amount, 4 ) : bcadd( $tendered, $amount, 4 );
					}
					$shortfall = bcsub( $order_total, $tendered, 4 );
					if ( bccomp( $shortfall, '0', 4 ) > 0 ) {
						$check = \Counter\Credit\CustomerLedger::check_limit( $customer_id, $shortfall );
						if ( is_wp_error( $check ) ) {
							throw new \Counter\Credit\CreditLimitExceeded( $check->get_error_message() );
						}
					}
				};

				try {
					$order = Builder::build( $lines, $context, $credit_guard );
				} catch ( \Counter\Credit\CreditLimitExceeded $e ) {
					Queue::fail_permanent( $uuid, $e->getMessage() );
					return new \WP_Error( 'cntr_credit_limit_exceeded', $e->getMessage(), [ 'status' => 422 ] );
				}
				// Written before anything else can fail — a crash leaves a
				// traceable orphan rather than an untraceable one.
				Queue::set_order_id( $uuid, $order->get_id() );
				$order_receipt_no = $receipt_no;
			}

			// 6. Apply stock, tenders, shift_sales — one transaction.
			// Gated on apply_stock()'s OWN idempotency guard: if it returns
			// false, this exact order's stock already committed in a prior
			// attempt, and recording tenders/shift_sales again here would
			// duplicate them for something that already happened.
			Db::transaction(
				function () use ( $order, $body, $shift_id, $current_shift_id, $is_late, $register_id, $order_receipt_no ) {
					$applied = Channel::apply_stock( $order );
					if ( $applied ) {
						$tender_result = \Counter\Pos\Tenders::record( $order, $body['tenders'] ?? [], $current_shift_id, (int) $order->get_customer_id() );
						if ( is_wp_error( $tender_result ) ) {
							// Throwing here rolls back the stock this same
							// transaction just applied — a tender mismatch
							// must not leave stock decremented for a sale that
							// didn't actually balance.
							throw new \RuntimeException( $tender_result->get_error_message() );
						}
						self::record_shift_sale( $order, $current_shift_id, $register_id, $order_receipt_no, (string) ( $body['exchange_group_id'] ?? '' ) );

						if ( $is_late ) {
							self::record_late_sale( $order, $shift_id, $current_shift_id );
						}
					}
				}
			);

			// 7. Set status directly — payment_complete() re-enters gateway
			// machinery Counter does not use, and WooCommerce's stock hooks
			// are already off, so this transition is inert with respect to
			// stock either way.
			$order->set_status( 'completed' );
			$order->save();

			// 8. Challan serial (P3.5), synchronously, now that the order is
			// committed — P7.7 lands this. Never passed the offline flag:
			// Docs\Challan's own docblock is explicit that a challan for an
			// offline-originated sale is issued through this SAME ordinary
			// path once the order exists as a normal committed order, never
			// through a special offline-origin bypass — and an order
			// reaching this exact line, however many drain retries it took
			// to get here, is by definition no longer "still offline"; the
			// server is handling it right now. Under the 'provisional'
			// policy (§10.1 question 9) that makes this line itself the
			// sync moment for an offline sale — there is no separate later
			// step. A challan failure must never fail an already-committed
			// sale, so this is deliberately never thrown on error.
			\Counter\Docs\Challan::issue( $order->get_id() );

			// 9. Build and store the receipt.
			$receipt      = self::build_receipt( $order, $order_receipt_no );
			$receipt_json = wp_json_encode( $receipt );
			Queue::complete( $uuid, (string) $receipt_json );

			return rest_ensure_response( $receipt );
		} catch ( \Throwable $e ) {
			Queue::fail_retry( $uuid, $e->getMessage() );
			return new \WP_Error( 'cntr_sale_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * F3 (COUNTERFRONTEND.md) — the till now resolves the customer itself,
	 * through a household picker (GET /customers/lookup) that never
	 * auto-picks among multiple candidates, and sends back the id it
	 * already knows is right. Prefer that id when present — it removes
	 * exactly the guess this method used to make by silently taking
	 * matches[0] on ANY phone match. Still validated, never trusted
	 * blindly. Phone-based lookup remains the fallback for a payload that
	 * predates the picker (an offline-queued sale built before F3 existed).
	 */
	private static function resolve_customer( array $customer ): int {
		$customer_id = (int) ( $customer['customer_id'] ?? 0 );
		if ( $customer_id > 0 && get_userdata( $customer_id ) ) {
			return $customer_id;
		}

		$phone = (string) ( $customer['phone'] ?? '' );
		if ( '' === $phone ) {
			return 0;
		}
		$matches = Customers::lookup( $phone );
		return ! empty( $matches ) ? (int) $matches[0]['customer_id'] : 0;
	}

	/** @return array|\WP_Error */
	private static function build_lines( array $raw_lines ) {
		$lines = [];
		foreach ( $raw_lines as $l ) {
			$variation_id = (int) ( $l['variation_id'] ?? 0 );
			$product_id   = $variation_id ?: (int) ( $l['product_id'] ?? 0 );
			$product      = wc_get_product( $product_id );
			if ( ! $product ) {
				return new \WP_Error(
					'cntr_sale_bad_line',
					sprintf( __( 'Product %d not found.', 'counter' ), $product_id ),
					[ 'status' => 422 ]
				);
			}

			$qty        = (string) ( $l['qty'] ?? '0' );
			$unit_price = (string) ( $l['unit_price'] ?? '0' );
			$discount   = (string) ( $l['discount'] ?? '0' );
			$subtotal   = wc_format_decimal( (float) $qty * (float) $unit_price, 4 );
			$total      = wc_format_decimal( (float) $subtotal - (float) $discount, 4 );

			$lines[] = [
				'product'  => $product,
				'qty'      => $qty,
				'subtotal' => $subtotal,
				'total'    => $total,
			];
		}
		return $lines;
	}

	/**
	 * $exchange_group_id, when the terminal supplies one, ties this new sale
	 * to the return half of an exchange (Orders\Refunds::process() writes the
	 * same id on its own shift_sales row) — without it the two look like an
	 * unexplained refund and an unexplained sale to any report.
	 */
	private static function record_shift_sale( \WC_Order $order, int $shift_id, int $register_id, string $receipt_no, string $exchange_group_id = '' ): void {
		global $wpdb;
		$table  = Install::table( 'shift_sales' );
		$result = $wpdb->insert(
			$table,
			[
				'shift_id'          => $shift_id,
				'register_id'       => $register_id,
				'order_id'          => $order->get_id(),
				'kind'              => 'sale',
				'exchange_group_id' => $exchange_group_id,
				'receipt_no'        => $receipt_no,
				'total'             => wc_format_decimal( $order->get_total(), 4 ),
				'created_at'        => Db::now(),
			]
		);
		// $wpdb->insert() returns false on a real failure (e.g. the UNIQUE
		// KEY on receipt_no) without throwing — an unchecked call here would
		// let a sale "complete" (order created, stock applied, tenders
		// recorded) with no shift_sales row at all, invisible to every
		// report and reconciliation that reads that table. Throwing rolls
		// back the whole transaction this runs inside (Rest\Sale::process()'s
		// own Db::transaction() wrapper) exactly like the tender-mismatch
		// case just above this call — a sale that cannot be fully recorded
		// must not be partially recorded.
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not record the shift sale: ' . ( $wpdb->last_error ?: 'unknown database error' ) );
		}
	}

	/**
	 * P7.4 — audit finding G6. Never reopens the shift it names as
	 * $intended_shift_id — that shift's own cntr_shift_sales/cntr_tenders
	 * rows are untouched by this call; this is purely a record for the
	 * variance report (ShiftReport::build() already reads it, both by
	 * intended AND landed shift id) and for whoever reconciles the drawer
	 * later to see why a shift's own count doesn't match its own
	 * cntr_shift_sales total to the poisha.
	 */
	private static function record_late_sale( \WC_Order $order, int $intended_shift_id, int $landed_shift_id ): void {
		global $wpdb;
		$wpdb->insert(
			Install::table( 'late_sales' ),
			[
				'order_id'          => $order->get_id(),
				'intended_shift_id' => $intended_shift_id,
				'landed_shift_id'   => $landed_shift_id,
				'amount'            => wc_format_decimal( $order->get_total(), 4 ),
				'status'            => 'open',
				'created_at'        => Db::now(),
			]
		);
	}

	/**
	 * Public since P1.17 — Pos\Queue::recover_row() reuses it to finish a
	 * sale that crashed after stock committed but before the receipt was
	 * ever built, without reimplementing receipt assembly.
	 */
	public static function build_receipt( \WC_Order $order, string $receipt_no ): array {
		$items = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$unit_id  = $item->get_meta( '_cntr_unit_id' );
			$unit_qty = $item->get_meta( '_cntr_unit_qty' );
			$line     = [
				'name'  => $item->get_name(),
				'qty'   => (string) $item->get_quantity(), // base units — always what apply_stock() actually moved
				'total' => (string) $item->get_total(),
			];
			// P2.12 — the unit the cashier actually chose, for the terminal's
			// own on-screen line and the printed receipt (templates/receipt-79.php
			// reads these same two meta keys directly). Absent for any line
			// sold in its own base unit — 'qty' above is already correct then.
			if ( $unit_id && '' !== $unit_qty ) {
				$line['unit_qty']  = (string) $unit_qty;
				$line['unit_name'] = (string) $item->get_meta( '_cntr_unit_name' );
			}
			$items[] = $line;
		}
		return [
			'order_id'     => $order->get_id(),
			'receipt_no'   => $receipt_no,
			'total'        => (string) $order->get_total(),
			'items'        => $items,
			'created_at'   => Db::now(),
			// The rendered HTML the terminal drops into a hidden iframe and
			// print()s. Included in the STORED receipt payload deliberately —
			// Invariant IV means a replay returns this byte-identical, so a
			// reprinted receipt can never differ from the first.
			'receipt_html' => \Counter\Docs\Receipt::render( $order ),
		];
	}
}
