<?php
namespace Counter\Admin\Screens;

use Counter\Credit\CustomerLedger;

defined( 'ABSPATH' ) || exit;

/**
 * P2.6 — "the aging report is what the owner reads on a Sunday morning; the
 * statement is what settles an argument at the counter, and one without the
 * other is half a feature." One screen, two views: the aging list (every
 * customer with a non-zero balance, bucketed) and, per customer, a
 * chronological statement with a running balance and a @media print layout
 * simple enough to hand over or screenshot into a message — no iframe/ESC-POS
 * mechanism here, unlike Docs\Receipt: a statement is a normal page a shop
 * prints on whatever paper it already has, not a 79mm drawer-kick receipt.
 */
class Receivables {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Receivables', 'counter' ),
			__( 'Receivables', 'counter' ),
			'cntr_view_reports',
			'counter-receivables',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_reports' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view, no state change

		echo '<style>@media print { #adminmenumain, #wpadminbar, .wrap > h1, .wrap > form, #wpfooter { display: none !important; } #wpcontent, #wpbody-content { margin: 0 !important; } }</style>';

		if ( $customer_id ) {
			self::render_statement( $customer_id );
		} else {
			self::render_aging();
		}
	}

	private static function render_aging(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Receivables (Aging)', 'counter' ) . '</h1>';

		$aging = CustomerLedger::aging();
		if ( empty( $aging ) ) {
			echo '<p>' . esc_html__( 'No customer currently owes a balance.', 'counter' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'Customer', 'counter' ) . '</th>';
		foreach ( CustomerLedger::AGING_BUCKETS as $b ) {
			echo '<th>' . esc_html( self::bucket_label( $b ) ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Total', 'counter' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $aging as $cid => $row ) {
			$user = get_userdata( $cid );
			$name = $user ? ( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ) : sprintf( '#%d', $cid );
			echo '<tr>';
			echo '<td>' . esc_html( $name ) . '</td>';
			foreach ( CustomerLedger::AGING_BUCKETS as $b ) {
				echo '<td>' . esc_html( $row[ $b ] ) . '</td>';
			}
			echo '<td><strong>' . esc_html( $row['total'] ) . '</strong></td>';
			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url( add_query_arg( [ 'page' => 'counter-receivables', 'customer_id' => $cid ] ) ),
				esc_html__( 'Statement', 'counter' )
			);
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_statement( int $customer_id ): void {
		$statement = CustomerLedger::statement( $customer_id );

		echo '<div class="wrap">';
		printf( '<h1>%s — %s</h1>', esc_html__( 'Counter — Customer Statement', 'counter' ), esc_html( $statement['customer_name'] ) );
		printf(
			'<p><a href="%s">&larr; %s</a> &nbsp; <button class="button" onclick="window.print()">%s</button></p>',
			esc_url( remove_query_arg( 'customer_id' ) ),
			esc_html__( 'Back to aging', 'counter' ),
			esc_html__( 'Print', 'counter' )
		);

		if ( empty( $statement['rows'] ) ) {
			echo '<p>' . esc_html__( 'No transactions for this customer.', 'counter' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Running balance', 'counter' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $statement['rows'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $row['type'] ) . '</td>';
			echo '<td>' . esc_html( $row['note'] ) . '</td>';
			echo '<td>' . esc_html( $row['amount'] ) . '</td>';
			echo '<td>' . esc_html( $row['balance_after'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		printf( '<p><strong>%s: ৳%s</strong></p>', esc_html__( 'Open balance', 'counter' ), esc_html( $statement['open_balance'] ) );
		echo '</div>';
	}

	private static function bucket_label( string $b ): string {
		return match ( $b ) {
			'current'  => __( 'Current', 'counter' ),
			'd1_30'    => __( '1–30', 'counter' ),
			'd31_60'   => __( '31–60', 'counter' ),
			'd61_90'   => __( '61–90', 'counter' ),
			'd90_plus' => __( '90+', 'counter' ),
			default    => $b,
		};
	}
}
