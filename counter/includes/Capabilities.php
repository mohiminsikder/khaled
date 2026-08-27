<?php
namespace Counter;

defined( 'ABSPATH' ) || exit;

/**
 * Five roles plus a locked-down terminal machine account. Grants are re-applied
 * idempotently on every version bump so a capability added later reaches existing
 * roles without a reinstall — see grant_all(), called from Boot::on_activate() and
 * from upgrade paths.
 */
class Capabilities {

	const ROLE_CASHIER     = 'cntr_cashier';
	const ROLE_SUPERVISOR  = 'cntr_supervisor';
	const ROLE_STOCKKEEPER = 'cntr_stockkeeper';
	const ROLE_MANAGER     = 'cntr_manager';
	const ROLE_TERMINAL    = 'cntr_terminal';

	/** Every capability this plugin defines, prefixed cntr_. The canonical list. */
	public static function all_caps(): array {
		return [
			// Till
			'cntr_use_pos', 'cntr_open_shift', 'cntr_close_shift', 'cntr_close_any_shift', 'cntr_hold_sale',
			// Overrides
			'cntr_discount_line', 'cntr_price_override', 'cntr_void_line', 'cntr_no_sale', 'cntr_refund',
			// Money visibility
			'cntr_view_cost', 'cntr_view_margin', 'cntr_view_all_sales',
			// Stock
			'cntr_manage_stock', 'cntr_adjust_stock', 'cntr_transfer_stock', 'cntr_stocktake', 'cntr_print_labels',
			// Purchasing
			'cntr_manage_purchasing', 'cntr_pay_supplier',
			// Customers
			'cntr_manage_customers', 'cntr_credit_sale', 'cntr_merge_customers',
			// Online
			'cntr_fulfil_orders',
			// Reports
			'cntr_view_reports', 'cntr_view_all_reports', 'cntr_export',
			// Expenses
			'cntr_manage_expenses',
			// People
			'cntr_manage_people', 'cntr_run_payroll', 'cntr_approve_leave', 'cntr_view_leave',
			// Admin
			'cntr_manage_settings', 'cntr_manage_templates', 'cntr_view_audit', 'cntr_manage_registers',
			// Terminal machine account — nothing else grants this.
			'cntr_terminal_access',
		];
	}

	/**
	 * D2 (COUNTERV2.md §1) — a real cashier could not bill a standing
	 * customer at all (neither role granted cntr_credit_sale), while
	 * cntr_refund — the classic shrinkage path every reference POS
	 * restricts — sat with the cashier instead of the supervisor. Credit at
	 * the counter is the feature the till was built for; refund moves up.
	 */
	private static function cashier_caps(): array {
		return [
			'cntr_use_pos', 'cntr_open_shift', 'cntr_close_shift', 'cntr_hold_sale', 'cntr_credit_sale',
		];
	}

	private static function supervisor_caps(): array {
		return array_merge(
			self::cashier_caps(),
			[
				'cntr_discount_line', 'cntr_price_override', 'cntr_void_line', 'cntr_no_sale', 'cntr_refund',
				'cntr_adjust_stock', 'cntr_transfer_stock', 'cntr_close_any_shift',
			]
		);
	}

	private static function stockkeeper_caps(): array {
		return [
			'cntr_manage_stock', 'cntr_manage_purchasing', 'cntr_pay_supplier', 'cntr_stocktake',
			'cntr_print_labels', 'cntr_view_cost',
			// P4.2 — the stockkeeper is the natural role for whoever is
			// named (§10.1 question 6, docs/decisions.md) to clear the
			// online fulfilment queue: the same person already handles
			// physical stock all day.
			'cntr_fulfil_orders',
			// deliberately NOT cntr_use_pos — no till access at all.
		];
	}

	private static function manager_caps(): array {
		// Everything operational, including cost/margin visibility and every
		// report, but not settings or user management.
		$exclude = [ 'cntr_manage_settings', 'cntr_terminal_access' ];
		return array_values( array_diff( self::all_caps(), $exclude ) );
	}

