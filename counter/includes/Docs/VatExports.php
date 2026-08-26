<?php
namespace Counter\Docs;

use Counter\Install;
use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * P3.6 — Direction's own scope line: "6.1, 6.2.1, 6.5 and 6.6 as spreadsheets
 * shaped to feed the VAT consultant or an NBR-approved package. Integrate
 * into that ecosystem; do not try to replace it." So these are NOT literal
 * redraws of the official NBR forms (the same honesty Challan.php already
 * applies to Mushak 6.3's own printed layout) — they are CSV extracts of
 * data Counter already has, columned so a consultant or a filing package can
 * consume them directly.
 *
 * - 6.1  Purchase-Sales (stock) Account — one row per item touched in the
 *        period: opening + receipts - issues = closing, by construction,
 *        because closing is read straight off cntr_stock_moves' own running
 *        balance_after rather than recomputed by this class. receipts/issues
 *        are ALL positive/negative deltas in the window, any reason — not
 *        just 'purchase'/'sale' — so the identity can never drift out from
 *        under an adjustment, transfer, or stocktake landing mid-period.
 * - 6.2.1 Purchase Register — one row per purchase order dated in the
 *        period, its own subtotal/tax_total/grand_total as recorded at
 *        ordering time (Purchasing\Orders::create()).
 * - 6.5  Account Current — the period's output VAT (from the challan
 *        register) against its input VAT (from the purchase register), and
 *        the net payable between them.
 * - 6.6  Return summary — the same two totals restated as a single filing
 *        row: this is Direction's "6.3 export" reconciliation point — 6.6's
 *        sales-side total_sales_value/output_vat figures ARE Challan::
 *        register()'s own totals for the period, restated, never
 *        recomputed a second, potentially-drifting way.
 *
 * Every date bound follows Challan::register()'s own convention exactly
 * ('Y-m-d 00:00:00' to 'Y-m-d 23:59:59', inclusive both ends) — so a 6.6
 * total and a Challan::register() total for the same $from/$to can never
 * silently disagree at a period boundary.
 */
class VatExports {

	/**
	 * The raw computation layer — ungated, same split as Reports::
	 * valuation()/movement_history() vs. Reports::export_csv(): a report
	 * screen or another check can read this directly without needing
	 * cntr_export, only the CSV download itself is capability-gated.
	 */
	public static function data_6_1( string $from, string $to, int $location_id = 0 ): array {
		global $wpdb;
		$table = Install::table( 'stock_moves' );
		[ $from_dt, $to_dt ] = self::bounds( $from, $to );

		$where  = 'WHERE created_at BETWEEN %s AND %s';
		$params = [ $from_dt, $to_dt ];
		if ( $location_id > 0 ) {
			$where   .= ' AND location_id = %d';
			$params[] = $location_id;
		}
		$combos = $wpdb->get_results(
			$wpdb->prepare( "SELECT DISTINCT product_id, variation_id, location_id FROM {$table} {$where} ORDER BY product_id, variation_id, location_id", ...$params ),
			ARRAY_A
		);

		$rows = [];
		foreach ( $combos as $combo ) {
			$product_id   = (int) $combo['product_id'];
			$variation_id = (int) $combo['variation_id'];
			$loc_id       = (int) $combo['location_id'];

			$opening_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT balance_after FROM {$table} WHERE product_id = %d AND variation_id = %d AND location_id = %d AND created_at < %s ORDER BY id DESC LIMIT 1",
					$product_id,
					$variation_id,
					$loc_id,
					$from_dt
				),
				ARRAY_A
			);
			$opening = $opening_row ? $opening_row['balance_after'] : '0.0000';

