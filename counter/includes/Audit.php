<?php
namespace Counter;

defined( 'ABSPATH' ) || exit;

/**
 * Every override, void, discount, no-sale, price change, PIN reset and stock
 * adjustment leaves a row. Never throws: an audit failure must not fail the sale it
 * was recording.
 */
class Audit {

	/**
	 * Records user_id (the WordPress account) AND operator_id (the PIN identity of
	 * whoever is standing at the terminal) — they differ on a shared till, and the
	 * distinction is the entire point on the day a drawer is short.
	 */
	public static function log(
		string $action,
		string $object_type,
		int $object_id,
		$before = null,
		$after = null,
		int $operator_id = 0
	): void {
		global $wpdb;

		try {
			$table = Install::table( 'audit_log' );

			$wpdb->insert(
				$table,
				[
					'user_id'     => get_current_user_id(),
					'operator_id' => $operator_id,
					'action'      => $action,
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'before_json' => self::encode( $before ),
					'after_json'  => self::encode( $after ),
					'ip'          => self::client_ip(),
					'created_at'  => Db::now(),
				]
			);
		} catch ( \Throwable $e ) {
			error_log( '[counter] Audit::log failed: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	/**
	 * D5 — the reader Audit::log() never had. A real SQL LIMIT/OFFSET page,
	 * not an in-PHP array_slice() over the whole table the way several
	 * smaller admin list screens do — cntr_audit_log is the one table in
	 * this plugin with no natural upper bound on row count.
	 *
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;
		$table = Install::table( 'audit_log' );

		$where  = 'WHERE 1=1';
		$params = [];
		if ( ! empty( $args['user_id'] ) ) {
			$where   .= ' AND user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where   .= ' AND action = %s';
			$params[] = (string) $args['action'];
		}
		if ( ! empty( $args['object_type'] ) ) {
			$where   .= ' AND object_type = %s';
			$params[] = (string) $args['object_type'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}

		$total = (int) ( empty( $params )
			? $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables when $params is empty
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$page_params = array_merge( $params, [ $per_page, $offset ] );
		$rows        = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...$page_params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where built from %-placeholders only
			ARRAY_A
		);

		foreach ( $rows as &$r ) {
			$r['id']          = (int) $r['id'];
			$r['user_id']     = (int) $r['user_id'];
			$r['operator_id'] = (int) $r['operator_id'];
			$r['object_id']   = (int) $r['object_id'];
			$r['before']      = json_decode( (string) $r['before_json'], true );
			$r['after']       = json_decode( (string) $r['after_json'], true );
		}
		unset( $r );

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/** Every distinct action string ever logged — the Activity Log screen's own filter dropdown, not a maintained list this file would drift from. */
	public static function distinct_actions(): array {
		global $wpdb;
		$table = Install::table( 'audit_log' );
		return $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	private static function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$json = wp_json_encode( $value );
		return false === $json ? null : $json;
	}

	private static function client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return is_string( $ip ) ? sanitize_text_field( $ip ) : '';
	}
}
