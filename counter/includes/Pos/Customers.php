<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Db;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Audit finding G4. WooCommerce identifies customers by email; billing_phone
 * is unindexed meta, so a phone lookup across a real order history is a table
 * scan on a keypress. cntr_customer_index exists so an F6 lookup at the till
 * stays fast regardless of catalogue/order history size.
 *
 * Histories do NOT merge automatically — a walk-in recorded today against a
 * guest order and the same person registering online next month are two
 * records until merge() is called deliberately.
 */
class Customers {

	public static function init(): void {
		add_action( 'woocommerce_new_customer', [ self::class, 'reindex_customer' ] );
		add_action( 'woocommerce_update_customer', [ self::class, 'reindex_customer' ] );
		add_action( 'woocommerce_new_order', [ self::class, 'reindex_guest_order' ] );
		add_action( 'woocommerce_checkout_order_processed', [ self::class, 'reindex_guest_order' ] );
	}

	/**
	 * Folds Bangladeshi phone forms into one key. Strips spaces/dashes/
	 * parentheses first, then reduces +8801711223344 / 8801711223344 /
	 * 01711223344 / 1711223344 to the same 880-prefixed 13-digit string.
	 * Rejects anything that does not reduce to that shape, rather than
	 * storing a mangled key.
	 */
	public static function normalise( string $phone ): ?string {
		$digits = preg_replace( '/[^\d+]/', '', $phone );
		$digits = ltrim( (string) $digits, '+' );

		if ( preg_match( '/^880(\d{10})$/', $digits, $m ) ) {
			return '880' . $m[1];
		}
		if ( preg_match( '/^0(\d{10})$/', $digits, $m ) ) {
			return '880' . $m[1];
		}
		if ( preg_match( '/^(\d{10})$/', $digits, $m ) ) {
			return '880' . $m[1];
		}
		return null;
	}

	public static function reindex_customer( int $customer_id ): void {
		$customer = new \WC_Customer( $customer_id );
		$phone    = $customer->get_billing_phone();
		if ( '' === $phone ) {
			return;
		}
		$norm = self::normalise( $phone );
		if ( null === $norm ) {
			return;
		}

		$name = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );
		self::upsert( $norm, $customer_id, '' !== $name ? $name : $customer->get_display_name() );
	}

	public static function reindex_guest_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_customer_id() ) {
			return; // registered customers are indexed via the hooks above
		}
		$phone = $order->get_billing_phone();
		if ( '' === $phone ) {
			return;
		}
		$norm = self::normalise( $phone );
		if ( null === $norm ) {
			return;
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		self::upsert( $norm, 0, $name, Db::now() );
	}

	/**
	 * Never uses VALUES() in the ON DUPLICATE KEY clause — deprecated since
	 * MySQL 8.0.20 (same reasoning as Stock\Balance::apply()). last_order_at is
	 * preserved via a read when this call doesn't know one (a plain profile
	 * update); that read-modify-write is fine here because this is customer
	 * metadata, not a balance Invariant II governs.
	 */
	private static function upsert( string $phone_norm, int $customer_id, string $display_name, ?string $last_order_at = null ): void {
		global $wpdb;
		$table = Install::table( 'customer_index' );
		$now   = Db::now();

		if ( null === $last_order_at ) {
			$last_order_at = $wpdb->get_var(
				$wpdb->prepare( "SELECT last_order_at FROM {$table} WHERE phone_norm = %s AND customer_id = %d", $phone_norm, $customer_id )
			);
		}

		// %s would stringify a PHP null into '', which a strict-mode datetime
		// column rejects — emit a literal NULL instead when there is nothing to
		// store, and a safely quoted value (via a nested prepare()) otherwise.
		$last_order_sql = null === $last_order_at ? 'NULL' : $wpdb->prepare( '%s', $last_order_at );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (phone_norm, customer_id, display_name, last_order_at, updated_at)
				 VALUES (%s, %d, %s, {$last_order_sql}, %s)
				 ON DUPLICATE KEY UPDATE display_name = %s, last_order_at = {$last_order_sql}, updated_at = %s",
				$phone_norm,
				$customer_id,
				$display_name,
				$now,
				$display_name,
				$now
			)
		);
	}

	/**
	 * Every candidate for a phone, most recent first. A household shares a
	 * number; the till shows a picker rather than guessing which one is right.
	 */
	public static function lookup( string $phone ): array {
		$norm = self::normalise( $phone );
		if ( null === $norm ) {
			return [];
		}

		global $wpdb;
		$table = Install::table( 'customer_index' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE phone_norm = %s ORDER BY last_order_at DESC, updated_at DESC",
				$norm
			),
			ARRAY_A
		);
	}

	/**
	 * Reassigns every order from $merge_id to $keep_id — never deletes
	 * anything. Permission-gated on cntr_merge_customers by the REST layer
	 * (Rest\Customer); this method itself only does the work and writes the
	 * audit row, so it stays callable from WP-CLI/tests without going through
	 * the REST guard.
	 */
	public static function merge( int $keep_id, int $merge_id ): bool {
		if ( $keep_id === $merge_id || $keep_id <= 0 || $merge_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$orders_table = $wpdb->prefix . 'wc_orders';
		$index_table  = Install::table( 'customer_index' );

		$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$orders_table} WHERE customer_id = %d", $merge_id ) );
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->set_customer_id( $keep_id );
				$order->save();
			}
		}

		// Reassign the index rows too, but never blindly UPDATE into a
		// (phone_norm, keep_id) pair that already exists — the PRIMARY KEY is
		// exactly that pair, and this is the common case for a merge: both
		// customers already share the phone that made someone notice the
		// duplicate in the first place.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT phone_norm FROM {$index_table} WHERE customer_id = %d", $merge_id ), ARRAY_A );
		foreach ( $rows as $row ) {
			$phone_norm = $row['phone_norm'];
			$exists     = $wpdb->get_var(
				$wpdb->prepare( "SELECT 1 FROM {$index_table} WHERE phone_norm = %s AND customer_id = %d", $phone_norm, $keep_id )
			);
			if ( $exists ) {
				$wpdb->delete( $index_table, [ 'phone_norm' => $phone_norm, 'customer_id' => $merge_id ] );
			} else {
				$wpdb->update(
					$index_table,
					[ 'customer_id' => $keep_id ],
					[ 'phone_norm' => $phone_norm, 'customer_id' => $merge_id ]
				);
			}
		}

		Audit::log( 'customer_merge', 'customer', $keep_id, [ 'merge_id' => $merge_id ], [ 'reassigned_orders' => $order_ids ] );

		return true;
	}
}