	private static function terminal_caps(): array {
		// The ONLY capability this role has. Audit finding T3: a normal WordPress
		// application password authenticates against the whole REST API on a
		// machine sitting on a shop floor. This role reaches counter/v1 and
		// nothing else — no edit_posts, no wp/v2, no read.
		return [ 'cntr_terminal_access' ];
	}

	/**
	 * C6 — the four roles a shop actually hands out day to day. Administrator
	 * (fixed: every cntr_* cap, not editable) and Terminal (fixed: a locked-down
	 * machine account, not a human role) are deliberately absent — nothing here
	 * makes either one editable.
	 */
	const EDITABLE_ROLES = [ self::ROLE_CASHIER, self::ROLE_SUPERVISOR, self::ROLE_STOCKKEEPER, self::ROLE_MANAGER ];

	/** Capabilities the Roles screen (C6) refuses to grant, however the role is otherwise edited. */
	const SCREEN_LOCKED_CAPS = [ 'cntr_manage_settings' ];

	private static function base_caps( string $role_name ): array {
		switch ( $role_name ) {
			case self::ROLE_CASHIER:
				return self::cashier_caps();
			case self::ROLE_SUPERVISOR:
				return self::supervisor_caps();
			case self::ROLE_STOCKKEEPER:
				return self::stockkeeper_caps();
			case self::ROLE_MANAGER:
				return self::manager_caps();
			case self::ROLE_TERMINAL:
				return self::terminal_caps();
			default:
				return [];
		}
	}

	/**
	 * Per-role, per-capability deltas the Roles screen has written on top of
	 * base_caps() — keyed [role_name][cap] => true (granted) | false (revoked).
	 * Kept separate from the live WP role object (rather than being the only
	 * record of a change) so sync_role()'s own version-bump reconciliation
	 * — the mechanism A4 exists for, so a code-level capability change reaches
	 * every existing install — can rebuild a role from base_caps() and still
	 * end up back at what an admin actually chose here, instead of quietly
	 * discarding it on the next deploy.
	 */
	public static function role_overrides(): array {
		$stored = get_option( 'cntr_role_overrides', [] );
		return is_array( $stored ) ? $stored : [];
	}

	/** base_caps() with this role's stored overrides applied on top. */
	public static function effective_caps( string $role_name ): array {
		$caps      = array_fill_keys( self::base_caps( $role_name ), true );
		$overrides = self::role_overrides()[ $role_name ] ?? [];
		foreach ( $overrides as $cap => $granted ) {
			if ( $granted ) {
				$caps[ $cap ] = true;
			} else {
				unset( $caps[ $cap ] );
			}
		}
		return array_keys( $caps );
	}

