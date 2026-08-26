<?php
namespace Counter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Menu slug is 'counter'; screens register on the cntr_admin_menu action rather
 * than being hardcoded here, so a screen added in a later phase (Screens/…) needs
 * only its own file, not an edit to this one.
 *
 * U3 (COUNTERFRONTEND.md) — seventeen items (one add_menu_page, sixteen
 * add_submenu_page calls) in one flat list, daily-use tools interleaved
 * with rare compliance exports. Grouped into five sections below WITHOUT
 * touching a single screen file: reorder() runs after every screen has
 * already self-registered on cntr_admin_menu (same hook, unchanged) and
 * only ever re-sorts WordPress's own $submenu global — it adds no page,
 * removes none, changes no capability. This file is the only one that
 * needs to know which group a screen belongs to; the screens themselves
 * stay exactly as ignorant of each other as cntr_admin_menu always meant
 * them to be.
 */
class Menu {

	/**
	 * Keyed by the submenu SLUG each screen already registers with.
	 * Purchasing has no screens yet ("once it exists" — Direction's own
	 * words) — kept as a real, empty group rather than omitted, so a
	 * future purchasing screen has an established place to register into
	 * without this file needing another pass.
	 *
	 * Two screens the plan's own five-group list never actually named —
	 * Reports and the Z-report — placed by what they're FOR, not left
	 * out: Reports (sales/margin/discount/tax figures) sits with the
	 * other financial screens in Money; the Z-report (a shift's own
	 * close-out reconciliation) sits with the daily tools in Operations,
	 * the same audience as Counter/Adjust Stock/Fulfilment.
	 */
	const GROUPS = [
		'operations' => [
			'label' => 'Operations',
			'slugs' => [ 'counter', 'counter-adjust', 'counter-fulfilment', 'counter-zreport' ],
		],
		'purchasing' => [
			'label' => 'Purchasing',
			'slugs' => [],
		],
		'people'      => [
			'label' => 'People',
			'slugs' => [ 'counter-attendance', 'counter-leave', 'counter-payroll' ],
		],
		'money'       => [
			'label' => 'Money',
			'slugs' => [ 'counter-receivables', 'counter-accounts', 'counter-expenses', 'counter-vat-exports', 'counter-reports' ],
		],
		'admin'       => [
			'label' => 'Admin',
			'slugs' => [ 'counter-health', 'counter-perf', 'counter-outbox-failures', 'counter-oversell-log', 'counter-labels' ],
		],
	];

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register' ] );
		// Priority 999 — after every screen's own cntr_admin_menu listener
		// (fired from inside register(), the default-priority admin_menu
		// callback) has already added its page; this only ever reorders
		// what already exists.
		add_action( 'admin_menu', [ self::class, 'reorder' ], 999 );
		add_action( 'admin_head', [ self::class, 'print_group_style' ] );
		// A5 — the till otherwise has no link anywhere; a cashier who can
		// use it but can't manage settings never even sees the Counter
		// admin menu (gated on cntr_manage_settings above). 100: after
		// WordPress's own core nodes so this doesn't fight for position.
		add_action( 'admin_bar_menu', [ self::class, 'add_admin_bar_node' ], 100 );
	}

	/**
	 * Gated on cntr_use_pos OR cntr_terminal_access — a real cashier has the
	 * former, the till's own machine account (which never sees wp-admin at
	 * all, `read` stripped in Capabilities::sync_role()) the latter; either
	 * is sufficient reason to show a way to the till, not just admins.
	 */
	public static function add_admin_bar_node( \WP_Admin_Bar $admin_bar ): void {
		if ( ! current_user_can( 'cntr_use_pos' ) && ! current_user_can( 'cntr_terminal_access' ) ) {
			return;
		}
		$admin_bar->add_node(
			[
				'id'    => 'cntr-till',
				'title' => __( 'Till', 'counter' ),
				'href'  => home_url( '/pos/' ),
				'meta'  => [ 'title' => __( 'Open the Counter till', 'counter' ) ],
			]
		);
	}

	public static function register(): void {
		add_menu_page(
			__( 'Counter', 'counter' ),
			__( 'Counter', 'counter' ),
			'cntr_manage_settings',
			'counter',
			[ Screens\Dashboard::class, 'render' ],
			'dashicons-cart'
		);

		add_submenu_page(
			'counter',
			__( 'Health', 'counter' ),
			__( 'Health', 'counter' ),
			'cntr_manage_settings',
			'counter-health',
			[ Health::class, 'render' ]
		);

		do_action( 'cntr_admin_menu' );
	}

	/**
	 * Re-sorts $submenu['counter'] into GROUPS order, group by group, each
	 * group's own items kept in whatever order they registered in. A slug
	 * this file doesn't know about yet — a future screen nobody has
	 * grouped — sorts to the very end rather than vanishing; "no screen is
	 * orphaned" is a property of this function, not just of today's
	 * GROUPS list being complete.
	 */
	public static function reorder(): void {
		global $submenu;
		if ( empty( $submenu['counter'] ) || ! is_array( $submenu['counter'] ) ) {
			return;
		}

		$position = [];
		foreach ( self::GROUPS as $group ) {
			foreach ( $group['slugs'] as $slug ) {
				$position[ $slug ] = count( $position );
			}
		}
		$unknown_position = count( $position );

		$rank = static function ( $item ) use ( $position, $unknown_position ) {
			$slug = $item[2] ?? '';
			return $position[ $slug ] ?? $unknown_position;
		};

		// usort() is not stable across every PHP version this plugin might
		// run on; a decorate-sort-undecorate keeps each group's own
		// registration order intact instead of leaving it to chance.
		$decorated = [];
		foreach ( $submenu['counter'] as $i => $item ) {
			$decorated[] = [ $rank( $item ), $i, $item ];
		}
		usort(
			$decorated,
			static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]
		);

		$submenu['counter'] = array_map( static fn( $d ) => $d[2], $decorated );
	}

	/** The group key ('operations', …) a submenu slug belongs to, or null if GROUPS doesn't name it. */
	public static function group_for_slug( string $slug ): ?string {
		foreach ( self::GROUPS as $key => $group ) {
			if ( in_array( $slug, $group['slugs'], true ) ) {
				return $key;
			}
		}
		return null;
	}

	/** Every slug currently registered under the Counter top-level menu, in whatever order $submenu['counter'] holds right now. */
	public static function registered_slugs(): array {
		global $submenu;
		if ( empty( $submenu['counter'] ) || ! is_array( $submenu['counter'] ) ) {
			return [];
		}
		return array_values( array_unique( array_map( static fn( $item ) => (string) ( $item[2] ?? '' ), $submenu['counter'] ) ) );
	}

	/**
	 * "Seventeen screens stop being one flat list" needs to be visible,
	 * not just true internally — a thin rule above each group's own first
	 * item, targeted by its real href so this survives a group gaining or
	 * losing a screen without needing to change. Printed only on a Counter
	 * admin screen, never sitewide.
	 */
	public static function print_group_style(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'counter' ) ) {
			return;
		}

		$hrefs = [];
		foreach ( self::GROUPS as $group ) {
			if ( empty( $group['slugs'] ) ) {
				continue;
			}
			$slug    = reset( $group['slugs'] );
			$hrefs[] = 'counter' === $slug ? 'admin.php?page=counter' : 'admin.php?page=' . $slug;
		}
		if ( empty( $hrefs ) ) {
			return;
		}

		echo '<style>';
		foreach ( $hrefs as $href ) {
			printf(
				'#adminmenu a[href="%s"]{border-top:1px solid rgba(240,246,252,.25);margin-top:6px;padding-top:6px;}',
				esc_attr( $href )
			);
		}
		echo '</style>';
	}
}
