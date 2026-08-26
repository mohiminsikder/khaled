<?php
namespace Counter\Admin\Screens;

use Counter\People\Payroll as PayrollClass;
use Counter\People\Employees;
use Counter\Pos\Accounts as AccountsClass;

defined( 'ABSPATH' ) || exit;

/**
 * P6.4 — draft, review, approve. Nothing here ever pays anyone directly;
 * "approve" is the one button that turns a draft into a real, ledgered
 * expense (Payroll::approve_run(), reusing Reports\Expenses (P5.4) —
 * never a second payment mechanism built to duplicate it).
 */
class Payroll {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Payroll', 'counter' ),
			__( 'Payroll', 'counter' ),
			'cntr_run_payroll',
			'counter-payroll',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_run_payroll' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$notice = '';
		if ( isset( $_POST['cntr_payroll_action'] ) ) {
			check_admin_referer( 'cntr_payroll_action' );
			$notice = self::handle_post();
		}

		$accounts = AccountsClass::all( 'active' );

		global $wpdb;
		$runs_table = \Counter\Install::table( 'payroll_runs' );
		$runs       = $wpdb->get_results( "SELECT * FROM {$runs_table} ORDER BY period DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables

		$viewing_run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;
		$viewing_lines  = $viewing_run_id ? PayrollClass::lines_for_run( $viewing_run_id ) : [];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Payroll', 'counter' ); ?></h1>
			<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<h2><?php esc_html_e( 'Draft a run', 'counter' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'cntr_payroll_action' ); ?>
				<input type="text" name="period" placeholder="YYYY-MM" value="<?php echo esc_attr( gmdate( 'Y-m' ) ); ?>" required>
				<select name="account_id" required>
					<?php foreach ( $accounts as $a ) : ?>
						<option value="<?php echo (int) $a['id']; ?>"><?php echo esc_html( $a['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" name="cntr_payroll_action" value="create" class="button button-primary"><?php esc_html_e( 'Draft', 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Runs', 'counter' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Period', 'counter' ); ?></th><th><?php esc_html_e( 'Status', 'counter' ); ?></th><th><?php esc_html_e( 'Total', 'counter' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $runs as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['period'] ); ?></td>
							<td><?php echo esc_html( $r['status'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $r['total'] ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'counter-payroll', 'run_id' => $r['id'] ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View', 'counter' ); ?></a>
								<?php if ( 'draft' === $r['status'] ) : ?>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( 'cntr_payroll_action' ); ?>
										<input type="hidden" name="run_id" value="<?php echo (int) $r['id']; ?>">
										<button type="submit" name="cntr_payroll_action" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'counter' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $runs ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No payroll runs yet.', 'counter' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $viewing_run_id ) : ?>
				<h2><?php echo esc_html( sprintf( /* translators: %d: run id */ __( 'Run #%d lines', 'counter' ), $viewing_run_id ) ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Employee', 'counter' ); ?></th><th><?php esc_html_e( 'Days', 'counter' ); ?></th><th><?php esc_html_e( 'Earned', 'counter' ); ?></th><th><?php esc_html_e( 'Allowances', 'counter' ); ?></th><th><?php esc_html_e( 'Deductions', 'counter' ); ?></th><th><?php esc_html_e( 'Net', 'counter' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $viewing_lines as $l ) : $emp = Employees::get( (int) $l['employee_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $emp['code'] ?? '#' . $l['employee_id'] ); ?></td>
								<td><?php echo esc_html( $l['days_worked'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $l['earned'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $l['allowances'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $l['deductions'] ) ); ?></td>
								<td><strong><?php echo wp_kses_post( wc_price( $l['net'] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function handle_post(): string {
		$action = sanitize_key( wp_unslash( $_POST['cntr_payroll_action'] ?? '' ) );

		if ( 'create' === $action ) {
			$result = PayrollClass::create_run(
				sanitize_text_field( wp_unslash( $_POST['period'] ?? '' ) ),
				absint( $_POST['account_id'] ?? 0 ),
				get_current_user_id()
			);
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'approve' === $action ) {
			$result = PayrollClass::approve_run( absint( $_POST['run_id'] ?? 0 ) );
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		return '';
	}
}
