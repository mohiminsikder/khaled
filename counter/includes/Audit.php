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
