<?php
namespace Counter\Rest;

use Counter\Db;
use Counter\Audit;
use Counter\Settings;
use Counter\Stock\Ledger;
use Counter\Stock\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * P2.2 — a correction is a new ledger move with a reason, never an edited
 * balance (same discipline as every other write path). handle() is a thin
 * REST wrapper; process() is the directly-testable core the self-test calls.
 */
class Stock {

	/** Reasons a MANUAL adjustment may carry — the closed list the Direction
	 * calls for. Every other Ledger::REASONS value (sale*, return, purchase,
	 * transfer_*, stocktake, opening) is written by its own subsystem, never
	 * by a human typing a correction here. */
	const REASONS = [ 'adjust', 'waste', 'damage' ];

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/stock/adjust',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => Router::guard( 'cntr_adjust_stock' ),
				'args'                => [
					'uuid'         => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && '' !== trim( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'product_id'   => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'variation_id' => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'location_id'  => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'qty_delta'    => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'reason'       => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'note'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);
	}

	public static function handle( \WP_REST_Request $req ) {
		$result = self::process(
			(string) $req->get_param( 'uuid' ),
			(int) $req->get_param( 'product_id' ),
			(int) $req->get_param( 'variation_id' ),
			(int) $req->get_param( 'location_id' ),
			(string) $req->get_param( 'qty_delta' ),
			(string) $req->get_param( 'reason' ),
			(string) $req->get_param( 'note' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @return array{move_id:int,before:string,after:string,flagged_negative:bool,replayed:bool}|\WP_Error
	 */
	public static function process(
		string $uuid,
		int $product_id,
		int $variation_id,
		int $location_id,
		string $qty_delta,
		string $reason,
		string $note = '',
		int $user_id = 0
	) {
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return new \WP_Error(
				'cntr_adjust_bad_reason',
				__( 'Reason must be one of: adjust, waste, damage.', 'counter' ),
				[ 'status' => 400 ]
			);
		}

		$uuid = trim( $uuid );
		if ( '' === $uuid ) {
			return new \WP_Error( 'cntr_adjust_no_uuid', __( 'A client UUID is required.', 'counter' ), [ 'status' => 400 ] );
		}

		$delta = wc_format_decimal( $qty_delta, 4 );
		if ( bccomp( $delta, '0', 4 ) === 0 ) {
			return new \WP_Error( 'cntr_adjust_zero', __( 'The adjustment quantity cannot be zero.', 'counter' ), [ 'status' => 400 ] );
		}

		// Idempotency (non-negotiable 6): an INSERT against wp_options' own
		// UNIQUE KEY on option_name is a genuinely atomic insert-if-not-exists —
		// add_option() returns false the instant a second racing request tries
		// the same uuid, same mechanism WordPress itself relies on. No dedicated
		// table for this task (the plan lists none), so this reuses core rather
		// than inventing a parallel one — the same principle Stock\Reserve
		// applies to WooCommerce's own reservation table.
		$option_name = 'cntr_adj_uuid_' . sanitize_key( $uuid );
		$existing    = get_option( $option_name, false );
		if ( false !== $existing ) {
			$replay             = json_decode( (string) $existing, true );
			$replay['replayed'] = true;
			return $replay;
		}

		$unit_cost = self::cost_for( $product_id, $variation_id );
		$value     = bcmul( self::abs_decimal( $delta ), $unit_cost, 4 );
		$threshold = (string) Settings::get( 'stock.adjustment_note_threshold', 0 );

		if ( bccomp( $value, $threshold, 4 ) > 0 && '' === trim( $note ) ) {
			return new \WP_Error(
				'cntr_adjust_note_required',
				__( 'A note is required for an adjustment of this value.', 'counter' ),
				[ 'status' => 400 ]
			);
		}

		$before = Ledger::balance( $product_id, $variation_id, $location_id );

		$move_id = Db::transaction(
			function () use ( $product_id, $variation_id, $location_id, $delta, $reason, $unit_cost, $note, $user_id ) {
				$id = Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'location_id'  => $location_id,
						'qty_delta'    => $delta,
						'unit_cost'    => $unit_cost,
						'reason'       => $reason,
						'ref_type'     => 'adjustment',
						'ref_id'       => 0,
						'note'         => $note,
						'user_id'      => $user_id,
					]
				);
				Publisher::publish( $product_id, $variation_id );
				return $id;
			}
		);

		$after            = Ledger::balance( $product_id, $variation_id, $location_id );
		$flagged_negative = bccomp( $after, '0', 4 ) < 0;

		Audit::log(
			'stock_adjust',
			'product',
			$variation_id ?: $product_id,
			[ 'balance' => $before ],
			[
				'balance'          => $after,
				'reason'           => $reason,
				'note'             => $note,
				'flagged_negative' => $flagged_negative,
			],
			$user_id
		);

		$result = [
			'move_id'          => $move_id,
			'before'           => $before,
			'after'            => $after,
			'flagged_negative' => $flagged_negative,
			'replayed'         => false,
		];

		// autoload=false — adjustments are infrequent compared to sales, but no
		// reason to make every page load carry them regardless.
		add_option( $option_name, wp_json_encode( $result ), '', 'no' );

		return $result;
	}

	/** Same stub every other task uses until P2.8's real FIFO cost lands. */
	private static function cost_for( int $product_id, int $variation_id ): string {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) {
			return '0';
		}
		$price = $product->get_meta( '_purchase_price' );
		return ( '' !== $price && null !== $price ) ? (string) $price : '0';
	}

	private static function abs_decimal( string $v ): string {
		return ( bccomp( $v, '0', 4 ) < 0 ) ? bcmul( $v, '-1', 4 ) : $v;
	}
}
