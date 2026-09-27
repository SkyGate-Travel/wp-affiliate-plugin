<?php
/**
 * The `[sky_search]` shortcode and its assets.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the search UI.
 *
 * The markup is the empty shell: tabs, forms and a results container. Results
 * are fetched by the browser from this site's own REST routes and rendered
 * client-side, so a cached HTML page still shows live inventory. Nothing about
 * the platform beyond its public domain is exposed to the page.
 */
class Sky_Aff_Shortcode {

	const TAG = 'sky_search';

	/** Handle shared by the script and style. */
	const HANDLE = 'sky-aff-search';

	/**
	 * Hook registration.
	 */
	public function hooks() {
		add_shortcode( self::TAG, array( $this, 'render' ) );

		// Registered on `init`, not on `wp_enqueue_scripts`. A block theme
		// renders the template — and therefore runs shortcodes — BEFORE
		// `wp_enqueue_scripts` fires, and wp_localize_script() /
		// wp_add_inline_style() silently return false for a handle that is not
		// registered yet. The enqueue calls would still resolve later and
		// print the files, so the failure looks like a widget that loads its
		// script but never receives its configuration. Registering this early
		// means the handle exists whichever order the theme renders in.
		add_action( 'init', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 *
	 * Safe to call more than once — repeated registration of a known handle
	 * is a no-op, which lets render() call it defensively.
	 */
	public function register_assets() {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style(
				self::HANDLE,
				SKY_AFF_URL . 'assets/css/sky-search.css',
				array(),
				Sky_Aff_Plugin::asset_version( 'assets/css/sky-search.css' )
			);
		}

		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script(
				self::HANDLE,
				SKY_AFF_URL . 'assets/js/sky-search.js',
				array(),
				Sky_Aff_Plugin::asset_version( 'assets/js/sky-search.js' ),
				true
			);
		}
	}

