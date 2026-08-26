<?php
namespace Counter\Orders;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Audit finding B4. Every return, online or in-shop, is processed at the
 * counter, restocks through the ledger, and reconciles against the drawer.
 *
 * The case that matters most: a customer returns, at the counter, an order
 * they paid for online by bKash. That is a WooCommerce refund on a gateway
 * order PLUS a cash withdrawal from the drawer. Without the refund shift-
 * event type the Z-report is missing a term and cannot balance on any day
 * containing a return — which is every day.
 */
class Refunds {

	/**
	 * Hard process rule, worth restating in the UI: every return, online or
	 * in-shop, is processed in the POS first. A refund issued in WooCommerce
	 * admin alone leaves stock and the P&L wrong — this notice says so on the
	 * order/refund screen itself, where someone is most likely to reach for
	 * the wrong button.
	 */
	public static function admin_notice_init(): void {
		add_action( 'admin_notices', [ self::class, 'maybe_show_notice' ] );
	}

	public static function maybe_show_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'shop_order' ], true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Counter: process every return through the POS terminal, not here. A refund issued in WooCommerce admin alone leaves stock and the profit-and-loss wrong.', 'counter' )
		);
	}

	/**
	 * $line_items is WooCommerce's own shape for wc_create_refund():
	 * [ order_item_id => [ 'qty' => ..., 'refund_total' => ..., 'refund_tax' => [...] ] ]
	 *
	 * @return true|\WP_Error
	 */
	public static function process(
		\WC_Order $order,
		string $amount,
		array $line_items,
		string $reason,
		int $shift_id,
		array $tenders,
		?string $exchange_group_id = null
	) {
		/**
		 * restock_items MUST be false. wc_create_refund( [ 'restock_items' =>
		 * true ] ) calls wc_restock_refunded_items(), which adjusts stock
		 * directly and behind the ledger's back. Counter owns restocking
		 * (Invariant III); the ledger move is written by
		 * Channel::apply_refund_reversion() immediately after, so the units
		 * come back with a reason, a reference and a cost.
		 */
		// Channel::on_order_refunded() (P4.1) would otherwise react to this
		// SAME refund the instant wc_create_refund() creates it — before the
		// transaction below even opens — breaking its atomicity with the
		// tenders/shift_event/shift_sale_return writes. This POS path reverts
		// stock itself, explicitly, inside that transaction; suppressing the
		// hook here is what keeps this refund's reversion atomic with the
		// rest of what this function writes.
		$refund = Channel::without_refund_hook(
			function () use ( $order, $amount, $line_items, $reason ) {
				return wc_create_refund(
					[
						'order_id'       => $order->get_id(),
						'amount'         => $amount,
						'line_items'     => $line_items,
						'reason'         => $reason,
						'refund_payment' => false, // no gateway call; the cash is in the drawer
						'restock_items'  => false,
					]
				);
			}
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		// P4.4 — the only mark left behind that a refund actually went
		// through the counter, not wp-admin directly. Admin\Health reads
		// its absence to warn rather than let a diverged refund pass
		// silently; see that class's own docblock.
		$refund->update_meta_data( '_cntr_via_pos', '1' );
		$refund->save_meta_data();

		try {
			Db::transaction(
				function () use ( $order, $refund, $shift_id, $tenders, $exchange_group_id, $amount ) {
					$applied = Channel::apply_refund_reversion( $order, $refund );
					if ( ! $applied ) {
						throw new \RuntimeException( __( 'This refund was already processed.', 'counter' ) );
					}

					$is_cash = self::any_cash_tender( $tenders );
					self::record_shift_event( $shift_id, $is_cash ? 'refund' : 'refund_noncash', $amount, $order->get_id(), $refund->get_id() );
					self::record_refund_tenders( $order, $refund, $tenders, $shift_id );
					self::record_shift_sale_return( $order, $refund, $shift_id, $exchange_group_id );
					self::apply_credit_refund( $order, $refund, $tenders );
				}
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'cntr_refund_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		return true;
	}

	private static function any_cash_tender( array $tenders ): bool {
		foreach ( $tenders as $t ) {
			if ( 'cash' === ( $t['method'] ?? '' ) ) {
				return true;
			}
		}
		return empty( $tenders ); // no explicit tender given defaults to a cash-drawer assumption
	}

	private static function record_shift_event( int $shift_id, string $type, string $amount, int $order_id, int $refund_id ): void {
		global $wpdb;
		$table = Install::table( 'shift_events' );
		$wpdb->insert(
			$table,
			[
				'shift_id'   => $shift_id,
				'type'       => $type,
				'amount'     => wc_format_decimal( $amount, 4 ),
				'method'     => 'refund' === $type ? 'cash' : '',
				'ref_type'   => 'refund',
				'ref_id'     => $refund_id,
				'note'       => sprintf( __( 'Refund for order #%d', 'counter' ), $order_id ),
				'user_id'    => get_current_user_id(),
				'created_at' => Db::now(),
			]
		);
	}

	private static function record_refund_tenders( \WC_Order $order, \WC_Order_Refund $refund, array $tenders, int $shift_id ): void {
		global $wpdb;
		$table = Install::table( 'tenders' );
		foreach ( $tenders as $t ) {
			$wpdb->insert(
				$table,
				[
					'order_id'   => $order->get_id(),
					'refund_id'  => $refund->get_id(),
					'shift_id'   => $shift_id,
					'method'     => (string) ( $t['method'] ?? 'cash' ),
					'amount'     => wc_format_decimal( $t['amount'] ?? 0, 4 ),
					'is_change'  => 0,
					'reference'  => (string) ( $t['reference'] ?? '' ),
					'account_id' => \Counter\Pos\Tenders::account_for_method( (string) ( $t['method'] ?? 'cash' ) ),
					'user_id'    => get_current_user_id(),
					'created_at' => Db::now(),
				]
			);
		}
	}

	/**
	 * P2.6 — 'credit' is a real tender method (Pos\Tenders::VALID_METHODS,
	 * since P1.14) meaning "this much of the refund reduces what the
	 * customer owes rather than coming back as cash." Sums every 'credit'-
	 * method line in $tenders and writes one customer_ledger credit for the
	 * total; a refund with no 'credit' tender (the ordinary case) writes
	 * nothing here.
	 */
	private static function apply_credit_refund( \WC_Order $order, \WC_Order_Refund $refund, array $tenders ): void {
		$customer_id = (int) $order->get_customer_id();
		if ( ! $customer_id ) {
			return;
		}

		$credit_amount = '0.0000';
		foreach ( $tenders as $t ) {
			if ( 'credit' === ( $t['method'] ?? '' ) ) {
				$credit_amount = bcadd( $credit_amount, wc_format_decimal( $t['amount'] ?? 0, 4 ), 4 );
			}
		}
		if ( bccomp( $credit_amount, '0', 4 ) <= 0 ) {
			return;
		}

		\Counter\Credit\CustomerLedger::record_refund_credit(
			$customer_id,
			$credit_amount,
			'refund',
			$refund->get_id(),
			sprintf( __( 'Refund credited against order #%d', 'counter' ), $order->get_id() )
		);
	}

	private static function record_shift_sale_return( \WC_Order $order, \WC_Order_Refund $refund, int $shift_id, ?string $exchange_group_id ): void {
		global $wpdb;
		$table       = Install::table( 'shift_sales' );
		$register_id = (int) $order->get_meta( '_cntr_register_id' );
		$result      = $wpdb->insert(
			$table,
			[
				'shift_id'          => $shift_id,
				'register_id'       => $register_id,
				'order_id'          => $order->get_id(),
				'refund_id'         => $refund->get_id(),
				'kind'              => 'return',
				'exchange_group_id' => (string) $exchange_group_id,
				'receipt_no'        => 'RTN-' . $refund->get_id(),
				'total'             => wc_format_decimal( $refund->get_amount(), 4 ),
				'created_at'        => Db::now(),
			]
		);
		// Same reasoning as Rest\Sale::record_shift_sale(): an unchecked
		// $wpdb->insert() here (e.g. a receipt_no collision against the
		// UNIQUE KEY) would let a refund "complete" — stock reverted, tenders
		// recorded, credit applied — with no shift_sales row to reconcile it
		// against on any Z-report. Throwing rolls back the whole transaction.
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not record the shift sale return: ' . ( $wpdb->last_error ?: 'unknown database error' ) );
		}
	}
}
