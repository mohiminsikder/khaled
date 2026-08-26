<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * P2.1 — stock lives somewhere specific and moves between places with a paper
 * trail. A transfer is never a single balance edit: it is two append-only ledger
 * moves (Invariant I), one per line per leg — `transfer_out` at the source when
 * it leaves, `transfer_in` at the destination when it arrives — so a half-received
 * transfer is visible in the data rather than lost between two numbers.
 *
 * Lifecycle: draft (lines planned, nothing has moved) -> sent (transfer_out
 * written, units leave the source's own sellable balance the moment a driver
 * carries them out the door) -> partial (some lines/quantity received) ->
 * received (every line's qty_sent === qty_received).
 *
 * `cntr_stock.reserved` at the SOURCE location is the running "still in transit"
 * figure — qty_sent minus whatever has been received back onto a transfer_in
 * move so far. This is deliberately a different thing from Reserve::reserved_for()
 * (WooCommerce's own wp_wc_reserved_stock, read by Publisher for unpaid-order
 * holds) — see the docblock on Stock\Reserve. Two reservation systems that don't
 * know about each other would be strictly worse than either alone; this one only
 * ever means "in transit on an internal transfer" and Publisher's own sellable
 * math never reads it, because the units already left the source's `qty` balance
 * via the transfer_out move — they are not sitting on the source's shelf waiting
 * to be double-counted.
 */
class Transfers {

	/**
	 * Creates a draft transfer and its lines. Nothing moves yet — no ledger row,
	 * no balance change — until send(). $data: from_location_id, to_location_id,
	 * note, user_id, lines => [ [ product_id, variation_id, qty ], ... ].
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$from_id = (int) ( $data['from_location_id'] ?? 0 );
		$to_id   = (int) ( $data['to_location_id'] ?? 0 );
		$lines   = (array) ( $data['lines'] ?? [] );

		if ( $from_id <= 0 || $to_id <= 0 ) {
			throw new \InvalidArgumentException( 'Transfers::create() requires a non-zero from_location_id and to_location_id.' );
		}
		if ( $from_id === $to_id ) {
			throw new \InvalidArgumentException( 'Transfers::create() cannot transfer a location to itself.' );
		}
		if ( empty( $lines ) ) {
			throw new \InvalidArgumentException( 'Transfers::create() requires at least one line.' );
		}

		return Db::transaction(
			function () use ( $wpdb, $from_id, $to_id, $lines, $data ) {
				$seq    = Db::next_seq( 'transfer_ref' );
				$ref_no = sprintf( 'TRF-%06d', $seq );

				$table = Install::table( 'transfers' );
				$wpdb->insert(
					$table,
					[
						'ref_no'           => $ref_no,
						'from_location_id' => $from_id,
						'to_location_id'   => $to_id,
						'status'           => 'draft',
						'note'             => $data['note'] ?? null,
						'user_id'          => (int) ( $data['user_id'] ?? get_current_user_id() ),
						'created_at'       => Db::now(),
					]
				);
				$transfer_id = (int) $wpdb->insert_id;

				$lines_table = Install::table( 'transfer_lines' );
				foreach ( $lines as $line ) {
					$qty = wc_format_decimal( $line['qty'] ?? 0, 4 );
					if ( bccomp( $qty, '0', 4 ) <= 0 ) {
						throw new \InvalidArgumentException( 'Transfers::create() line quantity must be greater than zero.' );
					}
					$wpdb->insert(
						$lines_table,
						[
							'transfer_id'  => $transfer_id,
							'product_id'   => (int) ( $line['product_id'] ?? 0 ),
							'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
							'batch_id'     => (int) ( $line['batch_id'] ?? 0 ),
							'qty_sent'     => $qty,
							'qty_received' => '0.0000',
						]
					);
				}

				Audit::log( 'transfer_create', 'transfer', $transfer_id, null, [ 'ref_no' => $ref_no, 'from' => $from_id, 'to' => $to_id ] );

				return $transfer_id;
			}
		);
	}

	/**
	 * Ships every line of a draft transfer: one transfer_out ledger move per
	 * line at the source, and the source's `reserved` bumped by the same amount
	 * — the physical units have left the source's shelf (qty already reflects
	 * that via the move) but are not yet sitting anywhere the ledger can see,
	 * so `reserved` is the only record that they are owed somewhere.
	 */
	public static function send( int $transfer_id, int $user_id = 0 ): void {
		global $wpdb;

		Db::transaction(
			function () use ( $wpdb, $transfer_id, $user_id ) {
				$transfer = self::locked_get( $transfer_id );
				if ( ! $transfer ) {
					throw new \InvalidArgumentException( "Transfers::send(): transfer {$transfer_id} does not exist." );
				}
				if ( 'draft' !== $transfer['status'] ) {
					throw new \RuntimeException( "Transfers::send(): transfer {$transfer_id} is not a draft (status={$transfer['status']})." );
				}

				$lines = self::lines( $transfer_id );
				if ( empty( $lines ) ) {
					throw new \RuntimeException( "Transfers::send(): transfer {$transfer_id} has no lines." );
				}

				foreach ( $lines as $line ) {
					Ledger::move(
						[
							'product_id'   => $line['product_id'],
							'variation_id' => $line['variation_id'],
							'location_id'  => $transfer['from_location_id'],
							'qty_delta'    => bcmul( $line['qty_sent'], '-1', 4 ),
							'reason'       => 'transfer_out',
							'ref_type'     => 'transfer',
							'ref_id'       => $transfer_id,
							'batch_id'     => $line['batch_id'],
							'user_id'      => $user_id,
						]
					);
					self::bump_reserved( (int) $line['product_id'], (int) $line['variation_id'], (int) $transfer['from_location_id'], $line['qty_sent'] );
					Publisher::publish( (int) $line['product_id'], (int) $line['variation_id'] );
				}

				$table = Install::table( 'transfers' );
				$wpdb->update( $table, [ 'status' => 'sent' ], [ 'id' => $transfer_id ] );

				Audit::log( 'transfer_send', 'transfer', $transfer_id, [ 'status' => 'draft' ], [ 'status' => 'sent' ], $user_id );
			}
		);
	}

	/**
	 * Receives some or all of a sent (or already-partial) transfer.
	 * $received: [ line_id => qty_received_now, ... ]. A line not mentioned is
	 * left exactly as it was. Over-receiving a line (more than its own
	 * outstanding qty_sent - qty_received) is refused, and so is any call once
	 * the transfer as a whole is already fully 'received' — see the class
	 * docblock: idempotent by state, not by replaying the same quantity twice.
	 */
	public static function receive( int $transfer_id, array $received, int $user_id = 0 ): void {
		global $wpdb;

		Db::transaction(
			function () use ( $wpdb, $transfer_id, $received, $user_id ) {
				$transfer = self::locked_get( $transfer_id );
				if ( ! $transfer ) {
					throw new \InvalidArgumentException( "Transfers::receive(): transfer {$transfer_id} does not exist." );
				}
				if ( 'received' === $transfer['status'] ) {
					throw new \RuntimeException( "Transfers::receive(): transfer {$transfer_id} has already been fully received." );
				}
				if ( ! in_array( $transfer['status'], [ 'sent', 'partial' ], true ) ) {
					throw new \RuntimeException( "Transfers::receive(): transfer {$transfer_id} has not been sent yet (status={$transfer['status']})." );
				}

				$lines_table = Install::table( 'transfer_lines' );
				$all_lines   = self::lines( $transfer_id );

				foreach ( $received as $line_id => $qty_now ) {
					$line_id = (int) $line_id;
					$qty_now = wc_format_decimal( $qty_now, 4 );
					if ( bccomp( $qty_now, '0', 4 ) <= 0 ) {
						continue;
					}

					$line = null;
					foreach ( $all_lines as $l ) {
						if ( (int) $l['id'] === $line_id ) {
							$line = $l;
							break;
						}
					}
					if ( ! $line ) {
						throw new \InvalidArgumentException( "Transfers::receive(): line {$line_id} does not belong to transfer {$transfer_id}." );
					}

					$outstanding = bcsub( $line['qty_sent'], $line['qty_received'], 4 );
					if ( bccomp( $qty_now, $outstanding, 4 ) > 0 ) {
						throw new \InvalidArgumentException( "Transfers::receive(): line {$line_id} cannot receive {$qty_now}, only {$outstanding} outstanding." );
					}

					Ledger::move(
						[
							'product_id'   => $line['product_id'],
							'variation_id' => $line['variation_id'],
							'location_id'  => $transfer['to_location_id'],
							'qty_delta'    => $qty_now,
							'reason'       => 'transfer_in',
							'ref_type'     => 'transfer',
							'ref_id'       => $transfer_id,
							'batch_id'     => $line['batch_id'],
							'user_id'      => $user_id,
						]
					);
					self::bump_reserved( (int) $line['product_id'], (int) $line['variation_id'], (int) $transfer['from_location_id'], bcmul( $qty_now, '-1', 4 ) );
					Publisher::publish( (int) $line['product_id'], (int) $line['variation_id'] );

					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$lines_table} SET qty_received = qty_received + %s WHERE id = %d",
							$qty_now,
							$line_id
						)
					);
				}

				$still_outstanding = self::outstanding_total( $transfer_id );
				$new_status        = bccomp( $still_outstanding, '0', 4 ) <= 0 ? 'received' : 'partial';

				$table  = Install::table( 'transfers' );
				$fields = [ 'status' => $new_status ];
				if ( 'received' === $new_status ) {
					$fields['received_at'] = Db::now();
				}
				$wpdb->update( $table, $fields, [ 'id' => $transfer_id ] );

				Audit::log( 'transfer_receive', 'transfer', $transfer_id, [ 'status' => $transfer['status'] ], [ 'status' => $new_status ], $user_id );
			}
		);
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'transfers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function lines( int $transfer_id ): array {
		global $wpdb;
		$table = Install::table( 'transfer_lines' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE transfer_id = %d ORDER BY id", $transfer_id ), ARRAY_A );
	}

	/** SELECT ... FOR UPDATE — send() and receive() both need the row locked against a concurrent call. */
	private static function locked_get( int $id ): ?array {
		$table = Install::table( 'transfers' );
		$rows  = Db::for_update( "SELECT * FROM {$table} WHERE id = %d", $id );
		return $rows[0] ?? null;
	}

	private static function outstanding_total( int $transfer_id ): string {
		global $wpdb;
		$table = Install::table( 'transfer_lines' );
		$sum   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CAST(COALESCE(SUM(qty_sent - qty_received), 0) AS DECIMAL(14,4)) FROM {$table} WHERE transfer_id = %d",
				$transfer_id
			)
		);
		return wc_format_decimal( $sum, 4 );
	}

	/**
	 * Relative-only, same discipline as Balance::apply() — never a computed
	 * SET. $delta may be negative (receive() unwinding what send() reserved).
	 * Clamped at zero: a corrected/short receive against a corrupted reserved
	 * figure must never go negative and make a later SELECT read nonsense.
	 */
	private static function bump_reserved( int $product_id, int $variation_id, int $location_id, string $delta ): void {
		global $wpdb;
		$table = Install::table( 'stock' );
		$delta = wc_format_decimal( $delta, 4 );
		$now   = Db::now();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, variation_id, location_id, qty, reserved, updated_at)
				 VALUES (%d, %d, %d, 0.0000, GREATEST(%s, 0), %s)
				 ON DUPLICATE KEY UPDATE reserved = GREATEST(reserved + %s, 0), updated_at = %s",
				$product_id,
				$variation_id,
				$location_id,
				$delta,
				$now,
				$delta,
				$now
			)
		);
	}
}
