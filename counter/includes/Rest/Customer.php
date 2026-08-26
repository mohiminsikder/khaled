<?php
namespace Counter\Rest;

use Counter\Credit\CustomerLedger;
use Counter\Pricing\Groups;

defined( 'ABSPATH' ) || exit;

/**
 * F2 (COUNTERFRONTEND.md) — the only backend addition that plan permits.
 * cntr_customer_index (Pos\Customers) only ever held phone_norm, customer_id,
 * display_name, last_order_at: enough to find WHO someone is, nothing to show
 * what they owe or usually buy once found. profile() exists to close exactly
 * that gap, once a customer has already been resolved to a WordPress user id
 * (by /customers/lookup) — it is deliberately not a second lookup path.
 */
class Customer {

	/** usual_items() never looks further back than this, however long the customer's real history is. */
	const USUAL_ITEMS_WINDOW_DAYS = 180;

	/** usual_items() never inspects more than this many orders, however many fall inside the window — see its own docblock. */
	const USUAL_ITEMS_ORDER_LIMIT = 50;

	const USUAL_ITEMS_RESULT_LIMIT = 12;

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/customers/lookup',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'lookup' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'phone' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && '' !== $v,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/profile',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'profile' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/customers/(?P<id>\d+)/price-overrides',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'price_overrides' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		// B2 — the till's own price-group picker, independent of any
		// attached customer: a walk-in wholesale buyer with no account still
		// needs wholesale pricing. Deliberately general (by group id, not by
		// customer) rather than a second customer-scoped route — price_overrides()
		// above already delegates to the exact same Groups::overrides_for_group(),
		// this is that same data reachable without a customer in the middle.
		register_rest_route(
			$ns,
			'/price-groups',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_price_groups' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
			]
		);

		register_rest_route(
			$ns,
			'/price-groups/(?P<id>\d+)/overrides',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'group_overrides' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/customers/merge',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'merge' ],
				'permission_callback' => Router::guard( 'cntr_merge_customers' ),
				'args'                => [
					'keep_id'  => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'merge_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public static function lookup( \WP_REST_Request $req ) {
		return rest_ensure_response( \Counter\Pos\Customers::lookup( (string) $req->get_param( 'phone' ) ) );
	}

	public static function profile( \WP_REST_Request $req ) {
		$customer_id = (int) $req->get_param( 'id' );

		$user = get_userdata( $customer_id );
		if ( ! $user ) {
			return new \WP_Error( 'cntr_customer_not_found', __( 'Customer not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$display_name = trim( $user->first_name . ' ' . $user->last_name );
		$display_name = '' !== $display_name ? $display_name : $user->display_name;
		$phone        = ( new \WC_Customer( $customer_id ) )->get_billing_phone();

		$balance      = CustomerLedger::balance( $customer_id );
		$credit_limit = CustomerLedger::credit_limit( $customer_id );

		// CustomerLedger::credit_limit() itself returns '0.0000' both when
		// no limit was ever set AND when one was deliberately set to zero —
		// indistinguishable at that layer. Here, the distinction is exactly
		// the point (no limit -> `available` is null/unlimited; a real zero
		// limit -> `available` is "0.0000"), so read the same raw meta key
		// directly rather than asking CustomerLedger to expose a second
		// accessor for one caller.
		$raw_limit = get_user_meta( $customer_id, '_cntr_credit_limit', true );
		$has_limit = '' !== $raw_limit && false !== $raw_limit;
		$available = null;
		if ( $has_limit ) {
			$available = bcsub( $credit_limit, $balance, 4 );
			if ( bccomp( $available, '0', 4 ) < 0 ) {
				$available = '0.0000';
			}
		}

		return rest_ensure_response(
			[
				'customer_id'    => $customer_id,
				'display_name'   => $display_name,
				'phone'          => $phone,
				'balance'        => $balance,
				'credit_limit'   => $credit_limit,
				'available'      => $available,
				'oldest_due_days' => self::oldest_due_days( $customer_id ),
				'can_credit'     => current_user_can( 'cntr_credit_sale' ) && $customer_id > 0,
				'usual_items'    => self::usual_items( $customer_id ),
				// F4 (COUNTERFRONTEND.md) §2 — 0 means "no group of their own,
				// the register's price applies as normal." Non-zero tells the
				// till to fetch price_overrides and re-price the cart; see
				// price_overrides() below and Pricing\Groups::group_for_customer().
				'price_group_id' => Groups::group_for_customer( $customer_id ),
			]
		);
	}

	/**
	 * F4 (COUNTERFRONTEND.md) §2 — the customer's own price-group override
	 * table, fetched ONCE when a customer with a group attaches (never on
	 * the scan/search hot path — P1.13's 5ms budget applies to those, not to
	 * an attach, which already round-trips for profile()). Empty when the
	 * customer has no group of their own; Pricing\Groups::group_for_customer()
	 * is the single source of truth for which group that is.
	 */
	public static function price_overrides( \WP_REST_Request $req ) {
		$customer_id = (int) $req->get_param( 'id' );
		$group_id    = Groups::group_for_customer( $customer_id );
		return rest_ensure_response( Groups::overrides_for_group( $group_id ) );
	}

	/**
	 * Days between now and the due_date of the OLDEST still-open debit —
	 * the exact integer U1's own wireframe shows ("Oldest due 34 days"),
	 * which CustomerLedger::aging()'s bucketed totals (current/d1_30/...)
	 * cannot produce on their own. Deliberately a small, self-contained
	 * re-run of aging()'s own FIFO consumption (payments/refunds consume
	 * the oldest open debit first) scoped to one customer, rather than a
	 * change to CustomerLedger — that class is out of F2's file list, and
	 * this is read-only aggregation, not a second source of truth for the
	 * balance itself (CustomerLedger::balance() stays authoritative there).
	 */
	private static function oldest_due_days( int $customer_id ): int {
		$rows        = CustomerLedger::ledger( $customer_id );
		$open_debits = [];

		foreach ( $rows as $row ) {
			$amount = $row['amount'];

			if ( bccomp( $amount, '0', 4 ) > 0 ) {
				$open_debits[] = [
					'remaining' => $amount,
					'due_date'  => $row['due_date'] ?: substr( $row['created_at'], 0, 10 ),
				];
				continue;
			}

			$to_consume = bcmul( $amount, '-1', 4 );
			foreach ( $open_debits as &$debit ) {
				if ( bccomp( $to_consume, '0', 4 ) <= 0 ) {
					break;
				}
				if ( bccomp( $debit['remaining'], '0', 4 ) <= 0 ) {
					continue;
				}
				$take               = bccomp( $debit['remaining'], $to_consume, 4 ) <= 0 ? $debit['remaining'] : $to_consume;
				$debit['remaining'] = bcsub( $debit['remaining'], $take, 4 );
				$to_consume         = bcsub( $to_consume, $take, 4 );
			}
			unset( $debit );

			if ( bccomp( $to_consume, '0', 4 ) > 0 ) {
				$open_debits[] = [
					'remaining' => bcmul( $to_consume, '-1', 4 ),
					'due_date'  => substr( $row['created_at'], 0, 10 ),
				];
			}
		}

		$oldest_ts = null;
		foreach ( $open_debits as $debit ) {
			if ( 0 === bccomp( $debit['remaining'], '0', 4 ) ) {
				continue;
			}
			$ts = strtotime( $debit['due_date'] );
			if ( null === $oldest_ts || $ts < $oldest_ts ) {
				$oldest_ts = $ts;
			}
		}

		if ( null === $oldest_ts ) {
			return 0; // nothing open
		}
		return max( 0, (int) floor( ( strtotime( \Counter\Db::now() ) - $oldest_ts ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Up to 12 products this customer buys often, most-bought first.
	 * "Often" is bounded two ways so this stays fast regardless of how long
	 * the customer's real history is: a 180-day window, AND a hard LIMIT of
	 * 50 orders even if more fall inside that window — a busy standing
	 * customer's full history must never turn this into a scan. Uses
	 * wc_get_order()->get_items(), the ONLY pattern this codebase uses
	 * anywhere else for order line items (Orders\Channel, Rest\OrderLookup)
	 * — never a raw join against the order-items tables — bounded to at
	 * most USUAL_ITEMS_ORDER_LIMIT hydrations by the indexed wc_orders
	 * query below (customer_id + status + date_created_gmt), not a
	 * per-item table scan.
	 */
	private static function usual_items( int $customer_id ): array {
		global $wpdb;
		$orders_table = $wpdb->prefix . 'wc_orders';
		$cutoff       = gmdate( 'Y-m-d H:i:s', strtotime( \Counter\Db::now() ) - ( self::USUAL_ITEMS_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$orders_table}
				 WHERE customer_id = %d AND status = 'wc-completed' AND date_created_gmt >= %s
				 ORDER BY date_created_gmt DESC
				 LIMIT %d",
				$customer_id,
				$cutoff,
				self::USUAL_ITEMS_ORDER_LIMIT
			)
		);

		$usual = [];
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id   = $item->get_product_id();
				if ( ! $product_id ) {
					continue;
				}
				$variation_id = $item->get_variation_id();
				$key          = $variation_id ?: $product_id;

				if ( ! isset( $usual[ $key ] ) ) {
					$product = $item->get_product();
					$qty     = (float) $item->get_quantity();
					$usual[ $key ] = [
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'name'         => $item->get_name(),
						'sku'          => $product ? $product->get_sku() : '',
						// Current catalogue price — this is for a "tap to
						// add" strip, so it should show what the item costs
						// NOW, not what this customer paid on some past
						// order. Falls back to that order's own line price
						// only if the product no longer exists to price.
						'price'        => $product
							? wc_format_decimal( $product->get_price(), 4 )
							: wc_format_decimal( $qty > 0 ? (float) $item->get_total() / $qty : 0, 4 ),
						'times_bought' => 0,
					];
				}
				// Once per ORDER that included this product, not per unit —
				// "I've bought this 5 times" means five separate visits, not
				// fifteen units across two of them.
				++$usual[ $key ]['times_bought'];
			}
		}

		usort( $usual, static fn( $a, $b ) => $b['times_bought'] <=> $a['times_bought'] );
		return array_slice( array_values( $usual ), 0, self::USUAL_ITEMS_RESULT_LIMIT );
	}

	/** B2 — active groups only; a deactivated group is not a choice the till should offer. */
	public static function list_price_groups( \WP_REST_Request $req ) {
		return rest_ensure_response(
			array_map(
				static fn( $g ) => [ 'id' => (int) $g['id'], 'name' => (string) $g['name'], 'code' => (string) $g['code'] ],
				Groups::all( 'active' )
			)
		);
	}

	/** B2 — same payload shape price_overrides() above returns, by group id directly. */
	public static function group_overrides( \WP_REST_Request $req ) {
		$group_id = (int) $req->get_param( 'id' );
		if ( ! Groups::get( $group_id ) ) {
			return new \WP_Error( 'cntr_price_group_not_found', __( 'Price group not found.', 'counter' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( Groups::overrides_for_group( $group_id ) );
	}

	public static function merge( \WP_REST_Request $req ) {
		$keep_id  = (int) $req->get_param( 'keep_id' );
		$merge_id = (int) $req->get_param( 'merge_id' );

		$ok = \Counter\Pos\Customers::merge( $keep_id, $merge_id );
		if ( ! $ok ) {
			return new \WP_Error( 'cntr_merge_failed', __( 'Merge failed.', 'counter' ), [ 'status' => 400 ] );
		}
		return rest_ensure_response( [ 'merged' => true ] );
	}
}
