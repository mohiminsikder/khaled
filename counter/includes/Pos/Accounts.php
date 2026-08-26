<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Db;
use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * P4.6 — the blueprint's sharpest operational finding: a live system
 * carrying 1,168 payments booked against no account, with a standing
 * banner about it. `cntr_payment_accounts` (P1.14) already seeded CASH and
 * a placeholder BKASH; `Tenders::account_for_method()` already resolved a
 * method to an account id, or 0 when none matched — but nothing before
 * this task ever REFUSED a tender over that 0. This class is what makes
 * the account mandatory, on both channels, without ever refusing a
 * customer's own online payment over a configuration gap that is not
 * theirs to fix.
 *
 * POS vs online are deliberately asymmetric: a POS tender with no account
 * is refused outright (Tenders::record()) — the cashier is standing right
 * there and can be told to fix the register's own config before the sale
 * completes. An online order through an unmapped gateway is ACCEPTED —
 * refusing a paying customer over Counter's own configuration gap would be
 * strictly worse than the problem this class exists to prevent — and
 * instead counted, via `_cntr_unmapped_gateway` order meta, so the gap is
 * visible on the Health page with a number that is meant to reach zero,
 * not a silent accumulation nobody notices until it is 1,168 deep.
 */
class Accounts {

	public static function init(): void {
		add_action( 'woocommerce_payment_complete', [ self::class, 'tag_gateway_mapping' ] );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'payment_accounts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$table = Install::table( 'payment_accounts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return $row ?: null;
	}

