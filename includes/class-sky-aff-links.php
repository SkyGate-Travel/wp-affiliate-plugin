<?php
/**
 * Builds affiliate-tagged deep links into the Sky storefront.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every link a visitor can follow off this site.
 *
 * Attribution works like this on the Sky side: the storefront reads
 * `?affiliateCode=` off the landing URL, persists it to a long-lived
 * `affiliateCode` cookie, and sends it with the sign-up call. Sky resolves the
 * code to a referrer within the platform and stamps `referred_by_user_id` on
 * the new user — once, at creation, never overwritten. The referrer is then
 * paid a cut of the seller's profit at settlement time.
 *
 * Two consequences shape this class:
 *
 * 1. The code only has to reach the storefront once, on any page — so every
 *    link we emit carries it, and a deep link into search results credits the
 *    affiliate exactly as well as a link to the home page.
 * 2. Attribution is per-platform, so the code is only meaningful against the
 *    configured platform domain. We never append it to any other host.
 */
class Sky_Aff_Links {

	/** Query parameter the storefront reads. Matches AFFILIATE_QUERY_PARAM. */
	const PARAM = 'affiliateCode';

	/**
	 * Root of the configured storefront, with scheme, no trailing slash.
	 *
	 * @return string Empty when the plugin is unconfigured.
	 */
	public static function base() {
		$domain = Sky_Aff_Settings::get( 'platform_domain' );

		return $domain ? 'https://' . $domain : '';
	}

	/** Transient holding the detected storefront shape. */
	const PROFILE_KEY = 'sky_aff_storefront';

	/**
	 * The storefront root including its language segment, if it has one.
	 *
	 * Sky storefronts come in two shapes. Some serve `/flight/DXB-IST`
	 * directly; the current build is localized and serves
	 * `/en/flight/DXB-IST`, redirecting only the bare `/flight` and answering
	 * 404 for any deep path without a language. Guessing wrong produces links
	 * that look right and 404 on arrival, so the shape is detected once and
	 * remembered.
	 *
	 * @return string Base with prefix, no trailing slash. '' when unconfigured.
	 */
	public static function localized_base() {
		$base = self::base();

		if ( '' === $base ) {
			return '';
		}

		$prefix = self::locale_prefix();

		return $prefix ? $base . '/' . $prefix : $base;
	}

	/**
	 * The language segment to put in front of every storefront path.
	 *
	 * @return string Language code, or '' when the storefront is not localized.
	 */
	public static function locale_prefix() {
		$profile = self::profile();

		return isset( $profile['prefix'] ) ? (string) $profile['prefix'] : '';
	}

	/**
	 * Detect and cache the storefront's shape.
	 *
	 * Two questions, one answer: does it use a language segment, and which
	 * language should this site's visitors land in?
	 *
	 * The probe is a single request to `/flight` with redirects disabled — a
	 * localized build answers 307 to `/xx/flight` and names its own default
	 * language in the process. Preference then goes to this site's language,
	 * but only if the platform actually serves it: linking a Persian site to
	 * `/fa/` on a platform without Persian is another 404.
	 *
	 * @return array{prefix:string}
	 */
	private static function profile() {
		$domain = Sky_Aff_Settings::get( 'platform_domain' );

		if ( '' === $domain ) {
			return array( 'prefix' => '' );
		}

		$key = self::PROFILE_KEY . '_' . md5(
			$domain . '|' . Sky_Aff_Settings::language() . '|' . (int) get_option( Sky_Aff_Settings::CACHE_FLAG, 0 )
		);

		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$detected = self::detect_prefix();
		$profile  = array( 'prefix' => (string) $detected );

		// A failed probe is remembered only briefly. Caching "no prefix" for a
		// day because the storefront happened to be down would leave every
		// link broken until tomorrow; retrying on every page render while it
		// stays down would block each one on a timeout. Fifteen minutes is the
		// compromise.
		set_transient( $key, $profile, null === $detected ? 15 * MINUTE_IN_SECONDS : DAY_IN_SECONDS );

		return $profile;
	}

