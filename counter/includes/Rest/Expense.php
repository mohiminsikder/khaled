<?php
namespace Counter\Rest;

use Counter\Reports\Expenses;
use Counter\Pos\Accounts;
use Counter\Pos\Shifts;

defined( 'ABSPATH' ) || exit;

/**
 * B7 — "the cashier never leaves the till for the two things that
 * interrupt a shift": recording a business expense is one of them. A
 * thin wrapper over Reports\Expenses::create() (P5.4, unchanged) — the
 * only logic added here is the cash-drawer tie-in: an expense paid from
 * a DRAWER account (is_drawer = 1 — CASH, and only CASH, today) also
 * writes the same cntr_shift_events 'cash_out' row a manual till payout
 * already would, so Pos\Shifts::x_report()'s own expense total (B5)
 * moves when a cashier logs one — expense_total reads shift_events
 * exclusively, never cntr_expenses directly, so without this an
 * expense entered here would be correctly booked for the P&L but
 * invisible to the till's own drawer reconciliation. An expense paid
 * from a non-drawer account (bank/mobile wallet) never touches
 * shift_events at all: no physical cash left this drawer.
 *
 * Gated on cntr_use_pos/cntr_terminal_access — the same bar as every
 * other till action — not the broader cntr_manage_expenses (the
 * wp-admin Expenses screen's own capability, covering categories,
 * recurring rules, and confirming drafts): a till expense is one
 * already-confirmed, already-categorised entry a cashier logs in the
 * moment, not shop-wide expense administration.
 */
class Expense {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/expense',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'create' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ], true ),
				'args'                => [
					'location_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'category_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'account_id'  => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'amount'      => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'spent_on'    => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'channel'     => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'note'        => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					// Only used for the cash-drawer tie-in above — an
					// expense logged with no open shift (should not
					// normally happen at a real till) simply skips it,
					// same as every other shift-scoped write refuses
					// gracefully rather than guessing which shift.
					'shift_id'    => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public static function create( \WP_REST_Request $req ) {
		$account_id = (int) $req->get_param( 'account_id' );
		$amount     = (string) $req->get_param( 'amount' );
		$note       = (string) $req->get_param( 'note' );

		$result = Expenses::create(
			[
				'location_id' => (int) $req->get_param( 'location_id' ),
				'category_id' => (int) $req->get_param( 'category_id' ),
				'account_id'  => $account_id,
				'amount'      => $amount,
				'spent_on'    => (string) $req->get_param( 'spent_on' ),
				'channel'     => (string) $req->get_param( 'channel' ),
				'note'        => $note,
				'user_id'     => get_current_user_id(),
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$shift_id = (int) $req->get_param( 'shift_id' );
		$account  = Accounts::get( $account_id );
		if ( $shift_id && $account && ! empty( $account['is_drawer'] ) ) {
			Shifts::record_event( $shift_id, 'cash_out', $amount, $note ?: __( 'Expense', 'counter' ), get_current_user_id() );
		}

		return rest_ensure_response( [ 'expense_id' => $result ] );
	}
}
