<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * cntr_stock is a CACHED balance, derived from cntr_stock_moves and rebuildable
 * from it (Ledger::rebuild()). Nobody edits a balance directly outside this class,
 * and this class never assigns one — it only ever adds a signed delta.
 */
class Balance {

	/**
	 * INVARIANT II — relative writes only.
	 *
	 * Never "SET qty = <value computed in PHP>". Two registers scanning the same
	 * SKU in the same second both read 10, both compute 9, both write 9: one unit
	 * sold, one unit of truth lost. It self-heals on the next rebuild, which is
	 * exactly what makes it dangerous — the till and the storefront run on a wrong
	 * number until somebody notices, and the ledger looks fine the whole time.
	 *
	 * The database serialises a relative update. It cannot serialise a
	 * read-modify-write done in PHP. The delta and timestamp are written twice
	 * rather than using VALUES(), which is deprecated from MySQL 8.0.20 and would
	 * emit warnings on a modern server.
	 *
	 * Returns the post-update balance as a string — forensic only (balance_after
	 * on the move row), never a source of truth. Under concurrency it can be
	 * non-monotonic across rows and that is fine; Invariant I says the truth is
	 * the sum of the moves.
	 */
	public static function apply( int $product_id, int $variation_id, int $location_id, string $delta ): string {
		global $wpdb;
		$table = Install::table( 'stock' );
		$delta = wc_format_decimal( $delta, 4 );
		$now   = Db::now();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, variation_id, location_id, qty, updated_at)
				 VALUES (%d, %d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE qty = qty + %s, updated_at = %s",
				$product_id,
				$variation_id,
				$location_id,
				$delta,
				$now,
				$delta,
				$now
			)
		);

		return self::read( $product_id, $variation_id, $location_id );
	}

	public static function read( int $product_id, int $variation_id, int $location_id ): string {
		global $wpdb;
		$table = Install::table( 'stock' );
		$qty   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT qty FROM {$table} WHERE product_id = %d AND variation_id = %d AND location_id = %d",
				$product_id,
				$variation_id,
				$location_id
			)
		);
		return null === $qty ? '0.0000' : (string) $qty;
	}

	/**
	 * Directly overwrites a row with an absolute quantity. The ONLY legitimate
	 * caller is the stocktake (P2.9), where an absolute count is the actual
	 * intent — and even there, the write is a ledger move of (counted -
	 * expected), never a direct call to this from application code outside that
	 * one path. Exists now so Ledger::rebuild() has something to reset a row to
	 * before replaying moves onto it.
	 */
	public static function set( int $product_id, int $variation_id, int $location_id, string $qty ): void {
		global $wpdb;
		$table = Install::table( 'stock' );
		$qty   = wc_format_decimal( $qty, 4 );
		$now   = Db::now();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, variation_id, location_id, qty, updated_at)
				 VALUES (%d, %d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE qty = %s, updated_at = %s",
				$product_id,
				$variation_id,
				$location_id,
				$qty,
				$now,
				$qty,
				$now
			)
		);
	}
}