	/**
	 * Which product tabs to show, honouring both the settings and an explicit
	 * `types` attribute.
	 *
	 * @param string $requested Comma-separated attribute value, or ''.
	 * @return string[] Ordered list of enabled type keys.
	 */
	private function resolve_types( $requested ) {
		$available = array();

		foreach ( array( 'flight', 'hotel', 'activity', 'tour' ) as $type ) {
			if ( Sky_Aff_Settings::get( 'enable_' . $type ) ) {
				$available[] = $type;
			}
		}

		$requested = trim( (string) $requested );

		if ( '' === $requested ) {
			return $available;
		}

		$wanted = array_filter( array_map( 'trim', explode( ',', strtolower( $requested ) ) ) );

		// An attribute narrows the set; it never enables a product the owner
		// switched off in settings.
		$types = array_values( array_intersect( $available, $wanted ) );

		return empty( $types ) ? $available : $types;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				// Limit or reorder the tabs, e.g. types="hotel,flight".
				'types' => '',
				// Which tab opens first; defaults to the first available.
				'open'  => '',
				// Hide the tab bar (useful with a single type).
				'tabs'  => 'yes',
				// Optional heading above the widget.
				'title' => '',
			),
			$atts,
			self::TAG
		);

		if ( ! Sky_Aff_Settings::is_configured() ) {
			// Never leak a configuration problem to visitors; only an editor
			// who can fix it sees why the widget is blank.
			if ( current_user_can( 'manage_options' ) ) {
				return sprintf(
					'<div class="sky-aff-notice">%s <a href="%s">%s</a></div>',
					esc_html__( 'Sky Affiliate Search is not configured yet.', 'sky-affiliate-search' ),
					esc_url( admin_url( 'admin.php?page=' . Sky_Aff_Settings::PAGE_SLUG ) ),
					esc_html__( 'Open settings', 'sky-affiliate-search' )
				);
			}

			return '';
		}

		$types = $this->resolve_types( $atts['types'] );

		if ( empty( $types ) ) {
			return '';
		}

		$open = in_array( $atts['open'], $types, true ) ? $atts['open'] : $types[0];

		$this->enqueue( $types, $open );

		ob_start();
		include SKY_AFF_PATH . 'includes/views/search-widget.php';

		return ob_get_clean();
	}

	/**
	 * Enqueue assets and hand the script its configuration.
	 *
	 * @param string[] $types Enabled types.
	 * @param string   $open  Initially open tab.
	 */
	private function enqueue( array $types, $open ) {
		// Belt and braces: a page builder or a REST-rendered block can invoke
		// a shortcode outside the normal request lifecycle, before `init` has
		// run for this code path. Attaching data to an unregistered handle
		// fails silently, so make sure it exists first.
		$this->register_assets();

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		$accent = Sky_Aff_Settings::get( 'accent_color' );

		wp_add_inline_style(
			self::HANDLE,
			'.sky-aff{--sky-aff-accent:' . esc_attr( $accent ) . ';}'
		);

		wp_localize_script(
			self::HANDLE,
			'skyAffConfig',
			array(
				'restBase'   => esc_url_raw( rest_url( SKY_AFF_REST_NS ) ),
				// Deliberately no REST nonce. These routes are public, and a
				// search page is exactly the kind of page a full-page cache
				// serves for hours: the embedded nonce would go stale, and
				// WordPress rejects a *stale* nonce (403 rest_cookie_invalid_nonce)
				// where it happily treats a *missing* one as an anonymous
				// request. Sending nothing is the only version that survives
				// caching for logged-in visitors.
				'types'      => $types,
				'open'       => $open,
				'language'   => Sky_Aff_Settings::language(),
				'isRtl'      => is_rtl(),
				'linkTarget' => Sky_Aff_Settings::get( 'link_target_blank' ) ? '_blank' : '_self',
				// Whether to ask for a streamed search. The script falls back
				// to the classic routes on its own when a browser or a proxy
				// turns out not to cooperate, so this only decides what it
				// tries first.
				'stream'     => (bool) Sky_Aff_Settings::get( 'enable_stream' ),
				// What the progress bar treats as "this is taking a while", in
				// seconds. Not a timeout — the search runs to the configured
				// request timeout regardless.
				'slowAfter'  => 15,
				// Pre-built so the browser never has to know the affiliate code
				// or assemble a platform URL itself. localized_base() carries
				// the storefront's language segment where it has one — without
				// it every deep link 404s, since the storefront only redirects
				// its shallow paths.
				'links'      => array(
					'home'      => Sky_Aff_Links::home(),
					'flight'    => Sky_Aff_Links::localized_base() . '/flight/',
					'hotel'     => Sky_Aff_Links::localized_base() . '/hotel/',
					'activity'  => Sky_Aff_Links::localized_base() . '/activities/',
					'tour'      => Sky_Aff_Links::localized_base() . '/tour/',
				),
				'affiliate'  => array(
					'param' => Sky_Aff_Links::PARAM,
					'code'  => Sky_Aff_Settings::get( 'affiliate_code' ),
				),
				'i18n'       => $this->strings(),
			)
		);
	}

	/**
	 * Every user-facing string the script needs, translated server-side.
	 *
	 * @return array<string,string>
	 */
	private function strings() {
		return array(
			'flight'            => __( 'Flights', 'sky-affiliate-search' ),
			'hotel'             => __( 'Hotels', 'sky-affiliate-search' ),
			'activity'          => __( 'Activities', 'sky-affiliate-search' ),
			'tour'              => __( 'Tours', 'sky-affiliate-search' ),
			'search'            => __( 'Search', 'sky-affiliate-search' ),
			'searching'         => __( 'Searching…', 'sky-affiliate-search' ),
			'origin'            => __( 'From', 'sky-affiliate-search' ),
			'destination'       => __( 'To', 'sky-affiliate-search' ),
			'departing'         => __( 'Departure', 'sky-affiliate-search' ),
			'returning'         => __( 'Return', 'sky-affiliate-search' ),
			'oneWay'            => __( 'One way', 'sky-affiliate-search' ),
			'roundTrip'         => __( 'Round trip', 'sky-affiliate-search' ),
			'passengers'        => __( 'Passengers', 'sky-affiliate-search' ),
			'adults'            => __( 'Adults', 'sky-affiliate-search' ),
			'children'          => __( 'Children', 'sky-affiliate-search' ),
			'infants'           => __( 'Infants', 'sky-affiliate-search' ),
			'cabin'             => __( 'Cabin', 'sky-affiliate-search' ),
			'economy'           => __( 'Economy', 'sky-affiliate-search' ),
			'business'          => __( 'Business', 'sky-affiliate-search' ),
			'first'             => __( 'First', 'sky-affiliate-search' ),
			'destinationPlace'  => __( 'City or hotel', 'sky-affiliate-search' ),
			'checkIn'           => __( 'Check-in', 'sky-affiliate-search' ),
			'checkOut'          => __( 'Check-out', 'sky-affiliate-search' ),
			'rooms'             => __( 'Rooms', 'sky-affiliate-search' ),
			'addRoom'           => __( 'Add room', 'sky-affiliate-search' ),
			'removeRoom'        => __( 'Remove', 'sky-affiliate-search' ),
			'room'              => __( 'Room', 'sky-affiliate-search' ),
			'keyword'           => __( 'What are you looking for?', 'sky-affiliate-search' ),
			'anyType'           => __( 'Any type', 'sky-affiliate-search' ),
			'anyCategory'       => __( 'Any category', 'sky-affiliate-search' ),
			'anyCountry'        => __( 'Any country', 'sky-affiliate-search' ),
			'anyCity'           => __( 'Any city', 'sky-affiliate-search' ),
			'priceFrom'         => __( 'Min price', 'sky-affiliate-search' ),
			'priceTo'           => __( 'Max price', 'sky-affiliate-search' ),
			'sort'              => __( 'Sort', 'sky-affiliate-search' ),
			'noResults'         => __( 'No results matched this search.', 'sky-affiliate-search' ),
			'error'             => __( 'Something went wrong. Please try again.', 'sky-affiliate-search' ),
			'viewOnPlatform'    => __( 'View & book', 'sky-affiliate-search' ),
			'seeAllResults'     => __( 'See all results', 'sky-affiliate-search' ),
			'resultsCount'      => /* translators: %s: number of results. */ __( '%s results', 'sky-affiliate-search' ),
			'from'              => __( 'from', 'sky-affiliate-search' ),
			'perNight'          => __( 'per night', 'sky-affiliate-search' ),
			'nights'            => __( 'nights', 'sky-affiliate-search' ),
			// Both forms, resolved in JS by count. Kept as two separate
			// strings rather than one _n() call because the number is only
			// known in the browser, after the search comes back.
			'stop'              => __( 'stop', 'sky-affiliate-search' ),
			'stops'             => __( 'stops', 'sky-affiliate-search' ),
			'direct'            => __( 'Direct', 'sky-affiliate-search' ),
			'typeToSearch'      => __( 'Type at least 2 characters…', 'sky-affiliate-search' ),
			'noPlaces'          => __( 'No matching places.', 'sky-affiliate-search' ),
			'allAirports'       => __( 'all airports', 'sky-affiliate-search' ),
			// Both sides of a flight search are chosen from a list, so the only
			// ways to get them wrong are to leave one empty or to pick the same
			// airport twice. Neither is a failure, so neither says "went wrong".
			'chooseAirports'    => __( 'Choose where you are flying from and to.', 'sky-affiliate-search' ),
			'sameAirports'      => __( 'Origin and destination are the same. Pick a different one.', 'sky-affiliate-search' ),
			'properties'        => __( 'properties', 'sky-affiliate-search' ),
			'reviews'           => __( 'reviews', 'sky-affiliate-search' ),
			// Compact duration units, e.g. "2h 15m".
			'hourShort'         => _x( 'h', 'hours, compact', 'sky-affiliate-search' ),
			'minuteShort'       => _x( 'm', 'minutes, compact', 'sky-affiliate-search' ),
			'freeCancellation'  => __( 'Free cancellation', 'sky-affiliate-search' ),
			'filters'           => __( 'Filters', 'sky-affiliate-search' ),
			'clearFilters'      => __( 'Clear', 'sky-affiliate-search' ),
			'stars'             => __( 'Stars', 'sky-affiliate-search' ),
			'loadMore'          => __( 'Load more', 'sky-affiliate-search' ),
			'affiliateDisclosure' => __( 'Booking happens on our travel partner’s site. We may earn a commission.', 'sky-affiliate-search' ),
			'severalAirlines'   => __( 'Several airlines', 'sky-affiliate-search' ),
			'perAdult'          => __( 'per adult', 'sky-affiliate-search' ),
			'officialFare'      => __( 'Airline’s own fare', 'sky-affiliate-search' ),
			/* translators: %s: number of seats still for sale. */
			'seatsLeft'         => __( '%s seats left', 'sky-affiliate-search' ),
			'baggage'           => __( 'Bag', 'sky-affiliate-search' ),
			'cabinBag'          => __( 'Cabin', 'sky-affiliate-search' ),
			'searchingProviders' => __( 'Contacting suppliers…', 'sky-affiliate-search' ),
			'stillSearching'    => __( 'Still searching — some suppliers are slower than others.', 'sky-affiliate-search' ),
			/* translators: unit appended to a number of seconds, e.g. "12s". */
			'secondsShort'      => __( 's', 'sky-affiliate-search' ),
			'fromCache'         => __( 'Shown instantly from a recent search.', 'sky-affiliate-search' ),
		);
	}
}