			$period_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT qty_delta, balance_after FROM {$table} WHERE product_id = %d AND variation_id = %d AND location_id = %d AND created_at BETWEEN %s AND %s ORDER BY id",
					$product_id,
					$variation_id,
					$loc_id,
					$from_dt,
					$to_dt
				),
				ARRAY_A
			);

			$receipts = '0.0000';
			$issues   = '0.0000';
			$closing  = $opening;
			foreach ( $period_rows as $pr ) {
				if ( bccomp( $pr['qty_delta'], '0', 4 ) > 0 ) {
					$receipts = bcadd( $receipts, $pr['qty_delta'], 4 );
				} elseif ( bccomp( $pr['qty_delta'], '0', 4 ) < 0 ) {
					$issues = bcadd( $issues, bcmul( $pr['qty_delta'], '-1', 4 ), 4 );
				}
				$closing = $pr['balance_after'];
			}

			$product  = wc_get_product( $variation_id ?: $product_id );
			$location = Locations::get( $loc_id );

			$rows[] = [
				// product_id is not part of export_6_1()'s CSV header — to_csv()
				// plucks by header key, so an internal key like this is safe to
				// carry for a caller (the self-test) that needs to identify ITS
				// OWN row precisely, rather than assume rows are exactly one:
				// cntr_stock_moves is append-only, so a fixture window reused
				// across repeated self-test runs will keep accumulating one dead
				// row per historical run's now-deleted product — expected, not
				// a bug, same as every other ledger fixture in this project.
				'product_id'   => $product_id,
				'sku'          => $product ? (string) $product->get_sku() : '',
				'product_name' => $product ? $product->get_name() : ( 'Product #' . $product_id ),
				'location'     => $location['name'] ?? '',
				'opening_qty'  => $opening,
				'receipts_qty' => $receipts,
				'issues_qty'   => $issues,
				'closing_qty'  => $closing,
			];
		}
		return $rows;
	}

	public static function data_6_2_1( string $from, string $to ): array {
		global $wpdb;
		$po_table  = Install::table( 'purchase_orders' );
		$sup_table = Install::table( 'suppliers' );
		[ $from_dt, $to_dt ] = self::bounds( $from, $to );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT po.po_no, po.created_at, po.subtotal, po.tax_total, po.grand_total, s.name AS supplier_name, s.bin AS supplier_bin
				 FROM {$po_table} po LEFT JOIN {$sup_table} s ON s.id = po.supplier_id
				 WHERE po.created_at BETWEEN %s AND %s ORDER BY po.created_at, po.id",
				$from_dt,
				$to_dt
			),
			ARRAY_A
		);

		return array_map(
			static function ( $r ) {
				return [
					'date'          => substr( (string) $r['created_at'], 0, 10 ),
					'po_no'         => $r['po_no'],
					'supplier_name' => (string) ( $r['supplier_name'] ?? '' ),
					'supplier_bin'  => (string) ( $r['supplier_bin'] ?? '' ),
					'taxable_value' => $r['subtotal'],
					'vat_amount'    => $r['tax_total'],
					'total'         => $r['grand_total'],
				];
			},
			$rows
		);
	}

	public static function data_6_5( string $from, string $to ): array {
		$totals = self::period_totals( $from, $to );
		return [
			[ 'line' => __( 'Output VAT (sales)', 'counter' ), 'amount' => $totals['output_vat'] ],
			[ 'line' => __( 'Input VAT (purchases)', 'counter' ), 'amount' => $totals['input_vat'] ],
			[ 'line' => __( 'Net payable', 'counter' ), 'amount' => $totals['net_payable'] ],
		];
	}

	public static function data_6_6( string $from, string $to ): array {
		$totals = self::period_totals( $from, $to );
		return [
			[
				'period_from'          => $from,
				'period_to'            => $to,
				'total_sales_value'    => $totals['total_sales_value'],
				'output_vat'           => $totals['output_vat'],
				'total_purchase_value' => $totals['total_purchase_value'],
				'input_vat'            => $totals['input_vat'],
				'net_payable'          => $totals['net_payable'],
			],
		];
	}

	/**
	 * @return string|\WP_Error CSV text, or a WP_Error if the current user lacks cntr_export.
	 */
	public static function export_6_1( string $from, string $to, int $location_id = 0 ) {
		$forbidden = self::forbid();
		if ( $forbidden ) {
			return $forbidden;
		}
		return self::to_csv(
			[ 'sku', 'product_name', 'location', 'opening_qty', 'receipts_qty', 'issues_qty', 'closing_qty' ],
			self::data_6_1( $from, $to, $location_id )
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function export_6_2_1( string $from, string $to ) {
		$forbidden = self::forbid();
		if ( $forbidden ) {
			return $forbidden;
		}
		return self::to_csv(
			[ 'date', 'po_no', 'supplier_name', 'supplier_bin', 'taxable_value', 'vat_amount', 'total' ],
			self::data_6_2_1( $from, $to )
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function export_6_5( string $from, string $to ) {
		$forbidden = self::forbid();
		if ( $forbidden ) {
			return $forbidden;
		}
		return self::to_csv( [ 'line', 'amount' ], self::data_6_5( $from, $to ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function export_6_6( string $from, string $to ) {
		$forbidden = self::forbid();
		if ( $forbidden ) {
			return $forbidden;
		}
		return self::to_csv(
			[ 'period_from', 'period_to', 'total_sales_value', 'output_vat', 'total_purchase_value', 'input_vat', 'net_payable' ],
			self::data_6_6( $from, $to )
		);
	}

	/** Output VAT/sales come straight from Challan::register() — never recomputed a second way. */
	private static function period_totals( string $from, string $to ): array {
		$challan_rows       = Challan::register( $from, $to );
		$total_sales_value  = '0.0000';
		$output_vat         = '0.0000';
		foreach ( $challan_rows as $r ) {
			$total_sales_value = bcadd( $total_sales_value, $r['taxable_value'], 4 );
			$output_vat         = bcadd( $output_vat, $r['vat_amount'], 4 );
		}

		$purchase_rows        = self::data_6_2_1( $from, $to );
		$total_purchase_value = '0.0000';
		$input_vat             = '0.0000';
		foreach ( $purchase_rows as $r ) {
			$total_purchase_value = bcadd( $total_purchase_value, $r['taxable_value'], 4 );
			$input_vat              = bcadd( $input_vat, $r['vat_amount'], 4 );
		}

		return [
			'total_sales_value'    => $total_sales_value,
			'output_vat'           => $output_vat,
			'total_purchase_value' => $total_purchase_value,
			'input_vat'            => $input_vat,
			'net_payable'          => bcsub( $output_vat, $input_vat, 4 ),
		];
	}

	private static function forbid(): ?\WP_Error {
		if ( ! current_user_can( 'cntr_export' ) ) {
			return new \WP_Error( 'cntr_export_forbidden', __( 'You do not have permission to export.', 'counter' ), [ 'status' => 403 ] );
		}
		return null;
	}

	/** Same convention as Challan::register(): 'Y-m-d' shop-local dates, inclusive both ends. */
	private static function bounds( string $from, string $to ): array {
		$from_dt = '' !== $from ? $from . ' 00:00:00' : '0000-01-01 00:00:00';
		$to_dt   = '' !== $to ? $to . ' 23:59:59' : '9999-12-31 23:59:59';
		return [ $from_dt, $to_dt ];
	}

	/**
	 * The header row is ALWAYS written, even with zero data rows — an empty
	 * period is not a failure. Values are plucked by header key, in header
	 * order — a data_*() row is free to carry extra internal keys (data_6_1()
	 * carries product_id for callers that need to identify a specific row)
	 * without ever shifting a CSV column out of alignment with its header.
	 */
	private static function to_csv( array $header, array $rows ): string {
		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, $header );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( static fn( $col ) => $row[ $col ] ?? '', $header ) );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}
}
