<?php
namespace Counter\Docs;

use Counter\Install;
use Counter\Db;
use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * P3.5 — Audit finding B5. Invariant V: the challan register is append-only,
 * gap-free, and never edited. A cancellation is a NEW row (kind='reversal',
 * reverses_id set, amounts negated so a naive SUM() across every row already
 * nets out correctly) — never an UPDATE, never a DELETE.
 *
 * Note the scope line from Direction, because it is the one that decides
 * whether this class is a week or two months: printing a compliant Mushak
 * 6.3 invoice is NOT the same as fiscalising a sale through an NBR device.
 * This class does the challan layout, the register, and (P3.6) exports —
 * where a fiscal device is legally mandated, an EFD/SDC handles reporting
 * to NBR, and that is out of scope here by design, not by oversight.
 *
 * The serial's numeric part comes from Db::next_seq('vat_challan') — a
 * dedicated, already-proven atomic counter (Db.php: "used for the catalogue
 * revision cursor and any other monotonic number") — rather than reading
 * back this table's own AUTO_INCREMENT insert_id. Reading back insert_id
 * would need either a follow-up write this table is not allowed to make
 * (append-only) or an INSERT...SELECT MAX(seq)+1 race that silently drifts
 * from the row's own seq the first time any gap ever occurs. next_seq()'s
 * own bookkeeping lives in a different table entirely (cntr_counters), so
 * this class's own append-only guarantee (test_challan() check 6, a source
 * grep) is untouched by it — a genuinely separate table being written, not
 * a workaround for this one.
 */
class Challan {

	/**
	 * Only ever called AFTER the order it is for is already committed —
	 * Direction: "allocated only after the order is committed... never in
	 * an async worker." $offline_request marks a call arriving as part of
	 * replaying/syncing an offline-originated sale (P7's own job to build;
	 * this flag is the contract that job will call through) — refused
	 * outright under the 'provisional' policy, because under that policy a
	 * challan for an offline sale is only ever issued through the ordinary
	 * synchronous path once that order exists as a normal committed order,
	 * never through a special offline-origin bypass.
	 *
	 * @return array|\WP_Error the register row (existing, if this order
	 *   already has one — issuing is idempotent by order id)
	 */
	public static function issue( int $order_id, bool $offline_request = false ) {
		if ( $offline_request && 'provisional' === Settings::get( 'offline.challan_policy' ) ) {
			return new \WP_Error(
				'cntr_challan_offline_refused',
				__( "An offline sale is a provisional bill under this shop's policy — its Mushak 6.3 challan is issued once the sale syncs, never while offline.", 'counter' ),
				[ 'status' => 409 ]
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'cntr_challan_no_order', __( 'Cannot issue a challan for an order that does not exist.', 'counter' ), [ 'status' => 404 ] );
		}

		// Idempotent by order id: a retry (a reprint, a re-POST after a
		// client-side timeout) must return the SAME serial, never burn a
		// second one. There is no row of ours to SELECT ... FOR UPDATE
		// against — WooCommerce owns the order — so this reuses the same
		// atomic add_option() UNIQUE-key guard P2.2/P2.4 use for exactly
		// this shape of problem.
		$guard = 'cntr_challan_issued_order_' . $order_id;
		if ( ! add_option( $guard, '', '', 'no' ) ) {
			$existing = self::get_by_order( $order_id );
			if ( $existing ) {
				return $existing;
			}
			// The guard exists but no row does — a prior call crashed
			// between the guard and the insert. Fall through and issue for
			// real; the guard is (re)written below once this succeeds.
		}

		$serial = self::allocate_serial();
		$bill   = self::bill_data_from_order( $order );

		global $wpdb;
		$table = Install::table( 'challan_register' );
		$wpdb->insert(
			$table,
			[
				'serial'        => $serial,
				'kind'          => 'issue',
				'reverses_id'   => 0,
				'order_id'      => $order_id,
				'bin'           => (string) Settings::get( 'shop.bin' ),
				'buyer_name'    => $bill['buyer_name'],
				'buyer_bin'     => $bill['buyer_bin'],
				'taxable_value' => $bill['taxable_value'],
				'sd_amount'     => $bill['sd_amount'],
				'vat_amount'    => $bill['vat_amount'],
				'rounding'      => $bill['rounding'],
				'total'         => $bill['total'],
				'lines_json'    => wp_json_encode( $bill['lines'] ),
				'issued_at'     => Db::now(),
				'user_id'       => get_current_user_id(),
			]
		);

		$row = self::get_by_order( $order_id );
		update_option( $guard, $serial );

		// §5.8's own "Written by" column names this meta as P3.5's
		// responsibility — a receipt template or export that wants to show
		// "this order has a VAT serial" reads it straight off the order,
		// never a second query against the register for something this
		// call just wrote. Written once, on the real issuing call only —
		// the "already issued" retry path above returns before this line.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_cntr_challan_serial', $serial );
			$order->save();
		}

