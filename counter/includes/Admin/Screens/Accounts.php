<?php
namespace Counter\Admin\Screens;

use Counter\Pos\Accounts as AccountsClass;

defined( 'ABSPATH' ) || exit;

/**
 * P4.6 — payment accounts, the gateway map, and the reconciliation report.
 * A plain wp-admin form over Pos\Accounts' own validated create()/delete()/
 * set_gateway_map(), same "the screen cannot drift from what actually
 * validates" principle as every other Admin\Screens class in this plugin.
 */
class Accounts {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Payment Accounts', 'counter' ),
			__( 'Payment Accounts', 'counter' ),
			'cntr_manage_settings',
			'counter-accounts',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$notice = '';
		if ( isset( $_POST['cntr_accounts_action'] ) ) {
			check_admin_referer( 'cntr_accounts_action' );
			$notice = self::handle_post();
		}

		$accounts = AccountsClass::all( '' );
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];
		$map      = AccountsClass::gateway_map();

		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' );
		$reconciliation = AccountsClass::reconciliation( $from, $to );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payment accounts', 'counter' ); ?></h1>
			<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<h2><?php esc_html_e( 'Accounts', 'counter' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'counter' ); ?></th><th><?php esc_html_e( 'Code', 'counter' ); ?></th><th><?php esc_html_e( 'Kind', 'counter' ); ?></th><th><?php esc_html_e( 'Status', 'counter' ); ?></th><th><?php esc_html_e( 'Actions', 'counter' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $accounts as $a ) : ?>
						<tr>
							<td><?php echo esc_html( $a['name'] ); ?></td>
							<td><?php echo esc_html( $a['code'] ); ?></td>
							<td><?php echo esc_html( $a['kind'] ); ?></td>
							<td><?php echo esc_html( $a['status'] ); ?></td>
							<td>
								<?php if ( 'active' === $a['status'] ) : ?>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( 'cntr_accounts_action' ); ?>
										<input type="hidden" name="account_id" value="<?php echo (int) $a['id']; ?>">
										<button type="submit" name="cntr_accounts_action" value="deactivate" class="button"><?php esc_html_e( 'Deactivate', 'counter' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Add an account', 'counter' ); ?></h3>
			<form method="post">
				<?php wp_nonce_field( 'cntr_accounts_action' ); ?>
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Name', 'counter' ); ?>" required>
				<input type="text" name="code" placeholder="<?php esc_attr_e( 'Code', 'counter' ); ?>" required>
				<input type="text" name="kind" placeholder="<?php esc_attr_e( 'Kind (cash/mfs/card/bank)', 'counter' ); ?>">
				<button type="submit" name="cntr_accounts_action" value="create" class="button button-primary"><?php esc_html_e( 'Add', 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Gateway mapping', 'counter' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'cntr_accounts_action' ); ?>
				<table class="widefat">
					<?php foreach ( $gateways as $gid => $gateway ) : ?>
						<tr>
							<td><?php echo esc_html( $gateway->get_title() ?: $gid ); ?> (<?php echo esc_html( $gid ); ?>)</td>
							<td>
								<select name="gateway_map[<?php echo esc_attr( $gid ); ?>]">
									<option value="0"><?php esc_html_e( '— none —', 'counter' ); ?></option>
									<?php foreach ( $accounts as $a ) : ?>
										<option value="<?php echo (int) $a['id']; ?>" <?php selected( (int) ( $map[ $gid ] ?? 0 ), (int) $a['id'] ); ?>><?php echo esc_html( $a['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $gateways ) ) : ?>
						<tr><td colspan="2"><?php esc_html_e( 'No WooCommerce payment gateways are currently enabled.', 'counter' ); ?></td></tr>
					<?php endif; ?>
				</table>
				<button type="submit" name="cntr_accounts_action" value="save_map" class="button button-primary"><?php esc_html_e( 'Save mapping', 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Reconciliation', 'counter' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="counter-accounts">
				<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>">
				<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Run', 'counter' ); ?></button>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Account', 'counter' ); ?></th><th><?php esc_html_e( 'Expected', 'counter' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $reconciliation as $r ) : ?>
						<tr><td><?php echo esc_html( $r['name'] ); ?></td><td>৳<?php echo esc_html( $r['expected'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function handle_post(): string {
		$action = sanitize_key( wp_unslash( $_POST['cntr_accounts_action'] ?? '' ) );

		if ( 'create' === $action ) {
			$result = AccountsClass::create(
				[
					'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
					'code' => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ),
					'kind' => sanitize_text_field( wp_unslash( $_POST['kind'] ?? 'bank' ) ),
				]
			);
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'deactivate' === $action ) {
			AccountsClass::deactivate( absint( $_POST['account_id'] ?? 0 ) );
			return '';
		}

		if ( 'save_map' === $action ) {
			$posted = (array) ( $_POST['gateway_map'] ?? [] );
			$map    = [];
			foreach ( $posted as $gid => $account_id ) {
				$account_id = absint( $account_id );
				if ( $account_id > 0 ) {
					$map[ sanitize_key( $gid ) ] = $account_id;
				}
			}
			AccountsClass::set_gateway_map( $map );
			return '';
		}

		return '';
	}
}
