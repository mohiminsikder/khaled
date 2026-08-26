<?php
namespace Counter\Pos;

defined( 'ABSPATH' ) || exit;

/**
 * Cache-buster: pos.js/pos.css are enqueued with CNTR_VERSION as the version
 * argument. Bump it on every rebuild, not every feature — otherwise new PHP runs
 * against cached old JavaScript, which presents as a failed fix and costs an hour
 * on a problem that was never there.
 *
 * The /pos/ terminal route itself is P1.13's work; this module only decides when
 * to load the assets once that route exists, via the cntr_is_pos_screen filter.
 */
class Assets {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'maybe_enqueue' ] );
	}

	public static function maybe_enqueue(): void {
		if ( ! apply_filters( 'cntr_is_pos_screen', false ) ) {
			return;
		}
		wp_enqueue_script( 'cntr-pos', CNTR_URL . 'assets/pos.js', [], CNTR_VERSION, true );
		wp_enqueue_style( 'cntr-pos', CNTR_URL . 'assets/pos.css', [], CNTR_VERSION );
	}
}