		return $row;
	}

	/**
	 * A cancellation is a NEW row, never an edit of the original.
	 *
	 * @return array|\WP_Error the reversal row
	 */
	public static function cancel( int $order_id ) {
		$original = self::get_by_order( $order_id );
		if ( ! $original ) {
			return new \WP_Error( 'cntr_challan_no_original', __( 'No issued challan exists for this order.', 'counter' ), [ 'status' => 404 ] );
		}

		global $wpdb;
		$table = Install::table( 'challan_register' );

		$already_reversed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE reverses_id = %d", $original['seq'] ) );
		if ( $already_reversed > 0 ) {
			return new \WP_Error( 'cntr_challan_already_reversed', __( 'This challan has already been reversed.', 'counter' ), [ 'status' => 409 ] );
		}

		$serial = self::allocate_serial();
		$wpdb->insert(
			$table,
			[
				'serial'        => $serial,
				'kind'          => 'reversal',
				'reverses_id'   => (int) $original['seq'],
				'order_id'      => $order_id,
				'bin'           => $original['bin'],
				'buyer_name'    => $original['buyer_name'],
				'buyer_bin'     => $original['buyer_bin'],
				'taxable_value' => bcmul( $original['taxable_value'], '-1', 4 ),
				'sd_amount'     => bcmul( $original['sd_amount'], '-1', 4 ),
				'vat_amount'    => bcmul( $original['vat_amount'], '-1', 4 ),
				'rounding'      => bcmul( $original['rounding'], '-1', 4 ),
				'total'         => bcmul( $original['total'], '-1', 4 ),
				'lines_json'    => $original['lines_json'],
				'issued_at'     => Db::now(),
				'user_id'       => get_current_user_id(),
			]
		);

		return self::get_by_seq( (int) $wpdb->insert_id );
	}

	public static function get_by_seq( int $seq ): ?array {
		global $wpdb;
		$table = Install::table( 'challan_register' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE seq = %d", $seq ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_order( int $order_id, string $kind = 'issue' ): ?array {
		global $wpdb;
		$table = Install::table( 'challan_register' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND kind = %s ORDER BY seq LIMIT 1", $order_id, $kind ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Every row in the register within a date range, oldest first — the raw
	 * material P3.6's exports and any auditor-facing listing read from.
	 * Inclusive at both ends: $from/$to are 'Y-m-d' shop-local dates.
	 */
	public static function register( string $from = '', string $to = '' ): array {
		global $wpdb;
		$table = Install::table( 'challan_register' );
		if ( '' === $from && '' === $to ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY seq", ARRAY_A );
		}
		$from_dt = '' !== $from ? $from . ' 00:00:00' : '0000-01-01 00:00:00';
		$to_dt   = '' !== $to ? $to . ' 23:59:59' : '9999-12-31 23:59:59';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE issued_at BETWEEN %s AND %s ORDER BY seq", $from_dt, $to_dt ),
			ARRAY_A
		);
	}

	public static function render_html( array $challan, \WC_Order $order ): string {
		ob_start();
		include CNTR_DIR . 'templates/challan-63.php';
		return (string) ob_get_clean();
	}

	private static function allocate_serial(): string {
		$n      = Db::next_seq( 'vat_challan' );
		$start  = (int) Settings::get( 'vat.challan_start' );
		$prefix = (string) Settings::get( 'vat.challan_prefix' );
		return $prefix . str_pad( (string) ( $start + $n - 1 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * sd_amount (Supplementary Duty) is always '0.0000' — no SD-liable
	 * product category exists anywhere else in this plugin to compute it
	 * from. A real gap, not a silent assumption: documented in
	 * docs/decisions.md rather than pretended away.
	 */
	private static function bill_data_from_order( \WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$lines[] = [
				'name'  => $item->get_name(),
				'qty'   => (string) $item->get_quantity(),
				'total' => (string) $item->get_total(),
			];
		}

		$buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		return [
			'buyer_name'    => '' !== $buyer_name ? $buyer_name : __( 'Walk-in customer', 'counter' ),
			'buyer_bin'     => (string) $order->get_meta( '_cntr_buyer_bin' ),
			'taxable_value' => wc_format_decimal( $order->get_subtotal(), 4 ),
			'sd_amount'     => '0.0000',
			'vat_amount'    => wc_format_decimal( $order->get_total_tax(), 4 ),
			'rounding'      => wc_format_decimal( (string) ( $order->get_meta( '_cntr_rounding' ) ?: '0' ), 4 ),
			'total'         => wc_format_decimal( $order->get_total(), 4 ),
			'lines'         => $lines,
		];
	}
}