	public static function all( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'payment_accounts' );
		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id", $status ), ARRAY_A );
	}

	/**
	 * True only for an ACTIVE account — Tenders::record() and this class's
	 * own mapping checks both need "resolves to a real, usable account",
	 * not merely "a row with this id exists somewhere".
	 */
	public static function is_active( int $id ): bool {
		$row = self::get( $id );
		return null !== $row && 'active' === $row['status'];
	}

	/**
	 * @return array{id:int}|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$name = trim( (string) ( $data['name'] ?? '' ) );
		$code = strtoupper( sanitize_key( (string) ( $data['code'] ?? '' ) ) );
		if ( '' === $name || '' === $code ) {
			return new \WP_Error( 'cntr_account_invalid', __( 'A payment account needs both a name and a code.', 'counter' ), [ 'status' => 400 ] );
		}
		$table  = Install::table( 'payment_accounts' );
		$result = $wpdb->insert(
			$table,
			[
				'name'            => $name,
				'code'            => $code,
				'kind'            => (string) ( $data['kind'] ?? 'bank' ),
				'is_drawer'       => ! empty( $data['is_drawer'] ) ? 1 : 0,
				'opening_balance' => wc_format_decimal( $data['opening_balance'] ?? 0, 4 ),
				'status'          => 'active',
			]
		);
		if ( false === $result || ! $wpdb->insert_id ) {
			return new \WP_Error( 'cntr_account_save_failed', $wpdb->last_error ?: __( 'Could not save this account.', 'counter' ), [ 'status' => 500 ] );
		}
		return [ 'id' => (int) $wpdb->insert_id ];
	}

	public static function deactivate( int $id ): void {
		global $wpdb;
		$wpdb->update( Install::table( 'payment_accounts' ), [ 'status' => 'inactive' ], [ 'id' => $id ] );
	}

	/**
	 * Refused, not silently ignored, when real tender movements already
	 * reference this account — deactivate() is the correct way to retire
	 * an account that has ever actually been used.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$tenders_table = Install::table( 'tenders' );
		$movements      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tenders_table} WHERE account_id = %d", $id ) );
		if ( $movements > 0 ) {
			return new \WP_Error(
				'cntr_account_has_movements',
				__( 'This account has real payment movements against it and cannot be deleted — deactivate it instead.', 'counter' ),
				[ 'status' => 409, 'movements' => $movements ]
			);
		}
		$wpdb->delete( Install::table( 'payment_accounts' ), [ 'id' => $id ] );
		return true;
	}

	/** gateway_id => account_id. Stored as JSON under a plain 'string' Settings key — the one map-shaped setting this plugin has. */
	public static function gateway_map(): array {
		$decoded = json_decode( (string) Settings::get( 'payments.gateway_accounts' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public static function set_gateway_map( array $map ): void {
		Settings::set( 'payments.gateway_accounts', wp_json_encode( array_map( 'intval', $map ) ) );
	}

	public static function account_for_gateway( string $gateway_id ): int {
		$map = self::gateway_map();
		return (int) ( $map[ $gateway_id ] ?? 0 );
	}

	/**
	 * Pure — takes the gateway id list rather than reading WC's own live
	 * config, so it is testable against a known set regardless of which
	 * payment plugins happen to be installed on any given site.
	 * unmapped_gateway_ids() (below) is the live wrapper Admin\Health uses.
	 */
	public static function unmapped_from_gateway_ids( array $gateway_ids ): array {
		$unmapped = [];
		foreach ( $gateway_ids as $gateway_id ) {
			$account_id = self::account_for_gateway( $gateway_id );
			if ( $account_id <= 0 || ! self::is_active( $account_id ) ) {
				$unmapped[] = $gateway_id;
			}
		}
		return $unmapped;
	}

	/** Every enabled WooCommerce gateway with no active account mapped. */
	public static function unmapped_gateway_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return [];
		}
		return self::unmapped_from_gateway_ids( array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) );
	}

	/**
	 * Never refuses — an online order's payment already happened. Tags the
	 * order so an unmapped gateway is COUNTED (Health page), not silently
	 * lost, and so reconciliation() below can attribute a mapped order's
	 * total to the right account.
	 */
	public static function tag_gateway_mapping( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$account_id = self::account_for_gateway( $order->get_payment_method() );
		if ( $account_id > 0 && self::is_active( $account_id ) ) {
			$order->update_meta_data( '_cntr_gateway_account_id', $account_id );
			$order->update_meta_data( '_cntr_unmapped_gateway', '' );
		} else {
			$order->update_meta_data( '_cntr_unmapped_gateway', '1' );
		}
		$order->save_meta_data();
	}

	public static function unmapped_order_ids(): array {
		$ids = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => [ [ 'key' => '_cntr_unmapped_gateway', 'value' => '1' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Expected per account for a date range — POS tenders (net of is_change)
	 * plus paid online orders tagged with a mapped account. What the shop
	 * says it actually received is a manual comparison against this,
	 * off-system (a bank/MFS statement, a physical cash count) — this
	 * report computes only the "expected" half.
	 */
	public static function reconciliation( string $from, string $to ): array {
		global $wpdb;
		$from_dt = $from . ' 00:00:00';
		$to_dt   = $to . ' 23:59:59';

		$result = [];
		foreach ( self::all( '' ) as $a ) {
			$result[ (int) $a['id'] ] = [ 'id' => (int) $a['id'], 'name' => $a['name'], 'code' => $a['code'], 'expected' => '0.0000' ];
		}

		$tenders_table = Install::table( 'tenders' );
		$rows          = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT account_id, SUM(CASE WHEN is_change = 1 THEN -amount ELSE amount END) AS net
				 FROM {$tenders_table} WHERE created_at BETWEEN %s AND %s AND account_id > 0
				 GROUP BY account_id",
				$from_dt,
				$to_dt
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$aid = (int) $r['account_id'];
			if ( isset( $result[ $aid ] ) ) {
				$result[ $aid ]['expected'] = bcadd( $result[ $aid ]['expected'], wc_format_decimal( $r['net'], 4 ), 4 );
			}
		}

		$order_ids = wc_get_orders(
			[
				'limit'      => -1,
				'return'     => 'ids',
				'date_paid'  => $from . '...' . $to,
				'meta_query' => [ [ 'key' => '_cntr_gateway_account_id', 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$aid = (int) $order->get_meta( '_cntr_gateway_account_id' );
			if ( isset( $result[ $aid ] ) ) {
				$result[ $aid ]['expected'] = bcadd( $result[ $aid ]['expected'], wc_format_decimal( $order->get_total(), 4 ), 4 );
			}
		}

		return array_values( $result );
	}
}