	/**
	 * The Roles screen's one write path — never $role->add_cap()/remove_cap()
	 * directly, so every change is validated, recorded (survives the next
	 * sync_role() reconciliation), applied live, and audited in one place.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_role_cap( string $role_name, string $cap, bool $granted ) {
		if ( ! in_array( $role_name, self::EDITABLE_ROLES, true ) ) {
			return new \WP_Error( 'cntr_role_locked', __( 'This role cannot be edited from here.', 'counter' ), [ 'status' => 403 ] );
		}
		if ( ! in_array( $cap, self::all_caps(), true ) ) {
			return new \WP_Error( 'cntr_role_bad_cap', __( 'Not a recognised capability.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( $granted && in_array( $cap, self::SCREEN_LOCKED_CAPS, true ) ) {
			return new \WP_Error( 'cntr_role_cap_locked', __( 'This capability cannot be granted from the Roles screen.', 'counter' ), [ 'status' => 403 ] );
		}

		$role = get_role( $role_name );
		if ( ! $role ) {
			return new \WP_Error( 'cntr_role_missing', __( 'Role not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$was_granted = $role->has_cap( $cap );

		$overrides                          = self::role_overrides();
		$overrides[ $role_name ][ $cap ]    = $granted;
		update_option( 'cntr_role_overrides', $overrides );

		if ( $granted ) {
			$role->add_cap( $cap );
		} else {
			$role->remove_cap( $cap );
		}

		Audit::log(
			'role_cap_change',
			'role',
			0,
			[ 'role' => $role_name, 'cap' => $cap, 'granted' => $was_granted ],
			[ 'role' => $role_name, 'cap' => $cap, 'granted' => $granted ],
			get_current_user_id()
		);

		return true;
	}

	public static function init(): void {
		// Roles/capabilities are set up on activation and version bump, not on
		// every request — see grant_all().
	}

	public static function grant_all(): void {
		self::add_role_if_missing( self::ROLE_CASHIER, __( 'Counter Cashier', 'counter' ), self::effective_caps( self::ROLE_CASHIER ) );
		self::add_role_if_missing( self::ROLE_SUPERVISOR, __( 'Counter Supervisor', 'counter' ), self::effective_caps( self::ROLE_SUPERVISOR ) );
		self::add_role_if_missing( self::ROLE_STOCKKEEPER, __( 'Counter Stockkeeper', 'counter' ), self::effective_caps( self::ROLE_STOCKKEEPER ) );
		self::add_role_if_missing( self::ROLE_MANAGER, __( 'Counter Manager', 'counter' ), self::effective_caps( self::ROLE_MANAGER ) );
		self::add_role_if_missing( self::ROLE_TERMINAL, __( 'Counter Terminal', 'counter' ), self::effective_caps( self::ROLE_TERMINAL ) );

		// administrator gets every cntr_* capability the plugin defines.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Re-apply idempotently: a role that already exists (e.g. re-activation,
		// or a later version bump adding a capability) gets the current grant set
		// without duplicating or dropping anything WordPress already tracks —
		// add_cap() on an existing capability is a no-op, not a duplicate.
		// effective_caps() (not the raw base_caps() list) so a version bump's
		// own reconciliation lands back on whatever the Roles screen (C6) last
		// chose, rather than quietly discarding it.
		self::sync_role( self::ROLE_CASHIER, self::effective_caps( self::ROLE_CASHIER ) );
		self::sync_role( self::ROLE_SUPERVISOR, self::effective_caps( self::ROLE_SUPERVISOR ) );
		self::sync_role( self::ROLE_STOCKKEEPER, self::effective_caps( self::ROLE_STOCKKEEPER ) );
		self::sync_role( self::ROLE_MANAGER, self::effective_caps( self::ROLE_MANAGER ) );
		self::sync_role( self::ROLE_TERMINAL, self::effective_caps( self::ROLE_TERMINAL ) );
	}

	private static function add_role_if_missing( string $role, string $label, array $caps ): void {
		if ( null === get_role( $role ) ) {
			add_role( $role, $label, array_fill_keys( $caps, true ) );
		}
	}

	/**
	 * Bring an existing role's capability set in line with the current definition
	 * without touching any capability WordPress core or another plugin granted —
	 * only the cntr_* capabilities this class itself defines are reconciled, by
	 * both adding what the current definition wants and removing what it no
	 * longer does. Without the removal half, a capability moved OFF a role (A4 —
	 * cntr_refund leaving Cashier) would silently stick on every install that
	 * already ran an earlier grant_all(), since add_cap() alone can only ever
	 * grow a role, never shrink one back toward the code's current definition.
	 * C6's Roles screen writes through set_role_cap(), never straight to the
	 * WP role object, specifically so its choices are recorded in
	 * role_overrides() and folded into $caps (via effective_caps(), the only
	 * caller of this method) BEFORE this runs — a raw $role->add_cap() call
	 * from anywhere else would get silently reverted the next time this
	 * fires.
	 * cntr_terminal additionally has 'read' stripped explicitly — a machine
	 * account on a shop floor must not be able to open wp-admin at all.
	 */
	private static function sync_role( string $role_name, array $caps ): void {
		$role = get_role( $role_name );
		if ( ! $role ) {
			return;
		}
		$wanted = array_flip( $caps );
		foreach ( self::all_caps() as $cap ) {
			if ( isset( $wanted[ $cap ] ) ) {
				$role->add_cap( $cap );
			} else {
				$role->remove_cap( $cap );
			}
		}
		if ( self::ROLE_TERMINAL === $role_name ) {
			$role->remove_cap( 'read' );
		}
	}
}
