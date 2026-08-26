<?php
namespace Counter\Pos;

defined( 'ABSPATH' ) || exit;

/**
 * P7.1 — serves `assets/sw.js` from the SITE ROOT (`/counter-sw.js`), not
 * from its own on-disk location under `/wp-content/plugins/counter/assets/`.
 * A service worker's default scope is the directory it is served FROM; the
 * file physically lives under `assets/`, but it needs to control `/pos/`,
 * which is outside that directory — serving it from root sidesteps the
 * whole `Service-Worker-Allowed` header question entirely (its scope
 * simply covers the whole origin, `/pos/` included) rather than depending
 * on every host correctly forwarding a custom response header.
 *
 * `build_response()` is the testable core — returns the MIME type, scope
 * and body as plain data, never touches the real header stack itself, so
 * `test_sw()` can assert on the exact values without needing a live HTTP
 * round trip (PHP CLI's `headers_list()` never reflects `header()` calls —
 * there is no real HTTP response for them to attach to under that SAPI,
 * which is exactly the SAPI `wp eval`/`wp eval-file` run under). `maybe_serve()`
 * is the thin route wrapper that actually calls `header()` and `exit`s —
 * same split as `Rest\Sale::process()`/`handle()`.
 */
class ServiceWorker {

	public static function init(): void {
		add_action( 'init', [ self::class, 'add_rewrite' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_var' ] );
		add_action( 'template_redirect', [ self::class, 'maybe_serve' ] );
		// Found live: WordPress's own redirect_canonical() 301-redirects
		// /counter-sw.js to /counter-sw.js/ — it has no way to know this
		// query var names a FILE, not a page, and "correcting" a .js URL
		// with a trailing slash is simply wrong. redirect_canonical() runs
		// on template_redirect too, and ahead of maybe_serve() below by
		// default priority, so without this filter the redirect fires and
		// exits before this class ever gets a chance to serve anything.
		add_filter( 'redirect_canonical', [ self::class, 'skip_canonical_redirect' ] );
	}

	public static function skip_canonical_redirect( $redirect_url ) {
		return get_query_var( 'cntr_sw' ) ? false : $redirect_url;
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^counter-sw\.js$', 'index.php?cntr_sw=1', 'top' );
	}

	public static function add_query_var( array $vars ): array {
		$vars[] = 'cntr_sw';
		return $vars;
	}

	public static function maybe_serve(): void {
		if ( ! get_query_var( 'cntr_sw' ) ) {
			return;
		}
		$response = self::build_response();
		nocache_headers();
		header( 'Content-Type: ' . $response['content_type'] );
		// Redundant once served from root (root's own scope already covers
		// everything), kept anyway as a second, independent guarantee if
		// this route is ever moved back under assets/ — a scope header
		// that is unnecessary costs nothing; a missing one that turns out
		// to be needed breaks the whole feature silently.
		header( 'Service-Worker-Allowed: ' . $response['scope'] );
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- raw application/javascript body, not HTML
		exit;
	}

	/** @return array{content_type:string,scope:string,body:string} */
	public static function build_response(): array {
		return [
			'content_type' => 'application/javascript; charset=utf-8',
			'scope'        => '/',
			'body'         => (string) file_get_contents( CNTR_DIR . 'assets/sw.js' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin asset, not a remote URL
		];
	}
}
