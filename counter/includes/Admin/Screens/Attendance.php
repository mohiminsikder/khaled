<?php
namespace Counter\Admin\Screens;

use Counter\People\Attendance as AttendanceClass;
use Counter\People\Employees;

defined( 'ABSPATH' ) || exit;

/**
 * P6.2 — the CSV import side ("a shop that already keeps a sheet"). The
 * clock in/out side lives entirely at the terminal (Rest\Attendance); this
 * screen never writes a clock event itself, only a human uploading a
 * spreadsheet on behalf of days the terminal never saw.
 */
class Attendance {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Attendance', 'counter' ),
			__( 'Attendance', 'counter' ),
			'cntr_manage_people',
			'counter-attendance',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_people' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$import_result = null;
		if ( isset( $_POST['cntr_attendance_action'] ) && 'import' === $_POST['cntr_attendance_action'] ) {
			check_admin_referer( 'cntr_attendance_import' );
			if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
				$csv_text       = (string) file_get_contents( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded tmp file, not a remote URL
				$import_result  = AttendanceClass::import_csv( $csv_text );
			}
		}

		$employees = Employees::all();
		$from      = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' );
		$to        = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' );
		$employee_id = isset( $_GET['employee_id'] ) ? absint( $_GET['employee_id'] ) : ( $employees[0]['id'] ?? 0 );
		$rows      = $employee_id ? AttendanceClass::for_employee( $employee_id, $from, $to ) : [];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Attendance', 'counter' ); ?></h1>

			<?php if ( null !== $import_result ) : ?>
				<div class="notice notice-<?php echo empty( $import_result['rejected'] ) ? 'success' : 'warning'; ?>">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: imported count, 2: rejected count */
								__( 'Imported %1$d row(s), rejected %2$d row(s).', 'counter' ),
								$import_result['imported'],
								count( $import_result['rejected'] )
							)
						);
						?>
					</p>
					<?php if ( ! empty( $import_result['rejected'] ) ) : ?>
						<ul>
							<?php foreach ( $import_result['rejected'] as $r ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: 1: row number, 2: reason */ __( 'Row %1$d: %2$s', 'counter' ), $r['row'], $r['reason'] ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import from CSV', 'counter' ); ?></h2>
			<p><?php esc_html_e( 'Columns: employee_code, work_date (Y-m-d), clock_in (H:i), clock_out (H:i).', 'counter' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'cntr_attendance_import' ); ?>
				<input type="file" name="csv" accept=".csv" required>
				<button type="submit" name="cntr_attendance_action" value="import" class="button button-primary"><?php esc_html_e( 'Import', 'counter' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Records', 'counter' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="counter-attendance">
				<select name="employee_id">
					<?php foreach ( $employees as $e ) : ?>
						<option value="<?php echo (int) $e['id']; ?>" <?php selected( $employee_id, (int) $e['id'] ); ?>><?php echo esc_html( $e['code'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>">
				<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Run', 'counter' ); ?></button>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Date', 'counter' ); ?></th><th><?php esc_html_e( 'Clock in', 'counter' ); ?></th><th><?php esc_html_e( 'Clock out', 'counter' ); ?></th><th><?php esc_html_e( 'Minutes', 'counter' ); ?></th><th><?php esc_html_e( 'Source', 'counter' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['work_date'] ); ?></td>
							<td><?php echo esc_html( $r['clock_in'] ? get_date_from_gmt( $r['clock_in'], 'Y-m-d H:i' ) : '—' ); ?></td>
							<td><?php echo esc_html( $r['clock_out'] ? get_date_from_gmt( $r['clock_out'], 'Y-m-d H:i' ) : '—' ); ?></td>
							<td><?php echo esc_html( (string) $r['minutes'] ); ?></td>
							<td><?php echo esc_html( $r['source'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No attendance records for this range.', 'counter' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
