<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Purchasing\Suppliers as SuppliersModel;
use Counter\Purchasing\SupplierLedger;
use Counter\Pos\Accounts;

defined( 'ABSPATH' ) || exit;

/**
 * C3 — the vendor master. Backend is none: Purchasing\Suppliers already
 * does create/update/deactivate and the near-duplicate check (the
 * blueprint's own akij/AKIZ/Masaf finding); this screen is the form over
 * it, plus a per-supplier ledger view (balance, statement, record a
 * payment/credit note) over Purchasing\SupplierLedger.
 */
class Suppliers {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Suppliers', 'counter' ),
			__( 'Suppliers', 'counter' ),
			'cntr_manage_purchasing',
			'counter-suppliers',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_purchasing' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Suppliers', 'counter' ) . '</h1>';

		if ( 'ledger' === $view ) {
			self::render_ledger_view();
		} elseif ( 'edit' === $view ) {
			self::render_edit_view();
		} else {
			$table = new SuppliersListTable();
			$table->maybe_handle_export();
			self::render_notice();
			$table->render_card_header();
			$table->prepare_items();
			echo '<form method="get">';
			echo '<input type="hidden" name="page" value="counter-suppliers">';
			$table->render_toolbar();
			$table->display();
			echo '</form>';
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$notice = get_transient( 'cntr_suppliers_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'cntr_suppliers_notice_' . get_current_user_id() );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$notice['ok'] ? 'success' : 'error',
			esc_html( $notice['message'] )
		);
	}

	private static function set_notice( bool $ok, string $message ): void {
		set_transient( 'cntr_suppliers_notice_' . get_current_user_id(), [ 'ok' => $ok, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private static function render_edit_view(): void {
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$existing = $id ? SuppliersModel::get( $id ) : null;
		$duplicate_warning = null;

		if ( isset( $_POST['cntr_supplier_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_supplier_save' );
			$data = [
				'name'            => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'phone'           => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				'email'           => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
				'bin'             => sanitize_text_field( wp_unslash( $_POST['bin'] ?? '' ) ),
				'address'         => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
				'terms_days'      => absint( $_POST['terms_days'] ?? 0 ),
				'opening_balance' => sanitize_text_field( wp_unslash( $_POST['opening_balance'] ?? '0' ) ),
			];
			$confirm = ! empty( $_POST['confirm_duplicate'] );

			$result = $id ? SuppliersModel::update( $id, $data, $confirm ) : SuppliersModel::create( $data, $confirm );

			if ( is_wp_error( $result ) ) {
				if ( 'cntr_supplier_duplicate' === $result->get_error_code() ) {
					$duplicate_warning = $result->get_error_data()['existing'] ?? null;
					$existing          = $existing ?: $data; // repopulate the form with what was just typed
					$existing['id']    = $id;
				} else {
					self::set_notice( false, $result->get_error_message() );
				}
			} else {
				$warning = is_array( $result ) ? ( $result['warning'] ?? null ) : null;
				self::set_notice(
					true,
					$warning
						? sprintf(
							/* translators: %s: the similarly-named existing supplier */
							__( 'Supplier saved. Note: a similarly-named supplier already exists (%s).', 'counter' ),
							$warning['name']
						)
						: __( 'Supplier saved.', 'counter' )
				);
				wp_safe_redirect( admin_url( 'admin.php?page=counter-suppliers' ) );
				exit;
			}
		}

		echo '<h2>' . ( $id ? esc_html__( 'Edit supplier', 'counter' ) : esc_html__( 'New supplier', 'counter' ) ) . '</h2>';

		if ( $duplicate_warning ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: %s: the similarly-named existing supplier's name */
					esc_html__( 'A similarly-named supplier already exists: "%s". Save again to confirm this is a different supplier.', 'counter' ),
					esc_html( $duplicate_warning['name'] )
				)
			);
		}

		echo '<form method="post">';
		wp_nonce_field( 'cntr_supplier_save' );
		echo '<input type="hidden" name="cntr_supplier_action" value="save">';
		if ( $duplicate_warning ) {
			echo '<input type="hidden" name="confirm_duplicate" value="1">';
		}
		echo '<table class="form-table"><tbody>';
		self::field_row( 'name', __( 'Name', 'counter' ), (string) ( $existing['name'] ?? '' ), true );
		self::field_row( 'phone', __( 'Phone', 'counter' ), (string) ( $existing['phone'] ?? '' ) );
		self::field_row( 'email', __( 'Email', 'counter' ), (string) ( $existing['email'] ?? '' ) );
		self::field_row( 'bin', __( 'BIN', 'counter' ), (string) ( $existing['bin'] ?? '' ) );
		self::textarea_row( 'address', __( 'Address', 'counter' ), (string) ( $existing['address'] ?? '' ) );
		self::field_row( 'terms_days', __( 'Payment terms (days)', 'counter' ), (string) ( $existing['terms_days'] ?? '0' ), false, 'number' );
		if ( ! $id ) {
			self::field_row( 'opening_balance', __( 'Opening balance (৳ owed)', 'counter' ), '0.00', false, 'text' );
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save supplier', 'counter' ) . '</button></p>';
		echo '</form>';
	}

	private static function field_row( string $name, string $label, string $value, bool $required = false, string $type = 'text' ): void {
		printf(
			'<tr><th><label for="cntr-sup-%1$s">%2$s</label></th><td><input type="%3$s" id="cntr-sup-%1$s" name="%1$s" class="regular-text" value="%4$s"%5$s></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$required ? ' required' : ''
		);
	}

	private static function textarea_row( string $name, string $label, string $value ): void {
		printf(
			'<tr><th><label for="cntr-sup-%1$s">%2$s</label></th><td><textarea id="cntr-sup-%1$s" name="%1$s" class="large-text" rows="3">%3$s</textarea></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( $value )
		);
	}

	private static function render_ledger_view(): void {
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$supplier = $id ? SuppliersModel::get( $id ) : null;
		if ( ! $supplier ) {
			echo '<p>' . esc_html__( 'Supplier not found.', 'counter' ) . '</p>';
			return;
		}

		if ( isset( $_POST['cntr_ledger_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_ledger_action' );
			if ( ! current_user_can( 'cntr_pay_supplier' ) ) {
				wp_die( esc_html__( 'Not permitted.', 'counter' ) );
			}
			$action = sanitize_key( wp_unslash( $_POST['cntr_ledger_action'] ) );
			$amount = sanitize_text_field( wp_unslash( $_POST['amount'] ?? '0' ) );
			$note   = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

			$result = 'payment' === $action
				? SupplierLedger::record_payment( $id, $amount, absint( $_POST['account_id'] ?? 0 ), $note )
				: SupplierLedger::record_credit_note( $id, $amount, $note );

			self::set_notice( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Recorded.', 'counter' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=counter-suppliers&view=ledger&id=' . $id ) );
			exit;
		}

		self::render_notice();

		echo '<h2>' . esc_html( $supplier['name'] ) . '</h2>';
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Balance owed:', 'counter' ), wc_price( SupplierLedger::balance( $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price() escapes its own output

		if ( current_user_can( 'cntr_pay_supplier' ) ) {
			$accounts = Accounts::all( 'active' );
			echo '<h3>' . esc_html__( 'Record a payment or credit note', 'counter' ) . '</h3>';
			echo '<form method="post">';
			wp_nonce_field( 'cntr_ledger_action' );
			echo '<table class="form-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Type', 'counter' ) . '</th><td>';
			echo '<select name="cntr_ledger_action"><option value="payment">' . esc_html__( 'Payment', 'counter' ) . '</option><option value="credit_note">' . esc_html__( 'Credit note', 'counter' ) . '</option></select>';
			echo '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Amount', 'counter' ) . '</th><td><input type="text" name="amount" value="0.00"></td></tr>';
			echo '<tr><th>' . esc_html__( 'Paid from', 'counter' ) . '</th><td><select name="account_id">';
			foreach ( $accounts as $a ) {
				printf( '<option value="%d">%s</option>', (int) $a['id'], esc_html( $a['name'] ) );
			}
			echo '</select> <span class="description">' . esc_html__( '(payments only)', 'counter' ) . '</span></td></tr>';
			echo '<tr><th>' . esc_html__( 'Note', 'counter' ) . '</th><td><input type="text" name="note" class="regular-text"></td></tr>';
			echo '</tbody></table>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Record', 'counter' ) . '</button></p>';
			echo '</form>';
		}

		echo '<h3>' . esc_html__( 'Statement', 'counter' ) . '</h3>';
		$rows = SupplierLedger::ledger( $id );
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Date', 'counter' ) . '</th><th>' . esc_html__( 'Type', 'counter' ) . '</th><th>' . esc_html__( 'Amount', 'counter' ) . '</th><th>' . esc_html__( 'Balance', 'counter' ) . '</th><th>' . esc_html__( 'Note', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r['created_at'] ),
				esc_html( $r['type'] ),
				wc_price( $r['amount'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price() escapes its own output
				wc_price( $r['balance_after'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $r['note'] )
			);
		}
		echo '</tbody></table>';
	}
}

class SuppliersListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$rows       = SuppliersModel::all( '' );
		$this->rows = array_map(
			static function ( $s ) {
				$s['balance'] = SupplierLedger::balance( (int) $s['id'] );
				return $s;
			},
			$rows
		);
		$this->add_url   = admin_url( 'admin.php?page=counter-suppliers&view=edit' );
		$this->add_label = __( '+ Add supplier', 'counter' );
		parent::__construct( [ 'singular' => 'supplier', 'plural' => 'suppliers', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'name'       => __( 'Name', 'counter' ),
			'phone'      => __( 'Phone', 'counter' ),
			'email'      => __( 'Email', 'counter' ),
			'terms_days' => __( 'Terms (days)', 'counter' ),
			'balance'    => __( 'Balance owed', 'counter' ),
			'status'     => __( 'Status', 'counter' ),
			'actions'    => '',
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
		$status = $this->filter_values()['status'] ?? '';
		return $status ? array_values( array_filter( $this->rows, static fn( $r ) => $r['status'] === $status ) ) : $this->rows;
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
		if ( 'balance' === $column_name ) {
			return wc_price( $item['balance'] );
		}
		if ( 'actions' === $column_name ) {
			return $this->build_row_actions(
				[
					'edit'   => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-suppliers&view=edit&id=' . $item['id'] ) ) . '">' . esc_html__( 'Edit', 'counter' ) . '</a>',
					'ledger' => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-suppliers&view=ledger&id=' . $item['id'] ) ) . '">' . esc_html__( 'Ledger', 'counter' ) . '</a>',
				]
			);
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
