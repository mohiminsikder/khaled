<?php
namespace Counter\Reports;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P5.1 — audit finding G3. Goal: "owner-facing numbers are a lookup, not a
 * scan." `cntr_sales_daily` is keyed (day, channel, location_id) and
 * written INCREMENTALLY, with relative SQL (`col = col + VALUES(col)`,
 * Invariant II's own reasoning), from three places only:
 * `Orders\Channel::apply_stock()` (a sale — gross/cogs/orders_count/
 * items_count go up), `revert_stock()` (a cancellation of an already-
 * applied order — refunds/net/cogs/margin adjust as if the whole order was
 * refunded, since it was never a real WC refund object), and
 * `apply_refund_reversion()` (a real refund — the same adjustment, scoped
 * to just that refund's own amount and lines).
 *
 * The day key is `wp_date( 'Y-m-d', $timestamp )` — shop-local (Asia/Dhaka),
 * NEVER `date()`/`gmdate()` — applied at the moment each event actually
 * happens (Db::now(), the same instant the stock move it accompanies is
 * written), not the order's original creation time: an order that sat
 * `pending` since yesterday and completes payment today is today's sale,
 * not yesterday's. rebuild() (wp counter rebuild-rollup) derives the exact
 * same figures independently, straight from `cntr_stock_moves` and real
 * WooCommerce orders/refunds — never from this table's own prior values.
 */
class Rollup {

	private static array $cols = [ 'orders_count', 'gross', 'discount', 'tax', 'shipping', 'rounding', 'refunds', 'net', 'cogs', 'margin', 'items_count' ];

	public static function init(): void {
		add_action( 'woocommerce_update_order', [ self::class, 'on_order_edited' ], 10, 2 );
	}

	/** Shop-local ('Y-m-d') calendar date for a UTC MySQL datetime string, e.g. Db::now()'s own output. */
	public static function day_for( string $utc_mysql_datetime ): string {
		return wp_date( 'Y-m-d', strtotime( $utc_mysql_datetime . ' UTC' ) );
	}

	/** D1 — shop-local hour (0-23) for a UTC MySQL datetime string, the same conversion as day_for(). */
	public static function hour_for( string $utc_mysql_datetime ): int {
		return (int) wp_date( 'G', strtotime( $utc_mysql_datetime . ' UTC' ) );
	}

	/**
	 * The UTC instant a shop-local calendar day begins/ends — the exact
	 * inverse of day_for(), needed anywhere a shop-local 'Y-m-d' boundary
	 * has to be compared against a UTC-stored column (rebuild()'s own
	 * date-range filters). Uses the site's real configured timezone (DateTime
	 * conversion), not a hardcoded offset — correct even if the site's own
	 * timezone setting is ever changed away from Asia/Dhaka. Public — P5.3's
	 * Dashboard needs the same conversion for its own closed_at (UTC) window
	 * over "last night", and duplicating the DateTime logic in a second file
	 * is how the two ever quietly drift apart.
	 */
	public static function local_day_start_utc( string $local_ymd ): string {
		$dt = new \DateTime( $local_ymd . ' 00:00:00', wp_timezone() );
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	public static function local_day_end_utc( string $local_ymd ): string {
		$dt = new \DateTime( $local_ymd . ' 23:59:59', wp_timezone() );
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Called from inside Channel::apply_stock()'s own transaction, at the
	 * exact moment a sale's stock moves are written — $cogs is that same
	 * call's own $total_cogs, never recomputed a second way.
	 */
	public static function record_sale( \WC_Order $order, string $channel, int $location_id, string $cogs ): void {
		$now   = Db::now();
		$day   = self::day_for( $now );
		$hour  = self::hour_for( $now );
		$gross = wc_format_decimal( $order->get_total(), 4 );

		$items_count = '0.0000';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items_count = bcadd( $items_count, (string) abs( (float) $item->get_quantity() ), 4 );
		}

		$deltas = [
			'orders_count' => 1,
			'gross'        => $gross,
			'discount'     => wc_format_decimal( $order->get_total_discount(), 4 ),
			'tax'          => wc_format_decimal( $order->get_total_tax(), 4 ),
			'shipping'     => wc_format_decimal( $order->get_shipping_total(), 4 ),
			'rounding'     => wc_format_decimal( (string) ( $order->get_meta( '_cntr_rounding' ) ?: '0' ), 4 ),
			'net'          => $gross,
			'cogs'         => $cogs,
			'margin'       => bcsub( $gross, $cogs, 4 ),
			'items_count'  => $items_count,
		];
		self::increment( $day, $channel, $location_id, $deltas );
		self::increment( $day, $channel, $location_id, $deltas, $hour );

		// The one durable record of "this order's sale already landed in the
		// rollup, on this day/channel/location, for this much" — what both
		// on_order_edited() (a later total change) and a future refund need
		// to adjust the right row by the right delta, without re-deriving
		// any of it from the order object at that later, possibly different,
		// point in time.
		$order->update_meta_data( '_cntr_rollup_day', $day );
		$order->update_meta_data( '_cntr_rollup_hour', $hour );
		$order->update_meta_data( '_cntr_rollup_channel', $channel );
		$order->update_meta_data( '_cntr_rollup_location', $location_id );
		$order->update_meta_data( '_cntr_rollup_gross', $gross );
	}

	/**
	 * Called from revert_stock() (a whole-order cancellation with no real
	 * WC refund object — $amount is the order's own total) and from
	 * apply_refund_reversion() ($amount is that specific refund's own
	 * amount). $cogs_delta is the POSITIVE cost restore_to_batches() just
	 * credited back — subtracted here, never added twice.
	 */
	public static function record_reversal( string $channel, int $location_id, string $amount, string $cogs_delta ): void {
		$now  = Db::now();
		$day  = self::day_for( $now );
		$hour = self::hour_for( $now );
		$deltas = [
			'refunds' => $amount,
			'net'     => bcmul( $amount, '-1', 4 ),
			'cogs'    => bcmul( $cogs_delta, '-1', 4 ),
			'margin'  => bcmul( bcsub( $amount, $cogs_delta, 4 ), '-1', 4 ),
		];
		self::increment( $day, $channel, $location_id, $deltas );
		self::increment( $day, $channel, $location_id, $deltas, $hour );
	}

	/**
	 * "An order edited after the fact updates the rollup" — a total change
	 * on an order that already has a rollup row (tagged by record_sale())
	 * adjusts THAT row by the delta, on the day it was originally recorded,
	 * not today.
	 *
	 * `woocommerce_order_object_updated_props` (the analogue LabelDesigner
	 * uses for products) is a LEGACY-CPT-data-store-only hook — found live,
	 * it never fires at all under HPOS (confirmed active on this site).
	 * `woocommerce_update_order` is what HPOS's own OrdersTableDataStore
	 * actually fires, on every save — no prop-diff list, just
	 * `($order_id, $order)`. There is no need for one anyway: comparing
	 * the order's current total against this order's own last-recorded
	 * `_cntr_rollup_gross` (below) is a cheap, correct no-op on every save
	 * that didn't change the total, including the very save() that
	 * record_sale() itself participates in.
	 */
	public static function on_order_edited( int $order_id, \WC_Order $order ): void {
		$day = (string) $order->get_meta( '_cntr_rollup_day' );
		if ( '' === $day ) {
			return; // this order's sale was never recorded — nothing to adjust
		}
		$channel     = (string) $order->get_meta( '_cntr_rollup_channel' );
		$location_id = (int) $order->get_meta( '_cntr_rollup_location' );
		$old_gross   = (string) $order->get_meta( '_cntr_rollup_gross' );
		$new_gross   = wc_format_decimal( $order->get_total(), 4 );
		$delta       = bcsub( $new_gross, $old_gross, 4 );
		if ( 0 === bccomp( $delta, '0', 4 ) ) {
			return;
		}
		// D1 — the hour meta only exists on orders recorded after this task
		// shipped; '' on an older order means "adjust the daily row only",
		// same as before this task ever existed, rather than guessing an hour.
		$hour_meta = $order->get_meta( '_cntr_rollup_hour' );
		$deltas    = [ 'gross' => $delta, 'net' => $delta, 'margin' => $delta ];
		self::increment( $day, $channel, $location_id, $deltas );
		if ( '' !== $hour_meta ) {
			self::increment( $day, $channel, $location_id, $deltas, (int) $hour_meta );
		}
		$order->update_meta_data( '_cntr_rollup_gross', $new_gross );
		$order->save_meta_data();
	}

	/**
	 * D1 — $hour null targets cntr_sales_daily (day,channel,location_id); a
	 * given $hour targets the cntr_sales_hourly sibling (day,hour,channel,
	 * location_id) instead. Every call site writes both — the daily table's
	 * own key and every existing query over it are unchanged either way.
	 */
	private static function increment( string $day, string $channel, int $location_id, array $deltas, ?int $hour = null ): void {
		global $wpdb;
		$table            = Install::table( null === $hour ? 'sales_daily' : 'sales_hourly' );
		$key_cols         = null === $hour ? [ 'day', 'channel', 'location_id' ] : [ 'day', 'hour', 'channel', 'location_id' ];
		$key_vals         = null === $hour ? [ $day, $channel, $location_id ] : [ $day, $hour, $channel, $location_id ];
		$key_placeholders = null === $hour ? [ '%s', '%s', '%d' ] : [ '%s', '%d', '%s', '%d' ];

		$values = [];
		$sets   = [];
		foreach ( self::$cols as $c ) {
			$values[] = $deltas[ $c ] ?? ( 'orders_count' === $c ? 0 : '0.0000' );
			$sets[]   = "{$c} = {$c} + VALUES({$c})";
		}
		$placeholders = array_map( static fn( $c ) => 'orders_count' === $c ? '%d' : '%s', self::$cols );
		$args         = array_merge( $key_vals, $values, [ Db::now() ] );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (" . implode( ',', $key_cols ) . ', ' . implode( ',', self::$cols ) . ', updated_at)
				 VALUES (' . implode( ',', $key_placeholders ) . ', ' . implode( ',', $placeholders ) . ', %s)
				 ON DUPLICATE KEY UPDATE ' . implode( ', ', $sets ) . ', updated_at = VALUES(updated_at)',
				...$args
			)
		);
	}

	/**
	 * D1 — one shop-local calendar day's 24 hours, always all 24: an hour
	 * with no activity is a real zero, not an absent key, since
	 * cntr_sales_hourly (like cntr_sales_daily before it) only ever holds a
	 * row for an hour something actually happened in. $channel/$location_id
	 * empty/0 means "every channel"/"every location", summed per hour.
	 *
	 * @return array<int,array> keyed 0-23
	 */
	public static function hourly_for( string $day, string $channel = '', int $location_id = 0 ): array {
		global $wpdb;
		$table = Install::table( 'sales_hourly' );

		$where  = 'WHERE day = %s';
		$params = [ $day ];
		if ( '' !== $channel ) {
			$where   .= ' AND channel = %s';
			$params[] = $channel;
		}
		if ( $location_id ) {
			$where   .= ' AND location_id = %d';
			$params[] = $location_id;
		}

		$sums = implode( ', ', array_map( static fn( $c ) => "SUM({$c}) AS {$c}", self::$cols ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT hour, {$sums} FROM {$table} {$where} GROUP BY hour", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where/$sums built from fixed strings, no user input
			ARRAY_A
		);

		$by_hour = [];
		foreach ( $rows as $r ) {
			$by_hour[ (int) $r['hour'] ] = $r;
		}

		$out = [];
		for ( $h = 0; $h < 24; $h++ ) {
			$row = $by_hour[ $h ] ?? array_fill_keys( self::$cols, '0.0000' );
			$row['hour'] = $h;
			if ( isset( $row['orders_count'] ) ) {
				$row['orders_count'] = (int) $row['orders_count'];
			}
			$out[ $h ] = $row;
		}
		return $out;
	}

	/**
	 * Recomputes cntr_sales_daily entirely from orders + cntr_stock_moves —
	 * never from this table's own existing rows. $from/$to are 'Y-m-d'
	 * shop-local dates (both inclusive), or null for the whole table.
	 * Always a dry run unless $write.
	 *
	 * @return array{rows:array,count:int}
	 */
	public static function rebuild( ?string $from = null, ?string $to = null, bool $write = false ): array {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );

		// $from/$to are shop-local 'Y-m-d' dates (day_for()'s own domain) —
		// stock_moves.created_at is stored UTC (Db::now()). Found before
		// this ever deployed: treating the boundary strings as literal UTC
		// timestamps is wrong by the site's own UTC offset (+6 for Dhaka,
		// no DST) — a shop-local day starts/ends up to 6 hours before its
		// own calendar-date-looking UTC boundary would suggest.
		$from_utc = null !== $from ? self::local_day_start_utc( $from ) : null;
		$to_utc   = null !== $to ? self::local_day_end_utc( $to ) : null;

		$where  = "WHERE reason IN ('sale','sale_online','return')";
		$params = [];
		if ( null !== $from_utc ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $from_utc;
		}
		if ( null !== $to_utc ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $to_utc;
		}

		$moves = empty( $params )
			? $wpdb->get_results( "SELECT ref_id, ref_type, reason, qty_delta, unit_cost, channel, created_at FROM {$moves_table} {$where} AND ref_type = 'order' ORDER BY id", ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT ref_id, ref_type, reason, qty_delta, unit_cost, channel, created_at FROM {$moves_table} {$where} AND ref_type = 'order' ORDER BY id", ...$params ), ARRAY_A );

		// Pass 1 — COGS, direct from the ledger, no order lookups needed:
		// SUM(|qty_delta| x unit_cost), sale moves positive, return moves negative.
		//
		// D1 — accumulated at the FINER day|hour|channel|location grain
		// throughout; the daily table's own rows are DERIVED by summing
		// these afterward (below), rather than kept as a second, separately
		// -accumulated total — "hourly sums equal the daily row" is then
		// true by construction, not by two independent computations
		// happening to agree.
		$hourly = []; // "day|hour|channel|location" => accumulator array
		$order_sale_orders = []; // order_id => [ 'day' => ..., 'hour' => ..., 'channel' => ..., 'location' => ... ] — first sale move seen per order

		foreach ( $moves as $m ) {
			$order_id = (int) $m['ref_id'];
			$channel  = (string) ( $m['channel'] ?: 'pos' );
			$order    = wc_get_order( $order_id );
			$location_id = $order ? (int) ( $order->get_meta( '_cntr_location_id' ) ?: \Counter\Stock\Locations::default_id() ) : \Counter\Stock\Locations::default_id();
			$day      = self::day_for( (string) $m['created_at'] );
			$hour     = self::hour_for( (string) $m['created_at'] );
			$key      = $day . '|' . $hour . '|' . $channel . '|' . $location_id;

			if ( ! isset( $hourly[ $key ] ) ) {
				$hourly[ $key ] = [ 'day' => $day, 'hour' => $hour, 'channel' => $channel, 'location_id' => $location_id, 'cogs' => '0.0000' ];
			}
			$cost = bcmul( (string) abs( (float) $m['qty_delta'] ), (string) $m['unit_cost'], 4 );
			$hourly[ $key ]['cogs'] = 'return' === $m['reason']
				? bcsub( $hourly[ $key ]['cogs'], $cost, 4 )
				: bcadd( $hourly[ $key ]['cogs'], $cost, 4 );

			if ( in_array( $m['reason'], [ 'sale', 'sale_online' ], true ) && ! isset( $order_sale_orders[ $order_id ] ) ) {
				$order_sale_orders[ $order_id ] = [ 'day' => $day, 'hour' => $hour, 'channel' => $channel, 'location_id' => $location_id ];
			}
		}

		// Pass 2 — gross/discount/tax/shipping/rounding/items_count/orders_count,
		// one order at a time, attributed to the hour its FIRST sale move landed.
		foreach ( $order_sale_orders as $order_id => $attrib ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$key = $attrib['day'] . '|' . $attrib['hour'] . '|' . $attrib['channel'] . '|' . $attrib['location_id'];
			if ( ! isset( $hourly[ $key ] ) ) {
				$hourly[ $key ] = [ 'day' => $attrib['day'], 'hour' => $attrib['hour'], 'channel' => $attrib['channel'], 'location_id' => $attrib['location_id'], 'cogs' => '0.0000' ];
			}
			$gross = wc_format_decimal( $order->get_total(), 4 );
			$hourly[ $key ]['orders_count'] = ( $hourly[ $key ]['orders_count'] ?? 0 ) + 1;
			$hourly[ $key ]['gross']        = bcadd( $hourly[ $key ]['gross'] ?? '0.0000', $gross, 4 );
			$hourly[ $key ]['discount']     = bcadd( $hourly[ $key ]['discount'] ?? '0.0000', wc_format_decimal( $order->get_total_discount(), 4 ), 4 );
			$hourly[ $key ]['tax']          = bcadd( $hourly[ $key ]['tax'] ?? '0.0000', wc_format_decimal( $order->get_total_tax(), 4 ), 4 );
			$hourly[ $key ]['shipping']     = bcadd( $hourly[ $key ]['shipping'] ?? '0.0000', wc_format_decimal( $order->get_shipping_total(), 4 ), 4 );
			$hourly[ $key ]['rounding']     = bcadd( $hourly[ $key ]['rounding'] ?? '0.0000', wc_format_decimal( (string) ( $order->get_meta( '_cntr_rounding' ) ?: '0' ), 4 ), 4 );
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$hourly[ $key ]['items_count'] = bcadd( $hourly[ $key ]['items_count'] ?? '0.0000', (string) abs( (float) $item->get_quantity() ), 4 );
			}
		}

		// Pass 3 — refunds, attributed to the hour EACH refund object was
		// itself created (not the original sale's hour — a refund is its own
		// financial event).
		$refund_where  = '';
		$refund_params = [];
		if ( null !== $from_utc ) {
			$refund_where   .= ' AND date_created_gmt >= %s';
			$refund_params[] = $from_utc;
		}
		if ( null !== $to_utc ) {
			$refund_where   .= ' AND date_created_gmt <= %s';
			$refund_params[] = $to_utc;
		}
		$orders_table  = $wpdb->prefix . 'wc_orders';
		$refund_ids    = empty( $refund_params )
			? $wpdb->get_col( "SELECT id FROM {$orders_table} WHERE type = 'shop_order_refund'" . $refund_where )
			: $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$orders_table} WHERE type = 'shop_order_refund'" . $refund_where, ...$refund_params ) );

		foreach ( $refund_ids as $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}
			$parent = wc_get_order( $refund->get_parent_id() );
			if ( ! $parent ) {
				continue;
			}
			$channel     = (string) ( $parent->get_meta( '_cntr_channel' ) ?: 'online' );
			$location_id = (int) ( $parent->get_meta( '_cntr_location_id' ) ?: \Counter\Stock\Locations::default_id() );
			$created_str = $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d H:i:s' ) : Db::now();
			$day         = self::day_for( $created_str );
			$hour        = self::hour_for( $created_str );
			$key         = $day . '|' . $hour . '|' . $channel . '|' . $location_id;
			if ( ! isset( $hourly[ $key ] ) ) {
				$hourly[ $key ] = [ 'day' => $day, 'hour' => $hour, 'channel' => $channel, 'location_id' => $location_id, 'cogs' => '0.0000' ];
			}
			$amount = wc_format_decimal( abs( (float) $refund->get_amount() ), 4 );
			$hourly[ $key ]['refunds'] = bcadd( $hourly[ $key ]['refunds'] ?? '0.0000', $amount, 4 );
		}

		// Fill in net/margin and every un-set column on the hourly grain.
		foreach ( $hourly as $key => &$r ) {
			foreach ( self::$cols as $c ) {
				if ( ! isset( $r[ $c ] ) ) {
					$r[ $c ] = 'orders_count' === $c ? 0 : '0.0000';
				}
			}
			$r['net']    = bcsub( $r['gross'], $r['refunds'], 4 );
			$r['margin'] = bcsub( $r['net'], $r['cogs'], 4 );
		}
		unset( $r );

		// Derive the daily grain by summing every hour of the same
		// day|channel|location — never accumulated separately.
		$daily = [];
		foreach ( $hourly as $r ) {
			$day_key = $r['day'] . '|' . $r['channel'] . '|' . $r['location_id'];
			if ( ! isset( $daily[ $day_key ] ) ) {
				$daily[ $day_key ] = [ 'day' => $r['day'], 'channel' => $r['channel'], 'location_id' => $r['location_id'] ];
				foreach ( self::$cols as $c ) {
					$daily[ $day_key ][ $c ] = 'orders_count' === $c ? 0 : '0.0000';
				}
			}
			foreach ( self::$cols as $c ) {
				$daily[ $day_key ][ $c ] = 'orders_count' === $c
					? $daily[ $day_key ][ $c ] + $r[ $c ]
					: bcadd( $daily[ $day_key ][ $c ], $r[ $c ], 4 );
			}
		}

		if ( $write ) {
			$daily_table  = Install::table( 'sales_daily' );
			$hourly_table = Install::table( 'sales_hourly' );
			if ( null !== $from || null !== $to ) {
				$del_where  = 'WHERE 1=1';
				$del_params = [];
				if ( null !== $from ) {
					$del_where   .= ' AND day >= %s';
					$del_params[] = $from;
				}
				if ( null !== $to ) {
					$del_where   .= ' AND day <= %s';
					$del_params[] = $to;
				}
				$wpdb->query( empty( $del_params ) ? "DELETE FROM {$daily_table} {$del_where}" : $wpdb->prepare( "DELETE FROM {$daily_table} {$del_where}", ...$del_params ) );
				$wpdb->query( empty( $del_params ) ? "DELETE FROM {$hourly_table} {$del_where}" : $wpdb->prepare( "DELETE FROM {$hourly_table} {$del_where}", ...$del_params ) );
			} else {
				$wpdb->query( "TRUNCATE TABLE {$daily_table}" );
				$wpdb->query( "TRUNCATE TABLE {$hourly_table}" );
			}
			foreach ( $daily as $r ) {
				$wpdb->insert(
					$daily_table,
					[
						'day'          => $r['day'],
						'channel'      => $r['channel'],
						'location_id'  => $r['location_id'],
						'orders_count' => $r['orders_count'],
						'gross'        => $r['gross'],
						'discount'     => $r['discount'],
						'tax'          => $r['tax'],
						'shipping'     => $r['shipping'],
						'rounding'     => $r['rounding'],
						'refunds'      => $r['refunds'],
						'net'          => $r['net'],
						'cogs'         => $r['cogs'],
						'margin'       => $r['margin'],
						'items_count'  => $r['items_count'],
						'updated_at'   => Db::now(),
					]
				);
			}
			foreach ( $hourly as $r ) {
				$wpdb->insert(
					$hourly_table,
					[
						'day'          => $r['day'],
						'hour'         => $r['hour'],
						'channel'      => $r['channel'],
						'location_id'  => $r['location_id'],
						'orders_count' => $r['orders_count'],
						'gross'        => $r['gross'],
						'discount'     => $r['discount'],
						'tax'          => $r['tax'],
						'shipping'     => $r['shipping'],
						'rounding'     => $r['rounding'],
						'refunds'      => $r['refunds'],
						'net'          => $r['net'],
						'cogs'         => $r['cogs'],
						'margin'       => $r['margin'],
						'items_count'  => $r['items_count'],
						'updated_at'   => Db::now(),
					]
				);
			}
		}

		return [ 'rows' => array_values( $daily ), 'hourly_rows' => array_values( $hourly ), 'count' => count( $daily ) ];
	}
}
