<?php
namespace Counter;

use Counter\Stock\Ledger;
use Counter\Stock\Batches;
use Counter\Stock\Publisher;
use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * P2.10 — proves Invariant I: every balance in the system is reconstructible
 * from cntr_stock_moves alone. `wp counter rebuild-stock` is what makes "why
 * is this number wrong?" a five-minute question — and the regression test
 * for audit finding B1: an incomplete ledger (an online order that stopped
 * writing moves, say) produces a rebuild that silently ERASES those sales
 * from cntr_stock instead of the shop finding out in three months.
 * test_rebuild() asserts an online sale specifically survives a rebuild for
 * exactly this reason.
 *
 * Dry run by default — a report only, nothing written. --yes to actually
 * write, at which point every touched product/variation is re-published to
 * WooCommerce too (a rebuilt balance nobody re-publishes is a balance the
 * storefront still has wrong).
 */
class Cli {

	public static function init(): void {
		if ( ! ( defined( 'WP_CLI' ) && \WP_CLI ) ) {
			return;
		}
		\WP_CLI::add_command( 'counter rebuild-stock', [ self::class, 'rebuild_stock' ] );
		\WP_CLI::add_command( 'counter rebuild-rollup', [ self::class, 'rebuild_rollup' ] );
	}

	/**
	 * P5.1 — recomputes cntr_sales_daily entirely from orders and
	 * cntr_stock_moves, same dry-run-by-default/--yes shape as
	 * rebuild-stock. Without --from/--to, rebuilds (and, with --yes,
	 * REPLACES) the whole table.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : 'Y-m-d', shop-local, inclusive.
	 *
	 * [--to=<date>]
	 * : 'Y-m-d', shop-local, inclusive.
	 *
	 * [--yes]
	 * : Actually write. Without it, this is a dry run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp counter rebuild-rollup
	 *     wp counter rebuild-rollup --from=2026-08-01 --to=2026-08-31 --yes
	 */
	public static function rebuild_rollup( array $args, array $assoc_args ): void {
		$from  = $assoc_args['from'] ?? null;
		$to    = $assoc_args['to'] ?? null;
		$write = ! empty( $assoc_args['yes'] );

		\WP_CLI::log( $write ? 'Writing changes.' : 'Dry run — nothing will be written. Pass --yes to write.' );

		$report = Reports\Rollup::rebuild( $from, $to, $write );

		\WP_CLI::log( '' );
		foreach ( $report['rows'] as $r ) {
			\WP_CLI::log( sprintf( '  %s / %s / location=%d: gross=%s refunds=%s net=%s cogs=%s margin=%s', $r['day'], $r['channel'], $r['location_id'], $r['gross'], $r['refunds'], $r['net'], $r['cogs'], $r['margin'] ) );
		}

		\WP_CLI::success( sprintf( '%s %d row(s).', $write ? 'Rebuilt' : 'Would rebuild', $report['count'] ) );
	}

	/**
	 * Recomputes cntr_stock and cntr_batches.qty_remaining from the ledger
	 * alone, reporting every row it changed (or would change).
	 *
	 * ## OPTIONS
	 *
	 * [--product=<id>]
	 * : Limit to one product (and its parent-managed variations' shared row).
	 *
	 * [--yes]
	 * : Actually write and re-publish. Without it, this is a dry run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp counter rebuild-stock
	 *     wp counter rebuild-stock --product=123 --yes
	 */
	public static function rebuild_stock( array $args, array $assoc_args ): void {
		$product_id = isset( $assoc_args['product'] ) ? absint( $assoc_args['product'] ) : null;
		$write      = ! empty( $assoc_args['yes'] );
		$dry_run    = ! $write;

		\WP_CLI::log( $dry_run ? 'Dry run — nothing will be written. Pass --yes to write.' : 'Writing changes.' );

		$stock_report = Ledger::rebuild( $product_id, $dry_run );
		$batch_report = self::rebuild_batches_for( $product_id, $dry_run );

		\WP_CLI::log( '' );
		\WP_CLI::log( 'cntr_stock:' );
		if ( empty( $stock_report['touched'] ) && empty( $stock_report['orphans_reset'] ) ) {
			\WP_CLI::log( '  (nothing to change)' );
		}
		foreach ( $stock_report['touched'] as $row ) {
			\WP_CLI::log( sprintf( '  product=%d variation=%d location=%d: %s -> %s', $row['product_id'], $row['variation_id'], $row['location_id'], $row['before'], $row['after'] ) );
		}
		foreach ( $stock_report['orphans_reset'] as $row ) {
			\WP_CLI::log( sprintf( '  product=%d variation=%d location=%d: %s -> 0.0000 (orphan — no moves behind this row at all)', $row['product_id'], $row['variation_id'], $row['location_id'], $row['before'] ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'cntr_batches.qty_remaining:' );
		if ( empty( $batch_report['touched'] ) ) {
			\WP_CLI::log( '  (nothing to change)' );
		}
		foreach ( $batch_report['touched'] as $row ) {
			\WP_CLI::log( sprintf( '  batch=%d: %s -> %s', $row['batch_id'], $row['before'], $row['after'] ) );
		}

		if ( $write ) {
			$published = self::republish_touched( $stock_report, $batch_report );
			\WP_CLI::success(
				sprintf(
					'%d cntr_stock row(s), %d cntr_batches row(s) rebuilt; %d product(s) re-published.',
					$stock_report['count'],
					$batch_report['count'],
					$published
				)
			);
		} else {
			\WP_CLI::success(
				sprintf(
					'Dry run: %d cntr_stock row(s), %d cntr_batches row(s) would change. Re-run with --yes to write.',
					$stock_report['count'],
					$batch_report['count']
				)
			);
		}
	}

	/**
	 * Batches::rebuild() itself scopes by batch id, not product id — when a
	 * product filter is given, resolve which batches belong to it first,
	 * then rebuild each and merge the reports. No filter rebuilds every
	 * batch in one call.
	 */
	private static function rebuild_batches_for( ?int $product_id, bool $dry_run ): array {
		if ( null === $product_id ) {
			return Batches::rebuild( null, $dry_run );
		}

		global $wpdb;
		$table     = Install::table( 'batches' );
		$batch_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d OR variation_id = %d", $product_id, $product_id )
		);

		$touched = [];
		foreach ( $batch_ids as $batch_id ) {
			$report  = Batches::rebuild( (int) $batch_id, $dry_run );
			$touched = array_merge( $touched, $report['touched'] );
		}
		return [ 'touched' => $touched, 'count' => count( $touched ) ];
	}

	/** Every unique (product_id, variation_id) either report touched, re-published once. */
	private static function republish_touched( array $stock_report, array $batch_report ): int {
		$pairs = [];
		foreach ( $stock_report['touched'] as $row ) {
			$pairs[ $row['product_id'] . ':' . $row['variation_id'] ] = [ $row['product_id'], $row['variation_id'] ];
		}
		foreach ( $stock_report['orphans_reset'] as $row ) {
			$pairs[ $row['product_id'] . ':' . $row['variation_id'] ] = [ $row['product_id'], $row['variation_id'] ];
		}
		foreach ( $batch_report['touched'] as $row ) {
			$batch = Batches::get( (int) $row['batch_id'] );
			if ( $batch ) {
				$pairs[ $batch['product_id'] . ':' . $batch['variation_id'] ] = [ (int) $batch['product_id'], (int) $batch['variation_id'] ];
			}
		}

		foreach ( $pairs as [ $pid, $vid ] ) {
			Publisher::publish( $pid, $vid );
		}
		return count( $pairs );
	}
}
