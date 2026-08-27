<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Stock\Locations as LocationsModel;
use Counter\Pos\Registers;

defined( 'ABSPATH' ) || exit;

/**
 * C5 — "a second till or a second branch can exist without a database
 * insert." Backend is (almost) none: Stock\Locations and Pos\Registers
 * already did create/read; this task's own two real gaps — teardown defect
 * #10 ("two locations both named Pharmacy") and "a new register gets a
 * unique prefix" — were closed directly in those classes (name_exists()/
 * has_stock() on Locations, name_exists()/generate_unique_prefix() on
 * Registers), not reinvented here, so every OTHER caller benefits too, not
 * just this screen.
 *
 * One file, two tabs (Locations / Registers) — the same GET-param-tab
 * dispatch Products.php already established, not a nav-tab-wrapper
 * anywhere else in this plugin yet either.
 */
class Locations {

	const TABS = [ 'locations' => 'Locations', 'registers' => 'Registers' ];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Locations & Registers', 'counter' ),
			__( 'Locations & Registers', 'counter' ),
			'cntr_manage_registers',
			'counter-locations',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_registers' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'locations'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'locations';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Locations & Registers', 'counter' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( [ 'page' => 'counter-locations', 'tab' => $key ], admin_url( 'admin.php' ) ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( __( $label, 'counter' ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- self::TABS is a fixed, known set
			);
		}
		echo '</h2>';

		if ( 'registers' === $tab ) {
			self::render_registers();
		} else {
			self::render_locations();
		}
		echo '</div>';
	}

	// -- Locations --------------------------------------------------------------------

	private static function render_locations(): void {
		$notice = self::handle_location_post();

		if ( $notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $notice['ok'] ? 'success' : 'error', esc_html( $notice['message'] ) );
		}

		$edit_id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$editing  = $edit_id ? LocationsModel::get( $edit_id ) : null;

		$table = new LocationsListTable();
		$table->render_card_header();
		$table->prepare_items();
		$table->display();

		echo '<h2>' . ( $editing ? esc_html__( 'Edit location', 'counter' ) : esc_html__( 'New location', 'counter' ) ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cntr_location_save' );
		echo '<input type="hidden" name="cntr_location_action" value="save">';
		printf( '<input type="hidden" name="id" value="%d">', (int) $edit_id );
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th><label for="cntr-loc-name">%s</label></th><td><input type="text" id="cntr-loc-name" name="name" class="regular-text" value="%s" required></td></tr>', esc_html__( 'Name', 'counter' ), esc_attr( (string) ( $editing['name'] ?? '' ) ) );
		printf( '<tr><th><label for="cntr-loc-code">%s</label></th><td><input type="text" id="cntr-loc-code" name="code" class="regular-text" value="%s"></td></tr>', esc_html__( 'Code', 'counter' ), esc_attr( (string) ( $editing['code'] ?? '' ) ) );
		printf( '<tr><th><label for="cntr-loc-online">%s</label></th><td><input type="checkbox" id="cntr-loc-online" name="is_online_sellable" value="1"%s></td></tr>', esc_html__( 'Feeds the website\'s stock', 'counter' ), ! empty( $editing['is_online_sellable'] ) ? ' checked' : '' );
		printf( '<tr><th><label for="cntr-loc-default">%s</label></th><td><input type="checkbox" id="cntr-loc-default" name="is_default" value="1"%s></td></tr>', esc_html__( 'Default location', 'counter' ), ! empty( $editing['is_default'] ) ? ' checked' : '' );
		if ( $editing ) {
			printf(
				'<tr><th><label for="cntr-loc-status">%s</label></th><td><select id="cntr-loc-status" name="status"><option value="active"%s>%s</option><option value="inactive"%s>%s</option></select></td></tr>',
				esc_html__( 'Status', 'counter' ),
				selected( $editing['status'], 'active', false ),
				esc_html__( 'Active', 'counter' ),
				selected( $editing['status'], 'inactive', false ),
				esc_html__( 'Inactive', 'counter' )
			);
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save location', 'counter' ) . '</button></p>';
		echo '</form>';
	}

	/** @return array{ok:bool,message:string}|null */
	private static function handle_location_post(): ?array {
		if ( ! isset( $_POST['cntr_location_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			return null;
		}
		check_admin_referer( 'cntr_location_save' );

		$id   = absint( $_POST['id'] ?? 0 );
		$data = [
			'name'               => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'code'               => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ),
			'is_online_sellable' => ! empty( $_POST['is_online_sellable'] ),
			'is_default'         => ! empty( $_POST['is_default'] ),
		];
		if ( $id ) {
			$data['status'] = sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) );
		}

		$result = $id ? LocationsModel::update( $id, $data ) : LocationsModel::create( $data );
		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'message' => $result->get_error_message() ];
		}
		return [ 'ok' => true, 'message' => __( 'Location saved.', 'counter' ) ];
	}

	// -- Registers --------------------------------------------------------------------

	private static function render_registers(): void {
		$notice = self::handle_register_post();
		if ( $notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $notice['ok'] ? 'success' : 'error', esc_html( $notice['message'] ) );
		}

		$table = new RegistersListTable();
		$table->render_card_header();
		$table->prepare_items();
		$table->display();

		$locations = LocationsModel::all( 'active' );
		echo '<h2>' . esc_html__( 'New register', 'counter' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cntr_register_save' );
		echo '<input type="hidden" name="cntr_register_action" value="save">';
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th><label for="cntr-reg-name">%s</label></th><td><input type="text" id="cntr-reg-name" name="name" class="regular-text" required></td></tr>', esc_html__( 'Name', 'counter' ) );
		echo '<tr><th><label for="cntr-reg-location">' . esc_html__( 'Location', 'counter' ) . '</label></th><td><select id="cntr-reg-location" name="location_id">';
		foreach ( $locations as $l ) {
			printf( '<option value="%d">%s</option>', (int) $l['id'], esc_html( $l['name'] ) );
		}
		echo '</select></td></tr>';
		printf(
			'<tr><th><label for="cntr-reg-prefix">%s</label></th><td><input type="text" id="cntr-reg-prefix" name="prefix" class="regular-text" placeholder="%s"></td></tr>',
			esc_html__( 'Prefix', 'counter' ),
			esc_attr__( 'blank = generated automatically', 'counter' )
		);
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save register', 'counter' ) . '</button></p>';
		echo '</form>';
	}

	/** @return array{ok:bool,message:string}|null */
	private static function handle_register_post(): ?array {
		if ( ! isset( $_POST['cntr_register_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			return null;
		}
		check_admin_referer( 'cntr_register_save' );

		$result = Registers::create(
			[
				'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'location_id' => absint( $_POST['location_id'] ?? 0 ),
				'prefix'      => sanitize_text_field( wp_unslash( $_POST['prefix'] ?? '' ) ),
			]
		);
		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'message' => $result->get_error_message() ];
		}
		$register = Registers::get( $result );
		return [
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: the register's own prefix */
				__( 'Register saved — prefix %s. Bookmark /pos/?register=%d on that machine.', 'counter' ),
				$register['prefix'] ?? '',
				$result
			),
		];
	}
}

class LocationsListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$this->rows      = LocationsModel::all( '' );
		$this->add_url   = '#cntr-loc-name';
		$this->add_label = __( '+ Add location', 'counter' );
		parent::__construct( [ 'singular' => 'location', 'plural' => 'locations', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [ 'name' => __( 'Name', 'counter' ), 'code' => __( 'Code', 'counter' ), 'is_online_sellable' => __( 'Feeds website', 'counter' ), 'is_default' => __( 'Default', 'counter' ), 'status' => __( 'Status', 'counter' ), 'actions' => '' ];
	}

	public function get_filters(): array {
		return [];
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
		$this->items = $this->rows;
		$this->set_pagination_args( [ 'total_items' => count( $this->rows ), 'per_page' => count( $this->rows ) ?: 1 ] );
	}

	public function column_default( $item, $column_name ) {
		if ( 'is_online_sellable' === $column_name || 'is_default' === $column_name ) {
			return $item[ $column_name ] ? esc_html__( 'Yes', 'counter' ) : esc_html__( 'No', 'counter' );
		}
		if ( 'actions' === $column_name ) {
			return $this->build_row_actions(
				[ 'edit' => '<a href="' . esc_url( add_query_arg( [ 'page' => 'counter-locations', 'id' => $item['id'] ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Edit', 'counter' ) . '</a>' ]
			);
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}

class RegistersListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$this->rows = Registers::all( '' );
		parent::__construct( [ 'singular' => 'register', 'plural' => 'registers', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [ 'name' => __( 'Name', 'counter' ), 'prefix' => __( 'Prefix', 'counter' ), 'location' => __( 'Location', 'counter' ), 'status' => __( 'Status', 'counter' ), 'link' => '' ];
	}

	public function get_filters(): array {
		return [];
	}

	public function export_rows(): array {
		return $this->rows;
	}

	public function export_columns(): array {
		$cols = $this->get_columns();
		unset( $cols['link'] );
		return $cols;
	}

	public function prepare_items(): void {
		$this->items = $this->rows;
		$this->set_pagination_args( [ 'total_items' => count( $this->rows ), 'per_page' => count( $this->rows ) ?: 1 ] );
	}

	public function column_default( $item, $column_name ) {
		if ( 'location' === $column_name ) {
			$l = LocationsModel::get( (int) $item['location_id'] );
			return esc_html( $l['name'] ?? '' );
		}
		if ( 'link' === $column_name ) {
			return '<a href="' . esc_url( home_url( '/pos/?register=' . $item['id'] ) ) . '" target="_blank">' . esc_html__( 'Open till', 'counter' ) . '</a>';
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
