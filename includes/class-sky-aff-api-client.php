<?php
/**
 * Server-side HTTP client for the Sky API.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Sky platform API from PHP.
 *
 * Every call MUST go through here rather than from browser JavaScript. Sky
 * resolves which platform (tenant) a request belongs to from the request's
 * `Origin` header, and in production it honours nothing else — an
 * `X-Platform-Domain` override exists but is only read while the API has debug
 * enabled. A browser sets `Origin` to the page's own host and forbids scripts
 * from changing it, so a fetch() from the WordPress site would arrive tagged
 * with the WordPress domain and be rejected as an unknown platform. Server-side
 * we are free to set the header to the platform domain the owner configured,
 * which is the only thing that makes this plugin work off-domain at all.
 */
class Sky_Aff_Api_Client {

	/**
	 * GET a general (public) endpoint.
	 *
	 * @param string   $path     Path below /v1/general, e.g. 'hotels/places'.
	 * @param array    $query    Query arguments. Nested arrays are encoded as
	 *                           `filter[search]=…`, which is what the API's
	 *                           query-builder filters expect.
	 * @param bool     $no_cache Skip the response cache in both directions.
	 * @param int|null $ttl      Override the configured cache lifetime, in
	 *                           seconds. Reference data (airports, taxonomies)
	 *                           barely changes and should not be re-fetched on
	 *                           the short TTL that live prices need.
	 * @return array|WP_Error Decoded `data` payload plus `meta`, or an error.
	 */
	public function get( $path, array $query = array(), $no_cache = false, $ttl = null ) {
		return $this->request( 'GET', $path, $query, null, $no_cache, $ttl );
	}

	/**
	 * POST a general (public) endpoint.
	 *
	 * @param string $path     Path below /v1/general, e.g. 'hotels/available'.
	 * @param array  $body     JSON body.
	 * @param bool   $no_cache Skip the response cache in both directions.
	 * @return array|WP_Error Decoded `data` payload plus `meta`, or an error.
	 */
	public function post( $path, array $body = array(), $no_cache = false ) {
		return $this->request( 'POST', $path, array(), $body, $no_cache );
	}

