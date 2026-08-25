<?php
/**
 * Settings storage, defaults and the admin settings screen.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the single settings option and renders the admin page.
 *
 * The two settings the site owner MUST provide are the platform domain and
 * their affiliate code — everything else has a workable default.
 */
class Sky_Aff_Settings {

	const PAGE_SLUG  = 'sky-affiliate-search';
	const GROUP      = 'sky_aff_settings_group';
	const CACHE_FLAG = 'sky_aff_cache_epoch';

	/**
	 * Defaults for every key. get() falls back to this map, so a settings key
	 * added in a later version is safe to read before the owner re-saves.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Required. The seller's Sky storefront domain, bare (no scheme).
			// Doubles as the `Origin` we send to the API and the base of every
			// affiliate link.
			'platform_domain'  => '',

			// Required. The referrer's affiliate code, appended to every
			// outgoing link as ?affiliateCode=.
			'affiliate_code'   => '',

			// Required. Root of the Sky API, e.g. https://api.example.com.
			// The plugin appends /v1/general/... itself.
			'api_base_url'     => '',

			// Destinations offered the moment a visitor clicks a search field,
			// before typing. One per line, shared by the flight and hotel tabs.
			//
			// Owner-supplied rather than taken from the API because neither
			// endpoint can produce a usable list on its own: the hotel place
			// search is a provider passthrough that requires a query, and
			// `flight-cities` only comes back in a sensible order when the
			// platform happens to have configured airport priorities — without
			// them it returns cities by internal id, which opens the dropdown
			// on places nobody has heard of.
			//
			// Each name is resolved to a real, bookable id through the same
			// search the visitor would have used, then cached.
			//
			// The "|CODE" suffix pins the exact city for the flight tab. City
			// names are not unique and the API cannot rank them: searching
			// "Barcelona" matches Barcelona, Venezuela and Barcelona, Spain
			// equally — same name, one airport each. Where a guess would be a
			// coin toss, the default says which one it means.
			'popular_destinations' => "Dubai|DXB\nIstanbul|IST\nParis|PAR\nLondon|LON\nBarcelona|BCN\nRome|ROM\nBangkok|BKK\nAntalya|AYT",

			// Which product tabs the search page offers.
			'enable_flight'    => 1,
			'enable_hotel'     => 1,
			'enable_activity'  => 1,
			'enable_tour'      => 0,

			// Two-letter language sent to the API and used for activity
			// results. Falls back to the site locale when blank.
			'language'         => '',

			// Seconds to cache an upstream response. Searches hit live
			// provider inventory, so this is deliberately short.
			'cache_ttl'        => 300,

			// Seconds before an upstream call is abandoned. Flight and hotel
			// searches fan out to every provider the platform sells and answer
			// when the last one has; Sky's own storefront allows a full minute
			// for the same call, and giving up at 25 seconds turned a slow
			// search into "temporarily unavailable" while it was still working.
			'request_timeout'  => 45,

			// Write search results to the browser as they are ready instead of
			// in one silent response at the end. Worth turning off only where
			// a proxy or CDN buffers the body anyway — see Sky_Aff_Stream.
			'enable_stream'    => 1,

			// Open platform links in a new tab.
			'link_target_blank' => 1,

			// Max searches per visitor IP per minute through the proxy.
			'rate_limit'       => 20,

			// Accent colour for the search UI.
			'accent_color'     => '#0f62fe',
		);
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional override for the built-in default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all      = self::all();
		$defaults = self::defaults();

		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ];
		}

		if ( null !== $default ) {
			return $default;
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}

	/**
	 * Every setting, defaults merged in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( SKY_AFF_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * True when the plugin has enough configuration to talk to the platform.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get( 'platform_domain' )
			&& '' !== self::get( 'api_base_url' );
	}

	/**
	 * Destinations for the browse lists, as a clean list of names.
	 *
	 * Capped at 12: each one costs an upstream place lookup when the list is
	 * rebuilt, and a dropdown longer than that is a worse way to choose than
	 * simply typing.
	 *
	 * @return string[]
	 */
	public static function popular_destinations() {
		$raw   = (string) self::get( 'popular_destinations' );
		$names = preg_split( '/\R/', $raw );
		$out   = array();

		foreach ( (array) $names as $name ) {
			$name = trim( $name );

			if ( '' !== $name && strlen( $name ) >= 2 ) {
				$out[] = $name;
			}
		}

		return array_slice( array_unique( $out ), 0, 12 );
	}

	/**
	 * The language code to send upstream: the explicit setting, else the first
	 * segment of the site locale, else English.
	 *
	 * @return string
	 */
	public static function language() {
		$lang = self::get( 'language' );

		if ( '' !== $lang ) {
			return $lang;
		}

		$locale = get_locale();               // e.g. fa_IR.
		$lang   = substr( $locale, 0, 2 );

		return $lang ? strtolower( $lang ) : 'en';
	}

