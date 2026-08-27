<?php
namespace Counter\Admin\Screens;

use Counter\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * C6 — who may do what, visible instead of code-only (this screen is why A4
 * exists: two wrong default grants sat unnoticed because roles were
 * code-only). Backend addition: Capabilities::set_role_cap()/effective_caps()/
 * role_overrides() — a role's capability set is still reconciled against the
 * code's own definition on every version bump (Capabilities::sync_role(),
 * unchanged in spirit since A4), but now folds in whatever this screen last
 * chose instead of quietly reverting it.
 *
 * Administrator (always every capability) and Terminal (a locked-down
 * machine account, not a human role) are shown nowhere here — not columns
 * that happen to be disabled, just absent — matching
 * Capabilities::EDITABLE_ROLES exactly.
 */
class Roles {

	const LABELS = [
		Capabilities::ROLE_CASHIER     => 'Cashier',
		Capabilities::ROLE_SUPERVISOR  => 'Supervisor',
		Capabilities::ROLE_STOCKKEEPER => 'Stockkeeper',
		Capabilities::ROLE_MANAGER     => 'Manager',
	];

	/** Grouped the same way Capabilities::all_caps()'s own comments already group them. */
	const GROUPS = [
		'Till'              => [ 'cntr_use_pos', 'cntr_open_shift', 'cntr_close_shift', 'cntr_close_any_shift', 'cntr_hold_sale' ],
		'Overrides'         => [ 'cntr_discount_line', 'cntr_price_override', 'cntr_void_line', 'cntr_no_sale', 'cntr_refund' ],
		'Money visibility'  => [ 'cntr_view_cost', 'cntr_view_margin', 'cntr_view_all_sales' ],
		'Stock'             => [ 'cntr_manage_stock', 'cntr_adjust_stock', 'cntr_transfer_stock', 'cntr_stocktake', 'cntr_print_labels' ],
		'Purchasing'        => [ 'cntr_manage_purchasing', 'cntr_pay_supplier' ],
		'Customers'         => [ 'cntr_manage_customers', 'cntr_credit_sale', 'cntr_merge_customers' ],
		'Online'            => [ 'cntr_fulfil_orders' ],
		'Reports'           => [ 'cntr_view_reports', 'cntr_view_all_reports', 'cntr_export' ],
		'Expenses'          => [ 'cntr_manage_expenses' ],
		'People'            => [ 'cntr_manage_people', 'cntr_run_payroll', 'cntr_approve_leave', 'cntr_view_leave' ],
		'Admin'             => [ 'cntr_manage_settings', 'cntr_manage_templates', 'cntr_view_audit', 'cntr_manage_registers' ],
	];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Roles', 'counter' ),
			__( 'Roles', 'counter' ),
			'cntr_manage_settings',
			'counter-roles',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_settings' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$notice = null;

		if ( isset( $_POST['cntr_roles_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_roles_save' );
			$changed = 0;
			$errors  = [];
			foreach ( Capabilities::EDITABLE_ROLES as $role_name ) {
				$role = get_role( $role_name );
				if ( ! $role ) {
					continue;
				}
				foreach ( Capabilities::all_caps() as $cap ) {
					$wanted = isset( $_POST['caps'][ $role_name ][ $cap ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- boolean presence check only, value never read
					if ( $wanted === $role->has_cap( $cap ) ) {
						continue;
					}
					$result = Capabilities::set_role_cap( $role_name, $cap, $wanted );
					if ( is_wp_error( $result ) ) {
						$errors[] = $result->get_error_message();
					} else {
						++$changed;
					}
				}
			}
			$notice = $errors
				? [ 'ok' => false, 'message' => implode( ' ', array_unique( $errors ) ) ]
				: [ 'ok' => true, 'message' => $changed ? sprintf( /* translators: %d: number of capability changes saved */ __( '%d change(s) saved.', 'counter' ), $changed ) : __( 'Nothing to save.', 'counter' ) ];
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Roles & Permissions', 'counter' ) . '</h1>';

		if ( $notice ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', $notice['ok'] ? 'success' : 'error', esc_html( $notice['message'] ) );
		}

		echo '<p>' . esc_html__( 'Administrator always has every capability and cannot be edited here. The Terminal machine account is locked down separately and does not appear here.', 'counter' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'cntr_roles_save' );
		echo '<input type="hidden" name="cntr_roles_action" value="save">';
		echo '<table class="widefat striped cntr-roles-matrix"><thead><tr>';
		echo '<th>' . esc_html__( 'Capability', 'counter' ) . '</th>';
		foreach ( self::LABELS as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( self::GROUPS as $group_label => $caps ) {
			printf( '<tr><th colspan="%d" style="background:#f0f0f1;">%s</th></tr>', count( self::LABELS ) + 1, esc_html( $group_label ) );
			foreach ( $caps as $cap ) {
				echo '<tr><td>' . esc_html( $cap ) . '</td>';
				foreach ( self::LABELS as $role_name => $label ) {
					$role   = get_role( $role_name );
					$locked = in_array( $cap, Capabilities::SCREEN_LOCKED_CAPS, true );
					printf(
						'<td style="text-align:center;"><input type="checkbox" name="caps[%1$s][%2$s]" value="1"%3$s%4$s></td>',
						esc_attr( $role_name ),
						esc_attr( $cap ),
						( $role && $role->has_cap( $cap ) ) ? ' checked' : '',
						$locked ? ' disabled title="' . esc_attr__( 'Cannot be granted from this screen.', 'counter' ) . '"' : ''
					);
				}
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'counter' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}
}
