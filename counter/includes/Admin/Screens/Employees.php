<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Admin\EntityPicker;
use Counter\People\Employees as EmployeesModel;
use Counter\Stock\Locations;
use Counter\Pos\Pin;

defined( 'ABSPATH' ) || exit;

/**
 * C6 — onboarding a cashier is a form. Backend is (almost) none:
 * People\Employees already had create()/get()/all()/deactivate(); this task
 * added update() (the edit half — user_id/code stay fixed once set, a PIN
 * change routes through Pin::set() instead so it keeps auditing exactly
 * once, not zero or twice).
 */
class Employees {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Employees', 'counter' ),
			__( 'Employees', 'counter' ),
			'cntr_manage_people',
			'counter-employees',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_people' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Employees', 'counter' ) . '</h1>';

		if ( 'edit' === $view ) {
			self::render_edit_view();
		} else {
			self::render_notice();
			$table = new EmployeesListTable();
			$table->render_card_header();
			$table->prepare_items();
			$table->display();
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$notice = get_transient( 'cntr_employees_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'cntr_employees_notice_' . get_current_user_id() );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$notice['ok'] ? 'success' : 'error',
			esc_html( $notice['message'] )
		);
	}

	private static function set_notice( bool $ok, string $message ): void {
		set_transient( 'cntr_employees_notice_' . get_current_user_id(), [ 'ok' => $ok, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	/** WP users not already linked to an employee record — the create form's own picker pool. */
	private static function eligible_users(): array {
		$taken = array_column( EmployeesModel::all( '' ), 'user_id' );
		$users = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_login' ] ] );
		$out   = [];
		foreach ( $users as $u ) {
			if ( in_array( (int) $u->ID, $taken, true ) ) {
				continue;
			}
			$out[] = [ 'id' => (int) $u->ID, 'label' => $u->display_name, 'sublabel' => $u->user_login ];
		}
		return $out;
	}

	private static function parse_allowances( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $label, $amount ] = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' === $label ) {
				continue;
			}
			$out[ sanitize_text_field( $label ) ] = wc_format_decimal( $amount, 4 );
		}
		return $out;
	}

	private static function format_allowances( ?string $json ): string {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$lines = [];
		foreach ( $decoded as $label => $amount ) {
			$lines[] = is_string( $label ) ? "{$label}: {$amount}" : (string) $amount;
		}
		return implode( "\n", $lines );
	}

	private static function render_edit_view(): void {
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$existing = $id ? EmployeesModel::get( $id ) : null;
		if ( $id && ! $existing ) {
			echo '<p>' . esc_html__( 'Employee not found.', 'counter' ) . '</p>';
			return;
		}

		$field_error = null;

		if ( isset( $_POST['cntr_employee_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_employee_save' );
			$data = [
				'designation'     => sanitize_text_field( wp_unslash( $_POST['designation'] ?? '' ) ),
				'location_id'     => absint( $_POST['location_id'] ?? 0 ),
				'join_date'       => sanitize_text_field( wp_unslash( $_POST['join_date'] ?? '' ) ),
				'starting_salary' => sanitize_text_field( wp_unslash( $_POST['starting_salary'] ?? '0' ) ),
				'basic'           => sanitize_text_field( wp_unslash( $_POST['basic'] ?? '0' ) ),
				'allowances'      => self::parse_allowances( sanitize_textarea_field( wp_unslash( $_POST['allowances'] ?? '' ) ) ),
				'note'            => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
			];

			if ( $id ) {
				$result = EmployeesModel::update( $id, $data );
			} else {
				$data['user_id'] = absint( $_POST['user_id'] ?? 0 );
				$data['code']    = sanitize_key( wp_unslash( $_POST['code'] ?? '' ) );
				$data['pin']     = sanitize_text_field( wp_unslash( $_POST['pin'] ?? '' ) );
				$result          = EmployeesModel::create( $data );
			}

			if ( is_wp_error( $result ) ) {
				$field_error = $result->get_error_message();
				$existing    = array_merge( $existing ?? [], $data );
			} else {
				$new_id = $id ?: (int) $result;
				$pin    = sanitize_text_field( wp_unslash( $_POST['pin'] ?? '' ) );
				if ( $id && '' !== $pin ) {
					$pin_result = Pin::set( $new_id, $pin );
					if ( is_wp_error( $pin_result ) ) {
						self::set_notice( false, $pin_result->get_error_message() );
						wp_safe_redirect( admin_url( 'admin.php?page=counter-employees&view=edit&id=' . $new_id ) );
						exit;
					}
				}
				self::set_notice( true, __( 'Employee saved.', 'counter' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=counter-employees' ) );
				exit;
			}
		}

		echo '<h2>' . ( $id ? esc_html__( 'Edit employee', 'counter' ) : esc_html__( 'New employee', 'counter' ) ) . '</h2>';

		if ( $field_error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $field_error ) );
		}

		$locations = Locations::all( 'active' );

		echo '<form method="post">';
		wp_nonce_field( 'cntr_employee_save' );
		echo '<input type="hidden" name="cntr_employee_action" value="save">';
		echo '<table class="form-table"><tbody>';

		if ( $id ) {
			$user = get_userdata( (int) $existing['user_id'] );
			printf(
				'<tr><th>%s</th><td><a href="%s">%s</a></td></tr>',
				esc_html__( 'WordPress user', 'counter' ),
				esc_url( admin_url( 'user-edit.php?user_id=' . (int) $existing['user_id'] ) ),
				esc_html( $user ? $user->display_name . ' (' . $user->user_login . ')' : '#' . (int) $existing['user_id'] )
			);
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Employee code', 'counter' ), esc_html( $existing['code'] ) );
		} else {
			echo '<tr><th><label>' . esc_html__( 'WordPress user', 'counter' ) . '</label></th><td>';
			EntityPicker::render(
				[
					'id'           => 'cntr-emp-user',
					'hidden_name'  => 'user_id',
					'type'         => 'user',
					'placeholder'  => __( 'Type a name…', 'counter' ),
					'required'     => true,
					'options'      => self::eligible_users(),
				]
			);
			echo ' <span class="description">' . esc_html__( 'Only users not already linked to an employee record are listed.', 'counter' ) . '</span></td></tr>';
			printf(
				'<tr><th><label for="cntr-emp-code">%1$s</label></th><td><input type="text" id="cntr-emp-code" name="code" class="regular-text" value="%2$s" required></td></tr>',
				esc_html__( 'Employee code', 'counter' ),
				esc_attr( (string) ( $existing['code'] ?? '' ) )
			);
		}

		echo '<tr><th><label for="cntr-emp-desig">' . esc_html__( 'Designation', 'counter' ) . '</label></th><td><input type="text" id="cntr-emp-desig" name="designation" class="regular-text" value="' . esc_attr( (string) ( $existing['designation'] ?? '' ) ) . '"></td></tr>';

		echo '<tr><th>' . esc_html__( 'Location', 'counter' ) . '</th><td><select name="location_id">';
		printf( '<option value="0">%s</option>', esc_html__( '— none —', 'counter' ) );
		foreach ( $locations as $l ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $l['id'],
				selected( (int) ( $existing['location_id'] ?? 0 ), (int) $l['id'], false ),
				esc_html( $l['name'] )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="cntr-emp-join">' . esc_html__( 'Join date', 'counter' ) . '</label></th><td><input type="date" id="cntr-emp-join" name="join_date" value="' . esc_attr( (string) ( $existing['join_date'] ?? '' ) ) . '"></td></tr>';
		echo '<tr><th><label for="cntr-emp-salary">' . esc_html__( 'Starting salary', 'counter' ) . '</label></th><td><input type="text" id="cntr-emp-salary" name="starting_salary" value="' . esc_attr( (string) ( $existing['starting_salary'] ?? '0' ) ) . '"></td></tr>';
		echo '<tr><th><label for="cntr-emp-basic">' . esc_html__( 'Basic (used by payroll)', 'counter' ) . '</label></th><td><input type="text" id="cntr-emp-basic" name="basic" value="' . esc_attr( (string) ( $existing['basic'] ?? '0' ) ) . '"></td></tr>';
		echo '<tr><th><label for="cntr-emp-allow">' . esc_html__( 'Allowances', 'counter' ) . '</label></th><td><textarea id="cntr-emp-allow" name="allowances" class="large-text code" rows="3" placeholder="Housing: 2000&#10;Transport: 500">' . esc_textarea( self::format_allowances( $existing['allowances_json'] ?? null ) ) . '</textarea><p class="description">' . esc_html__( 'One "label: amount" per line.', 'counter' ) . '</p></td></tr>';

		printf(
			'<tr><th><label for="cntr-emp-pin">%1$s</label></th><td><input type="text" id="cntr-emp-pin" name="pin" inputmode="numeric" pattern="[0-9]{4,6}" placeholder="%2$s"><p class="description">%3$s</p></td></tr>',
			$id ? esc_html__( 'Change PIN', 'counter' ) : esc_html__( 'PIN (optional)', 'counter' ),
			esc_attr__( '4 to 6 digits', 'counter' ),
			$id ? esc_html__( 'Leave blank to keep the current PIN.', 'counter' ) : esc_html__( 'Leave blank to assign one later.', 'counter' )
		);

		echo '<tr><th><label for="cntr-emp-note">' . esc_html__( 'Note', 'counter' ) . '</label></th><td><textarea id="cntr-emp-note" name="note" class="large-text" rows="2">' . esc_textarea( (string) ( $existing['note'] ?? '' ) ) . '</textarea></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save employee', 'counter' ) . '</button></p>';
		echo '</form>';
	}
}

class EmployeesListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$this->rows      = EmployeesModel::all( '' );
		$this->add_url   = admin_url( 'admin.php?page=counter-employees&view=edit' );
		$this->add_label = __( '+ Add employee', 'counter' );
		parent::__construct( [ 'singular' => 'employee', 'plural' => 'employees', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'name'        => __( 'Name', 'counter' ),
			'code'        => __( 'Code', 'counter' ),
			'designation' => __( 'Designation', 'counter' ),
			'location'    => __( 'Location', 'counter' ),
			'status'      => __( 'Status', 'counter' ),
			'actions'     => '',
		];
	}

	public function get_filters(): array {
		return [
			'status' => [
				'label'   => __( 'Status', 'counter' ),
				'type'    => 'select',
				'options' => [ 'active' => __( 'Active', 'counter' ), 'inactive' => __( 'Inactive', 'counter' ) ],
			],
		];
	}

	public function export_rows(): array {
		return $this->rows;
	}

	public function export_columns(): array {
		$cols = $this->get_columns();
		unset( $cols['actions'] );
		return $cols;
	}

	public function prepare_items(): void {
		$status = $this->filter_values()['status'] ?? '';
		$rows   = $status ? array_values( array_filter( $this->rows, static fn( $r ) => $r['status'] === $status ) ) : $this->rows;

		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $rows ), 'per_page' => $per_page ] );
		$this->items = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
	}

	public function column_default( $item, $column_name ) {
		if ( 'name' === $column_name ) {
			$user = get_userdata( (int) $item['user_id'] );
			return esc_html( $user ? $user->display_name : ( '#' . $item['user_id'] ) );
		}
		if ( 'location' === $column_name ) {
			if ( ! $item['location_id'] ) {
				return '';
			}
			$l = Locations::get( (int) $item['location_id'] );
			return esc_html( $l['name'] ?? '' );
		}
		if ( 'actions' === $column_name ) {
			$actions = [ 'edit' => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-employees&view=edit&id=' . $item['id'] ) ) . '">' . esc_html__( 'Edit', 'counter' ) . '</a>' ];
			return $this->build_row_actions( $actions );
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
