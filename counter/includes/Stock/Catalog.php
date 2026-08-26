<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;
use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * cntr_catalog_index holds one row per sellable entity with a monotonic rev
 * from Db::next_seq('catalog_rev'). The terminal downloads the whole thing
 * once and stays current on a cheap "what changed since my cursor" poll.
 *
 * Payload — sellable fields only (non-negotiable rule 9, audit finding T3):
 * cost, supplier, margin and purchase_price are NEVER in here. IndexedDB is
 * unencrypted and sits on a machine facing a shop floor.
 *
 * Deleted rows keep a tombstone (deleted = 1) rather than disappearing, so a
 * client that missed the delete still finds out on its next poll. Tombstone
 * pruning (>90 days) is a cron job for a later task; this one only writes them.
 */
class Catalog {

	public static function init(): void {
		add_action( 'woocommerce_update_product', [ self::class, 'bump_product' ] );
		add_action( 'woocommerce_new_product', [ self::class, 'bump_product' ] );
		add_action( 'woocommerce_update_product_variation', [ self::class, 'bump_product' ] );
		add_action( 'woocommerce_updated_product_stock', [ self::class, 'bump_product' ] );
		add_action( 'wp_trash_post', [ self::class, 'maybe_tombstone' ] );
		add_action( 'before_delete_post', [ self::class, 'maybe_tombstone' ] );
	}

	public static function bump_product( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::reindex( $product );
	}