	/**
	 * Work out the language segment.
	 *
	 * @return string|null Code, '' when the storefront is not localized, or
	 *                     null when it could not be reached at all.
	 */
	private static function detect_prefix() {
		$response = wp_remote_get(
			self::base() . '/flight',
			array(
				// Short: this runs while a visitor waits for the page. A
				// storefront that cannot answer in five seconds is not going
				// to give a useful answer at all.
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 301 !== $status && 302 !== $status && 307 !== $status && 308 !== $status ) {
			return '';
		}

		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$path     = (string) wp_parse_url( $location, PHP_URL_PATH );

		if ( ! preg_match( '#^/([a-z]{2})/flight#', $path, $m ) ) {
			return '';
		}

		$default = $m[1];
		$wanted  = Sky_Aff_Settings::language();

		// Send visitors to their own language where the platform offers it,
		// otherwise to whichever one the storefront defaulted to.
		if ( $wanted && $wanted !== $default && in_array( $wanted, self::enabled_languages(), true ) ) {
			return $wanted;
		}

		return $default;
	}

	/**
	 * Languages this platform serves, from its public profile.
	 *
	 * @return string[]
	 */
	private static function enabled_languages() {
		$client = new Sky_Aff_Api_Client();
		$result = $client->get( 'platform', array(), false, DAY_IN_SECONDS );

		if ( is_wp_error( $result ) || ! is_array( $result['data'] ) ) {
			return array();
		}

		$langs = $result['data']['enabledLanguages'] ?? array();

		return is_array( $langs ) ? array_map( 'strval', $langs ) : array();
	}

	/**
	 * Attach the affiliate code to a storefront path.
	 *
	 * @param string $path  Path on the storefront, e.g. '/hotel/result'.
	 * @param array  $query Query arguments.
	 * @return string Absolute URL, or '' when unconfigured.
	 */
	public static function build( $path = '/', array $query = array() ) {
		$base = self::localized_base();

		if ( '' === $base ) {
			return '';
		}

		$code = Sky_Aff_Settings::get( 'affiliate_code' );

		if ( '' !== $code ) {
			$query[ self::PARAM ] = $code;
		}

		// Drop empties so a link never carries "&returning=" with no value —
		// the storefront treats a present-but-blank `returning` as a round
		// trip and would render the wrong search.
		$query = array_filter(
			$query,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);

		$path = ltrim( $path, '/' );

		// A bare root under a language segment is `/en`, not `/en/` — the
		// storefront 308-redirects the trailing slash, and there is no reason
		// to send every visitor through a redirect.
		$url = '' === $path ? $base : $base . '/' . $path;

		return empty( $query ) ? $url : $url . '?' . http_build_query( $query );
	}

	/**
	 * Storefront home, affiliate-tagged. The plain referral link.
	 *
	 * @return string
	 */
	public static function home() {
		return self::build( '/' );
	}

	/**
	 * Flight search results.
	 *
	 * The route lives in the path as `ORIGIN-DEST` (three letters each — the
	 * storefront 404s on anything else), and the rest rides in the query.
	 *
	 * @param array $args {
	 *     @type string $origin      IATA code.
	 *     @type string $destination IATA code.
	 *     @type string $departing   Y-m-d.
	 *     @type string $returning   Y-m-d, omitted for a one-way.
	 *     @type int    $adult       Adult count.
	 *     @type int    $child       Child count.
	 *     @type int    $infant      Infant count.
	 *     @type int    $cabin_type  CabinClassEnum value.
	 *     @type int    $is_domestic 1 for a domestic search.
	 * }
	 * @return string
	 */
	public static function flight_results( array $args ) {
		$origin      = strtoupper( substr( (string) ( $args['origin'] ?? '' ), 0, 3 ) );
		$destination = strtoupper( substr( (string) ( $args['destination'] ?? '' ), 0, 3 ) );

		if ( ! preg_match( '/^[A-Z]{3}$/', $origin ) || ! preg_match( '/^[A-Z]{3}$/', $destination ) ) {
			return self::home();
		}

		return self::build(
			'/flight/' . $origin . '-' . $destination,
			array(
				'departing'   => $args['departing'] ?? '',
				'returning'   => $args['returning'] ?? '',
				'adult'       => isset( $args['adult'] ) ? (int) $args['adult'] : 1,
				'child'       => isset( $args['child'] ) ? (int) $args['child'] : 0,
				'infant'      => isset( $args['infant'] ) ? (int) $args['infant'] : 0,
				'cabin_type'  => isset( $args['cabin_type'] ) ? (int) $args['cabin_type'] : 1,
				'is_domestic' => ! empty( $args['is_domestic'] ) ? 1 : 0,
			)
		);
	}

	/**
	 * Hotel search results for a place.
	 *
	 * @param array $args {
	 *     @type string $city       Provider place id.
	 *     @type string $city_name  Display name, echoed back by the storefront.
	 *     @type string $start_date Y-m-d.
	 *     @type string $end_date   Y-m-d.
	 *     @type array  $rooms      Room configs, see encode_rooms().
	 * }
	 * @return string
	 */
	public static function hotel_results( array $args ) {
		return self::build(
			'/hotel/result',
			array(
				'city'       => $args['city'] ?? '',
				'city_name'  => $args['city_name'] ?? '',
				'start_date' => $args['start_date'] ?? '',
				'end_date'   => $args['end_date'] ?? '',
				'rooms'      => self::encode_rooms( $args['rooms'] ?? array() ),
			)
		);
	}

	/**
	 * One property, opened with the search that found it so the storefront can
	 * re-price it rather than showing an empty detail page.
	 *
	 * @param string $candidate_id Offer/property id from the search response.
	 * @param array  $args         Same shape as hotel_results().
	 * @return string
	 */
	public static function hotel_detail( $candidate_id, array $args ) {
		$candidate_id = (string) $candidate_id;

		if ( '' === $candidate_id ) {
			return self::hotel_results( $args );
		}

		return self::build(
			'/hotel/' . rawurlencode( $candidate_id ),
			array(
				'city'       => $args['city'] ?? '',
				'city_name'  => $args['city_name'] ?? '',
				'start_date' => $args['start_date'] ?? '',
				'end_date'   => $args['end_date'] ?? '',
				'rooms'      => self::encode_rooms( $args['rooms'] ?? array() ),
			)
		);
	}

	/**
	 * Activity search results.
	 *
	 * @param array $args Any of keyword, type, category, country, city,
	 *                    language, sort.
	 * @return string
	 */
	public static function activity_results( array $args ) {
		$allowed = array( 'keyword', 'type', 'category', 'country', 'city', 'language', 'sort' );
		$query   = array();

		foreach ( $allowed as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$query[ $key ] = (string) $args[ $key ];
			}
		}

		// The storefront drops `country` whenever a `city` is present; mirror
		// that so our link and its parsed state agree.
		if ( isset( $query['city'], $query['country'] ) ) {
			unset( $query['country'] );
		}

		return self::build( '/activities/result', $query );
	}

