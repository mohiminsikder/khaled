<?php
namespace Counter;

defined( 'ABSPATH' ) || exit;

/**
 * The primitives every later task depends on getting right: an atomic sequence
 * allocator, a re-entrant transaction wrapper, a locking read, and a single UTC
 * clock. Nothing here should ever need to change once P1 starts depending on it.
 */
class Db {

	/**
	 * Depth counter for transaction() re-entrancy. The outermost call begins and
	 * commits; an inner call joins it. MySQL has no true nested transactions, so
	 * this is the mechanism, not a savepoint.
	 */
	private static int $tx_depth = 0;

	/**
	 * Callbacks registered via on_rollback(), fired only if the OUTERMOST
	 * transaction actually rolls back — never on commit, and never for a nested
	 * call's own throw (that just propagates up; only depth reaching 0 with a
	 * pending exception is a real ROLLBACK). See Stock\Publisher, which uses
	 * this to invalidate WooCommerce's object cache for products it wrote
	 * through to during a transaction that was then undone — the object cache
	 * does not participate in the SQL transaction, so without this the database
	 * reverts but the cache keeps serving the value that never actually landed.
	 */
	private static array $rollback_callbacks = [];

	/**
	 * Atomic monotonic sequence. Used for the catalogue revision cursor and any
	 * other monotonic number. LAST_INSERT_ID(expr) is the standard MySQL trick for
	 * "increment and tell me the new value" without a lock or a SELECT-then-UPDATE
	 * race.
	 *
	 * The insert branch returns 0 from LAST_INSERT_ID() because there is no
	 * AUTO_INCREMENT column here, so it is handled explicitly. Install::seed()
	 * creates the rows so that branch should never run in production, but a
	 * missing row must not silently return 0 — a rev cursor of 0 would resend the
	 * whole catalogue.
	 */
	public static function next_seq( string $name ): int {
		global $wpdb;
		$t = Install::table( 'counters' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$t} (name, value) VALUES (%s, 1)
				 ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)",
				$name
			)
		);
		if ( 1 === (int) $wpdb->rows_affected ) {
			return 1;                      // row was created by this call
		}
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Wrap a callable in a transaction. RE-ENTRANT, by depth counter: the outermost
	 * call begins and commits; an inner call joins it and neither begins nor
	 * commits on its own. An exception anywhere rolls back the whole outer
	 * transaction.
	 *
	 * It MUST be re-entrant. Channel::apply_stock() opens a transaction, and the
	 * sale endpoint (P1.12) wraps that call in another one. A helper that threw on
	 * nesting would fail on every single sale; one that silently no-opped the inner
	 * call would leave a half-applied sale looking committed. The depth counter is
	 * the only shape that is both safe and usable here.
	 *
	 * MySQL has no true nested transactions, so an inner call does NOT get its own
	 * savepoint and cannot be rolled back independently. If you find yourself
	 * wanting that, restructure the call sites rather than adding SAVEPOINT here —
	 * every caller currently relies on all-or-nothing.
	 *
	 * Never call dbDelta(), CREATE, ALTER or DROP inside this. DDL causes an
	 * implicit commit in MySQL and would silently end the transaction mid-way.
	 */
	public static function transaction( callable $fn ) {
		global $wpdb;

		self::$tx_depth++;
		if ( 1 === self::$tx_depth ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		try {
			$result = $fn();
		} catch ( \Throwable $e ) {
			self::$tx_depth--;
			if ( 0 === self::$tx_depth ) {
				$wpdb->query( 'ROLLBACK' );
				self::run_rollback_callbacks();
			}
			throw $e;
		}

		self::$tx_depth--;
		if ( 0 === self::$tx_depth ) {
			$wpdb->query( 'COMMIT' );
			self::$rollback_callbacks = []; // nothing to undo — discard, don't run
		}

		return $result;
	}

	/**
	 * Register a callback to run if — and only if — the outermost transaction
	 * currently open ends up rolling back. Safe to call from any nesting depth;
	 * it always resolves against the real, outermost commit/rollback.
	 */
	public static function on_rollback( callable $fn ): void {
		self::$rollback_callbacks[] = $fn;
	}

	private static function run_rollback_callbacks(): void {
		$callbacks                = self::$rollback_callbacks;
		self::$rollback_callbacks = [];
		foreach ( $callbacks as $cb ) {
			try {
				$cb();
			} catch ( \Throwable $inner ) {
				// A cache-invalidation failure must not mask the original rollback
				// reason, and must not stop the remaining callbacks from running.
				error_log( '[counter] Db::on_rollback callback failed: ' . $inner->getMessage() ); // phpcs:ignore
			}
		}
	}

	/**
	 * A prepared SELECT ... FOR UPDATE. Only meaningful inside transaction() — the
	 * lock is released at COMMIT/ROLLBACK, and calling this outside a transaction
	 * takes and immediately drops the lock, which defeats the purpose. That is the
	 * caller's responsibility; this helper does not open one itself, because
	 * nesting it in transaction() here would hide the lock's actual lifetime from
	 * whoever is reading the call site.
	 */
	public static function for_update( string $sql, ...$args ): array {
		global $wpdb;
		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, ...$args );
		}
		$rows = $wpdb->get_results( $sql . ' FOR UPDATE', ARRAY_A );
		return null === $rows ? [] : $rows;
	}

	/**
	 * UTC always. Every timestamp is stored UTC and rendered with wp_date() at
	 * display time. Never date(), never gmdate() for anything a cashier sees — this
	 * is the single point that decides what "now" means for a stored row.
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}
}