	/**
	 * Wire up the admin screen.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
	}

	/**
	 * Top-level menu entry. Top level rather than under Settings because the
	 * page is the plugin's whole control surface, including the connection
	 * test the owner will come back to.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Sky Affiliate Search', 'sky-affiliate-search' ),
			__( 'Sky Search', 'sky-affiliate-search' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-airplane',
			58
		);
	}

	/**
	 * Register the option and its sanitizer.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			SKY_AFF_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Validate and normalise the whole form.
	 *
	 * Saving always bumps the cache epoch: a changed domain or language must
	 * not keep serving results fetched under the old settings.
	 *
	 * @param mixed $input Raw $_POST slice for the option.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$out = self::defaults();

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['platform_domain'] = self::normalize_domain(
			isset( $input['platform_domain'] ) ? $input['platform_domain'] : ''
		);

		// Affiliate codes are generated by Sky as short alphanumeric strings.
		// Strip anything that could not have come from there rather than
		// silently URL-encoding junk into every outgoing link.
		$out['affiliate_code'] = isset( $input['affiliate_code'] )
			? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['affiliate_code'] )
			: '';

		$out['api_base_url'] = isset( $input['api_base_url'] )
			? untrailingslashit( esc_url_raw( trim( (string) $input['api_base_url'] ) ) )
			: '';

		foreach ( array( 'enable_flight', 'enable_hotel', 'enable_activity', 'enable_tour', 'link_target_blank', 'enable_stream' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$lang = isset( $input['language'] ) ? strtolower( trim( (string) $input['language'] ) ) : '';
		$out['language'] = preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : '';

		if ( isset( $input['popular_destinations'] ) ) {
			$lines = preg_split( '/\R/', (string) $input['popular_destinations'] );
			$clean = array();

			foreach ( (array) $lines as $line ) {
				$line = sanitize_text_field( trim( $line ) );

				if ( '' !== $line ) {
					$clean[] = $line;
				}
			}

			$out['popular_destinations'] = implode( "\n", array_slice( array_unique( $clean ), 0, 12 ) );
		}

		$out['cache_ttl']       = self::clamp_int( $input, 'cache_ttl', 0, 3600, 300 );
		$out['request_timeout'] = self::clamp_int( $input, 'request_timeout', 5, 120, 45 );
		$out['rate_limit']      = self::clamp_int( $input, 'rate_limit', 1, 240, 20 );

		$color = isset( $input['accent_color'] ) ? sanitize_hex_color( $input['accent_color'] ) : '';
		$out['accent_color'] = $color ? $color : '#0f62fe';

		update_option( self::CACHE_FLAG, time(), false );

		return $out;
	}

	/**
	 * Read an integer field and hold it inside a sane range.
	 *
	 * @param array  $input   Raw input.
	 * @param string $key     Field name.
	 * @param int    $min     Lower bound.
	 * @param int    $max     Upper bound.
	 * @param int    $default Value when the field is missing or unparseable.
	 * @return int
	 */
	private static function clamp_int( $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $default;
		}

		$value = (int) $input[ $key ];

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Reduce whatever the owner pasted to a bare host[:port].
	 *
	 * They will paste "https://shop.example.com/" as often as "shop.example.com",
	 * and the API matches on the bare host — mirroring the platform-resolution
	 * middleware, which strips the scheme and a leading "www.".
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function normalize_domain( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $value, '//' ) ) {
			$value = 'https://' . $value;
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		$port = wp_parse_url( $value, PHP_URL_PORT );

		if ( ! $host ) {
			return '';
		}

		$host = preg_replace( '/^www\./i', '', strtolower( $host ) );

		// wp_parse_url() is lenient and will hand back things that are not
		// hostnames at all ("not a domain" survives intact). Whatever comes
		// out of here is interpolated straight into an `Origin` header, so
		// anything that is not a plausible host — or a bare label with no dot,
		// which could never be a real platform — is rejected rather than
		// stored and sent. `localhost:8003` is allowed on purpose: staging
		// platforms are routinely addressed that way.
		$is_hostname = (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host );
		$is_local    = (bool) preg_match( '/^(?:localhost|(?:\d{1,3}\.){3}\d{1,3})$/', $host );

		if ( ! $is_hostname && ! $is_local ) {
			return '';
		}

		return $port ? $host . ':' . $port : $host;
	}

	/**
	 * Nudge the owner to the settings screen while the plugin cannot work.
	 */
	public function configuration_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::is_configured() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && 'toplevel_page_' . self::PAGE_SLUG === $screen->id ) {
			return; // They are already looking at the form.
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Sky Affiliate Search needs a platform domain and API URL before it can show results.', 'sky-affiliate-search' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open settings', 'sky-affiliate-search' )
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = self::all();

		require SKY_AFF_PATH . 'includes/views/settings-page.php';
	}
}
