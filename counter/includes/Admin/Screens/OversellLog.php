<?php
namespace Counter\Admin\Screens;

use Counter\Stock\Oversell;

defined( 'ABSPATH' ) || exit;

/**
 * P7.5 — the manager's own reconciliation screen. Every row here was
 * written by Orders\Channel::apply_stock() at the moment a sale drained a
 * location below zero while offline; this screen only reads and lets a
 * manager acknowledge it (a restock, a write-off, a corrected count found
 * elsewhere) — it never moves stock itself.
 */
class OversellLog {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Oversell Log', 'counter' ),
			__( 'Oversell Log', 'counter' ),
			'cntr_manage_stock',
			'counter-oversell-log',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_stock' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$notice = '';
		if ( isset( $_POST['cntr_oversell_action'] ) ) {
			check_admin_referer( 'cntr_oversell_action' );
			$notice = self::handle_post();
		}

		$rows = Oversell::open_list();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Oversell log', 'counter' ); ?></h1>
			<p><?php esc_html_e( 'A sale that drained a location below zero — two registers, offline at the same time, both sold the last unit. The sale was still accepted; the goods physically left the shop. Reconcile the shortfall (a restock, a write-off, a corrected count) and resolve the row below.', 'counter' ); ?></p>
			<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Product', 'counter' ); ?></th><th><?php esc_html_e( 'Location', 'counter' ); ?></th><th><?php esc_html_e( 'Register', 'counter' ); ?></th><th><?php esc_html_e( 'Order', 'counter' ); ?></th><th><?php esc_html_e( 'Shortfall', 'counter' ); ?></th><th><?php esc_html_e( 'Balance after', 'counter' ); ?></th><th><?php esc_html_e( 'Created', 'counter' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo (int) $r['product_id']; ?><?php echo $r['variation_id'] ? ' / ' . (int) $r['variation_id'] : ''; ?></td>
							<td><?php echo (int) $r['location_id']; ?></td>
							<td><?php echo (int) $r['register_id']; ?></td>
							<td><?php echo (int) $r['order_id']; ?></td>
							<td><?php echo esc_html( $r['qty_short'] ); ?></td>
							<td><?php echo esc_html( $r['balance_after'] ); ?></td>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'cntr_oversell_action' ); ?>
									<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
									<input type="text" name="note" placeholder="<?php esc_attr_e( 'How was this resolved?', 'counter' ); ?>" required>
									<button type="submit" name="cntr_oversell_action" value="resolve" class="button button-primary"><?php esc_html_e( 'Resolve', 'counter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'Nothing here.', 'counter' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function handle_post(): string {
		$action = sanitize_key( wp_unslash( $_POST['cntr_oversell_action'] ?? '' ) );
		if ( 'resolve' !== $action ) {
			return '';
		}
		$id     = (int) ( $_POST['id'] ?? 0 );
		$note   = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		$result = Oversell::resolve( $id, $note, get_current_user_id() );
		return is_wp_error( $result ) ? $result->get_error_message() : '';
	}
}
