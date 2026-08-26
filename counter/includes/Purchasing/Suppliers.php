<?php
namespace Counter\Purchasing;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P2.3 — a vendor master with BIN and terms. The blueprint found *akij ×2,
 * AKIZ ×2, Masaf ×3* in a live demo: four rows for what should have been one
 * supplier, because nothing at save time ever compared a new name against
 * what already existed. find_near_duplicate() is that comparison — normalise
 * (case-fold, strip ALL whitespace) and THEN measure edit distance, because
 * "AKIZ" and "akij" are already identical after normalising case and
 * whitespace and still two different strings; catching them needs an actual
 * near-match, not just a stricter exact-match.
 *
 * create()/update() WARN rather than silently refuse — a near-duplicate can
 * be perfectly legitimate (two real suppliers who happen to have similar
 * names). The caller sees the existing record and either points the operator
 * at it or passes $confirm_duplicate = true to create the new one anyway.
 */
class Suppliers {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'suppliers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function all( string $status = '' ): array {
		global $wpdb;
		$table = Install::table( 'suppliers' );
		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name", $status ), ARRAY_A );
	}

	/**
	 * @return array{id:int,warning:?array}|\WP_Error
	 */
	public static function create( array $data, bool $confirm_duplicate = false ) {
		global $wpdb;

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'cntr_supplier_no_name', __( 'A supplier name is required.', 'counter' ), [ 'status' => 400 ] );
		}

		$duplicate = self::find_near_duplicate( $name );
		if ( $duplicate && ! $confirm_duplicate ) {
			return new \WP_Error(
				'cntr_supplier_duplicate',
				__( 'A similarly-named supplier already exists.', 'counter' ),
				[ 'status' => 409, 'existing' => $duplicate ]
			);
		}

		return Db::transaction(
			function () use ( $wpdb, $data, $name, $duplicate ) {
				$table           = Install::table( 'suppliers' );
				$opening_balance = wc_format_decimal( $data['opening_balance'] ?? 0, 4 );

				$wpdb->insert(
					$table,
					[
						'name'            => $name,
						'phone'           => (string) ( $data['phone'] ?? '' ),
						'email'           => (string) ( $data['email'] ?? '' ),
						'bin'             => (string) ( $data['bin'] ?? '' ),
						'address'         => $data['address'] ?? null,
						'terms_days'      => (int) ( $data['terms_days'] ?? 0 ),
						'opening_balance' => $opening_balance,
						'status'          => 'active',
						'created_at'      => Db::now(),
					]
				);
				$id = (int) $wpdb->insert_id;

				if ( bccomp( $opening_balance, '0', 4 ) !== 0 ) {
					self::write_ledger_row( $id, 'opening', $opening_balance, 'opening', 0, __( 'Opening balance', 'counter' ) );
				}

				return [
					'id'      => $id,
					'warning' => $duplicate,
				];
			}
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function update( int $id, array $data, bool $confirm_duplicate = false ) {
		global $wpdb;
		$table = Install::table( 'suppliers' );

		if ( isset( $data['name'] ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'cntr_supplier_no_name', __( 'A supplier name is required.', 'counter' ), [ 'status' => 400 ] );
			}
			$duplicate = self::find_near_duplicate( $name, $id );
			if ( $duplicate && ! $confirm_duplicate ) {
				return new \WP_Error(
					'cntr_supplier_duplicate',
					__( 'A similarly-named supplier already exists.', 'counter' ),
					[ 'status' => 409, 'existing' => $duplicate ]
				);
			}
			$data['name'] = $name;
		}

		$allowed = [ 'name', 'phone', 'email', 'bin', 'address', 'terms_days', 'status' ];
		$fields  = array_intersect_key( $data, array_flip( $allowed ) );
		if ( ! empty( $fields ) ) {
			$wpdb->update( $table, $fields, [ 'id' => $id ] );
		}

		return true;
	}

	/**
	 * A supplier with any supplier_ledger movement (including its own opening
	 * balance) is never hard-deleted — deactivate() instead, so the ledger's
	 * supplier_id foreign reference never dangles. Only a supplier with zero
	 * ledger rows can actually be removed.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$ledger_table = Install::table( 'supplier_ledger' );
		$movements    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ledger_table} WHERE supplier_id = %d", $id ) );
		if ( $movements > 0 ) {
			return new \WP_Error(
				'cntr_supplier_has_movements',
				__( 'This supplier has ledger movements and cannot be deleted — deactivate it instead.', 'counter' ),
				[ 'status' => 409 ]
			);
		}

		$table = Install::table( 'suppliers' );
		$wpdb->delete( $table, [ 'id' => $id ] );
		return true;
	}

	public static function deactivate( int $id ): void {
		global $wpdb;
		$table = Install::table( 'suppliers' );
		$wpdb->update( $table, [ 'status' => 'inactive' ], [ 'id' => $id ] );
	}

	/**
	 * Normalise (case-fold, strip every whitespace character) THEN measure
	 * Levenshtein distance against every other active/inactive supplier name.
	 * A short name tolerates a distance of exactly 1 (one typo'd character —
	 * "AKIZ" against "akij"); the tolerance widens slowly for longer names so
	 * two genuinely different multi-word names don't collide. $exclude_id
	 * skips a supplier's own row when checking an update.
	 */
	public static function find_near_duplicate( string $name, int $exclude_id = 0 ): ?array {
		global $wpdb;
		$table    = Install::table( 'suppliers' );
		$needle   = self::normalize( $name );
		if ( '' === $needle ) {
			return null;
		}

		$rows = $wpdb->get_results( "SELECT id, name FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
		foreach ( $rows as $row ) {
			if ( $exclude_id && (int) $row['id'] === $exclude_id ) {
				continue;
			}
			$hay = self::normalize( $row['name'] );
			if ( '' === $hay ) {
				continue;
			}
			$distance  = levenshtein( $needle, $hay );
			// 12%, not a rounder-looking 20% — verified empirically against both
			// the blueprint's real case (akij/AKIZ, and realistic longer variants
			// like "Akij Food Products Ltd" vs "Akiz Food Products Ltd") and a
			// battery of same-prefix-different-word fixture names from this
			// project's own "Counter Selftest Fixture X" naming convention, which
			// share a long common prefix purely by convention and must NOT
			// collide with each other at 20%.
			$tolerance = max( 1, (int) floor( min( strlen( $needle ), strlen( $hay ) ) * 0.12 ) );
			if ( $distance <= $tolerance ) {
				return self::get( (int) $row['id'] );
			}
		}
		return null;
	}

	public static function normalize( string $name ): string {
		return strtolower( preg_replace( '/\s+/', '', trim( $name ) ) );
	}

	/**
	 * Appends one supplier_ledger row, chaining balance_after off the last row
	 * for this supplier (relative, Invariant II — never a computed absolute
	 * balance written directly). No UPDATE, no DELETE, ever — INSERT-only,
	 * same as cntr_stock_moves; P2.5's own test_supplier_ledger() proves it via
	 * the same source-grep Invariant I uses. Public: P2.4's Receiving uses this
	 * for the bill a receipt creates; P2.5 (SupplierLedger) uses it for
	 * payments and credit notes. A positive amount increases what the shop
	 * owes (opening balance, a bill); a payment or credit note passes negative.
	 *
	 * $due_date is null for anything that isn't itself a bill (P2.3's opening
	 * balance predates this parameter and stays null too — P2.5's aging()
	 * treats a null due_date as due the day it was recorded).
	 */
	public static function write_ledger_row(
		int $supplier_id,
		string $type,
		string $amount,
		string $ref_type,
		int $ref_id,
		string $note,
		?string $due_date = null,
		int $user_id = 0
	): int {
		global $wpdb;
		$table = Install::table( 'supplier_ledger' );

		$prior = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT balance_after FROM {$table} WHERE supplier_id = %d ORDER BY id DESC LIMIT 1",
				$supplier_id
			)
		);
		$prior         = '' === $prior ? '0.0000' : $prior;
		$balance_after = bcadd( $prior, $amount, 4 );

		$wpdb->insert(
			$table,
			[
				'supplier_id'   => $supplier_id,
				'type'          => $type,
				'amount'        => $amount,
				'balance_after' => $balance_after,
				'ref_type'      => $ref_type,
				'ref_id'        => $ref_id,
				'due_date'      => $due_date,
				'note'          => $note,
				'user_id'       => $user_id ?: get_current_user_id(),
				'created_at'    => Db::now(),
			]
		);
		return (int) $wpdb->insert_id;
	}
}