	/**
	 * One activity product.
	 *
	 * @param string $product_id Provider product id.
	 * @return string
	 */
	public static function activity_detail( $product_id ) {
		$product_id = (string) $product_id;

		if ( '' === $product_id ) {
			return self::activity_results( array() );
		}

		return self::build( '/activities/' . rawurlencode( $product_id ) );
	}

	/**
	 * Tour listing.
	 *
	 * @return string
	 */
	public static function tour_results() {
		return self::build( '/tour/result' );
	}

	/**
	 * One tour, addressed by slug.
	 *
	 * @param string $slug Tour slug.
	 * @return string
	 */
	public static function tour_detail( $slug ) {
		$slug = (string) $slug;

		if ( '' === $slug ) {
			return self::tour_results();
		}

		return self::build( '/tour/show/' . rawurlencode( $slug ) );
	}

	/**
	 * Encode room occupancy the way the storefront's URL parser expects:
	 * `adults-children[-age.age]`, rooms joined by `_`.
	 *
	 * Example: two rooms, the second with a 6- and a 9-year-old →
	 * `2-0_2-2-6.9`.
	 *
	 * @param array $rooms List of room arrays with adults/children/ages.
	 * @return string
	 */
	public static function encode_rooms( array $rooms ) {
		if ( empty( $rooms ) ) {
			return '';
		}

		$encoded = array();

		foreach ( $rooms as $room ) {
			$adults   = isset( $room['adults'] ) ? max( 1, (int) $room['adults'] ) : 1;
			$children = isset( $room['children'] ) ? max( 0, (int) $room['children'] ) : 0;

			$ages = array();

			if ( ! empty( $room['ages'] ) && is_array( $room['ages'] ) ) {
				foreach ( $room['ages'] as $age ) {
					if ( is_numeric( $age ) ) {
						$ages[] = (int) $age;
					}
				}
			}

			$part = $adults . '-' . $children;

			if ( ! empty( $ages ) ) {
				$part .= '-' . implode( '.', $ages );
			}

			$encoded[] = $part;
		}

		return implode( '_', $encoded );
	}

	/**
	 * `target` / `rel` attributes for an outgoing link, honouring the setting.
	 *
	 * @return string Ready to interpolate into an <a> tag.
	 */
	public static function link_attrs() {
		if ( ! Sky_Aff_Settings::get( 'link_target_blank' ) ) {
			return '';
		}

		// noopener is required with target=_blank; sponsored+nofollow is the
		// honest description of a paid referral link and keeps the host site
		// out of trouble with search engines.
		return ' target="_blank" rel="noopener sponsored nofollow"';
	}
}
