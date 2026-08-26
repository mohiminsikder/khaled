<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * INVARIANT IV. cntr_sale_queue.uuid is UNIQUE — the idempotency gate and the
 * offline inbox. gate() is an upsert (ON DUPLICATE KEY UPDATE uuid = uuid),
 * never INSERT IGNORE, for the same reason as Orders\Channel's guard: IGNORE
 * downgrades every error to a warning, and a truncated payload would read as
 * "already sold."
 */
class Queue {

	/**
	 * @return array{status:string, row:?array} status is 'new' when this uuid
	 *         has never been seen (rows_affected===1, a fresh INSERT), or
	 *         'existing' with the current row when it has.
	 */
	public static function gate( string $uuid, int $register_id, int $shift_id, string $payload_json ): array {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$now   = Db::now();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (uuid, register_id, shift_id, kind, payload_json, status, attempts, created_at)
				 VALUES (%s, %d, %d, 'sale', %s, 'queued', 0, %s)
				 ON DUPLICATE KEY UPDATE uuid = uuid",
				$uuid,
				$register_id,
				$shift_id,
				$payload_json,
				$now
			)
		);

		if ( 1 === (int) $wpdb->rows_affected ) {
			return [ 'status' => 'new', 'row' => null ];
		}

		return [ 'status' => 'existing', 'row' => self::get_by_uuid( $uuid ) ];
	}

	public static function get_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A );
		return $row ?: null;
	}

	public static function mark_processing( string $uuid ): void {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', attempts = attempts + 1, rung_at = %s WHERE uuid = %s",
				Db::now(),
				$uuid
			)
		);
	}

	public static function set_order_id( string $uuid, int $order_id ): void {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$wpdb->update( $table, [ 'order_id' => $order_id ], [ 'uuid' => $uuid ] );
	}

	public static function complete( string $uuid, string $receipt_json ): void {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$wpdb->update(
			$table,
			[
				'status'       => 'done',
				'receipt_json' => $receipt_json,
				'completed_at' => Db::now(),
			],
			[ 'uuid' => $uuid ]
		);
	}

	/** Retryable failure — a later attempt with the same uuid may still succeed. */
	public static function fail_retry( string $uuid, string $error ): void {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$wpdb->update( $table, [ 'status' => 'failed_retry', 'error' => $error ], [ 'uuid' => $uuid ] );
	}

	/** Not retryable — the same uuid will always fail this way (e.g. no shift, deleted product). */
	public static function fail_permanent( string $uuid, string $error ): void {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$wpdb->update( $table, [ 'status' => 'failed_permanent', 'error' => $error ], [ 'uuid' => $uuid ] );
	}

	/**
	 * P7.3 — every row a manager still needs to look at: server-rejected
	 * outright (Rest\Sale::process()'s own fail_permanent() calls, e.g. a
	 * deleted product) OR the terminal's own outbox gave up on it after its
	 * retry budget and reported it here on its very next successful
	 * contact with the server (whichever attempt that ends up being — the
	 * row already exists from that attempt, this just reads it back).
	 */
	public static function failed_permanent_list(): array {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'failed_permanent' ORDER BY created_at", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	/**
	 * "Resolving it writes an audit row" — Direction's own words. This is
	 * the acknowledgement half of "re-key the sale, or accept it against a
	 * placeholder line": both of those happen OUTSIDE this system (a human
	 * rings the sale again for real, or books it manually some other way);
	 * this call is what stops the failed row from cluttering the manager
	 * screen once whichever real-world fix already happened, with a durable
	 * record of who did it and what they said they did.
	 *
	 * @return true|\WP_Error
	 */
	public static function resolve_failed( string $uuid, string $note, int $user_id ) {
		$row = self::get_by_uuid( $uuid );
		if ( null === $row ) {
			return new \WP_Error( 'cntr_queue_missing', __( 'Queue entry not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'failed_permanent' !== $row['status'] ) {
			return new \WP_Error( 'cntr_queue_not_failed', __( 'Only a permanently failed entry can be resolved.', 'counter' ), [ 'status' => 409 ] );
		}
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$wpdb->update( $table, [ 'status' => 'resolved' ], [ 'uuid' => $uuid ] );
		\Counter\Audit::log( 'outbox_resolved', 'sale_queue', (int) $row['id'], [ 'status' => 'failed_permanent' ], [ 'status' => 'resolved', 'note' => $note ], $user_id );
		return true;
	}

	const RECOVERY_HOOK        = 'cntr_queue_recovery_tick';
	const STUCK_AFTER_SECONDS  = 120;

	/**
	 * The cron_schedules filter itself is registered from Boot::init(),
	 * unconditionally and before this — see the comment there.
	 */
	public static function init(): void {
		add_action( self::RECOVERY_HOOK, [ self::class, 'recover' ] );
	}

	public static function add_cron_schedule( array $schedules ): array {
		$schedules['cntr_minute'] = [
			'interval' => 60,
			'display'  => __( 'Every minute (Counter queue recovery)', 'counter' ),
		];
		return $schedules;
	}

	/**
	 * Called from Boot::on_activate(). WP-Cron only fires on a page visit
	 * (§P1.17's own direction: "at 06:50 nobody visits") — this schedules the
	 * fallback that always exists; docs/install-guide.md additionally
	 * recommends a real crontab line hitting wp-cron.php directly, with no
	 * query string, for a shop that wants this to run reliably overnight.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::RECOVERY_HOOK ) ) {
			wp_schedule_event( time(), 'cntr_minute', self::RECOVERY_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::RECOVERY_HOOK );
	}

	/**
	 * A crash between apply_stock() committing and the queue row being
	 * marked done (P1.12 steps 6–9) leaves a 'processing' row that never
	 * resolves on its own — process()'s own 'processing' branch deliberately
	 * just replies 202 rather than resuming (two concurrent requests must
	 * not race to finish the same row), so only an out-of-band sweep like
	 * this one can close it out.
	 *
	 * @return array{completed:int, failed:int}
	 */
	public static function recover( int $stuck_after_seconds = self::STUCK_AFTER_SECONDS ): array {
		global $wpdb;
		$table     = Install::table( 'sale_queue' );
		$threshold = gmdate( 'Y-m-d H:i:s', time() - $stuck_after_seconds );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'processing' AND rung_at < %s", $threshold ),
			ARRAY_A
		);

		$result = [ 'completed' => 0, 'failed' => 0 ];
		foreach ( $rows as $row ) {
			if ( self::recover_row( $row ) ) {
				++$result['completed'];
			} else {
				++$result['failed'];
			}
		}
		return $result;
	}

	/**
	 * Does NOT call Orders\Channel::apply_stock() again — the whole point is
	 * that stock is already applied-or-not, guarded by cntr_order_stock_state,
	 * before recovery ever runs. This only finishes the bookkeeping
	 * (order status, receipt, queue row) a completed transaction was left
	 * holding, or marks the attempt retryable when nothing durable happened.
	 */
	private static function recover_row( array $row ): bool {
		$order_id = (int) $row['order_id'];
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! self::stock_applied( $order_id ) ) {
			self::fail_retry(
				$row['uuid'],
				$order ? __( 'Recovered: stock was never applied for this attempt — safe to retry.', 'counter' )
					: __( 'Recovered: the order for this attempt no longer exists.', 'counter' )
			);
			return false;
		}

		if ( 'completed' !== $order->get_status() ) {
			$order->set_status( 'completed' );
			$order->save();
		}

		$receipt_no = (string) $order->get_meta( '_cntr_receipt_no' );
		$receipt    = \Counter\Rest\Sale::build_receipt( $order, $receipt_no );
		self::complete( $row['uuid'], (string) wp_json_encode( $receipt ) );
		return true;
	}

	private static function stock_applied( int $order_id ): bool {
		global $wpdb;
		$table = Install::table( 'order_stock_state' );
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE order_id = %d AND refund_id = 0 AND state = 'applied'", $order_id )
		);
	}
}
