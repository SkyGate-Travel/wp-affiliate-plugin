/**
 * Sky Affiliate Search — front-end.
 *
 * Talks only to this site's own REST proxy (/wp-json/sky-affiliate/v1/…),
 * never to the Sky API: Sky identifies a tenant by the request's Origin
 * header, which a browser sets to this site's domain and scripts cannot
 * override, so a direct call would be rejected as an unknown platform. The
 * proxy re-signs each call server-side with the configured platform domain.
 *
 * Every outgoing "book" link is built here from the pre-computed bases in
 * skyAffConfig.links plus the affiliate code, so attribution rides along
 * whichever card the visitor clicks.
 */
( function () {
	'use strict';

	var cfg = window.skyAffConfig;

	if ( ! cfg || ! cfg.restBase ) {
		return;
	}

	var t = cfg.i18n || {};

	/* ---------------------------------------------------------------- utils */

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}

		return node;
	}

	/**
	 * Trailing-edge debounce, with a way to call off a pending run.
	 *
	 * cancel() matters here: picking a suggestion happens while a keystroke's
	 * search is still queued, and letting that run would re-open the dropdown
	 * a moment after the field was filled — searching for the label it had
	 * just been set to, and finding nothing.
	 *
	 * @param {Function} fn   Function to defer.
	 * @param {number}   wait Milliseconds of quiet before it runs.
	 * @return {Function} Deferred function, carrying a `cancel` method.
	 */
	function debounce( fn, wait ) {
		var timer = null;

		var wrapped = function () {
			var args = arguments;
			var self = this;

			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};

		wrapped.cancel = function () {
			window.clearTimeout( timer );
			timer = null;
		};

		return wrapped;
	}

	/**
	 * Call the proxy. Returns a promise resolving to { data, meta }.
	 *
	 * @param {string} path   Route below the plugin namespace.
	 * @param {Object} [opts] { method, query, body, signal }.
	 * @return {Promise<Object>} Parsed payload.
	 */
	function api( path, opts ) {
		opts = opts || {};

		var url = cfg.restBase.replace( /\/$/, '' ) + path;

		if ( opts.query ) {
			var qs = new URLSearchParams();

			Object.keys( opts.query ).forEach( function ( key ) {
				var value = opts.query[ key ];

				if ( null !== value && undefined !== value && '' !== value ) {
					qs.append( key, value );
				}
			} );

			var q = qs.toString();

			if ( q ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + q;
			}
		}

		// No X-WP-Nonce and no cookies: these routes are public, and a nonce
		// embedded in a cached page goes stale — WordPress rejects a stale
		// nonce outright while treating an absent one as a plain anonymous
		// request. 'omit' also keeps the call cacheable by any CDN in front.
		var init = {
			method: opts.method || 'GET',
			headers: { Accept: 'application/json' },
			credentials: 'omit',
			signal: opts.signal
		};

		if ( opts.body ) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}

		return window.fetch( url, init ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( payload ) {
				if ( ! response.ok ) {
					var message = ( payload && payload.message ) || t.error || 'Error';
					var err = new Error( message );

					err.status = response.status;
					throw err;
				}

				return payload || {};
			} );
		} );
	}

	/* ---------------------------------------------------------------- search */

	/** How many flight rows a panel shows before deferring to the platform. */
	var FLIGHT_LIMIT = 20;

	/**
	 * The classic route behind each kind of search.
	 *
	 * Used whenever the stream is not an option: streaming switched off in the
	 * settings, a browser without a readable response body, or a proxy that
	 * handed back something other than a stream.
	 */
	var CLASSIC_ROUTE = {
		flight: { path: '/flights/search', method: 'POST' },
		hotel: { path: '/hotels/search', method: 'POST' },
		activity: { path: '/activities/search', method: 'GET' },
		tour: { path: '/tours', method: 'GET' }
	};

	/**
	 * Split a whole payload into the same named batches the stream sends, so
	 * a panel renders one way whichever route answered it.
	 *
	 * @param {string} kind    flight|hotel|activity|tour.
	 * @param {Object} payload { data, meta }.
	 * @return {Array<Object>} [ { bucket, items } ].
	 */
	function bucketsOf( kind, payload ) {
		var data = ( payload && payload.data ) || null;

		if ( 'flight' === kind ) {
			var groups = [];

			[ 'departing', 'round_trip', 'multi_city' ].forEach( function ( key ) {
				if ( data && Array.isArray( data[ key ] ) && data[ key ].length ) {
					groups.push( { bucket: key, items: data[ key ] } );
				}
			} );

			return groups;
		}

		if ( 'activity' === kind ) {
			var rows = ( data && ( data.products || data.data ) ) || ( Array.isArray( data ) ? data : [] );

			return [ { bucket: 'items', items: Array.isArray( rows ) ? rows : [] } ];
		}

		return [ { bucket: 'items', items: Array.isArray( data ) ? data : [] } ];
	}

	/**
	 * The count worth showing, which is not always what arrived: a paged hotel
	 * search reports a catalogue-wide total while sending one page of it.
	 *
	 * @param {string} kind    Search kind.
	 * @param {Object} payload { data, meta }.
	 * @param {number} counted Rows actually present.
	 * @return {number} Count for the status line.
	 */
	function totalOf( kind, payload, counted ) {
		var data = ( payload && payload.data ) || null;
		var meta = ( payload && payload.meta ) || null;

		if ( 'hotel' === kind && meta && meta.total ) {
			return meta.total;
		}

		if ( 'activity' === kind && data && data.total ) {
			return data.total;
		}

		return counted;
	}

	/**
	 * Run a search and report it as it arrives.
	 *
	 * A search is slow because the platform asks every supplier it sells and
	 * answers when the last one has — half a minute is normal. Held in a
	 * single silent response that is a problem twice over: the visitor cannot
	 * tell a slow search from a broken one, and any proxy or CDN in front of
	 * WordPress may give up on a body that has not started arriving.
	 *
	 * So the panel is fed through handlers instead, in the order
	 * meta → items… → done, and gets the same sequence whether the answer
	 * streamed in over thirty seconds or came back whole from the cache.
	 * `alive` fires on each server heartbeat; panels run their own clock and
	 * use it only as proof the search is still moving.
	 *
	 * @param {string} kind     flight|hotel|activity|tour.
	 * @param {Object} payload  Search parameters.
	 * @param {Object} handlers { meta, items, done, error, alive }.
	 * @return {Object} { cancel } — cancel() silences every later event too,
	 *                  which is what keeps a superseded search from painting
	 *                  its results over a newer one.
	 */
	function startSearch( kind, payload, handlers ) {
		var controller = window.AbortController ? new AbortController() : null;
		var stopped = false;

		function emit( name, a, b, c ) {
			if ( ! stopped && handlers[ name ] ) {
				handlers[ name ]( a, b, c );
			}
		}

		function cancel() {
			stopped = true;

			if ( controller ) {
				controller.abort();
			}
		}

		function whole( body ) {
			var groups = bucketsOf( kind, body );
			var counted = 0;

			groups.forEach( function ( group ) {
				counted += group.items.length;
			} );

			emit( 'meta', ( body && body.meta ) || null, totalOf( kind, body, counted ) );

			groups.forEach( function ( group ) {
				emit( 'items', group.bucket, group.items );
			} );

			emit( 'done', { count: counted, cached: false } );
		}

		function failed( error ) {
			// An abort is this code cancelling itself; nothing went wrong and
			// nobody is waiting for the answer any more.
			if ( error && 'AbortError' === error.name ) {
				return;
			}

			emit( 'error', error || new Error( t.error ) );
		}

		function classic() {
			var route = CLASSIC_ROUTE[ kind ];
			var opts = {
				method: route.method,
				signal: controller ? controller.signal : undefined
			};

			if ( 'POST' === route.method ) {
				opts.body = payload;
			} else {
				opts.query = payload;
			}

			api( route.path, opts ).then( whole ).catch( failed );
		}

		/**
		 * Read NDJSON lines as they land and turn each into a handler call.
		 *
		 * @param {ReadableStream} stream Response body.
		 * @return {Promise} Resolves when the stream ends.
		 */
		function consume( stream ) {
			var reader = stream.getReader();
			var decoder = new window.TextDecoder();
			var buffer = '';

			function line( raw ) {
				var text = raw.trim();

				// ':' opens the padding the server sends first, to shake loose
				// any proxy that would otherwise sit on the response until it
				// had all of it.
				if ( ! text || ':' === text.charAt( 0 ) ) {
					return;
				}

				var event;

				try {
					event = JSON.parse( text );
				} catch ( e ) {
					return;
				}

				if ( 'tick' === event.type ) {
					emit( 'alive', event.elapsed );
				} else if ( 'meta' === event.type ) {
					emit( 'meta', event.meta, event.total );
				} else if ( 'items' === event.type ) {
					// A batch marked `replace` is the settling frame at the
					// end of a supplier-by-supplier search: the whole list, in
					// price order, with anything withdrawn along the way gone.
					emit( 'items', event.bucket, event.items || [], { replace: !! event.replace } );
				} else if ( 'done' === event.type ) {
					emit( 'done', event );
				} else if ( 'error' === event.type ) {
					// The status was 200 the moment the first line went out, so
					// a failure arrives as a line and is raised from here.
					var error = new Error( event.message || t.error );

					error.status = event.status;

					failed( error );
				}
			}

			function pump() {
				return reader.read().then( function ( chunk ) {
					if ( stopped ) {
						reader.cancel();

						return;
					}

					if ( chunk.done ) {
						line( buffer );

						return;
					}

					buffer += decoder.decode( chunk.value, { stream: true } );

					var parts = buffer.split( '\n' );

					// Whatever follows the last newline is half a line; hold
					// it until the rest of it turns up.
					buffer = parts.pop();
					parts.forEach( line );

					return pump();
				} );
			}

			return pump();
		}

		if ( ! cfg.stream || ! window.fetch || ! window.TextDecoder || ! window.ReadableStream ) {
			classic();

			return { cancel: cancel };
		}

		var body = { kind: kind };

		Object.keys( payload || {} ).forEach( function ( key ) {
			body[ key ] = payload[ key ];
		} );

		window.fetch( cfg.restBase.replace( /\/$/, '' ) + '/stream', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/x-ndjson, application/json'
			},
			credentials: 'omit',
			body: JSON.stringify( body ),
			signal: controller ? controller.signal : undefined
		} ).then( function ( response ) {
			var type = response.headers.get( 'Content-Type' ) || '';

			// Two things answer here without streaming: a rejected search,
			// which is still an ordinary REST error with a real status, and a
			// site with streaming switched off, which answers like the classic
			// route. Both are handled as a whole payload.
			if ( ! response.ok || -1 === type.indexOf( 'ndjson' ) || ! response.body || ! response.body.getReader ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( parsed ) {
					if ( ! response.ok ) {
						var error = new Error( ( parsed && parsed.message ) || t.error );

						error.status = response.status;

						throw error;
					}

					whole( parsed || {} );
				} );
			}

			return consume( response.body );
		} ).catch( failed );

		return { cancel: cancel };
	}

	/**
	 * Append the affiliate code to a storefront URL.
	 *
	 * The server already put the code in skyAffConfig; doing the join here
	 * keeps every card, every tab and the "see all" link consistent.
	 *
	 * @param {string} base  Absolute storefront URL.
	 * @param {Object} query Query arguments; empties are dropped.
	 * @return {string} Tagged URL.
	 */
	function affiliateUrl( base, query ) {
		if ( ! base ) {
			return '#';
		}

		var url;

		try {
			url = new URL( base );
		} catch ( e ) {
			return '#';
		}

		Object.keys( query || {} ).forEach( function ( key ) {
			var value = query[ key ];

			if ( null !== value && undefined !== value && '' !== value ) {
				url.searchParams.set( key, value );
			}
		} );

		if ( cfg.affiliate && cfg.affiliate.code ) {
			url.searchParams.set( cfg.affiliate.param, cfg.affiliate.code );
		}

		return url.toString();
	}

	/**
	 * An <a> pointing at the platform, carrying the affiliate code.
	 *
	 * rel is sponsored+nofollow because this is exactly what those values
	 * describe: a paid referral. It also keeps the host site clear of search
	 * engines' paid-link rules.
	 *
	 * @param {string} href  Tagged URL.
	 * @param {string} label Link text.
	 * @param {string} cls   Class name.
	 * @return {HTMLAnchorElement} The link.
	 */
	function outLink( href, label, cls ) {
		var a = el( 'a', cls, label );

		a.href = href;
		a.target = cfg.linkTarget || '_blank';

		if ( '_blank' === a.target ) {
			a.rel = 'noopener sponsored nofollow';
		} else {
			a.rel = 'sponsored nofollow';
		}

		return a;
	}

	/**
	 * Format a price with its currency, using the visitor's locale rules.
	 *
	 * @param {number|string} amount   Price.
	 * @param {string}        currency ISO code, when the provider gave one.
	 * @return {string} Display string, or '' when there is no usable number.
	 */
	function money( amount, currency ) {
		var value = Number( amount );

		if ( ! isFinite( value ) || 0 === value ) {
			return '';
		}

		// Activity suppliers send the currency as { code, symbol, uuid }
		// rather than a bare ISO code. Handed straight to Intl that throws,
		// and the fallback below printed "195 [object Object]".
		if ( currency && 'object' === typeof currency ) {
			currency = currency.code || currency.iso || '';
		}

		try {
			return new Intl.NumberFormat( cfg.language || undefined, {
				style: currency ? 'currency' : 'decimal',
				currency: currency || undefined,
				maximumFractionDigits: 0
			} ).format( value );
		} catch ( e ) {
			return String( Math.round( value ) ) + ( currency ? ' ' + currency : '' );
		}
	}

	/**
	 * Format a plain integer in the visitor's locale.
	 *
	 * Prices already go through Intl, so counts have to as well — otherwise a
	 * Persian page reads "۳۴۰ € / 3 شب", mixing Eastern Arabic and Latin
	 * digits in one sentence.
	 *
	 * @param {number|string} value Number to format.
	 * @return {string} Localised digits.
	 */
	function num( value ) {
		var n = Number( value );

		if ( ! isFinite( n ) ) {
			return String( value );
		}

		try {
			return new Intl.NumberFormat( cfg.language || undefined ).format( n );
		} catch ( e ) {
			return String( n );
		}
	}

	/**
	 * Pull the first present key out of an object.
	 *
	 * Provider payloads are passed through Sky largely untouched and differ in
	 * casing and naming between suppliers, so cards read defensively rather
	 * than assuming one shape.
	 *
	 * @param {Object} obj  Source.
	 * @param {Array}  keys Candidate keys, in order of preference.
	 * @return {*} First defined value, or undefined.
	 */
	function pick( obj, keys ) {
		if ( ! obj ) {
			return undefined;
		}

		for ( var i = 0; i < keys.length; i++ ) {
			var parts = keys[ i ].split( '.' );
			var cursor = obj;
			var ok = true;

			for ( var j = 0; j < parts.length; j++ ) {
				if ( cursor && typeof cursor === 'object' && parts[ j ] in cursor ) {
					cursor = cursor[ parts[ j ] ];
				} else {
					ok = false;
					break;
				}
			}

			if ( ok && null !== cursor && undefined !== cursor && '' !== cursor ) {
				return cursor;
			}
		}

		return undefined;
	}

	function daysBetween( a, b ) {
		var start = new Date( a + 'T00:00:00Z' ).getTime();
		var end = new Date( b + 'T00:00:00Z' ).getTime();

		if ( ! isFinite( start ) || ! isFinite( end ) || end <= start ) {
			return 0;
		}

		return Math.round( ( end - start ) / 86400000 );
	}

	/* ------------------------------------------------------------- typeahead */

	/**
	 * Wire an input to a suggestion list.
	 *
	 * @param {Object} opts Configuration.
	 * @param {HTMLInputElement} opts.input     Field to attach to.
	 * @param {HTMLElement}      opts.list      Container for the options.
	 * @param {Function}         opts.fetch     (query, signal) => Promise<items>.
	 * @param {Function}         opts.labelOf   item => display string.
	 * @param {Function}         opts.onChoose  Called with the chosen item.
	 * @param {Function}         opts.onClear   Called when the text is edited.
	 * @param {number}           [opts.minChars=2] Characters before searching.
	 *                           Pass 0 to open a browse list on focus — only
	 *                           worth doing where the endpoint answers a blank
	 *                           query with something useful.
	 * @param {string}           [opts.hint]   Shown instead of results while
	 *                           the query is below minChars.
	 */
	function typeahead( opts ) {
		var input = opts.input;
		var list = opts.list;
		var items = [];
		var controller = null;
		var activeIndex = -1;
		var minChars = undefined === opts.minChars ? 2 : opts.minChars;

		// The browse list is the same for every visit to the field, so it is
		// fetched once and replayed from memory — clicking in and out of the
		// field should feel instant, not re-request on every focus.
		var browseCache = null;

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			activeIndex = -1;
		}

		function showHint( text ) {
			list.innerHTML = '';
			list.appendChild( el( 'li', 'sky-aff__suggest-empty', text ) );
			list.hidden = false;
		}

		function choose( item ) {
			// Stop anything already in flight for the half-typed query, or it
			// lands after the pick and re-opens the list over a filled field.
			if ( runDebounced ) {
				runDebounced.cancel();
			}

			if ( controller ) {
				controller.abort();
				controller = null;
			}

			opts.onChoose( item );
			input.value = opts.labelOf( item );
			close();
		}

		function render() {
			list.innerHTML = '';

			if ( ! items.length ) {
				var empty = el( 'li', 'sky-aff__suggest-empty', t.noPlaces || 'No matches' );

				list.appendChild( empty );
				list.hidden = false;

				return;
			}

			items.forEach( function ( item, index ) {
				var li = el( 'li', 'sky-aff__suggest-item' );

				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', index === activeIndex ? 'true' : 'false' );
				li.tabIndex = -1;
				li.appendChild( el( 'span', 'sky-aff__suggest-main', opts.labelOf( item ) ) );

				// A quieter second line: the country, how many airports a city
				// covers, how many properties a place holds. Enough to tell two
				// same-named results apart without crowding the first line.
				var secondary = opts.secondaryOf ? opts.secondaryOf( item ) : '';

				if ( secondary ) {
					li.appendChild( el( 'span', 'sky-aff__suggest-sub', secondary ) );
				}

				if ( index === activeIndex ) {
					li.classList.add( 'is-active' );
				}

				li.addEventListener( 'mousedown', function ( event ) {
					// mousedown, not click: blur would close the list first.
					event.preventDefault();
					choose( item );
				} );

				list.appendChild( li );
			} );

			list.hidden = false;
		}

		/**
		 * Fetch and show suggestions for the field's current text.
		 *
		 * @param {boolean} immediate Skip the "still typing" guard, for focus.
		 */
		function search( immediate ) {
			var query = input.value.trim();

			if ( query.length < minChars ) {
				if ( opts.hint ) {
					showHint( opts.hint );
				} else {
					close();
				}

				return;
			}

			// Browsing (empty query) always yields the same list; serve the
			// copy we already have rather than hitting the network again.
			if ( '' === query && browseCache ) {
				// Drop any filtered request still in flight, or it lands after
				// this and replaces the browse list with stale matches.
				if ( controller ) {
					controller.abort();
					controller = null;
				}

				items = browseCache;
				activeIndex = -1;
				render();

				return;
			}

			if ( controller ) {
				controller.abort();
			}

			controller = new AbortController();

			if ( immediate ) {
				showHint( t.searching || 'Searching…' );
			}

			opts.fetch( query, controller.signal ).then( function ( results ) {
				items = results || [];

				if ( '' === query ) {
					browseCache = items;
				}

				activeIndex = -1;
				render();
			} ).catch( function ( error ) {
				if ( 'AbortError' !== error.name ) {
					close();
				}
			} );
		}

		var runDebounced = debounce( function () {
			search( false );
		}, 250 );

		input.addEventListener( 'input', function () {
			opts.onClear();
			runDebounced();
		} );

		input.addEventListener( 'focus', function () {
			var query = input.value.trim();

			// An empty field with minChars 0 is the "just clicked in" case:
			// open the browse list straight away so the visitor can pick a
			// destination without having to guess what to type.
			if ( '' === query ) {
				if ( 0 === minChars ) {
					search( true );
				} else if ( opts.hint ) {
					showHint( opts.hint );
				}

				return;
			}

			if ( items.length ) {
				render();
			}
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 150 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden || ! items.length ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				activeIndex = Math.min( activeIndex + 1, items.length - 1 );
				render();
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				activeIndex = Math.max( activeIndex - 1, 0 );
				render();
			} else if ( 'Enter' === event.key && activeIndex >= 0 ) {
				event.preventDefault();
				choose( items[ activeIndex ] );
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );
	}

	/**
	 * Whether the page under the widget is dark.
	 *
	 * The widget paints its own backdrop, so it has to choose light or smoked
	 * glass itself — and the visitor's OS setting says nothing about a theme
	 * that is dark regardless. The first ancestor with a painted background
	 * is what the widget actually sits on.
	 *
	 * @param {HTMLElement} root Widget root.
	 * @return {boolean}
	 */
	function onDarkGround( root ) {
		var node = root.parentElement;

		while ( node ) {
			var match = window.getComputedStyle( node ).backgroundColor.match( /[\d.]+/g );

			if ( match && match.length >= 3 && ( match.length < 4 || Number( match[ 3 ] ) > 0.5 ) ) {
				// Relative luminance, near enough for a light/dark call.
				var luminance = ( 0.2126 * match[ 0 ] + 0.7152 * match[ 1 ] + 0.0722 * match[ 2 ] ) / 255;

				return luminance < 0.4;
			}

			node = node.parentElement;
		}

		return false;
	}

	/* ------------------------------------------------------------ the widget */

	/**
	 * Bring one shortcode instance to life.
	 *
	 * @param {HTMLElement} root Widget root.
	 */
	function initWidget( root ) {
		if ( onDarkGround( root ) ) {
			root.classList.add( 'sky-aff--dark' );
		}

		var tabs = root.querySelectorAll( '[data-sky-tab]' );
		var panels = root.querySelectorAll( '[data-sky-panel]' );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-sky-tab' );

				tabs.forEach( function ( other ) {
					var isActive = other === tab;

					other.classList.toggle( 'is-active', isActive );
					other.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );

				panels.forEach( function ( panel ) {
					var isActive = panel.getAttribute( 'data-sky-panel' ) === target;

					panel.classList.toggle( 'is-active', isActive );
					panel.hidden = ! isActive;
				} );
			} );
		} );

		panels.forEach( function ( panel ) {
			var type = panel.getAttribute( 'data-sky-panel' );

			if ( 'flight' === type ) {
				initFlight( panel );
			} else if ( 'hotel' === type ) {
				initHotel( panel );
			} else if ( 'activity' === type ) {
				initActivity( panel );
			} else if ( 'tour' === type ) {
				initTour( panel );
			}
		} );
	}

	/**
	 * Shared per-panel plumbing: status line, results container, submit lock.
	 *
	 * @param {HTMLElement} panel Panel element.
	 * @return {Object} Helpers.
	 */
	function panelParts( panel ) {
		var form = panel.querySelector( '[data-sky-form]' );
		var status = panel.querySelector( '[data-sky-status]' );
		var results = panel.querySelector( '[data-sky-results]' );
		var more = panel.querySelector( '[data-sky-more]' );
		var all = panel.querySelector( '[data-sky-all]' );
		var submit = form ? form.querySelector( '.sky-aff__submit' ) : null;
		var submitLabel = submit ? submit.textContent : '';
		var bar = null;
		var tracking = null;

		function busy( isBusy ) {
			if ( ! submit ) {
				return;
			}

			submit.disabled = isBusy;
			submit.textContent = isBusy ? ( t.searching || 'Searching…' ) : submitLabel;
			panel.classList.toggle( 'is-loading', isBusy );
		}

		function say( message, kind ) {
			status.textContent = message || '';
			status.className = 'sky-aff__status' + ( kind ? ' is-' + kind : '' );
		}

		function clear() {
			results.innerHTML = '';

			if ( more ) {
				more.hidden = true;
			}

			if ( all ) {
				all.hidden = true;
				all.innerHTML = '';
			}
		}

		/**
		 * Placeholder cards while the platform is being asked.
		 *
		 * An empty results area during a thirty-second search reads as "this
		 * found nothing"; cards in the shape of the answer read as "this is
		 * working". The first real batch overwrites them.
		 *
		 * @param {string} kind Search kind, for the row shape.
		 */
		function skeletons( kind ) {
			var many = 'flight' === kind ? 4 : 6;
			var shape = 'flight' === kind ? ' sky-aff__card--flight' : '';

			results.innerHTML = '';

			for ( var i = 0; i < many; i++ ) {
				results.appendChild( el( 'div', 'sky-aff__card sky-aff__skeleton' + shape ) );
			}
		}

		function progressBar() {
			if ( ! bar ) {
				bar = el( 'div', 'sky-aff__progress' );
				bar.setAttribute( 'aria-hidden', 'true' );
				bar.appendChild( el( 'span', 'sky-aff__progress-fill' ) );
				status.parentNode.insertBefore( bar, status.nextSibling );
			}

			return bar;
		}

		return {
			form: form,
			status: status,
			results: results,
			more: more,
			all: all,
			busy: busy,
			say: say,
			clear: clear,
			skeletons: skeletons,

			/**
			 * Show a search in progress, and hand back the handle that ends
			 * it. Every exit from a search — results, nothing, an error, a
			 * newer search replacing it — goes through stop().
			 *
			 * @param {string}  kind       Search kind.
			 * @param {boolean} [placehold] Draw skeleton cards. False when
			 *                              appending a further page, which
			 *                              must not wipe what is on screen.
			 * @return {Object} { stop, seconds }.
			 */
			track: function ( kind, placehold ) {
				// A search that is being replaced never reports back — it was
				// cancelled — so its clock has to be stopped from here, or it
				// keeps overwriting the status line of the search that
				// replaced it.
				if ( tracking ) {
					tracking.stop();
				}

				var started = Date.now();
				var slowAfter = ( cfg.slowAfter || 15 ) * 1000;
				var fill = progressBar().firstChild;
				var timer;

				function paint() {
					var elapsed = Date.now() - started;

					say(
						( elapsed >= slowAfter
							? ( t.stillSearching || t.searching )
							: ( t.searchingProviders || t.searching )
						) + ' ' + num( Math.round( elapsed / 1000 ) ) + ( t.secondsShort || 's' )
					);

					// Asymptotic: always moving, never arriving. How long a
					// supplier fan-out has left is the one thing nobody here
					// knows, and a bar that reaches the end and sits there
					// reads as stuck.
					fill.style.width = ( 100 - 100 * Math.exp( -elapsed / 9000 ) ).toFixed( 1 ) + '%';
				}

				panel.classList.add( 'is-streaming' );
				busy( true );

				if ( false !== placehold ) {
					skeletons( kind );
				}

				paint();
				timer = window.setInterval( paint, 250 );

				var handle = {
					seconds: function () {
						return Math.round( ( Date.now() - started ) / 1000 );
					},
					stop: function () {
						window.clearInterval( timer );

						if ( tracking === handle ) {
							tracking = null;
						}

						panel.classList.remove( 'is-streaming' );
						busy( false );

						if ( bar && bar.parentNode ) {
							bar.parentNode.removeChild( bar );
						}

						bar = null;
					}
				};

				tracking = handle;

				return handle;
			},

			/**
			 * The result count, with a note when the answer was already known.
			 *
			 * @param {number} total How many results to report.
			 * @param {Object} info  The stream's `done` event.
			 */
			summary: function ( total, info ) {
				var text = ( t.resultsCount || '%s results' ).replace( '%s', num( total ) );

				if ( info && info.cached && t.fromCache ) {
					text += ' · ' + t.fromCache;
				}

				say( text );
			},

			seeAll: function ( href, label ) {
				if ( ! all ) {
					return;
				}

				all.innerHTML = '';
				all.appendChild( outLink( href, label || t.seeAllResults || 'See all results', 'sky-aff__seeall' ) );
				all.hidden = false;
			}
		};
	}

	/* ----------------------------------------------------------------- flight */

	function initFlight( panel ) {
		var p = panelParts( panel );
		var active = null;
		var form = p.form;

		if ( ! form ) {
			return;
		}

		var state = { origin: null, destination: null };

		[ 'origin', 'destination' ].forEach( function ( which ) {
			var input = form.querySelector( '[data-sky-airport="' + which + '"]' );
			var hidden = form.querySelector( '[data-sky-code="' + which + '"]' );
			var list = form.querySelector( '[data-sky-suggest="' + which + '"]' );

			if ( ! input || ! list ) {
				return;
			}

			typeahead( {
				input: input,
				list: list,
				// 0 = open the airport list as soon as the field is clicked,
				// so choosing from a list is the default path and typing is
				// only there to narrow it down.
				minChars: 0,
				labelOf: function ( item ) {
					// The proxy normalises cities and airports into one shape:
					// { kind, code, city, name, country }.
					var label = item.city || item.name || item.code;

					// Several airports serve one city, so an airport entry has
					// to name itself or the rows are indistinguishable.
					if ( 'airport' === item.kind && item.name && item.name !== item.city ) {
						label = item.city ? item.city + ' — ' + item.name : item.name;
					}

					return item.code ? label + ' (' + item.code + ')' : label;
				},
				secondaryOf: function ( item ) {
					if ( 'city' === item.kind && item.airports > 1 ) {
						return item.country
							? item.country + ' · ' + ( t.allAirports || 'all airports' )
							: ( t.allAirports || 'all airports' );
					}

					return item.country || '';
				},
				fetch: function ( query, signal ) {
					return api( '/flights/airports', {
						query: { q: query, limit: 15 },
						signal: signal
					} ).then( function ( payload ) {
						var rows = payload.data || [];

						return Array.isArray( rows ) ? rows : [];
					} );
				},
				onClear: function () {
					state[ which ] = null;
					hidden.value = '';
				},
				onChoose: function ( item ) {
					state[ which ] = item;
					hidden.value = String( item.code || '' ).toUpperCase();
				}
			} );
		} );

		var swap = form.querySelector( '[data-sky-swap]' );

		if ( swap ) {
			swap.addEventListener( 'click', function () {
				var oi = form.querySelector( '[data-sky-airport="origin"]' );
				var di = form.querySelector( '[data-sky-airport="destination"]' );
				var oc = form.querySelector( '[data-sky-code="origin"]' );
				var dc = form.querySelector( '[data-sky-code="destination"]' );

				var tmpText = oi.value;
				var tmpCode = oc.value;
				var tmpState = state.origin;

				oi.value = di.value;
				oc.value = dc.value;
				state.origin = state.destination;

				di.value = tmpText;
				dc.value = tmpCode;
				state.destination = tmpState;
			} );
		}

		var returnField = form.querySelector( '[data-sky-return]' );
		var returnInput = form.querySelector( 'input[name="returning"]' );
		var departInput = form.querySelector( 'input[name="departing"]' );

		form.querySelectorAll( 'input[name="trip_type"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				var isRound = 'round_trip' === radio.value && radio.checked;

				returnField.hidden = ! isRound;

				if ( ! isRound ) {
					returnInput.value = '';
				}
			} );
		} );

		// A return date can never precede the outbound one; let the browser
		// enforce it rather than discovering it server-side.
		departInput.addEventListener( 'change', function () {
			returnInput.min = departInput.value;

			if ( returnInput.value && returnInput.value < departInput.value ) {
				returnInput.value = departInput.value;
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var origin = form.querySelector( '[data-sky-code="origin"]' ).value;
			var destination = form.querySelector( '[data-sky-code="destination"]' ).value;

			// The hidden code fields are only filled by choosing from the
			// list, so an empty one means the visitor typed a name and never
			// picked a row — not that they typed too little.
			if ( ! origin || ! destination ) {
				p.say( t.chooseAirports || 'Choose where you are flying from and to.', 'error' );

				return;
			}

			if ( origin === destination ) {
				p.say( t.sameAirports || 'Origin and destination are the same. Pick a different one.', 'error' );

				return;
			}

			// Sky needs to know whether this is a domestic search. Both
			// endpoints resolving to the same country is the only signal
			// available client-side.
			var countryA = ( state.origin || {} ).country;
			var countryB = ( state.destination || {} ).country;
			var isInternal = countryA && countryB && countryA === countryB ? 1 : 0;

			var body = {
				is_internal: isInternal,
				origin: origin,
				destination: destination,
				departing: departInput.value,
				returning: returnField.hidden ? '' : returnInput.value,
				adult: Number( form.querySelector( 'input[name="adult"]' ).value ) || 1,
				child: Number( form.querySelector( 'input[name="child"]' ).value ) || 0,
				infant: Number( form.querySelector( 'input[name="infant"]' ).value ) || 0,
				cabin_type: Number( form.querySelector( 'select[name="cabin_type"]' ).value ) || 1
			};

			var deepLink = affiliateUrl(
				cfg.links.flight + origin + '-' + destination,
				{
					departing: body.departing,
					returning: body.returning,
					adult: body.adult,
					child: body.child,
					infant: body.infant,
					cabin_type: body.cabin_type,
					is_domestic: isInternal
				}
			);

			// A visitor who edits the form and searches again while the first
			// search is still out must not have the older, slower answer land
			// on top of the newer one.
			if ( active ) {
				active.cancel();
			}

			p.clear();

			var progress = p.track( 'flight' );
			var shown = 0;
			var total = 0;

			active = startSearch( 'flight', body, {
				meta: function ( meta, count ) {
					total = count;
				},

				items: function ( bucket, items, flags ) {
					if ( flags && flags.replace ) {
						shown = 0;
					}

					var room = FLIGHT_LIMIT - shown;

					if ( room <= 0 ) {
						return;
					}

					var batch = items.slice( 0, room );

					// The first batch replaces the placeholders; the rest are
					// appended, so nothing that is already readable moves.
					renderFlights( p.results, batch, deepLink, shown > 0 );
					shown += batch.length;
				},

				done: function ( info ) {
					progress.stop();

					if ( ! shown ) {
						p.clear();
						p.say( t.noResults || 'No results.', 'empty' );
					} else {
						// A streamed search only knows its total at the end;
						// a whole-answer one knew it from the start.
						p.summary( ( info && info.total ) || total || shown, info );
					}

					p.seeAll( deepLink );
				},

				error: function ( error ) {
					progress.stop();
					p.clear();
					p.say( error.message || t.error, 'error' );
					// Even a failed search should still send the visitor onward.
					p.seeAll( deepLink );
				}
			} );
		} );
	}

	/**
	 * Clock time out of an API timestamp.
	 *
	 * Timestamps arrive as "2026-09-20T04:50:00.000000Z" — a Z-suffixed local
	 * departure time, not a real UTC instant. Parsing it as a Date would shift
	 * an 04:50 departure by the viewer's offset, so the characters are read
	 * straight off the string instead.
	 *
	 * @param {string} value Timestamp.
	 * @return {string} "HH:MM", or '' if unrecognisable.
	 */
	function clockTime( value ) {
		var m = /T(\d{2}:\d{2})/.exec( String( value || '' ) );

		return m ? m[ 1 ] : '';
	}

	/**
	 * Calendar date out of an API timestamp.
	 *
	 * @param {string} value Timestamp.
	 * @return {string} "YYYY-MM-DD", or ''.
	 */
	function datePart( value ) {
		var m = /^(\d{4}-\d{2}-\d{2})/.exec( String( value || '' ) );

		return m ? m[ 1 ] : '';
	}

	/**
	 * Minutes as "2h 15m".
	 *
	 * @param {number} mins Duration in minutes.
	 * @return {string} Formatted, or ''.
	 */
	function duration( mins ) {
		var n = Number( mins );

		if ( ! isFinite( n ) || n <= 0 ) {
			return '';
		}

		var h = Math.floor( n / 60 );
		var m = n % 60;

		return ( h ? num( h ) + ( t.hourShort || 'h' ) + ' ' : '' ) + num( m ) + ( t.minuteShort || 'm' );
	}

	/**
	 * An inline SVG, because a widget that drops into someone else's theme
	 * cannot assume an icon font is loaded and should not fetch one.
	 *
	 * @param {string} path  Path data, on a 24×24 grid.
	 * @param {string} cls   Class for the <svg>.
	 * @param {string} label Accessible name, or '' for decoration.
	 * @return {SVGElement}
	 */
	function icon( path, cls, label ) {
		var NS = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( NS, 'svg' );
		var shape = document.createElementNS( NS, 'path' );

		shape.setAttribute( 'd', path );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'class', cls );
		svg.setAttribute( 'focusable', 'false' );

		if ( label ) {
			svg.setAttribute( 'role', 'img' );
			svg.setAttribute( 'aria-label', label );
		} else {
			svg.setAttribute( 'aria-hidden', 'true' );
		}

		svg.appendChild( shape );

		return svg;
	}

	var PLANE_PATH = 'M21 16v-2l-8-5V3.5C13 2.67 12.33 2 11.5 2S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z';

	/**
	 * Who is actually flying a fare.
	 *
	 * A one-leg fare names its airline; a connection names one per leg, and
	 * the same carrier can appear on several of them. The platform's own site
	 * stacks the distinct logos and calls it "several airlines" past the
	 * first, which is the honest summary for a card this size.
	 *
	 * @param {Object} row Fare.
	 * @return {Array<Object>} [ { name, code, logo } ], never undefined.
	 */
	function airlinesOf( row ) {
		var seen = {};
		var out = [];

		function add( airline, fallbackCode ) {
			airline = airline || {};

			var code = airline.iata_code || airline.iata || fallbackCode || '';
			var name = airline.name || airline.en_name || code;
			var key = airline.id || code || name;

			if ( ! key || seen[ key ] ) {
				return;
			}

			seen[ key ] = true;
			out.push( { name: name, code: code, logo: airline.logo || '' } );
		}

		var routes = pick( row, [ 'routes', 'legs' ] );

		if ( Array.isArray( routes ) ) {
			routes.forEach( function ( route ) {
				if ( route && route.airline ) {
					add( route.airline );
				}
			} );
		}

		if ( ! out.length ) {
			add( pick( row, [ 'airline' ] ), pick( row, [ 'iata_code' ] ) );
		}

		return out;
	}

	/**
	 * One airline's mark: its logo, or its code in the same circle when there
	 * is no logo or the image will not load. An empty ring reads as a broken
	 * page; two letters read as an airline.
	 *
	 * @param {Object} airline From airlinesOf().
	 * @return {HTMLElement}
	 */
	function airlineMark( airline ) {
		var mark = el( 'span', 'sky-aff__logo' );
		var code = airline.code || ( airline.name || '' ).slice( 0, 2 ).toUpperCase();

		function asText() {
			mark.className = 'sky-aff__logo sky-aff__logo--text';
			mark.textContent = code;
		}

		if ( ! airline.logo ) {
			asText();

			return mark;
		}

		var img = document.createElement( 'img' );

		img.src = airline.logo;
		img.alt = airline.name || code;
		img.loading = 'lazy';
		img.decoding = 'async';
		// The logo is served by the platform, not by this site. Sending no
		// referrer keeps which page a visitor was reading out of its logs.
		img.referrerPolicy = 'no-referrer';
		img.addEventListener( 'error', asText );
		mark.appendChild( img );

		return mark;
	}

	/**
	 * Cabin, as a word rather than the API's shouted enum.
	 *
	 * @param {Object} row Fare.
	 * @return {string}
	 */
	function cabinName( row ) {
		var named = String( pick( row, [ 'class' ] ) || '' ).toLowerCase();
		var byNumber = { 1: t.economy, 2: t.business, 3: t.first };

		if ( named.indexOf( 'business' ) !== -1 ) {
			return t.business || 'Business';
		}

		if ( named.indexOf( 'first' ) !== -1 ) {
			return t.first || 'First';
		}

		if ( named.indexOf( 'economy' ) !== -1 ) {
			return t.economy || 'Economy';
		}

		return byNumber[ pick( row, [ 'cabin_type' ] ) ] || '';
	}

	/**
	 * Baggage as it fits on a chip.
	 *
	 * Providers spell it out — "1 Piece(s) x 8 Kilogram(s)" — which is three
	 * times the width of anything else on the card and says nothing extra.
	 *
	 * @param {*} value Raw allowance.
	 * @return {string}
	 */
	function baggage( value ) {
		if ( ! value ) {
			return '';
		}

		return String( value )
			.replace( /kilogram\(s\)/gi, 'kg' )
			.replace( /kilograms?\b/gi, 'kg' )
			.replace( /piece\(s\)/gi, '×' )
			.replace( /pieces?\b/gi, '×' )
			.replace( /\s*x\s*/gi, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	/**
	 * The chips above a fare's times: how many stops, which cabin, what you
	 * may bring, and anything the platform itself tagged it with.
	 *
	 * @param {Object} row       Fare.
	 * @param {number} stopCount Stops.
	 * @return {HTMLElement}
	 */
	function flightChips( row, stopCount ) {
		var chips = el( 'div', 'sky-aff__chips' );
		var stopLabel = 0 === stopCount
			? ( t.direct || 'Direct' )
			: num( stopCount ) + ' ' + ( 1 === stopCount ? ( t.stop || 'stop' ) : ( t.stops || 'stops' ) );

		chips.appendChild(
			el( 'span', 'sky-aff__badge' + ( 0 === stopCount ? ' sky-aff__badge--good' : '' ), stopLabel )
		);

		var cabin = cabinName( row );

		if ( cabin ) {
			chips.appendChild( el( 'span', 'sky-aff__badge', cabin ) );
		}

		var aircraft = pick( row, [ 'aircraft' ] );

		// Only on a direct flight is there one aircraft to name.
		if ( aircraft && 0 === stopCount ) {
			chips.appendChild( el( 'span', 'sky-aff__badge', aircraft ) );
		}

		var free = baggage( pick( row, [ 'free_baggage' ] ) );
		var cabinBag = baggage( pick( row, [ 'cabin_baggage' ] ) );

		if ( free ) {
			chips.appendChild( el( 'span', 'sky-aff__badge', ( t.baggage || 'Baggage' ) + ' ' + free ) );
		}

		if ( cabinBag ) {
			chips.appendChild( el( 'span', 'sky-aff__badge', ( t.cabinBag || 'Cabin' ) + ' ' + cabinBag ) );
		}

		// The platform tags fares itself — "charter", "no refund" and the
		// like — and localises them; pass them straight through.
		var labels = pick( row, [ 'fa' === cfg.language ? 'labels' : 'labels_en', 'labels' ] );

		if ( labels && Array.isArray( labels.labels ) ) {
			labels = labels.labels;
		}

		if ( Array.isArray( labels ) ) {
			labels.slice( 0, 2 ).forEach( function ( label ) {
				if ( label && 'string' === typeof label ) {
					chips.appendChild( el( 'span', 'sky-aff__badge', label ) );
				}
			} );
		}

		return chips;
	}

	/**
	 * One end of the journey: the clock, the airport code, the city.
	 *
	 * @param {string} time  Timestamp.
	 * @param {string} code  Airport code.
	 * @param {string} place City name.
	 * @param {number} shift Days later than departure, for an arrival.
	 * @return {HTMLElement}
	 */
	function legEnd( time, code, place, shift ) {
		var end = el( 'div', 'sky-aff__leg-end' );
		var clock = el( 'strong', 'sky-aff__time', clockTime( time ) || '—' );

		if ( shift > 0 ) {
			// An overnight leg lands on a later date; without this the times
			// read as a flight that arrives before it left.
			clock.appendChild( el( 'sup', 'sky-aff__dayshift', '+' + num( shift ) ) );
		}

		end.appendChild( clock );

		var label = code || '';

		if ( place && place !== code ) {
			label = label ? label + ' · ' + place : place;
		}

		if ( label ) {
			end.appendChild( el( 'span', 'sky-aff__place', label ) );
		}

		return end;
	}

	/**
	 * The line between the two ends: how long, and a pip for each stop.
	 *
	 * @param {*}      mins      Duration in minutes.
	 * @param {number} stopCount Stops.
	 * @return {HTMLElement}
	 */
	function legLine( mins, stopCount ) {
		var line = el( 'div', 'sky-aff__leg-line' );
		var dur = duration( mins );

		if ( dur ) {
			line.appendChild( el( 'span', 'sky-aff__leg-duration', dur ) );
		}

		var track = el( 'span', 'sky-aff__leg-track' );

		track.appendChild( el( 'span', 'sky-aff__leg-rail' ) );

		for ( var i = 0; i < Math.min( stopCount, 3 ); i++ ) {
			track.appendChild( el( 'span', 'sky-aff__leg-stop' ) );
		}

		track.appendChild( icon( PLANE_PATH, 'sky-aff__leg-plane', '' ) );
		line.appendChild( track );

		return line;
	}

	function renderFlights( container, rows, deepLink, append ) {
		if ( ! append ) {
			container.innerHTML = '';
		}

		rows.forEach( function ( row ) {
			var card = el( 'article', 'sky-aff__card sky-aff__card--flight' );

			// Real key first in each list; the alternatives cover other
			// providers and older API builds.
			var iata = pick( row, [ 'iata_code', 'airline.iata_code' ] ) || '';
			var flightNo = pick( row, [ 'flight_number', 'FlightNumber', 'number' ] ) || '';
			var depTime = pick( row, [ 'leave_date_time', 'departure_time', 'DepartureDateTime' ] ) || '';
			var arrTime = pick( row, [ 'arrival_date_time', 'arrival_time', 'ArrivalDateTime' ] ) || '';
			// adult_price_final is the fare after Sky's markups — the number the
			// platform will actually charge. price_adult is the pre-markup rate
			// and would under-quote every result.
			var price = pick( row, [ 'adult_price_final', 'total_price', 'price_adult', 'price', 'TotalFare' ] );
			var currency = pick( row, [ 'currency', 'currency_symbol', 'Currency' ] ) || '';
			var from = pick( row, [ 'from', 'origin_airport.city_en_name' ] ) || '';
			var to = pick( row, [ 'to', 'destination_airport.city_en_name' ] ) || '';
			var origin = pick( row, [ 'origin' ] ) || '';
			var dest = pick( row, [ 'destination' ] ) || '';
			var legs = pick( row, [ 'routes', 'legs' ] );
			var mins = pick( row, [ 'flight_duration' ] );

			// No `stops` field exists; a journey's stop count is the number of
			// legs beyond the first.
			var stopCount = Array.isArray( legs ) && legs.length
				? legs.length - 1
				: Number( pick( row, [ 'stops', 'stop_count' ] ) || 0 );

			var airlines = airlinesOf( row );
			var carrier = el( 'div', 'sky-aff__carrier' );
			var marks = el( 'div', 'sky-aff__logos' );

			airlines.slice( 0, 3 ).forEach( function ( airline ) {
				marks.appendChild( airlineMark( airline ) );
			} );

			carrier.appendChild( marks );

			var named = el( 'div', 'sky-aff__carrier-name' );

			named.appendChild(
				el(
					'span',
					'sky-aff__card-title',
					airlines.length > 1
						? ( t.severalAirlines || 'Several airlines' )
						: ( ( airlines[ 0 ] && airlines[ 0 ].name ) || '—' )
				)
			);

			var subtitle = [ iata, flightNo ].filter( Boolean ).join( ' ' );

			if ( subtitle ) {
				named.appendChild( el( 'span', 'sky-aff__card-sub', subtitle ) );
			}

			carrier.appendChild( named );
			card.appendChild( carrier );

			var journey = el( 'div', 'sky-aff__journey' );

			journey.appendChild( flightChips( row, stopCount ) );

			var leg = el( 'div', 'sky-aff__leg' );
			var shift = datePart( depTime ) && datePart( arrTime )
				? daysBetween( datePart( depTime ), datePart( arrTime ) )
				: 0;

			// The searched route and the flown route can differ: a DXB search
			// legitimately returns a flight out of SHJ, and hiding that is a
			// nasty surprise at the airport.
			leg.appendChild( legEnd( depTime, origin, from, 0 ) );
			leg.appendChild( legLine( mins, stopCount ) );
			leg.appendChild( legEnd( arrTime, dest, to, shift ) );
			journey.appendChild( leg );
			card.appendChild( journey );

			var buy = el( 'div', 'sky-aff__buy' );
			var priceText = money( price, currency );

			if ( priceText ) {
				var amount = el( 'span', 'sky-aff__price', priceText );

				amount.appendChild( el( 'span', 'sky-aff__price-note', t.perAdult || 'per adult' ) );
				buy.appendChild( amount );
			}

			// "system" is the platform's word for a fare sold at the airline's
			// own price rather than a charter seat, and it is the one thing on
			// the card a price-shopper actually wants to know.
			if ( 'system' === pick( row, [ 'ticket_type' ] ) && t.officialFare ) {
				buy.appendChild( el( 'span', 'sky-aff__fare-note', t.officialFare ) );
			}

			buy.appendChild( outLink( deepLink, t.viewOnPlatform || 'View & book', 'sky-aff__cta' ) );

			var seats = Number( pick( row, [ 'seat', 'seats' ] ) );

			// Past a handful, a seat count is noise; below it, it is the
			// reason someone books today.
			if ( isFinite( seats ) && seats > 0 && seats <= 8 ) {
				buy.appendChild(
					el( 'span', 'sky-aff__seats', ( t.seatsLeft || '%s seats left' ).replace( '%s', num( seats ) ) )
				);
			}

			card.appendChild( buy );
			container.appendChild( card );
		} );
	}

	/* ------------------------------------------------------------------ hotel */

	function initHotel( panel ) {
		var p = panelParts( panel );
		var active = null;
		var form = p.form;

		if ( ! form ) {
			return;
		}

		var placeInput = form.querySelector( '[data-sky-place]' );
		var placeId = form.querySelector( '[data-sky-place-id]' );
		var placeKind = form.querySelector( '[data-sky-place-kind]' );
		var placeName = form.querySelector( '[data-sky-place-name]' );
		var list = form.querySelector( '[data-sky-suggest="place"]' );
		var roomsList = form.querySelector( '[data-sky-rooms-list]' );
		var addRoom = form.querySelector( '[data-sky-add-room]' );
		var filtersBox = panel.querySelector( '[data-sky-filters="hotel"]' );
		var filtersBody = panel.querySelector( '[data-sky-filters-body]' );
		var clearFilters = panel.querySelector( '[data-sky-clear-filters]' );

		var rooms = [ { adults: 2, children: 0, ages: [] } ];
		var lastQuery = null;
		var activeFilters = {};

		function renderRooms() {
			roomsList.innerHTML = '';

			rooms.forEach( function ( room, index ) {
				var wrap = el( 'div', 'sky-aff__room' );

				wrap.appendChild( el( 'span', 'sky-aff__room-label', ( t.room || 'Room' ) + ' ' + ( index + 1 ) ) );

				var adults = el( 'label', 'sky-aff__room-field' );

				adults.appendChild( el( 'span', null, t.adults || 'Adults' ) );

				var adultsInput = document.createElement( 'input' );

				adultsInput.type = 'number';
				adultsInput.min = '1';
				adultsInput.max = '12';
				adultsInput.value = room.adults;
				adultsInput.addEventListener( 'change', function () {
					room.adults = Math.max( 1, Number( adultsInput.value ) || 1 );
				} );
				adults.appendChild( adultsInput );
				wrap.appendChild( adults );

				var kids = el( 'label', 'sky-aff__room-field' );

				kids.appendChild( el( 'span', null, t.children || 'Children' ) );

				var kidsInput = document.createElement( 'input' );

				kidsInput.type = 'number';
				kidsInput.min = '0';
				kidsInput.max = '8';
				kidsInput.value = room.children;
				kidsInput.addEventListener( 'change', function () {
					room.children = Math.max( 0, Number( kidsInput.value ) || 0 );

					// Providers price a child by age, so each one needs one.
					while ( room.ages.length < room.children ) {
						room.ages.push( 8 );
					}

					room.ages = room.ages.slice( 0, room.children );
					renderRooms();
				} );
				kids.appendChild( kidsInput );
				wrap.appendChild( kids );

				room.ages.forEach( function ( age, ageIndex ) {
					var ageField = el( 'label', 'sky-aff__room-field sky-aff__room-field--age' );

					ageField.appendChild( el( 'span', null, '#' + ( ageIndex + 1 ) ) );

					var ageInput = document.createElement( 'input' );

					ageInput.type = 'number';
					ageInput.min = '0';
					ageInput.max = '17';
					ageInput.value = age;
					ageInput.addEventListener( 'change', function () {
						room.ages[ ageIndex ] = Math.max( 0, Math.min( 17, Number( ageInput.value ) || 0 ) );
					} );
					ageField.appendChild( ageInput );
					wrap.appendChild( ageField );
				} );

				if ( rooms.length > 1 ) {
					var remove = el( 'button', 'sky-aff__link-btn', t.removeRoom || 'Remove' );

					remove.type = 'button';
					remove.addEventListener( 'click', function () {
						rooms.splice( index, 1 );
						renderRooms();
					} );
					wrap.appendChild( remove );
				}

				roomsList.appendChild( wrap );
			} );
		}

		renderRooms();

		if ( addRoom ) {
			addRoom.addEventListener( 'click', function () {
				if ( rooms.length >= 6 ) {
					return;
				}

				rooms.push( { adults: 2, children: 0, ages: [] } );
				renderRooms();
			} );
		}

		typeahead( {
			input: placeInput,
			list: list,
			// 0, like the flight field: clicking in opens the destination list
			// the proxy assembles from the configured names, and typing then
			// runs the provider's own place search.
			minChars: 0,
			labelOf: function ( item ) {
				return item.name || item.label || '';
			},
			secondaryOf: function ( item ) {
				// `label` is the provider's fully-qualified name
				// ("Dubai Marina, Dubai, Dubai Emirate, UAE") — too long for
				// the main line, but exactly what disambiguates two places
				// with the same name.
				var detail = item.label && item.label !== item.name ? item.label : '';

				if ( ! detail ) {
					// country is an object on the real API, a string on some
					// builds.
					detail = pick( item, [ 'country.name', 'country' ] ) || '';
				}

				if ( item.hotels ) {
					detail = detail
						? detail + ' · ' + num( item.hotels ) + ' ' + ( t.properties || 'properties' )
						: num( item.hotels ) + ' ' + ( t.properties || 'properties' );
				}

				return detail;
			},
			fetch: function ( query, signal ) {
				return api( '/hotels/places', { query: { q: query, limit: 12 }, signal: signal } )
					.then( function ( payload ) {
						return Array.isArray( payload.data ) ? payload.data : [];
					} );
			},
			onClear: function () {
				placeId.value = '';
				placeName.value = '';
			},
			onChoose: function ( item ) {
				placeId.value = item.id || '';
				// The API tells us which request field this id belongs in;
				// sending a property id as a city (or vice versa) is a 422.
				placeKind.value = 'hotel' === item.search_as ? 'hotel' : 'city';
				placeName.value = item.name || item.label || '';
			}
		} );

		function encodeRooms() {
			return rooms.map( function ( room ) {
				var part = room.adults + '-' + room.children;

				if ( room.ages.length ) {
					part += '-' + room.ages.join( '.' );
				}

				return part;
			} ).join( '_' );
		}

		function resultsLink() {
			return affiliateUrl( cfg.links.hotel + 'result', {
				city: 'city' === placeKind.value ? placeId.value : '',
				city_name: placeName.value,
				start_date: form.querySelector( 'input[name="start_date"]' ).value,
				end_date: form.querySelector( 'input[name="end_date"]' ).value,
				rooms: encodeRooms()
			} );
		}

		function runSearch( page ) {
			var checkIn = form.querySelector( 'input[name="start_date"]' ).value;
			var checkOut = form.querySelector( 'input[name="end_date"]' ).value;

			if ( ! placeId.value ) {
				p.say( t.typeToSearch || 'Choose a destination.', 'error' );

				return;
			}

			if ( ! checkIn || ! checkOut || checkOut <= checkIn ) {
				p.say( t.error, 'error' );

				return;
			}

			var body = {
				start_date: checkIn,
				end_date: checkOut,
				rooms: rooms,
				page: page || 1
			};

			body[ placeKind.value ] = placeId.value;

			if ( Object.keys( activeFilters ).length ) {
				body.filters = activeFilters;
			}

			lastQuery = body;

			var first = 1 === ( page || 1 );
			var link = resultsLink();
			var context = {
				nights: daysBetween( checkIn, checkOut ),
				link: link,
				detailBase: cfg.links.hotel,
				query: {
					city: 'city' === placeKind.value ? placeId.value : '',
					city_name: placeName.value,
					start_date: checkIn,
					end_date: checkOut,
					rooms: encodeRooms()
				}
			};

			if ( active ) {
				active.cancel();
			}

			if ( first ) {
				p.clear();
			}

			// Placeholders only on a fresh search. "Load more" must leave the
			// page the visitor is reading exactly where it is.
			var progress = p.track( 'hotel', first );
			var shown = 0;
			var total = 0;
			var pagination = {};

			active = startSearch( 'hotel', body, {
				meta: function ( meta, count ) {
					meta = meta || {};
					total = count;
					pagination = meta.pagination || {};

					// The facets describe the whole result set, so they can go
					// up before a single property has been rendered.
					renderHotelFilters( meta );
				},

				items: function ( bucket, items ) {
					renderHotels( p.results, items, context, ! first || shown > 0 );
					shown += items.length;
				},

				done: function ( info ) {
					progress.stop();

					if ( ! shown && first ) {
						p.clear();
						p.say( t.noResults || 'No results.', 'empty' );
						p.seeAll( link );

						return;
					}

					p.summary( total || shown, info );
					p.seeAll( link );

					if ( pagination.has_next_page && p.more ) {
						p.more.hidden = false;
						p.more.querySelector( '[data-sky-load-more]' ).onclick = function () {
							runSearch( ( pagination.page || 1 ) + 1 );
						};
					} else if ( p.more ) {
						p.more.hidden = true;
					}
				},

				error: function ( error ) {
					progress.stop();

					if ( first ) {
						p.clear();
					}

					p.say( error.message || t.error, 'error' );
					p.seeAll( link );
				}
			} );
		}

		/**
		 * Facet groups the search endpoint will actually accept.
		 *
		 * The providers return around twenty groups, but the API's own
		 * validation only allows these — sending anything else 422s the whole
		 * search. Anything not listed here is left to the platform, which the
		 * "see all results" link hands the visitor over to.
		 *
		 * `mode` is how the value is sent: a list of ids, one id, or a flag.
		 */
		var HOTEL_FACETS = [
			{ key: 'stars', mode: 'multi' },
			{ key: 'property_types', mode: 'multi' },
			{ key: 'facilities', mode: 'multi' },
			{ key: 'room_facilities', mode: 'multi' },
			{ key: 'chains', mode: 'multi' },
			{ key: 'districts', mode: 'multi' },
			{ key: 'landmarks', mode: 'multi' },
			{ key: 'meal_plan', mode: 'single' },
			{ key: 'review_score', mode: 'single' },
			{ key: 'free_cancellation', mode: 'flag' }
		];

		// Long facets (40 neighbourhoods, 25 chains) would bury the results.
		var FACET_OPTION_CAP = 8;

		function renderHotelFilters( meta ) {
			if ( ! filtersBox || ! filtersBody ) {
				return;
			}

			var available = meta.available_filters || {};

			filtersBody.innerHTML = '';

			var rendered = 0;

			HOTEL_FACETS.forEach( function ( facet ) {
				var group = available[ facet.key ];
				var options = group && Array.isArray( group.options ) ? group.options : null;

				if ( ! options || ! options.length ) {
					return;
				}

				var wrap = el( 'div', 'sky-aff__filter-group' );

				wrap.appendChild(
					el( 'span', 'sky-aff__filter-label', group.title || facet.key )
				);

				options.slice( 0, FACET_OPTION_CAP ).forEach( function ( option ) {
					if ( ! option || undefined === option.id ) {
						return;
					}

					var label = document.createElement( 'label' );

					label.className = 'sky-aff__chip';

					var box = document.createElement( 'input' );

					box.type = 'multi' === facet.mode ? 'checkbox' : 'radio';

					if ( 'multi' !== facet.mode ) {
						box.name = 'sky-facet-' + facet.key;
					}

					box.checked = isFacetActive( facet, option.id );
					box.addEventListener( 'change', function () {
						toggleFacet( facet, option.id, box.checked );
						runSearch( 1 );
					} );

					label.appendChild( box );
					label.appendChild( el( 'span', null, option.name || String( option.id ) ) );

					if ( option.count ) {
						label.appendChild( el( 'span', 'sky-aff__chip-count', num( option.count ) ) );
					}

					wrap.appendChild( label );
				} );

				filtersBody.appendChild( wrap );
				rendered++;
			} );

			filtersBox.hidden = 0 === rendered;
			updateFilterCount();
		}

		/**
		 * Show how many filters are applied, so a collapsed panel still says
		 * that it is doing something.
		 */
		function updateFilterCount() {
			var badge = panel.querySelector( '[data-sky-filters-count]' );

			if ( ! badge ) {
				return;
			}

			var count = 0;

			Object.keys( activeFilters ).forEach( function ( key ) {
				var value = activeFilters[ key ];

				count += Array.isArray( value ) ? value.length : 1;
			} );

			badge.textContent = count ? String( num( count ) ) : '';
			badge.hidden = 0 === count;
		}

		/**
		 * Whether one facet option is currently applied.
		 *
		 * @param {Object} facet Facet definition.
		 * @param {*}      id    Option id.
		 * @return {boolean} True when applied.
		 */
		function isFacetActive( facet, id ) {
			var current = activeFilters[ facet.key ];

			if ( 'multi' === facet.mode ) {
				return Array.isArray( current ) && current.indexOf( id ) !== -1;
			}

			if ( 'flag' === facet.mode ) {
				return true === current;
			}

			return current === id;
		}

		/**
		 * Apply or remove one facet option.
		 *
		 * @param {Object}  facet Facet definition.
		 * @param {*}       id    Option id.
		 * @param {boolean} on    Whether it is now selected.
		 */
		function toggleFacet( facet, id, on ) {
			if ( 'multi' === facet.mode ) {
				var current = Array.isArray( activeFilters[ facet.key ] )
					? activeFilters[ facet.key ].slice()
					: [];

				if ( on ) {
					current.push( id );
				} else {
					current = current.filter( function ( value ) {
						return value !== id;
					} );
				}

				if ( current.length ) {
					activeFilters[ facet.key ] = current;
				} else {
					delete activeFilters[ facet.key ];
				}

				return;
			}

			if ( on ) {
				activeFilters[ facet.key ] = 'flag' === facet.mode ? true : id;
			} else {
				delete activeFilters[ facet.key ];
			}
		}

		if ( clearFilters ) {
			clearFilters.addEventListener( 'click', function () {
				activeFilters = {};

				if ( lastQuery ) {
					runSearch( 1 );
				}
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			activeFilters = {};
			runSearch( 1 );
		} );
	}

	function renderHotels( container, rows, ctx, append ) {
		if ( ! append ) {
			container.innerHTML = '';
		}

		rows.forEach( function ( row ) {
			var hotel = row.Hotel || row.hotel || row;
			var name = pick( hotel, [ 'name', 'HotelName', 'Name', 'title' ] ) || '—';
			// address.city is an object ({id, external_id, name}); reading it
			// directly renders "[object Object]".
			var city = pick( hotel, [ 'address.city.name', 'CityName', 'city_name', 'City' ] ) || '';
			var country = pick( hotel, [ 'address.country.name' ] ) || '';
			var stars = pick( hotel, [ 'rating', 'HotelRating', 'stars', 'Rating' ] );
			// images[] entries are objects; thumbnail_url is the sized variant.
			var image = pick( hotel, [
				'images.0.thumbnail_url',
				'images.0.url',
				'ThumbnailUrl',
				'image',
				'main_photo'
			] );
			var price = pick( row, [ 'NetRate', 'price', 'total_price', 'BaseRate', 'Price' ] );
			// `currency` is a numeric enum on hotel offers (3), while
			// currency_symbol carries the ISO code Intl needs.
			var currency = pick( row, [ 'currency_symbol', 'Currency' ] ) || '';
			var candidate = pick( row, [ 'candidate_id', 'HotelId', 'FareSourceCode', 'id' ] ) || '';
			var freeCancel = pick( row, [ 'NonRefundable' ] );
			var score = pick( hotel, [ 'review_score' ] );
			var scoreWord = pick( hotel, [ 'review_word' ] ) || '';
			var reviews = pick( hotel, [ 'review_count' ] );

			var card = el( 'article', 'sky-aff__card sky-aff__card--hotel' );

			if ( image ) {
				var figure = el( 'div', 'sky-aff__thumb' );
				var img = document.createElement( 'img' );

				img.src = String( image );
				img.alt = '';
				img.loading = 'lazy';
				img.decoding = 'async';
				// Provider image hosts expire links; a broken icon in a grid of
				// cards looks worse than a plain coloured block.
				img.addEventListener( 'error', function () {
					figure.remove();
				} );
				figure.appendChild( img );
				card.appendChild( figure );
			}

			var bodyEl = el( 'div', 'sky-aff__card-body' );
			var head = el( 'div', 'sky-aff__card-head' );

			head.appendChild( el( 'span', 'sky-aff__card-title', name ) );

			// rating 0 means unrated, not zero stars — omit rather than show
			// an empty row of stars.
			var starCount = Math.max( 0, Math.min( 5, Number( stars ) || 0 ) );

			if ( starCount > 0 ) {
				head.appendChild( el( 'span', 'sky-aff__stars', '★'.repeat( starCount ) ) );
			}

			bodyEl.appendChild( head );

			var place = [ city, country ].filter( Boolean ).join( ', ' );

			if ( place ) {
				bodyEl.appendChild( el( 'p', 'sky-aff__card-sub', place ) );
			}

			if ( isFinite( Number( score ) ) && Number( score ) > 0 ) {
				var reviewRow = el( 'p', 'sky-aff__reviews' );

				reviewRow.appendChild( el( 'strong', 'sky-aff__score', num( score ) ) );

				if ( scoreWord ) {
					reviewRow.appendChild( el( 'span', null, ' ' + scoreWord ) );
				}

				if ( reviews ) {
					reviewRow.appendChild(
						el( 'span', 'sky-aff__muted', ' · ' + num( reviews ) + ' ' + ( t.reviews || 'reviews' ) )
					);
				}

				bodyEl.appendChild( reviewRow );
			}

			if ( false === freeCancel ) {
				bodyEl.appendChild( el( 'span', 'sky-aff__badge sky-aff__badge--good', t.freeCancellation || 'Free cancellation' ) );
			}

			var foot = el( 'div', 'sky-aff__card-foot' );
			var priceText = money( price, currency );

			if ( priceText ) {
				var priceWrap = el( 'span', 'sky-aff__price', priceText );

				if ( ctx.nights > 0 ) {
					priceWrap.appendChild(
						el( 'small', 'sky-aff__price-note', ' / ' + num( ctx.nights ) + ' ' + ( t.nights || 'nights' ) )
					);
				}

				foot.appendChild( priceWrap );
			}

			var href = candidate
				? affiliateUrl( ctx.detailBase + encodeURIComponent( candidate ), ctx.query )
				: ctx.link;

			foot.appendChild( outLink( href, t.viewOnPlatform || 'View & book', 'sky-aff__cta' ) );
			bodyEl.appendChild( foot );
			card.appendChild( bodyEl );
			container.appendChild( card );
		} );
	}

	/* --------------------------------------------------------------- activity */

	function initActivity( panel ) {
		var p = panelParts( panel );
		var active = null;
		var form = p.form;

		if ( ! form ) {
			return;
		}

		var countrySel = form.querySelector( '[data-sky-country]' );
		var citySel = form.querySelector( '[data-sky-city]' );
		var categorySel = form.querySelector( '[data-sky-category]' );
		var keyword = form.querySelector( 'input[name="q"]' );
		var taxonomy = { countries: [], categories: [] };

		// Text in the keyword field that stands for a filter the visitor
		// picked — "Dubai" once they chose the city — rather than a word to
		// search titles for. Sent as a keyword too, it would narrow the city's
		// activities to the few that repeat its name.
		var standIn = '';

		function fillSelect( select, options, placeholder ) {
			select.innerHTML = '';
			select.appendChild( new Option( placeholder, '' ) );

			options.forEach( function ( option ) {
				select.appendChild( new Option( option.label, option.value ) );
			} );
		}

		function fillCities( country ) {
			if ( ! citySel ) {
				return;
			}

			fillSelect(
				citySel,
				( ( country && country.cities ) || [] ).map( function ( city ) {
					return { value: city.id, label: city.name };
				} ),
				t.anyCity || 'Any city'
			);
		}

		function countryById( id ) {
			return taxonomy.countries.filter( function ( country ) {
				return country.id === id;
			} )[ 0 ] || null;
		}

		// The taxonomy is tenant-global and cached upstream, so one fetch per
		// page load populates every dropdown and the keyword suggestions.
		api( '/activities/config' ).then( function ( payload ) {
			taxonomy = activityTaxonomy( payload.data || {} );

			if ( taxonomy.countries.length && countrySel ) {
				fillSelect(
					countrySel,
					taxonomy.countries.map( function ( country ) {
						return { value: country.id, label: country.name };
					} ),
					t.anyCountry || 'Any country'
				);
			}

			if ( taxonomy.categories.length && categorySel ) {
				fillSelect(
					categorySel,
					taxonomy.categories.map( function ( category ) {
						// Sub-categories indent under their parent: a select
						// has no other way to show a tree.
						return {
							value: category.id,
							label: new Array( category.depth + 1 ).join( '   ' ) + category.name
						};
					} ),
					t.anyCategory || 'Any category'
				);
			}
		} ).catch( function () {
			// A missing taxonomy is not fatal — keyword search still works, so
			// the dropdowns simply stay on their placeholder.
		} );

		if ( countrySel ) {
			countrySel.addEventListener( 'change', function () {
				fillCities( countryById( countrySel.value ) );
			} );
		}

		var suggestList = form.querySelector( '[data-sky-suggest="activity"]' );

		if ( keyword && suggestList ) {
			activitySuggest( {
				input: keyword,
				list: suggestList,
				taxonomy: function () {
					return taxonomy;
				},
				onPlace: function ( country, city ) {
					if ( countrySel ) {
						countrySel.value = country.id;
					}

					fillCities( country );

					if ( citySel ) {
						citySel.value = city ? city.id : '';
					}

					standIn = keyword.value.trim();
					run( 1 );
				},
				onSearch: function () {
					run( 1 );
				},
				onEdit: function () {
					standIn = '';
				}
			} );
		}

		var page = 1;

		function currentQuery() {
			return {
				q: keyword && keyword.value.trim() !== standIn ? keyword.value.trim() : '',
				country: countrySel ? countrySel.value : '',
				city: citySel ? citySel.value : '',
				category: categorySel ? categorySel.value : '',
				min_price: form.querySelector( 'input[name="min_price"]' ).value,
				max_price: form.querySelector( 'input[name="max_price"]' ).value
			};
		}

		function resultsLink() {
			var q = currentQuery();

			return affiliateUrl( cfg.links.activity + 'result', {
				keyword: q.q,
				category: q.category,
				city: q.city,
				// The storefront drops country when a city is set; match it so
				// the link and the page it opens agree.
				country: q.city ? '' : q.country,
				language: cfg.language
			} );
		}

		function run( nextPage ) {
			var query = currentQuery();

			query.page = nextPage || 1;
			query.per_page = 12;

			var first = 1 === query.page;
			var link = resultsLink();

			if ( active ) {
				active.cancel();
			}

			if ( first ) {
				p.clear();
			}

			var progress = p.track( 'activity', first );
			var shown = 0;
			var total = 0;

			page = query.page;

			active = startSearch( 'activity', query, {
				meta: function ( meta, count ) {
					total = count;
				},

				items: function ( bucket, items ) {
					renderActivities( p.results, items, ! first || shown > 0 );
					shown += items.length;
				},

				done: function ( info ) {
					progress.stop();

					if ( ! shown && first ) {
						p.clear();
						p.say( t.noResults || 'No results.', 'empty' );
						p.seeAll( link );

						return;
					}

					p.summary( total || shown, info );
					p.seeAll( link );

					if ( p.more ) {
						// A full page is the only hint the list continues:
						// this endpoint reports no page count.
						var hasMore = shown >= query.per_page;

						p.more.hidden = ! hasMore;

						if ( hasMore ) {
							p.more.querySelector( '[data-sky-load-more]' ).onclick = function () {
								run( page + 1 );
							};
						}
					}
				},

				error: function ( error ) {
					progress.stop();

					if ( first ) {
						p.clear();
					}

					p.say( error.message || t.error, 'error' );
					p.seeAll( link );
				}
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			run( 1 );
		} );

		// Something to look at before anything is typed: the empty form is a
		// search for every activity, so the tab opens on the first cards of
		// that — but only when it is the tab actually on screen, otherwise a
		// hidden panel costs every visitor a request they never asked for.
		if ( panel.classList.contains( 'is-active' ) ) {
			run( 1 );
		} else {
			var tab = document.querySelector( '[data-sky-tab="activity"]' );

			if ( tab ) {
				tab.addEventListener( 'click', function once() {
					tab.removeEventListener( 'click', once );
					run( 1 );
				} );
			}
		}
	}

	/**
	 * Flatten the activity taxonomy into what the form needs.
	 *
	 * The supplier nests locations continent → country → state → city, each
	 * level wrapped in { data: [...] }, keys the continents "0".."n" next to a
	 * product_count, and wraps the category tree the same way. Older tenants
	 * sent flat country rows with a `cities` array. Both come out as:
	 *
	 *   countries:  [ { id, name, cities: [ { id, name } ] } ]  (A→Z)
	 *   categories: [ { id, name, depth } ]                     (tree order)
	 *
	 * @param {Object} data /activities/config payload data.
	 * @return {{countries: Array, categories: Array}}
	 */
	function activityTaxonomy( data ) {
		function list( value ) {
			if ( Array.isArray( value ) ) {
				return value;
			}

			if ( value && Array.isArray( value.data ) ) {
				return value.data;
			}

			if ( value && 'object' === typeof value ) {
				return Object.keys( value ).map( function ( key ) {
					return value[ key ];
				} ).filter( function ( item ) {
					return item && 'object' === typeof item;
				} );
			}

			return [];
		}

		function idOf( row, keys ) {
			return String( pick( row, keys ) || '' );
		}

		var countries = [];
		var seen = {};

		function addCountry( row, cities ) {
			var id = idOf( row, [ 'countryUuid', 'uuid', 'id', 'code' ] );
			var name = row.country || row.name || '';

			if ( ! id || ! name || seen[ id ] ) {
				return;
			}

			seen[ id ] = true;
			countries.push( {
				id: id,
				name: String( name ),
				cities: cities.map( function ( city ) {
					return {
						id: idOf( city, [ 'cityUuid', 'uuid', 'id' ] ),
						name: String( city.city || city.name || '' )
					};
				} ).filter( function ( city ) {
					return city.id && city.name;
				} ).sort( function ( a, b ) {
					return a.name.localeCompare( b.name );
				} )
			} );
		}

		list( data.locations || data.countries ).forEach( function ( entry ) {
			// Either a continent map { "0": continent, product_count }, a
			// continent, or already a country.
			var continents = entry && entry.countries ? [ entry ] : list( entry ).filter( function ( item ) {
				return item.countries;
			} );

			if ( ! continents.length && entry && ( entry.country || entry.name ) ) {
				addCountry( entry, list( entry.cities ) );

				return;
			}

			continents.forEach( function ( continent ) {
				list( continent.countries ).forEach( function ( country ) {
					var cities = list( country.cities );

					list( country.states ).forEach( function ( state ) {
						cities = cities.concat( list( state.cities ) );
					} );

					addCountry( country, cities );
				} );
			} );
		} );

		countries.sort( function ( a, b ) {
			return a.name.localeCompare( b.name );
		} );

		var categories = [];

		( function walk( rows, depth ) {
			rows.forEach( function ( row ) {
				var id = idOf( row, [ 'uuid', 'id', 'slug' ] );
				var name = row.name || row.title || '';

				if ( id && name ) {
					categories.push( { id: id, name: String( name ), depth: depth } );
				}

				walk( list( row.children ), depth + 1 );
			} );
		}( list( data.categories ), 0 ) );

		return { countries: countries, categories: categories };
	}

	/**
	 * Live suggestions under the activity keyword field.
	 *
	 * Two groups, so what a visitor types always finds something: places
	 * matched on the spot from the taxonomy already on the page, and actual
	 * activities from the platform a moment later. Picking a place sets that
	 * filter and searches; picking an activity goes straight to it; the last
	 * row searches the words as typed. Categories are left to their select.
	 *
	 * @param {Object}   opts
	 * @param {HTMLInputElement} opts.input
	 * @param {HTMLElement}      opts.list
	 * @param {Function} opts.taxonomy   () => { countries, categories }.
	 * @param {Function} opts.onPlace    (country, city|null).
	 * @param {Function} opts.onSearch   Search the typed words.
	 * @param {Function} opts.onEdit     The text changed by hand.
	 */
	function activitySuggest( opts ) {
		var input = opts.input;
		var list = opts.list;
		var options = [];
		var activeIndex = -1;
		var controller = null;
		var remote = { query: null, items: null, failed: false };
		var cache = {};
		var listId = list.id || ( input.id ? input.id + '-suggest' : '' );

		if ( listId ) {
			list.id = listId;
			input.setAttribute( 'aria-controls', listId );
		}

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );

		function fold( text ) {
			return String( text || '' ).toLocaleLowerCase();
		}

		/**
		 * Up to `limit` entries whose name contains the query, the ones that
		 * start with it first — "Rome" before "Jerome".
		 */
		function matching( rows, query, limit, nameOf ) {
			var starts = [];
			var within = [];

			rows.forEach( function ( row ) {
				var name = fold( nameOf( row ) );
				var at = name.indexOf( query );

				if ( 0 === at ) {
					starts.push( row );
				} else if ( at > 0 && /[\s\-(,'’]/.test( name.charAt( at - 1 ) ) ) {
					// Only at a word boundary: "an" should find "Antalya" and
					// "San Diego", not every name with those two letters in it.
					within.push( row );
				}
			} );

			return starts.concat( within ).slice( 0, limit );
		}

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			options = [];
			activeIndex = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function choose( option ) {
			if ( ! option ) {
				return;
			}

			runDebounced.cancel();

			if ( 'activity' === option.kind ) {
				close();

				return;
			}

			if ( controller ) {
				controller.abort();
				controller = null;
			}

			if ( 'search' !== option.kind ) {
				input.value = option.label;
			}

			close();

			if ( 'place' === option.kind ) {
				opts.onPlace( option.country, option.city );
			} else {
				opts.onSearch();
			}
		}

		function highlight( index ) {
			activeIndex = index;

			options.forEach( function ( option, i ) {
				var on = i === index;

				option.node.classList.toggle( 'is-active', on );
				option.node.setAttribute( 'aria-selected', on ? 'true' : 'false' );

				if ( on ) {
					if ( option.node.id ) {
						input.setAttribute( 'aria-activedescendant', option.node.id );
					}

					if ( option.node.scrollIntoView ) {
						option.node.scrollIntoView( { block: 'nearest' } );
					}
				}
			} );
		}

		function group( label ) {
			var li = el( 'li', 'sky-aff__suggest-group', label );

			li.setAttribute( 'role', 'presentation' );
			list.appendChild( li );
		}

		function add( option, node ) {
			node.setAttribute( 'role', 'option' );
			node.setAttribute( 'aria-selected', 'false' );

			if ( listId ) {
				node.id = listId + '-' + options.length;
			}

			node.addEventListener( 'mousedown', function ( event ) {
				// Keeps focus in the field, so blur does not close the list
				// before the click lands. An activity is a real link: its
				// click still follows it, in whatever window links open in.
				event.preventDefault();
			} );

			node.addEventListener( 'click', function () {
				choose( option );
			} );

			option.node = node;
			options.push( option );
			list.appendChild( node );
		}

		function line( node, icon, main, sub, aside ) {
			var badge = el( 'span', 'sky-aff__suggest-icon' );

			badge.innerHTML = icon;
			badge.setAttribute( 'aria-hidden', 'true' );
			node.appendChild( badge );

			var text = el( 'span', 'sky-aff__suggest-text' );

			text.appendChild( el( 'span', 'sky-aff__suggest-main', main ) );

			if ( sub ) {
				text.appendChild( el( 'span', 'sky-aff__suggest-sub', sub ) );
			}

			node.appendChild( text );

			if ( aside ) {
				node.appendChild( el( 'span', 'sky-aff__suggest-aside', aside ) );
			}

			return node;
		}

		function render() {
			var query = fold( input.value.trim() );
			var taxonomy = opts.taxonomy();

			list.innerHTML = '';
			options = [];
			activeIndex = -1;

			// Nothing to suggest for an empty field: categories have their own
			// select further down the form.
			if ( ! query ) {
				close();

				return;
			}

			var places = [];

			taxonomy.countries.forEach( function ( country ) {
				country.cities.forEach( function ( city ) {
					places.push( { country: country, city: city, name: city.name } );
				} );
			} );

			places = matching( places, query, 4, function ( place ) {
				return place.name;
			} ).concat( matching( taxonomy.countries, query, 2, function ( country ) {
				return country.name;
			} ).map( function ( country ) {
				return { country: country, city: null, name: country.name };
			} ) );

			if ( places.length ) {
				group( t.suggestPlaces || 'Destinations' );

				places.forEach( function ( place ) {
					add(
						{ kind: 'place', label: place.name, country: place.country, city: place.city },
						line(
							el( 'li', 'sky-aff__suggest-item' ),
							ICONS.pin,
							place.name,
							place.city ? place.country.name : ( t.allOfCountry || 'Everywhere in this country' )
						)
					);
				} );
			}

			group( t.activity || 'Activities' );

			var current = remote.query === query ? remote : null;

			if ( ! current || ( ! current.items && ! current.failed ) ) {
				var wait = el( 'li', 'sky-aff__suggest-empty sky-aff__suggest-loading', t.findingActivities || 'Finding activities…' );

				wait.setAttribute( 'role', 'presentation' );
				list.appendChild( wait );
			} else if ( current.items && current.items.length ) {
				current.items.forEach( function ( item ) {
					var href = item.id
						? affiliateUrl( cfg.links.activity + encodeURIComponent( item.id ), {} )
						: affiliateUrl( cfg.links.activity + 'result', { keyword: input.value.trim(), language: cfg.language } );
					var a = outLink( href, '', 'sky-aff__suggest-item sky-aff__suggest-item--activity' );
					var thumb = el( 'span', 'sky-aff__suggest-thumb' );

					a.textContent = '';
					a.tabIndex = -1;

					if ( item.image ) {
						var img = document.createElement( 'img' );

						img.src = item.image;
						img.referrerPolicy = 'no-referrer';
						img.alt = '';
						img.loading = 'lazy';
						img.decoding = 'async';
						img.addEventListener( 'error', function () {
							img.remove();
						} );
						thumb.appendChild( img );
					}

					a.appendChild( thumb );

					var text = el( 'span', 'sky-aff__suggest-text' );

					text.appendChild( el( 'span', 'sky-aff__suggest-main', item.title ) );
					text.appendChild( el( 'span', 'sky-aff__suggest-sub', [ item.city, item.type ].filter( Boolean ).join( ' · ' ) ) );
					a.appendChild( text );

					var price = money( item.price, item.currency );

					if ( price ) {
						a.appendChild( el( 'span', 'sky-aff__suggest-aside', price ) );
					}

					add( { kind: 'activity', label: item.title, href: href }, a );
				} );
			} else {
				var none = el( 'li', 'sky-aff__suggest-empty', t.noActivityMatches || 'No activity names match — try searching anyway.' );

				none.setAttribute( 'role', 'presentation' );
				list.appendChild( none );
			}

			add(
				{ kind: 'search', label: input.value.trim() },
				line(
					el( 'li', 'sky-aff__suggest-item sky-aff__suggest-item--all' ),
					ICONS.search,
					( t.searchFor || 'Search for “%s”' ).replace( '%s', input.value.trim() )
				)
			);

			open();
		}

		function open() {
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function fetchRemote( query ) {
			if ( cache[ query ] ) {
				remote = { query: query, items: cache[ query ], failed: false };
				render();

				return;
			}

			if ( controller ) {
				controller.abort();
			}

			controller = window.AbortController ? new AbortController() : null;
			remote = { query: query, items: null, failed: false };

			api( '/activities/suggest', {
				query: { q: query },
				signal: controller ? controller.signal : undefined
			} ).then( function ( payload ) {
				cache[ query ] = Array.isArray( payload.data ) ? payload.data : [];

				if ( remote.query === query ) {
					remote.items = cache[ query ];
					render();
				}
			} ).catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				if ( remote.query === query ) {
					remote.failed = true;
					render();
				}
			} );
		}

		var runDebounced = debounce( function () {
			var query = fold( input.value.trim() );

			if ( query.length >= 2 && document.activeElement === input ) {
				fetchRemote( query );
			}
		}, 280 );

		input.addEventListener( 'input', function () {
			opts.onEdit();

			var query = fold( input.value.trim() );

			if ( 1 === query.length ) {
				// One letter matches half the catalogue: say what it takes
				// instead of guessing.
				runDebounced.cancel();
				list.innerHTML = '';
				options = [];
				list.appendChild( el( 'li', 'sky-aff__suggest-empty', t.typeToSearch || 'Type at least 2 characters…' ) );
				open();

				return;
			}

			// Places answer instantly; activities follow once
			// typing pauses.
			render();
			runDebounced();
		} );

		input.addEventListener( 'focus', function () {
			var query = fold( input.value.trim() );

			render();

			if ( query.length >= 2 && remote.query !== query ) {
				fetchRemote( query );
			}
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 150 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				close();

				return;
			}

			if ( list.hidden || ! options.length ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( activeIndex + 1 >= options.length ? 0 : activeIndex + 1 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( activeIndex <= 0 ? options.length - 1 : activeIndex - 1 );
			} else if ( 'Enter' === event.key ) {
				var option = options[ activeIndex ];

				if ( ! option ) {
					// Plain Enter searches the words, via the form's submit.
					runDebounced.cancel();
					close();

					return;
				}

				event.preventDefault();

				// An activity is a link: clicking it is the keyboard
				// equivalent, and its click handler does the rest.
				if ( 'activity' === option.kind ) {
					option.node.click();
				} else {
					choose( option );
				}
			}
		} );
	}

	/** Small inline icons for the suggestion rows. Stroke follows currentColor. */
	var ICONS = ( function () {
		function svg( path ) {
			return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
				'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
		}

		return {
			pin: svg( '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>' ),
			search: svg( '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>' )
		};
	}() );

	function renderActivities( container, rows, append ) {
		if ( ! append ) {
			container.innerHTML = '';
		}

		rows.forEach( function ( row ) {
			var name = pick( row, [ 'title', 'name', 'productName' ] ) || '—';
			var city = pick( row, [ 'city', 'location.city', 'cityName' ] ) || '';
			var image = pick( row, [ 'photo', 'image', 'thumbnail', 'photos.0', 'images.0' ] );
			var price = pick( row, [ 'basePrice', 'base_price', 'price', 'fromPrice' ] );
			var currency = pick( row, [ 'currency', 'currencyCode' ] ) || '';
			var id = pick( row, [ 'productUuid', 'uuid', 'id', 'productId' ] ) || '';

			var card = el( 'article', 'sky-aff__card sky-aff__card--activity' );

			if ( image ) {
				var figure = el( 'div', 'sky-aff__thumb' );
				var img = document.createElement( 'img' );

				img.src = String( image );
				// Some supplier CDNs refuse images to referers they do not know.
				img.referrerPolicy = 'no-referrer';
				img.alt = '';
				img.loading = 'lazy';
				img.decoding = 'async';
				figure.appendChild( img );
				card.appendChild( figure );
			}

			var bodyEl = el( 'div', 'sky-aff__card-body' );

			bodyEl.appendChild( el( 'span', 'sky-aff__card-title', name ) );

			if ( city ) {
				bodyEl.appendChild( el( 'p', 'sky-aff__card-sub', city ) );
			}

			var foot = el( 'div', 'sky-aff__card-foot' );
			var priceText = money( price, currency );

			if ( priceText ) {
				foot.appendChild( el( 'span', 'sky-aff__price', ( t.from || 'from' ) + ' ' + priceText ) );
			}

			var href = id
				? affiliateUrl( cfg.links.activity + encodeURIComponent( id ), {} )
				: affiliateUrl( cfg.links.activity + 'result', {} );

			foot.appendChild( outLink( href, t.viewOnPlatform || 'View & book', 'sky-aff__cta' ) );
			bodyEl.appendChild( foot );
			card.appendChild( bodyEl );
			container.appendChild( card );
		} );
	}

	/* ------------------------------------------------------------------- tour */

	function initTour( panel ) {
		var p = panelParts( panel );
		var active = null;
		var form = p.form;

		if ( ! form ) {
			return;
		}

		function load() {
			var term = form.querySelector( 'input[name="q"]' ).value.trim().toLowerCase();

			var link = affiliateUrl( cfg.links.tour + 'result', {} );

			if ( active ) {
				active.cancel();
			}

			p.clear();

			var progress = p.track( 'tour' );
			var shown = 0;

			active = startSearch( 'tour', { limit: 40 }, {
				items: function ( bucket, items ) {
					// The tour endpoint has no server-side keyword filter, so
					// the term is applied here over the (small,
					// platform-owned) list, batch by batch as it arrives.
					var rows = term ? items.filter( function ( row ) {
						var haystack = [
							pick( row, [ 'title', 'name' ] ) || '',
							tourFacts( row ).where,
							pick( row, [ 'origin' ] ) || ''
						].join( ' ' ).toLowerCase();

						return haystack.indexOf( term ) !== -1;
					} ) : items;

					if ( ! rows.length ) {
						return;
					}

					renderTours( p.results, rows, shown > 0 );
					shown += rows.length;
				},

				done: function ( info ) {
					progress.stop();

					if ( ! shown ) {
						p.clear();
						p.say( t.noResults || 'No results.', 'empty' );
						p.seeAll( link );

						return;
					}

					p.summary( shown, info );
					p.seeAll( link );
				},

				error: function ( error ) {
					progress.stop();
					p.clear();
					p.say( error.message || t.error, 'error' );
				}
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			load();
		} );

		// Tours are a small, platform-owned list rather than a live supplier
		// search, so the tab can populate itself — but only when it is the tab
		// actually on screen, otherwise a hidden panel costs every visitor a
		// request they never asked for.
		if ( panel.classList.contains( 'is-active' ) ) {
			load();
		} else {
			var tab = document.querySelector( '[data-sky-tab="tour"]' );

			if ( tab ) {
				tab.addEventListener( 'click', function once() {
					tab.removeEventListener( 'click', once );
					load();
				} );
			}
		}
	}

	/**
	 * What a tour card shows, read from the platform's own tour row.
	 *
	 * The row names none of it directly: pictures and destinations are
	 * relations (asked for with include=images,destinations), and the price
	 * is the cheapest schedule's, in a currency given as the platform's own
	 * number — 1 rial, 2 toman, 3 dollar, 4 euro.
	 *
	 * @param {Object} row Tour row.
	 * @return {{where: string, image: string, price: string}}
	 */
	function tourFacts( row ) {
		var destinations = ( Array.isArray( row.destinations ) ? row.destinations : [] )
			.map( function ( item ) {
				return item && ( item.destination || item.name ) ? String( item.destination || item.name ) : '';
			} )
			.filter( Boolean );

		var where = destinations.length
			? destinations.slice( 0, 3 ).join( ' · ' ) + ( destinations.length > 3 ? ' …' : '' )
			: String( pick( row, [ 'destination', 'location', 'origin' ] ) || '' );

		var amount = pick( row, [ 'min_price_tour_schedule', 'price', 'base_price', 'from_price' ] );
		var code = pick( row, [ 'min_currency_tour_schedule', 'currency' ] );
		var iso = { 1: 'IRR', 3: 'USD', 4: 'EUR' }[ code ] || ( 'string' === typeof code ? code : '' );

		// Intl has no toman, so it is a plain number with the word after it.
		var price = 2 === Number( code )
			? ( money( amount, '' ) ? money( amount, '' ) + ' ' + ( t.toman || 'Toman' ) : '' )
			: money( amount, iso );

		return {
			where: where,
			// The full picture, not the thumbnail: the thumbnail is a 160px
			// square and the card is wider than that.
			image: pick( row, [ 'images.0.path', 'images.0.thumbnail_path', 'image', 'cover', 'images.0.url', 'images.0' ] ) || '',
			price: price
		};
	}

	function renderTours( container, rows, append ) {
		if ( ! append ) {
			container.innerHTML = '';
		}

		rows.forEach( function ( row ) {
			var name = pick( row, [ 'title', 'name' ] ) || '—';
			var slug = pick( row, [ 'slug' ] ) || '';
			var facts = tourFacts( row );
			var destination = facts.where;
			var image = facts.image;

			var card = el( 'article', 'sky-aff__card sky-aff__card--tour' );

			if ( image ) {
				var figure = el( 'div', 'sky-aff__thumb' );
				var img = document.createElement( 'img' );

				img.src = String( image );
				img.referrerPolicy = 'no-referrer';
				img.alt = '';
				img.loading = 'lazy';
				img.decoding = 'async';
				figure.appendChild( img );
				card.appendChild( figure );
			}

			var bodyEl = el( 'div', 'sky-aff__card-body' );

			bodyEl.appendChild( el( 'span', 'sky-aff__card-title', name ) );

			if ( destination ) {
				bodyEl.appendChild( el( 'p', 'sky-aff__card-sub', destination ) );
			}

			var foot = el( 'div', 'sky-aff__card-foot' );
			var priceText = facts.price;

			if ( priceText ) {
				foot.appendChild( el( 'span', 'sky-aff__price', ( t.from || 'from' ) + ' ' + priceText ) );
			}

			var href = slug
				? affiliateUrl( cfg.links.tour + 'show/' + encodeURIComponent( slug ), {} )
				: affiliateUrl( cfg.links.tour + 'result', {} );

			foot.appendChild( outLink( href, t.viewOnPlatform || 'View & book', 'sky-aff__cta' ) );
			bodyEl.appendChild( foot );
			card.appendChild( bodyEl );
			container.appendChild( card );
		} );
	}

	/* ------------------------------------------------------------------- boot */

	function boot() {
		document.querySelectorAll( '[data-sky-aff]' ).forEach( initWidget );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