	/**
	 * Perform the call, with a short-lived cache in front of it.
	 *
	 * @param string     $method   HTTP verb.
	 * @param string     $path     Path below /v1/general.
	 * @param array      $query    Query arguments.
	 * @param array|null $body     JSON body, or null for none.
	 * @param bool       $no_cache Skip the response cache in both directions.
	 * @param int|null   $ttl      Cache lifetime override, in seconds.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $query, $body, $no_cache = false, $ttl = null ) {
		$plan = $this->plan( $method, $path, $query, $body, $no_cache, $ttl );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$cached = $this->cached( $plan );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->fetch( $plan );

		if ( ! is_wp_error( $result ) ) {
			$this->remember( $plan, $result );
		}

		return $result;
	}

	/**
	 * Resolve one call to everything a transport needs to make it: URL,
	 * headers, encoded body, cache key and deadline.
	 *
	 * Split out of request() for the streaming endpoint, which cannot simply
	 * block on wp_remote_request(): it has to stay inside its own loop
	 * emitting heartbeats while the platform fans out to providers. Sharing
	 * the plan means the streamed call carries the same tenant headers,
	 * honours the same timeout, and reads and writes the same cache entries
	 * as the plain one — so a search that was already run answers instantly
	 * whichever route asks for it.
	 *
	 * @param string     $method   HTTP verb.
	 * @param string     $path     Path below /v1/general.
	 * @param array      $query    Query arguments.
	 * @param array|null $body     JSON body, or null for none.
	 * @param bool       $no_cache Skip the response cache in both directions.
	 * @param int|null   $ttl      Cache lifetime override, in seconds.
	 * @param array      $headers  Extra headers, e.g. an Accept that asks the
	 *                             platform for its streamed answer instead of
	 *                             its whole one.
	 * @return array|WP_Error
	 */
	public function plan( $method, $path, array $query = array(), $body = null, $no_cache = false, $ttl = null, array $headers = array() ) {
		if ( ! Sky_Aff_Settings::is_configured() ) {
			return new WP_Error(
				'sky_aff_not_configured',
				__( 'The Sky platform domain or API URL has not been set.', 'sky-affiliate-search' ),
				array( 'status' => 503 )
			);
		}

		$cache_key = $this->cache_key( $method, $path, $query, $body );

		if ( $no_cache ) {
			$ttl = 0;
		} elseif ( null === $ttl ) {
			$ttl = (int) Sky_Aff_Settings::get( 'cache_ttl' );
		} else {
			// A caller asking for a long TTL still honours "caching is off":
			// setting cache_ttl to 0 is how you disable it while debugging,
			// and reference data quietly ignoring that would be maddening.
			$ttl = (int) Sky_Aff_Settings::get( 'cache_ttl' ) > 0 ? (int) $ttl : 0;
		}

		$url = $this->endpoint( $path );

		if ( ! empty( $query ) ) {
			// http_build_query handles both scalars and nested arrays, and
			// encodes exactly once — add_query_arg() would encode a second
			// time on top of any pre-encoding and turn a space into %2520.
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}

		$headers = array_merge( $this->headers(), $headers );
		$encoded = null;

		if ( null !== $body ) {
			$encoded                 = wp_json_encode( $body );
			$headers['Content-Type'] = 'application/json';
		}

		return array(
			'method'    => $method,
			'path'      => $path,
			'url'       => $url,
			'headers'   => $headers,
			'body'      => $encoded,
			'cache_key' => $cache_key,
			'ttl'       => (int) $ttl,
			'timeout'   => max( 5, (int) Sky_Aff_Settings::get( 'request_timeout' ) ),
		);
	}

	/**
	 * Cached answer for a plan.
	 *
	 * @param array $plan From plan().
	 * @return array|false Result, or false when nothing usable is stored.
	 */
	public function cached( array $plan ) {
		if ( $plan['ttl'] <= 0 ) {
			return false;
		}

		return get_transient( $plan['cache_key'] );
	}

	/**
	 * Store a result against a plan.
	 *
	 * @param array $plan   From plan().
	 * @param array $result Result to keep.
	 */
	public function remember( array $plan, array $result ) {
		if ( $plan['ttl'] > 0 ) {
			set_transient( $plan['cache_key'], $result, $plan['ttl'] );
		}
	}

