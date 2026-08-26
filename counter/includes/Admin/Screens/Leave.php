<?php
namespace Counter\Admin\Screens;

use Counter\People\Leave as LeaveClass;
use Counter\People\Employees;

defined( 'ABSPATH' ) || exit;

/**
 * P6.3 — the three-way split, rendered as three sections rather than
 * three screens: "your own requests" (everyone with an employee record —
 * Request, and View of your own is never gated), "pending your approval"
 * (cntr_approve_leave), "every request" (cntr_view_leave). A section
 * whose capability the current user lacks simply does not render, the
 * same pattern `Reports.php`'s own cost-gating uses — absence, not an
 * error.
 */
class Leave {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Leave', 'counter' ),
			__( 'Leave', 'counter' ),
			'read',
			'counter-leave',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$notice = '';
		if ( isset( $_POST['cntr_leave_action'] ) ) {
			check_admin_referer( 'cntr_leave_action' );
			$notice = self::handle_post();
		}

		$my_employee = Employees::get_by_user( get_current_user_id() );
		$types       = LeaveClass::types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Leave', 'counter' ); ?></h1>
			<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<?php if ( $my_employee ) : ?>
				<h2><?php esc_html_e( 'Request leave', 'counter' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'cntr_leave_action' ); ?>
					<select name="type">
						<?php foreach ( $types as $t ) : ?>
							<option value="<?php echo esc_attr( $t['code'] ); ?>"><?php echo esc_html( $t['name'] ); ?> (<?php echo esc_html( (string) $t['annual_days'] ); ?>/yr)</option>
						<?php endforeach; ?>
					</select>
					<input type="date" name="from_date" required>
					<input type="date" name="to_date" required>
					<label><input type="checkbox" name="half_day" value="1"> <?php esc_html_e( 'Half day', 'counter' ); ?></label>
					<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason', 'counter' ); ?>">
					<button type="submit" name="cntr_leave_action" value="request" class="button button-primary"><?php esc_html_e( 'Request', 'counter' ); ?></button>
				</form>

				<h2><?php esc_html_e( 'Your own requests', 'counter' ); ?></h2>
				<?php self::table( LeaveClass::for_employee( $my_employee['id'] ), true ); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'You have no employee record, so there is nothing of your own to request or show here.', 'counter' ); ?></p>
			<?php endif; ?>

			<?php if ( current_user_can( 'cntr_approve_leave' ) ) : ?>
				<h2><?php esc_html_e( 'Pending your approval', 'counter' ); ?></h2>
				<?php self::table( LeaveClass::pending_for_approver( get_current_user_id() ), false, true ); ?>
			<?php endif; ?>

			<?php if ( current_user_can( 'cntr_view_leave' ) ) : ?>
				<h2><?php esc_html_e( 'Every request', 'counter' ); ?></h2>
				<?php self::table( LeaveClass::all(), false ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function table( array $rows, bool $show_cancel, bool $show_decide = false ): void {
		?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Employee', 'counter' ); ?></th><th><?php esc_html_e( 'Type', 'counter' ); ?></th><th><?php esc_html_e( 'From', 'counter' ); ?></th><th><?php esc_html_e( 'To', 'counter' ); ?></th><th><?php esc_html_e( 'Days', 'counter' ); ?></th><th><?php esc_html_e( 'Status', 'counter' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $rows as $r ) : $emp = Employees::get( (int) $r['employee_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $emp['code'] ?? '#' . $r['employee_id'] ); ?></td>
						<td><?php echo esc_html( $r['type'] ); ?></td>
						<td><?php echo esc_html( $r['from_date'] ); ?></td>
						<td><?php echo esc_html( $r['to_date'] ); ?></td>
						<td><?php echo esc_html( $r['days'] ); ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
						<td>
							<?php if ( $show_decide && 'requested' === $r['status'] ) : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'cntr_leave_action' ); ?>
									<input type="hidden" name="leave_id" value="<?php echo (int) $r['id']; ?>">
									<button type="submit" name="cntr_leave_action" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'counter' ); ?></button>
									<button type="submit" name="cntr_leave_action" value="reject" class="button"><?php esc_html_e( 'Reject', 'counter' ); ?></button>
								</form>
							<?php elseif ( $show_cancel && in_array( $r['status'], [ 'requested', 'approved' ], true ) ) : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'cntr_leave_action' ); ?>
									<input type="hidden" name="leave_id" value="<?php echo (int) $r['id']; ?>">
									<button type="submit" name="cntr_leave_action" value="cancel" class="button"><?php esc_html_e( 'Cancel', 'counter' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Nothing here.', 'counter' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function handle_post(): string {
		$action = sanitize_key( wp_unslash( $_POST['cntr_leave_action'] ?? '' ) );

		if ( 'request' === $action ) {
			$my_employee = Employees::get_by_user( get_current_user_id() );
			if ( ! $my_employee ) {
				return __( 'You have no employee record.', 'counter' );
			}
			$result = LeaveClass::request(
				[
					'employee_id' => $my_employee['id'],
					'type'        => sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ),
					'from_date'   => sanitize_text_field( wp_unslash( $_POST['from_date'] ?? '' ) ),
					'to_date'     => sanitize_text_field( wp_unslash( $_POST['to_date'] ?? '' ) ),
					'half_day'    => ! empty( $_POST['half_day'] ),
					'reason'      => sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ),
				]
			);
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'approve' === $action && current_user_can( 'cntr_approve_leave' ) ) {
			$result = LeaveClass::approve( absint( $_POST['leave_id'] ?? 0 ), get_current_user_id() );
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'reject' === $action && current_user_can( 'cntr_approve_leave' ) ) {
			$result = LeaveClass::reject( absint( $_POST['leave_id'] ?? 0 ), get_current_user_id() );
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		if ( 'cancel' === $action ) {
			$leave_id    = absint( $_POST['leave_id'] ?? 0 );
			$row         = LeaveClass::get( $leave_id );
			$my_employee = Employees::get_by_user( get_current_user_id() );
			$is_own      = $row && $my_employee && (int) $my_employee['id'] === (int) $row['employee_id'];
			if ( ! $is_own && ! current_user_can( 'cntr_approve_leave' ) ) {
				return __( 'You may not cancel this request.', 'counter' );
			}
			$result = LeaveClass::cancel( $leave_id );
			return is_wp_error( $result ) ? $result->get_error_message() : '';
		}

		return '';
	}
}
