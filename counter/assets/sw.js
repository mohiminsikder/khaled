/**
 * Counter POS — shell cache. Caches the terminal shell (the /pos/ page,
 * pos.js, pos.css) so the till survives a dropped connection. The product
 * catalogue is already in IndexedDB (P1.7) — this worker never touches it,
 * and never intercepts the REST API or anything outside its own three
 * files.
 *
 * Served from the site root (includes/Pos/ServiceWorker.php), not from
 * this file's own /wp-content/plugins/counter/assets/ location — a
 * service worker's default scope is the directory it is served FROM, and
 * this one needs to control /pos/, one level up from where the file
 * itself actually lives on disk.
 *
 * No external host anywhere in this file — the terminal must run
 * offline, so nothing here may depend on one being reachable.
 */

const CACHE_NAME = 'cntr-pos-shell-v1';

const PRECACHE_URLS = [
	'/pos/',
	'/wp-content/plugins/counter/assets/pos.js',
	'/wp-content/plugins/counter/assets/pos.css',
];

self.addEventListener( 'install', ( event ) => {
	event.waitUntil(
		caches.open( CACHE_NAME )
			.then( ( cache ) => cache.addAll( PRECACHE_URLS ) )
			.then( () => self.skipWaiting() )
	);
} );

self.addEventListener( 'activate', ( event ) => {
	event.waitUntil(
		caches.keys()
			.then( ( names ) => Promise.all( names.filter( ( n ) => n !== CACHE_NAME ).map( ( n ) => caches.delete( n ) ) ) )
			.then( () => self.clients.claim() )
	);
} );

/**
 * Matched by PATH only, never the full URL — WordPress's own `?ver=`
 * cache-buster on pos.js/pos.css, and a real till's own `?register=N` on
 * the shell URL itself, must never stop a cached shell from being served.
 * Stale-while-revalidate: the cached shell answers immediately, a fresh
 * copy is fetched and stored in the background whenever the network is
 * actually reachable, and a failed fetch (offline) falls back to whatever
 * is already cached without ever rejecting the request.
 */
self.addEventListener( 'fetch', ( event ) => {
	const url = new URL( event.request.url );
	if ( url.origin !== self.location.origin ) {
		return;
	}

	const path      = url.pathname;
	const cacheKey  = '/pos/' === path ? '/pos/' : PRECACHE_URLS.find( ( u ) => u === path );
	if ( ! cacheKey ) {
		return;
	}

	event.respondWith(
		caches.open( CACHE_NAME ).then( ( cache ) =>
			cache.match( cacheKey ).then( ( cached ) => {
				const network = fetch( event.request )
					.then( ( response ) => {
						if ( response && response.ok ) {
							cache.put( cacheKey, response.clone() );
						}
						return response;
					} )
					.catch( () => cached );
				return cached || network;
			} )
		)
	);
} );
