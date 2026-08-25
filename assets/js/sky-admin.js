/**
 * Sky Affiliate Search — settings screen.
 *
 * Only job: run the connection test and report what came back, so the owner
 * finds a wrong domain here rather than on a published page.
 */
( function () {
	'use strict';

	var cfg = window.skyAffAdmin;

	if ( ! cfg ) {
		return;
	}

	var t = cfg.i18n || {};
	var button = document.getElementById( 'sky-aff-test' );
	var output = document.getElementById( 'sky-aff-test-result' );
	var form = document.getElementById( 'sky-aff-settings-form' );

	if ( ! button || ! output ) {
		return;
	}

	var dirty = false;

	// The test runs against saved settings, so warn instead of reporting a
	// confusing result for values the server has never seen.
	if ( form ) {
		form.addEventListener( 'input', function () {
			dirty = true;
		} );
	}

	function report( message, kind ) {
		output.textContent = message;
		output.className = 'sky-aff-test-result is-' + kind;
	}

	button.addEventListener( 'click', function () {
		if ( dirty ) {
			report( t.unsaved || 'Save first.', 'warn' );

			return;
		}

		button.disabled = true;
		report( t.testing || 'Testing…', 'pending' );

		window.fetch( cfg.restBase.replace( /\/$/, '' ) + '/test-connection', {
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( payload ) {
				button.disabled = false;

				if ( ! response.ok ) {
					var detail = ( payload && payload.message ) || ( 'HTTP ' + response.status );

					report( ( t.failed || 'Failed' ) + ': ' + detail, 'error' );

					return;
				}

				var name = payload && payload.platform && payload.platform.name;
				var text = name
					? ( t.ok || 'Connected to %s' ).replace( '%s', name )
					: ( t.okBare || 'Connected.' );

				report( text, 'ok' );

				// Show the link the plugin will actually emit. The storefront's
				// language segment is detected, and seeing the result is the
				// only way to catch a wrong one before a visitor hits a 404.
				var example = payload && payload.links && payload.links.example;

				if ( example ) {
					var line = document.getElementById( 'sky-aff-test-example' );

					if ( ! line ) {
						line = document.createElement( 'p' );
						line.id = 'sky-aff-test-example';
						line.className = 'description';
						output.parentNode.insertBefore( line, output.nextSibling );
					}

					line.textContent = ( t.exampleLink || 'Links will look like:' ) + ' ';

					var code = document.createElement( 'code' );

					code.dir = 'ltr';
					code.textContent = example;
					line.appendChild( code );
				}
			} );
		} ).catch( function ( error ) {
			button.disabled = false;
			report( ( t.failed || 'Failed' ) + ': ' + error.message, 'error' );
		} );
	} );
}() );