	/**
	 * Run a plan on WordPress' own HTTP API, blocking until it answers.
	 *
	 * @param array $plan From plan().
	 * @return array|WP_Error
	 */
	public function fetch( array $plan ) {
		$args = array(
			'method'  => $plan['method'],
			'timeout' => $plan['timeout'],
			'headers' => $plan['headers'],
			// Provider fan-out can be slow; do not let a slow search block the
			// whole PHP worker on a redirect chain as well.
			'redirection' => 2,
		);

		if ( null !== $plan['body'] ) {
			$args['body'] = $plan['body'];
		}

		$response = wp_remote_request( $plan['url'], $args );

		if ( is_wp_error( $response ) ) {
			return $this->transport_failure( $response->get_error_message(), $plan['path'] );
		}

		return $this->interpret(
			$plan,
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * A cURL handle set up exactly like fetch() would have called it, for a
	 * caller that wants to drive the transfer itself.
	 *
	 * Only the streaming endpoint uses this, and only to put the handle in a
	 * curl_multi so it can keep writing to the visitor while the platform is
	 * still thinking. Everything else should call fetch().
	 *
	 * @param array         $plan      From plan().
	 * @param callable|null  $on_write  Receives each chunk of body as it
	 *                                  arrives, for a response that is meant
	 *                                  to be read before it has finished —
	 *                                  the platform's event stream. Given one,
	 *                                  nothing is buffered for the caller.
	 * @param callable|null  $on_header Receives each response header line,
	 *                                  which is how a streamed call learns its
	 *                                  status before deciding to trust the
	 *                                  body.
	 * @return resource|CurlHandle|null Null when cURL is unavailable.
	 */
	public function curl_handle( array $plan, $on_write = null, $on_header = null ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return null;
		}

		$headers = array();

		foreach ( $plan['headers'] as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
		$handle = curl_init( $plan['url'] );

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => $plan['method'],
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => $plan['timeout'],
				CURLOPT_CONNECTTIMEOUT => min( 10, $plan['timeout'] ),
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 2,
				// Same knob WP_Http honours, so a site that has had to turn
				// verification off for a self-signed staging platform does not
				// find streaming the one route that still fails.
				CURLOPT_SSL_VERIFYPEER => (bool) apply_filters( 'https_ssl_verify', true, $plan['url'] ),
				// Accept whatever encoding cURL can undo. The body is decoded
				// by the time we read it.
				CURLOPT_ENCODING       => '',
			)
		);

		if ( null !== $plan['body'] ) {
			curl_setopt( $handle, CURLOPT_POSTFIELDS, $plan['body'] );
		}

		if ( is_callable( $on_write ) ) {
			curl_setopt(
				$handle,
				CURLOPT_WRITEFUNCTION,
				static function ( $handle, $chunk ) use ( $on_write ) {
					$on_write( $chunk );

					// cURL treats any other number as a write failure and
					// aborts the transfer.
					return strlen( $chunk );
				}
			);
		}

		if ( is_callable( $on_header ) ) {
			curl_setopt(
				$handle,
				CURLOPT_HEADERFUNCTION,
				static function ( $handle, $line ) use ( $on_header ) {
					$on_header( $line );

					return strlen( $line );
				}
			);
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init

		return $handle;
	}

	/**
	 * Turn a finished transfer into a result, or into the error a visitor
	 * should see.
	 *
	 * @param array  $plan   From plan().
	 * @param int    $status HTTP status.
	 * @param string $raw    Response body.
	 * @return array|WP_Error
	 */
	public function interpret( array $plan, $status, $raw ) {
		$status = (int) $status;
		$json   = json_decode( (string) $raw, true );

		if ( $status >= 400 || ! is_array( $json ) ) {
			$this->log(
				sprintf(
					'upstream %d for %s: %s',
					$status,
					$plan['path'],
					is_array( $json ) && isset( $json['message'] ) ? (string) $json['message'] : substr( (string) $raw, 0, 200 )
				)
			);

			return new WP_Error(
				'sky_aff_upstream',
				$this->upstream_message( $status, $json ),
				array(
					'status'          => ( $status >= 400 && $status < 600 ) ? $status : 502,
					'upstream_status' => $status,
				)
			);
		}

		return array(
			'data' => isset( $json['data'] ) ? $json['data'] : null,
			'meta' => isset( $json['meta'] ) ? $json['meta'] : null,
		);
	}

	/**
	 * The error for a call that never reached the platform at all.
	 *
	 * @param string $message Transport-level failure, as cURL described it.
	 * @param string $path    Endpoint that was being called.
	 * @return WP_Error
	 */
	public function transport_failure( $message, $path ) {
		$data = array( 'status' => 502 );

		// A cURL failure names the host it could not reach. That belongs
		// in the log and on an administrator's screen, never in a response
		// a public visitor can read.
		if ( current_user_can( 'manage_options' ) ) {
			$data['detail'] = $message;
		}

		$this->log( 'transport failure for ' . $path . ': ' . $message );

		return new WP_Error(
			'sky_aff_transport',
			__( 'Search is temporarily unavailable. Please try again.', 'sky-affiliate-search' ),
			$data
		);
	}

	/**
	 * Headers every call carries.
	 *
	 * `Origin` is the load-bearing one — see the class docblock.
	 *
	 * @return array<string,string>
	 */
	private function headers() {
		$domain = Sky_Aff_Settings::get( 'platform_domain' );

		return array(
			'Origin'           => 'https://' . $domain,
			// Honoured only when the API runs with debug on; harmless
			// otherwise, and it makes staging setups work without fuss.
			'X-Platform-Domain' => $domain,
			'Accept'           => 'application/json',
			'Accept-Language'  => Sky_Aff_Settings::language(),
			'User-Agent'       => 'SkyAffiliateSearch/' . SKY_AFF_VERSION . '; ' . home_url( '/' ),
		);
	}

	/**
	 * Absolute URL for a general endpoint.
	 *
	 * @param string $path Path below /v1/general.
	 * @return string
	 */
	private function endpoint( $path ) {
		$base = untrailingslashit( Sky_Aff_Settings::get( 'api_base_url' ) );

		return $base . '/v1/general/' . ltrim( $path, '/' );
	}

	/**
	 * Cache key for one call.
	 *
	 * The platform domain and language are folded in because the same path and
	 * body mean different things per tenant and per language, and the epoch
	 * makes saving settings invalidate everything at once.
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Endpoint path.
	 * @param array      $query  Query arguments.
	 * @param array|null $body   JSON body.
	 * @return string
	 */
	private function cache_key( $method, $path, array $query, $body ) {
		$parts = array(
			$method,
			$path,
			$query,
			$body,
			Sky_Aff_Settings::get( 'platform_domain' ),
			Sky_Aff_Settings::language(),
			(int) get_option( Sky_Aff_Settings::CACHE_FLAG, 0 ),
		);

		// Transient keys are capped at 172 characters; a hash is always safe.
		return 'sky_aff_' . md5( wp_json_encode( $parts ) );
	}

	/**
	 * Turn an upstream failure into something worth showing.
	 *
	 * Two audiences, two answers. An administrator is trying to fix a
	 * misconfiguration and wants the platform's own words; a visitor wants to
	 * know whether to try again, and must not be shown internal hostnames or
	 * validation internals that describe how the integration is wired.
	 *
	 * @param int        $status HTTP status.
	 * @param array|null $json   Decoded body, when it was JSON at all.
	 * @return string
	 */
	private function upstream_message( $status, $json ) {
		$is_admin = current_user_can( 'manage_options' );

		if ( 404 === $status ) {
			// Overwhelmingly this is NotFoundDomainException: the configured
			// domain is not a platform the API knows. The hint is more use to
			// an administrator than the platform's own wording, so it wins
			// even when a message came back.
			return $is_admin
				? __( 'The Sky platform did not recognise this domain. Check the platform domain in the plugin settings.', 'sky-affiliate-search' )
				: __( 'Search is temporarily unavailable.', 'sky-affiliate-search' );
		}

		if ( $is_admin && is_array( $json ) && ! empty( $json['message'] ) && is_string( $json['message'] ) ) {
			return $json['message'];
		}

		if ( 422 === $status ) {
			return __( 'The search parameters were rejected by the platform.', 'sky-affiliate-search' );
		}

		if ( 429 === $status ) {
			return __( 'The platform is busy. Please try again in a moment.', 'sky-affiliate-search' );
		}

		return __( 'Search is temporarily unavailable. Please try again.', 'sky-affiliate-search' );
	}

	/**
	 * Record a failure for the site owner to find later.
	 *
	 * Only writes when the site has debug logging on: a search page can fail
	 * hundreds of times an hour if the platform is down, and filling a
	 * production log with that is worse than the silence.
	 *
	 * @param string $message What went wrong.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Sky Affiliate Search] ' . $message );
		}
	}

	/**
	 * Fetch the platform profile — used by the settings screen to prove the
	 * domain and API URL line up before the owner publishes a search page.
	 *
	 * Runs uncached so "Test connection" always reflects reality.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		return $this->get( 'platform', array(), true );
	}
}
