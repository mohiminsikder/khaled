<?php
namespace Counter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * U4 (COUNTERFRONTEND.md) — one name-search field, reused everywhere an
 * admin screen currently asks someone to type a raw database id. render()
 * draws one field; the shared runtime (its JS behaviour + CSS) is printed
 * once per page no matter how many fields a screen renders, via the same
 * "inline script, no build step, no enqueued asset" convention every other
 * admin screen already uses (Screens\Adjust's own self::script()) — this
 * plugin has never had an admin JS/CSS file, and this task doesn't start
 * one.
 *
 * Two data sources, one widget:
 *  - "remote" (product, variation): too many rows to ever embed — a debounced
 *    call to GET /counter/v1/entities/search (Rest\EntitySearch).
 *  - "local" (location, and any future small bounded set): the caller
 *    already has the whole list in hand server-side; embedded as JSON and
 *    filtered client-side, so a location field never makes a network call
 *    to search four locations.
 * Same type-to-filter, arrow-key-navigate, click-to-pick interaction either
 * way — the thing U4 actually promises is that experience, not which
 * transport backs it.
 */
class EntityPicker {

	private static bool $runtime_printed = false;

	/**
	 * @param array{
	 *   id: string,
	 *   hidden_name: string,
	 *   type: string,
	 *   placeholder?: string,
	 *   required?: bool,
	 *   parent_field?: string,
	 *   value?: int,
	 *   value_label?: string,
	 *   options?: array<int,array{id:int,label:string,sublabel?:string}>,
	 * } $args
	 */
	public static function render( array $args ): void {
		self::print_runtime();

		$id           = (string) $args['id'];
		$hidden_name  = (string) $args['hidden_name'];
		$type         = (string) $args['type'];
		$placeholder  = (string) ( $args['placeholder'] ?? __( 'Type a name…', 'counter' ) );
		$required     = ! empty( $args['required'] );
		$parent_field = (string) ( $args['parent_field'] ?? '' );
		$value        = (int) ( $args['value'] ?? 0 );
		$value_label  = (string) ( $args['value_label'] ?? '' );
		$local        = null !== ( $args['options'] ?? null );
		$disabled     = '' !== $parent_field; // enabled by its own parent's change handler, once one is chosen

		printf(
			'<div class="cntr-entity-picker" data-cntr-picker="%1$s" data-type="%2$s" data-parent-field="%3$s"%4$s>',
			esc_attr( $id ),
			esc_attr( $type ),
			esc_attr( $parent_field ),
			$local ? ' data-local="1"' : ''
		);
		printf(
			'<input type="text" id="%1$s-input" class="regular-text cntr-entity-picker-input" placeholder="%2$s" value="%3$s" autocomplete="off"%4$s%5$s>',
			esc_attr( $id ),
			esc_attr( $placeholder ),
			esc_attr( $value_label ),
			$required ? ' required' : '',
			$disabled ? ' disabled' : ''
		);
		printf(
			'<input type="hidden" id="%1$s" name="%2$s" value="%3$s">',
			esc_attr( $id ),
			esc_attr( $hidden_name ),
			$value ? esc_attr( (string) $value ) : ''
		);
		printf( '<div class="cntr-entity-picker-results" id="%s-results" hidden></div>', esc_attr( $id ) );

		if ( $local ) {
			printf(
				'<script type="application/json" id="%s-options">%s</script>',
				esc_attr( $id ),
				wp_json_encode( array_values( $args['options'] ) )
			);
		}
		echo '</div>';
	}

	/** Printed once per page regardless of how many pickers render() draws. */
	private static function print_runtime(): void {
		if ( self::$runtime_printed ) {
			return;
		}
		self::$runtime_printed = true;

		$rest_url = esc_url_raw( rest_url( 'counter/v1/entities/search' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<style>
		.cntr-entity-picker { position: relative; display: inline-block; }
		.cntr-entity-picker-results {
			position: absolute; z-index: 10000; top: 100%; left: 0; min-width: 260px; max-width: 480px;
			max-height: 240px; overflow-y: auto; background: #fff; border: 1px solid #8c8f94;
			box-shadow: 0 2px 6px rgba(0,0,0,.15); margin-top: 2px;
		}
		.cntr-entity-picker-row { padding: 6px 10px; cursor: pointer; font-size: 13px; }
		.cntr-entity-picker-row.is-active, .cntr-entity-picker-row:hover { background: #2271b1; color: #fff; }
		</style>
		<script>
		( function () {
			if ( window.__cntrEntityPickerInit ) { return; }
			window.__cntrEntityPickerInit = true;

			var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var DEBOUNCE_MS = 200; // a network round trip, not F6's in-memory 120ms

			function debounce( fn, ms ) {
				var t;
				return function () {
					var args = arguments, ctx = this;
					clearTimeout( t );
					t = setTimeout( function () { fn.apply( ctx, args ); }, ms );
				};
			}

			function closeResults( id ) {
				var results = document.getElementById( id + '-results' );
				results.hidden = true;
				results.innerHTML = '';
			}

			function localOptions( id ) {
				var script = document.getElementById( id + '-options' );
				if ( ! script ) { return []; }
				try { return JSON.parse( script.textContent || '[]' ); } catch ( e ) { return []; }
			}

			function wireRoot( root ) {
				var id = root.getAttribute( 'data-cntr-picker' );
				var type = root.getAttribute( 'data-type' );
				var parentFieldId = root.getAttribute( 'data-parent-field' );
				var isLocal = root.hasAttribute( 'data-local' );
				var input = document.getElementById( id + '-input' );
				var hidden = document.getElementById( id );
				var results = document.getElementById( id + '-results' );
				var activeIdx = -1;
				var currentItems = [];

				function pick( item ) {
					hidden.value = String( item.id );
					hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					input.value = item.label + ( item.sublabel ? ' (' + item.sublabel + ')' : '' );
					closeResults( id );
					activeIdx = -1;
				}

				function showItems( items ) {
					currentItems = items;
					activeIdx = -1;
					results.innerHTML = '';
					if ( ! items.length ) {
						results.hidden = true;
						return;
					}
					items.forEach( function ( item, idx ) {
						var row = document.createElement( 'div' );
						row.className = 'cntr-entity-picker-row';
						row.setAttribute( 'data-idx', idx );
						row.textContent = item.label + ( item.sublabel ? ' — ' + item.sublabel : '' );
						row.addEventListener( 'mousedown', function ( e ) {
							e.preventDefault();
							pick( item );
						} );
						results.appendChild( row );
					} );
					results.hidden = false;
				}

				function search( q ) {
					if ( isLocal ) {
						var all = localOptions( id );
						var needle = q.trim().toLowerCase();
						var filtered = ! needle ? all : all.filter( function ( o ) {
							return ( o.label || '' ).toLowerCase().indexOf( needle ) !== -1;
						} );
						showItems( filtered.slice( 0, 20 ) );
						return;
					}

					var params = 'type=' + encodeURIComponent( type ) + '&q=' + encodeURIComponent( q );
					if ( parentFieldId ) {
						var parentHidden = document.getElementById( parentFieldId );
						var parentVal = parentHidden ? parentHidden.value : '';
						if ( ! parentVal ) { showItems( [] ); return; }
						params += '&parent_id=' + encodeURIComponent( parentVal );
					}

					fetch( REST_URL + '?' + params, { headers: { 'X-WP-Nonce': NONCE } } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( items ) { showItems( Array.isArray( items ) ? items : [] ); } )
						.catch( function () { showItems( [] ); } );
				}

				var debouncedSearch = debounce( search, DEBOUNCE_MS );

				input.addEventListener( 'input', function () {
					hidden.value = '';
					debouncedSearch( input.value );
				} );
				input.addEventListener( 'focus', function () {
					if ( isLocal ) { search( input.value ); }
				} );
				input.addEventListener( 'keydown', function ( e ) {
					if ( results.hidden ) { return; }
					var rows = results.querySelectorAll( '.cntr-entity-picker-row' );
					if ( 'ArrowDown' === e.key ) {
						e.preventDefault();
						activeIdx = Math.min( activeIdx + 1, rows.length - 1 );
					} else if ( 'ArrowUp' === e.key ) {
						e.preventDefault();
						activeIdx = Math.max( activeIdx - 1, 0 );
					} else if ( 'Enter' === e.key ) {
						if ( activeIdx >= 0 && currentItems[ activeIdx ] ) {
							e.preventDefault();
							pick( currentItems[ activeIdx ] );
						}
						return;
					} else if ( 'Escape' === e.key ) {
						closeResults( id );
						activeIdx = -1;
						return;
					} else {
						return;
					}
					rows.forEach( function ( r, i ) { r.classList.toggle( 'is-active', i === activeIdx ); } );
				} );
				document.addEventListener( 'click', function ( e ) {
					if ( ! root.contains( e.target ) ) { closeResults( id ); }
				} );

				if ( parentFieldId ) {
					var parentHidden = document.getElementById( parentFieldId );
					if ( parentHidden ) {
						parentHidden.addEventListener( 'change', function () {
							hidden.value = '';
							input.value = '';
							input.disabled = ! parentHidden.value;
						} );
					}
				}
			}

			function wireAll() {
				document.querySelectorAll( '.cntr-entity-picker' ).forEach( function ( root ) {
					if ( root.getAttribute( 'data-cntr-wired' ) ) { return; }
					root.setAttribute( 'data-cntr-wired', '1' );
					wireRoot( root );
				} );
			}

			if ( 'loading' === document.readyState ) {
				document.addEventListener( 'DOMContentLoaded', wireAll );
			} else {
				wireAll();
			}

			// C3 — a screen with a repeatable-row line grid (Purchase Orders)
			// clones a <template> containing a real, server-rendered picker for
			// each new row rather than hand-building the same markup a second
			// time in JS (which would drift from render()'s own HTML the moment
			// either one changed). wireAll() is idempotent by its own
			// data-cntr-wired guard, so calling it again after inserting a
			// cloned row only ever wires what's actually new.
			window.CNTR_EntityPicker = { wireAll: wireAll };
		} )();
		</script>
		<?php
	}
}
