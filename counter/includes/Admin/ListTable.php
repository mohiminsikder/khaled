<?php
namespace Counter\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * C1 — the shared anatomy every back-office list screen from here on is
 * built from, learned once (docs/COUNTERV2.md Phase C intro):
 *
 *   Filters (a collapsed accordion) -> Card header (title left, + Add
 *   right) -> Toolbar (show N, exports, search) -> Rows (one Actions menu
 *   per row) -> Footer (totals on financial tables, then paging).
 *
 * Extends \WP_List_Table rather than reinventing paging/sorting — those
 * already work (core reads $_GET['paged']/['orderby']/['order']); Counter's
 * own value-add here is the filter accordion and the cntr_export-gated CSV
 * export, not a second pagination implementation. A subclass still writes
 * its own prepare_items() (core's own contract) — this class only adds
 * what core doesn't have.
 *
 * Teardown defect #14: every export button in SuperShop renders
 * permanently disabled, gated behind a permission not granted even to
 * admin. cntr_export is granted by role from A4; can_export() is checked
 * BOTH for whether the button appears at all and, separately and
 * authoritatively, before the export itself ever streams a byte — the
 * button's absence is a courtesy, the capability check is the actual
 * boundary.
 */
abstract class ListTable extends \WP_List_Table {

	/**
	 * @return array<string,array{label:string,type:string,options?:array<string,string>}>
	 * key => filter definition. type is 'select', 'date', or 'text'.
	 */
	abstract public function get_filters(): array;

	/** Every row matching the CURRENT filters, unpaginated — the export's own source, same filtered query prepare_items() itself runs, just without the LIMIT. */
	abstract public function export_rows(): array;

	/** column key => header label, for the CSV's own header row — usually get_columns() minus any checkbox/actions column, which a CSV has no use for. */
	abstract public function export_columns(): array;

	protected string $add_url          = '';
	protected string $add_label        = '';
	protected bool $show_totals_footer = false;

	/**
	 * The round-trip surface: read once here, rendered back into the filter
	 * form by render_filters_accordion() and folded into the export link by
	 * render_toolbar() — the SAME values every time, so a GET request
	 * carrying these params always reproduces this exact filter state
	 * (never a filter silently reset on the next render).
	 *
	 * @return array<string,string>
	 */
	public function filter_values(): array {
		$values = [];
		foreach ( $this->get_filters() as $key => $def ) {
			$values[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state, not a state change
		}
		return $values;
	}

	public function render_filters_accordion(): void {
		$filters = $this->get_filters();
		if ( empty( $filters ) ) {
			return;
		}
		$values = $this->filter_values();
		$active = count( array_filter( $values ) );

		echo '<details class="cntr-list-filters"' . ( $active ? ' open' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup
		printf(
			'<summary>%s%s</summary>',
			esc_html__( 'Filters', 'counter' ),
			$active ? ' (' . (int) $active . ')' : ''
		);
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( (string) ( $_GET['page'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		foreach ( $filters as $key => $def ) {
			$value = $values[ $key ];
			printf( '<label>%s ', esc_html( $def['label'] ) );
			if ( 'select' === ( $def['type'] ?? '' ) ) {
				printf( '<select name="%s"><option value="">%s</option>', esc_attr( $key ), esc_html__( 'All', 'counter' ) );
				foreach ( (array) ( $def['options'] ?? [] ) as $opt_value => $opt_label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( (string) $opt_value ),
						selected( $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
			} elseif ( 'date' === ( $def['type'] ?? '' ) ) {
				printf( '<input type="date" name="%s" value="%s">', esc_attr( $key ), esc_attr( $value ) );
			} else {
				printf( '<input type="text" name="%s" value="%s">', esc_attr( $key ), esc_attr( $value ) );
			}
			echo '</label> ';
		}
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Apply', 'counter' ) );
		if ( $active ) {
			printf(
				' <a class="button-link" href="%s">%s</a>',
				esc_url( remove_query_arg( array_keys( $filters ) ) ),
				esc_html__( 'Clear', 'counter' )
			);
		}
		echo '</form></details>';
	}

	protected function get_screen_title(): string {
		return (string) ( $this->_args['plural'] ?? '' );
	}

	public function render_card_header(): void {
		echo '<div class="cntr-list-header">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $this->get_screen_title() ) . '</h1>';
		if ( $this->add_url ) {
			printf(
				'<a href="%s" class="page-title-action">%s</a>',
				esc_url( $this->add_url ),
				esc_html( $this->add_label ?: __( '+ Add', 'counter' ) )
			);
		}
		echo '</div>';
	}

	/** The authoritative boundary — checked here once, both for the export button's own presence and, separately, before maybe_handle_export() ever streams anything. */
	public function can_export(): bool {
		return current_user_can( 'cntr_export' );
	}

	/** Just the export control's own markup — kept separate from render_toolbar() (which also calls $this->pagination(), a real wp-admin screen concern) so a self-test can check button visibility without needing a live admin screen. */
	public function render_export_button(): string {
		if ( ! $this->can_export() ) {
			return '';
		}
		$export_url = add_query_arg( array_merge( $this->filter_values(), [ 'cntr_export' => 'csv' ] ) );
		return sprintf(
			'<a href="%s" class="button cntr-list-export">%s</a>',
			esc_url( $export_url ),
			esc_html__( 'Export CSV', 'counter' )
		);
	}

	public function render_toolbar(): void {
		echo '<div class="cntr-list-toolbar tablenav top">';
		echo $this->render_export_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_export_button()
		$this->pagination( 'top' );
		echo '<br class="clear"></div>';
	}

	protected function get_export_filename(): string {
		return sanitize_file_name( sanitize_key( (string) ( $this->_args['plural'] ?? 'export' ) ) . '-' . wp_date( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Streams a CSV of export_rows() and exits — called from the screen's
	 * own render(), BEFORE any HTML output, the same "this request is
	 * actually a file download" shape every other export in this codebase
	 * already uses (Screens\VatExports, Screens\OutboxFailures). Refuses
	 * via wp_die() without cntr_export — can_export() is the boundary this
	 * only enforces; deliberately never called from a self-test (wp_die()
	 * halts a real WordPress process), which is exactly why can_export()
	 * exists as its own testable predicate.
	 */
	public function maybe_handle_export(): void {
		if ( ! isset( $_GET['cntr_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export, no state change
			return;
		}
		if ( ! $this->can_export() ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ), '', [ 'response' => 403 ] );
		}

		$columns = $this->export_columns();
		$rows    = $this->export_rows();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->get_export_filename() . '"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_values( $columns ) );
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( array_keys( $columns ) as $key ) {
				$line[] = $row[ $key ] ?? '';
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	/** Financial tables show a totals row before pagination — a subclass opts in via $show_totals_footer and overrides this. */
	public function get_totals_row(): array {
		return [];
	}

	public function render_footer_totals(): void {
		if ( ! $this->show_totals_footer ) {
			return;
		}
		$totals = $this->get_totals_row();
		if ( empty( $totals ) ) {
			return;
		}
		echo '<tfoot class="cntr-list-totals"><tr>';
		foreach ( array_keys( $this->get_columns() ) as $col ) {
			printf( '<td>%s</td>', esc_html( (string) ( $totals[ $col ] ?? '' ) ) );
		}
		echo '</tr></tfoot>';
	}

	/** One Actions menu per row — a thin name for core's own row_actions(), so every subclass reaches for the same method name rather than half of them calling $this->row_actions() directly and half inventing their own. */
	protected function build_row_actions( array $actions ): string {
		return $this->row_actions( $actions );
	}
}
