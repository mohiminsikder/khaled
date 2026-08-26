<?php
namespace Counter\Rest;

use Counter\People\Leave as LeaveClass;
use Counter\People\Employees;

defined( 'ABSPATH' ) || exit;

/**
 * P6.3 — the three-way split enforced at THIS layer, not inside
 * People\Leave itself (which stays capability-agnostic, same separation
 * as every other business-logic class in this plugin): request() only
 * ever acts on the CALLER's own employee record, never a client-supplied
 * one — that is what "a junior can raise leave without seeing anyone
 * else's" actually means in a REST surface a junior can call directly.
 */
class Leave {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/leave/request',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'request' ],
				'permission_callback' => Router::guard( 'read' ), // any signed-in user; the real gate below is "do you have your own employee record"
				'args'                => [
					'type'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'from_date' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'to_date'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'half_day'  => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
					'reason'    => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
		register_rest_route(
			$ns,
			'/leave/mine',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'mine' ],
				'permission_callback' => Router::guard( 'read' ),
			]
		);
		register_rest_route(
			$ns,
			'/leave/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'cancel' ],
				'permission_callback' => Router::guard( 'read' ), // ownership vs. cntr_approve_leave checked in the controller, same reasoning as Shift::close()
				'args'                => [
					'leave_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		register_rest_route(
			$ns,
			'/leave/pending',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'pending' ],
				'permission_callback' => Router::guard( 'cntr_approve_leave' ),
			]
		);
		register_rest_route(
			$ns,
			'/leave/approve',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'approve' ],
				'permission_callback' => Router::guard( 'cntr_approve_leave' ),
				'args'                => [
					'leave_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
		register_rest_route(
			$ns,
			'/leave/reject',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'reject' ],
				'permission_callback' => Router::guard( 'cntr_approve_leave' ),
				'args'                => [
					'leave_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'reason'   => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
		register_rest_route(
			$ns,
			'/leave/all',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'all' ],
				'permission_callback' => Router::guard( 'cntr_view_leave' ),
			]
		);
	}

	private static function callers_employee(): ?array {
		return Employees::get_by_user( get_current_user_id() );
	}

	public static function request( \WP_REST_Request $req ) {
		$employee = self::callers_employee();
		if ( null === $employee ) {
			return new \WP_Error( 'cntr_leave_not_employee', __( 'You have no employee record to request leave against.', 'counter' ), [ 'status' => 403 ] );
		}
		$result = LeaveClass::request(
			[
				'employee_id' => $employee['id'],
				'type'        => (string) $req->get_param( 'type' ),
				'from_date'   => (string) $req->get_param( 'from_date' ),
				'to_date'     => (string) $req->get_param( 'to_date' ),
				'half_day'    => (bool) $req->get_param( 'half_day' ),
				'reason'      => (string) $req->get_param( 'reason' ),
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'leave_id' => $result ] );
	}

	public static function mine( \WP_REST_Request $req ) {
		$employee = self::callers_employee();
		if ( null === $employee ) {
			return rest_ensure_response( [] );
		}
		return rest_ensure_response( LeaveClass::for_employee( $employee['id'] ) );
	}

	/** Cancel your own request, or (holding cntr_approve_leave) anyone's. */
	public static function cancel( \WP_REST_Request $req ) {
		$leave_id = (int) $req->get_param( 'leave_id' );
		$row      = LeaveClass::get( $leave_id );
		if ( null === $row ) {
			return new \WP_Error( 'cntr_leave_missing', __( 'Leave request not found.', 'counter' ), [ 'status' => 404 ] );
		}
		$employee = self::callers_employee();
		$is_own   = $employee && (int) $employee['id'] === (int) $row['employee_id'];
		if ( ! $is_own && ! current_user_can( 'cntr_approve_leave' ) ) {
			return new \WP_Error( 'cntr_leave_forbidden', __( 'You may not cancel this request.', 'counter' ), [ 'status' => 403 ] );
		}
		$result = LeaveClass::cancel( $leave_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'cancelled' => true ] );
	}

	public static function pending( \WP_REST_Request $req ) {
		return rest_ensure_response( LeaveClass::pending_for_approver( get_current_user_id() ) );
	}

	public static function approve( \WP_REST_Request $req ) {
		$result = LeaveClass::approve( (int) $req->get_param( 'leave_id' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'approved' => true ] );
	}

	public static function reject( \WP_REST_Request $req ) {
		$result = LeaveClass::reject( (int) $req->get_param( 'leave_id' ), get_current_user_id(), (string) $req->get_param( 'reason' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'rejected' => true ] );
	}

	public static function all( \WP_REST_Request $req ) {
		return rest_ensure_response( LeaveClass::all() );
	}
}