	public static function maybe_tombstone( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}
		self::tombstone( $post_id );
	}

	/**
	 * Writes (or refreshes) one row. Called from the product-change hooks above
	 * and from every Publisher::publish() — a stock write is a catalogue change
	 * even if nothing about the product itself changed, because sellable_qty is
	 * part of the payload.
	 */
	public static function reindex( \WC_Product $product ): void {
		global $wpdb;
		$table = Install::table( 'catalog_index' );

		$is_variation = $product->is_type( 'variation' );
		$product_id   = $is_variation ? $product->get_parent_id() : $product->get_id();
		$variation_id = $is_variation ? $product->get_id() : 0;

		$entity = Entity::resolve( $product );

		// D3 — a real manufacturer barcode lives in its own meta field, distinct
		// from the internal SKU (the normal case for an imported grocery
		// catalogue); falling back to SKU means a shop that never sets one is
		// unaffected, and Terminal::quick_add() already writes new barcodes
		// straight into the SKU (Terminal.php:150), so they resolve here too.
		$barcode = (string) $product->get_meta( '_cntr_barcode' );
		if ( '' === $barcode ) {
			$barcode = (string) $product->get_sku();
		}

		$payload = [
			'id'           => $product->get_id(),
			'parent_id'    => $is_variation ? $product->get_parent_id() : 0,
			'sku'          => (string) $product->get_sku(),
			'barcode'      => $barcode,
			'name'         => $product->get_name(),
			'name_bn'      => (string) $product->get_meta( '_cntr_name_bn' ),
			// The shared catalogue every register downloads must never carry
			// the 'online' group's price just because woocommerce_product_
			// get_price() is a global filter — see Pricing\Groups' own
			// docblock on without_online_override().
			'price'        => \Counter\Pricing\Groups::without_online_override( static fn() => (string) $product->get_price() ),
			'tax_class'    => $product->get_tax_class(),
			'units'        => Units::for_product( $product_id, $variation_id ), // P2.12 — [] for a product that only ever sells in its own base unit
			'sellable_qty' => self::sellable_qty_for( $entity ),
			'manage_stock' => $entity['managed'],
			'backorders'   => $entity['backorders'],
			'category_ids' => $product->get_category_ids(),
			'image_thumb'  => (string) ( wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '' ),
		];

		$rev = Db::next_seq( 'catalog_rev' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, variation_id, rev, deleted, sku, barcode, payload_json)
				 VALUES (%d, %d, %d, 0, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE rev = VALUES(rev), deleted = 0, sku = VALUES(sku), barcode = VALUES(barcode), payload_json = VALUES(payload_json)",
				$product_id,
				$variation_id,
				$rev,
				(string) $product->get_sku(),
				$barcode,
				wp_json_encode( $payload )
			)
		);
	}

	public static function tombstone( int $post_id ): void {
		global $wpdb;
		$table = Install::table( 'catalog_index' );

		$is_variation = 'product_variation' === get_post_type( $post_id );
		$product_id   = $is_variation ? (int) wp_get_post_parent_id( $post_id ) : $post_id;
		$variation_id = $is_variation ? $post_id : 0;

		$rev = Db::next_seq( 'catalog_rev' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET deleted = 1, rev = %d WHERE product_id = %d AND variation_id = %d",
				$rev,
				$product_id,
				$variation_id
			)
		);
	}

	/**
	 * changed/removed since $since, capped at $limit rows. If more than
	 * catalog.delta_cap rows changed (a bulk price update, a CSV re-import),
	 * returns resnapshot => true with an empty changed set rather than
	 * streaming thousands of rows mid-trade — the client is expected to pull a
	 * full snapshot instead, via snapshot() below, never by retrying delta()
	 * itself: a $since this stale is, by definition, what just tripped the
	 * cap, and resetting $since to 0 trips the exact same total-count check
	 * again — P8.1's own load-test catalog (14,000 rows, cap 2000) found this
	 * live: a terminal booting cold against a real-shape catalogue could
	 * never finish syncing, forever re-triggering resnapshot with nothing to
	 * show for it. snapshot() shares this same paginated query, just without
	 * the total-count gate — LIMIT already bounds any single response's own
	 * cost, which is the only thing worth guarding here.
	 */
	public static function delta( int $since, int $limit = 500 ): array {
		global $wpdb;
		$table = Install::table( 'catalog_index' );
		$cap   = (int) Settings::get( 'catalog.delta_cap', 2000 );

		$total_changed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE rev > %d", $since ) );

		if ( $total_changed > $cap ) {
			$max_rev = (int) $wpdb->get_var( "SELECT MAX(rev) FROM {$table}" );
			return [
				'cursor'     => $max_rev,
				'changed'    => [],
				'removed'    => [],
				'resnapshot' => true,
			];
		}

		return self::page( $since, $limit ) + [ 'resnapshot' => false ];
	}

	/**
	 * The "full snapshot" delta()'s own docblock promises but pos.js never
	 * actually had a distinct call for — same paginated query as delta(),
	 * with no total-count cap to ever get stuck behind. A client that just
	 * received resnapshot => true from delta() is expected to clear its
	 * local cache and page through THIS instead, starting from $since = 0,
	 * until a response comes back shorter than $limit.
	 */
	public static function snapshot( int $since, int $limit = 500 ): array {
		return self::page( $since, $limit ) + [ 'resnapshot' => false ];
	}

	private static function page( int $since, int $limit ): array {
		global $wpdb;
		$table = Install::table( 'catalog_index' );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rev > %d ORDER BY rev ASC LIMIT %d", $since, $limit ),
			ARRAY_A
		);

		$changed = [];
		$removed = [];
		$cursor  = $since;

		foreach ( $rows as $row ) {
			$cursor = max( $cursor, (int) $row['rev'] );
			if ( (int) $row['deleted'] ) {
				$removed[] = [
					'product_id'   => (int) $row['product_id'],
					'variation_id' => (int) $row['variation_id'],
				];
			} else {
				$decoded = json_decode( (string) $row['payload_json'], true );
				if ( is_array( $decoded ) ) {
					$changed[] = $decoded;
				}
			}
		}

		return [
			'cursor'  => $cursor,
			'changed' => $changed,
			'removed' => $removed,
		];
	}

	/**
	 * A server-side equivalent of the terminal's own IndexedDB lookup
	 * (assets/pos.js never calls this on its hot path — searching stays
	 * client-side so typing never waits on the network). This exists for
	 * anything that needs a lookup server-side instead — P1.18's own
	 * performance self-test times it directly against a seeded 14,000-row
	 * index, since barcode and sku both carry a real KEY in §5.3's schema.
	 */
	public static function lookup_by_code( string $code ): ?array {
		global $wpdb;
		$table = Install::table( 'catalog_index' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload_json FROM {$table} WHERE deleted = 0 AND (barcode = %s OR sku = %s) LIMIT 1",
				$code,
				$code
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$decoded = json_decode( (string) $row['payload_json'], true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Same formula as Publisher::publish(), without the FOR UPDATE lock — this
	 * is a read-only projection for the catalogue payload, not a write-through,
	 * so there is no lost-update risk to guard against here.
	 */
	private static function sellable_qty_for( array $entity ): string {
		if ( ! $entity['managed'] ) {
			return '0';
		}

		global $wpdb;
		[ $product_id, $variation_id ] = Entity::ledger_key( $entity );

		$sellable_ids = Locations::online_sellable_ids();
		$balance      = '0.0000';

		if ( ! empty( $sellable_ids ) ) {
			$stock_table  = Install::table( 'stock' );
			$placeholders = implode( ',', array_fill( 0, count( $sellable_ids ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT qty FROM {$stock_table} WHERE product_id = %d AND variation_id = %d AND location_id IN ({$placeholders})",
					$product_id,
					$variation_id,
					...$sellable_ids
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$balance = bcadd( $balance, (string) $row['qty'], 4 );
			}
		}

		$reserved = Reserve::reserved_for( $entity['stock_id'] );
		$buffer   = (string) Settings::get( 'stock.online_buffer', 0 );

		$sellable = bcsub( bcsub( $balance, $reserved, 4 ), $buffer, 4 );
		return bccomp( $sellable, '0', 4 ) < 0 ? '0' : $sellable;
	}
}
