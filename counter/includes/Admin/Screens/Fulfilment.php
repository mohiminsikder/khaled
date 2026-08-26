<?php
namespace Counter\Admin\Screens;

use Counter\Orders\Channel;
use Counter\Stock\Entity;
use Counter\Stock\Ledger;
use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * P4.2 — pick, pack, print a packing slip, hand to courier. No new schema:
 * the queue IS WooCommerce's own online orders sitting in an unfulfilled
 * status, read fresh on every page load — there is no separate "queue"
 * table of our own to drift out of sync with the orders it lists.
 *
 * "Name the person who clears this queue every day" — §10.1 question 6,
 * the blueprint's own hardest operational finding. Software cannot own
 * this; the answer on record is in docs/decisions.md.
 *
 * "Picking" does not move stock a second time in the normal case — P4.1's
 * own status hooks already applied it the moment the order reached
 * on-hold/processing/completed. pick() calls Channel::apply_stock()
 * anyway, unconditionally: its guard makes that call idempotent, so this
 * screen never has to know or care whether the hook already ran, and an
 * order that somehow reached the queue before its hook fired is still
 * covered.
 */
class Fulfilment {

	const QUEUE_STATUSES = [ 'processing', 'on-hold' ];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Fulfilment', 'counter' ),
			__( 'Fulfilment', 'counter' ),
			'cntr_fulfil_orders',
			'counter-fulfilment',
			[ self::class, 'render' ]
		);
	}

	/**
	 * The queue itself. Ungated (same split as every other Docs/Reports data
	 * method) — ordered oldest first, so the operator naturally works the
	 * queue in the order orders arrived. An order whose current stock
	 * cannot cover it is FLAGGED (insufficient_stock => true), never
	 * omitted — Phase 4's own acceptance line: "confirm it is flagged, not
	 * silently accepted."
	 *
	 * $args: status (one of QUEUE_STATUSES or '' for both), flagged_only
	 * (bool), min_age_hours (float, 0 = no filter).
	 */
	public static function queue( array $args = [] ): array {
		$statuses = '' !== ( $args['status'] ?? '' ) ? [ $args['status'] ] : self::QUEUE_STATUSES;

		$orders = wc_get_orders(
			[
				'status'     => $statuses,
				'limit'      => -1,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[ 'key' => '_cntr_channel', 'value' => 'pos', 'compare' => '!=' ],
				],
			]
		);

		$rows = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$created    = $order->get_date_created();
			$age_hours  = $created ? round( ( time() - $created->getTimestamp() ) / 3600, 1 ) : 0.0;
			if ( ( $args['min_age_hours'] ?? 0 ) > 0 && $age_hours < $args['min_age_hours'] ) {
				continue;
			}

			$location_id  = (int) $order->get_meta( '_cntr_location_id' ) ?: Locations::default_id();
			$insufficient = false;
			$lines        = [];
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$e       = Entity::for_item( $item );
				$managed = (bool) ( $e && $e['managed'] );
				$balance = null;
				if ( $managed ) {
					$balance = Ledger::balance( $e['product_id'], $e['variation_id'], $location_id );
					if ( bccomp( $balance, '0', 4 ) < 0 ) {
						$insufficient = true;
					}
				}
				$lines[] = [
					'name'    => $item->get_name(),
					'qty'     => (string) $item->get_quantity(),
					'total'   => (string) $item->get_total(),
					'managed' => $managed,
					'balance' => $balance,
				];
			}

			if ( ! empty( $args['flagged_only'] ) && ! $insufficient ) {
				continue;
			}

			$rows[] = [
				'order_id'           => $order->get_id(),
				'status'             => $order->get_status(),
				'date_created'       => $created ? $created->date( 'Y-m-d H:i:s' ) : '',
				'age_hours'          => $age_hours,
				'insufficient_stock' => $insufficient,
				'picked'             => '' !== (string) $order->get_meta( '_cntr_picked_at' ),
				'lines'              => $lines,
			];
		}
		return $rows;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function pick( int $order_id ) {
		if ( ! current_user_can( 'cntr_fulfil_orders' ) ) {
			return new \WP_Error( 'cntr_fulfil_forbidden', __( 'You do not have permission to fulfil orders.', 'counter' ), [ 'status' => 403 ] );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'cntr_fulfil_no_order', __( 'Order not found.', 'counter' ), [ 'status' => 404 ] );
		}
		Channel::apply_stock( $order ); // idempotent — see this class's own docblock
		$order->update_meta_data( '_cntr_picked_at', current_time( 'mysql', true ) );
		$order->save();
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function cancel( int $order_id ) {
		if ( ! current_user_can( 'cntr_fulfil_orders' ) ) {
			return new \WP_Error( 'cntr_fulfil_forbidden', __( 'You do not have permission to fulfil orders.', 'counter' ), [ 'status' => 403 ] );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'cntr_fulfil_no_order', __( 'Order not found.', 'counter' ), [ 'status' => 404 ] );
		}
		$order->update_status( 'cancelled' ); // fires the real P4.1 hook -> revert_stock()
		return true;
	}

	/** Every line, including a free (zero-total) one — same idiom as Docs\Receipt::render(). */
	public static function packing_slip_html( \WC_Order $order ): string {
		ob_start();
		include CNTR_DIR . 'templates/packing-slip.php';
		return (string) ob_get_clean();
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_fulfil_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$slip_order_id = isset( $_GET['cntr_packing_slip'] ) ? absint( $_GET['cntr_packing_slip'] ) : 0;
		if ( $slip_order_id > 0 ) {
			$order = wc_get_order( $slip_order_id );
			if ( $order instanceof \WC_Order ) {
				echo self::packing_slip_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- a full print-ready HTML document, not admin page content
				return;
			}
		}

		if ( isset( $_POST['cntr_fulfil_action'], $_POST['order_id'] ) ) {
			check_admin_referer( 'cntr_fulfil_action' );
			$order_id = absint( $_POST['order_id'] );
			$action   = sanitize_key( wp_unslash( $_POST['cntr_fulfil_action'] ) );
			$result   = 'cancel' === $action ? self::cancel( $order_id ) : self::pick( $order_id );
			if ( is_wp_error( $result ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result->get_error_message() ) );
			}
		}

		$status_filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$flagged_only   = ! empty( $_GET['flagged_only'] );
		$min_age_hours  = isset( $_GET['min_age_hours'] ) ? (float) $_GET['min_age_hours'] : 0.0;
		$rows           = self::queue( [ 'status' => $status_filter, 'flagged_only' => $flagged_only, 'min_age_hours' => $min_age_hours ] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fulfilment queue', 'counter' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="counter-fulfilment">
				<select name="status">
					<option value=""><?php esc_html_e( 'Any status', 'counter' ); ?></option>
					<?php foreach ( self::QUEUE_STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( $s ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="flagged_only" value="1" <?php checked( $flagged_only ); ?>> <?php esc_html_e( 'Insufficient stock only', 'counter' ); ?></label>
				<label><?php esc_html_e( 'Older than (hours)', 'counter' ); ?> <input type="number" step="0.1" min="0" name="min_age_hours" value="<?php echo esc_attr( $min_age_hours ?: '' ); ?>"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'counter' ); ?></button>
			</form>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'counter' ); ?></th>
						<th><?php esc_html_e( 'Placed', 'counter' ); ?></th>
						<th><?php esc_html_e( 'Age (hrs)', 'counter' ); ?></th>
						<th><?php esc_html_e( 'Status', 'counter' ); ?></th>
						<th><?php esc_html_e( 'Lines', 'counter' ); ?></th>
						<th><?php esc_html_e( 'Stock', 'counter' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'counter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>#<?php echo (int) $row['order_id']; ?></td>
							<td><?php echo esc_html( $row['date_created'] ); ?></td>
							<td><?php echo esc_html( (string) $row['age_hours'] ); ?></td>
							<td><?php echo esc_html( $row['status'] ); ?><?php echo $row['picked'] ? ' &mdash; ' . esc_html__( 'picked', 'counter' ) : ''; ?></td>
							<td><?php foreach ( $row['lines'] as $l ) : ?><div><?php echo esc_html( $l['qty'] . '× ' . $l['name'] ); ?></div><?php endforeach; ?></td>
							<td><?php echo $row['insufficient_stock'] ? '<strong style="color:#b00">' . esc_html__( 'Insufficient stock', 'counter' ) . '</strong>' : esc_html__( 'OK', 'counter' ); ?></td>
							<td>
								<a class="button" target="_blank" href="<?php echo esc_url( add_query_arg( [ 'page' => 'counter-fulfilment', 'cntr_packing_slip' => $row['order_id'] ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Packing slip', 'counter' ); ?></a>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'cntr_fulfil_action' ); ?>
									<input type="hidden" name="order_id" value="<?php echo (int) $row['order_id']; ?>">
									<button type="submit" name="cntr_fulfil_action" value="pick" class="button button-primary"><?php esc_html_e( 'Pick', 'counter' ); ?></button>
									<button type="submit" name="cntr_fulfil_action" value="cancel" class="button"><?php esc_html_e( 'Cancel', 'counter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Nothing in the queue.', 'counter' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
