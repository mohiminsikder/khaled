<?php
namespace Counter\Admin\Screens;

use Counter\Pos\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * P7.3 — audit finding T6, the manager's own screen. Every row here already
 * exists in `cntr_sale_queue`, written by `Rest\Sale::process()` itself
 * (a server-rejected sale) or reported here by the terminal's own outbox on
 * its next successful contact with the server (a sale the outbox gave up
 * retrying) — this screen only reads and lets a manager acknowledge, it
 * never re-attempts a sale itself.
 */
class OutboxFailures {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Offline Failures', 'counter' ),
			__( 'Offline Failures', 'counter' ),
			'cntr_manage_registers',
			'counter-outbox-failures',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_registers' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$notice = '';
		if ( isset( $_POST['cntr_outbox_action'] ) ) {
			check_admin_referer( 'cntr_outbox_action' );
			$notice = self::handle_post();
		}

		$rows = Queue::failed_permanent_list();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Offline sale failures', 'counter' ); ?></h1>
			<p><?php esc_html_e( 'A sale a terminal could not complete, even after retrying — a deleted product, a variation that no longer exists, or a server rejection that will never resolve on its own. Re-key the sale at the till, or book it manually another way, then resolve the row below.', 'counter' ); ?></p>
			<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Register', 'counter' ); ?></th><th><?php esc_html_e( 'Shift', 'counter' ); ?></th><th><?php esc_html_e( 'Reason', 'counter' ); ?></th><th><?php esc_html_e( 'Attempts', 'counter' ); ?></th><th><?php esc_html_e( 'Created', 'counter' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo (int) $r['register_id']; ?></td>
							<td><?php echo (int) $r['shift_id']; ?></td>
							<td><?php echo esc_html( $r['error'] ?: __( 'No reason recorded.', 'counter' ) ); ?></td>
							<td><?php echo (int) $r['attempts']; ?></td>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'cntr_outbox_action' ); ?>
									<input type="hidden" name="uuid" value="<?php echo esc_attr( $r['uuid'] ); ?>">
									<input type="text" name="note" placeholder="<?php esc_attr_e( 'How was this resolved?', 'counter' ); ?>" required>
									<button type="submit" name="cntr_outbox_action" value="resolve" class="button button-primary"><?php esc_html_e( 'Resolve', 'counter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Nothing here.', 'counter' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function handle_post(): string {
		$action = sanitize_key( wp_unslash( $_POST['cntr_outbox_action'] ?? '' ) );
		if ( 'resolve' !== $action ) {
			return '';
		}
		$uuid = sanitize_text_field( wp_unslash( $_POST['uuid'] ?? '' ) );
		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		$result = Queue::resolve_failed( $uuid, $note, get_current_user_id() );
		return is_wp_error( $result ) ? $result->get_error_message() : '';
	}
}
