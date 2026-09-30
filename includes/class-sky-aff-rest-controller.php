<?php
/**
 * REST routes the search UI calls. Proxies to Sky server-side.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * The site's own REST surface, under /wp-json/sky-affiliate/v1/.
 *
 * The browser never reaches the Sky API directly (it cannot — see
 * Sky_Aff_Api_Client), so every search runs: browser → these routes → Sky.
 * That also means these routes are the plugin's public attack surface, hence
 * the per-IP rate limit and the strict per-endpoint argument schemas.
 *
 * Each search exists twice over: as a classic route that answers once the
 * platform has, and through `/stream`, which writes the same answer as it
 * arrives (see Sky_Aff_Stream). Both share one validator per product — the
 * *_request() builders — so the two can never disagree about what a valid
 * search is.
 */
class Sky_Aff_Rest_Controller {

	/**
	 * API client.
	 *
	 * @var Sky_Aff_Api_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Sky_Aff_Api_Client $client API client.
	 */
	public function __construct( Sky_Aff_Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Hook registration.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_rate_limit' ), 10, 3 );
	}

	/**
	 * Register every route.
	 */
	public function register_routes() {
		$public = array( $this, 'permit_public' );

		register_rest_route(
			SKY_AFF_REST_NS,
			'/hotels/places',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'hotel_places' ),
				'permission_callback' => $public,
				'args'                => array(
					// Optional: absent means "give me the browse list".
					// Present but shorter than two characters is rejected,
					// because that is what the upstream place search requires.
					'q'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							if ( null === $value || '' === trim( (string) $value ) ) {
								return true;
							}

							return strlen( trim( (string) $value ) ) >= 2;
						},
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 12,
						'minimum' => 1,
						'maximum' => 30,
					),
				),
			)
		);

		register_rest_route(
			SKY_AFF_REST_NS,
			'/hotels/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'hotel_search' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			SKY_AFF_REST_NS,
			'/flights/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'flight_search' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			SKY_AFF_REST_NS,
			'/flights/airports',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'airports' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			SKY_AFF_REST_NS,
			'/activities/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'activity_search' ),
				'permission_callback' => $public,
			)
		);

		// The keyword field's live suggestions: a handful of slimmed-down
		// matches per keystroke pause, so a visitor sees activities appear
		// while they type rather than only after pressing Search.
		register_rest_route(
			SKY_AFF_REST_NS,
			'/activities/suggest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'activity_suggest' ),
				'permission_callback' => $public,
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							$length = mb_strlen( trim( (string) $value ) );

							return $length >= 2 && $length <= 80;
						},
					),
				),
			)
		);

		register_rest_route(
			SKY_AFF_REST_NS,
			'/activities/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'activity_config' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			SKY_AFF_REST_NS,
			'/tours',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tours' ),
				'permission_callback' => $public,
			)
		);

		// One route for every product, because the client side of a stream is
		// the same code whatever it is streaming; `kind` in the body picks the
		// validator and the upstream call.
		register_rest_route(
			SKY_AFF_REST_NS,
			'/stream',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => $public,
			)
		);

		// Admin-only: the settings screen's "Test connection" button.
		register_rest_route(
			SKY_AFF_REST_NS,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Gate for the visitor-facing routes.
	 *
	 * Open to logged-out visitors by design — this is a public search page.
	 *
	 * MUST stay free of side effects: WordPress calls a permission callback
	 * more than once per request (once to dispatch, and again from
	 * rest_send_allow_header() to work out which methods to advertise), so
	 * anything that counts or writes belongs in enforce_rate_limit() instead.
	 *
	 * @return true|WP_Error
	 */
	public function permit_public() {
		if ( ! Sky_Aff_Settings::is_configured() ) {
			return new WP_Error(
				'sky_aff_not_configured',
				__( 'Search is not available yet.', 'sky-affiliate-search' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	/**
	 * Fixed-window per-IP counter, applied once per request.
	 *
	 * Hooked to rest_pre_dispatch rather than to the routes' permission
	 * callbacks because this one fires exactly once, which is what a counter
	 * needs. Deliberately coarse: it exists to stop a scraper turning the host
	 * site into a free proxy for live inventory, not to be a precise quota.
	 *
	 * @param mixed           $result  Short-circuit value, normally null.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request Incoming request.
	 * @return mixed Untouched $result, or a WP_Error to reject the request.
	 */
	public function enforce_rate_limit( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result; // Something else already answered.
		}

		$route = $request->get_route();

		// Only the public proxy routes; the admin test button is exempt.
		if ( 0 !== strpos( ltrim( $route, '/' ), SKY_AFF_REST_NS . '/' ) ) {
			return $result;
		}

		if ( false !== strpos( $route, '/test-connection' ) ) {
			return $result;
		}

		$limit = (int) Sky_Aff_Settings::get( 'rate_limit' );

		if ( $limit <= 0 ) {
			return $result;
		}

		$ip = $this->client_ip();

		if ( '' === $ip ) {
			return $result;
		}

		// Suggestions fire on every pause in typing, so they would spend a
		// visitor's whole search budget before they ever pressed Search. They
		// get a bucket of their own, three times the size: a typist, not a
		// scraper, and each answer is six slim rows from a cached call.
		$bucket = 'sky_aff_rl_';

		if ( false !== strpos( $route, '/activities/suggest' ) ) {
			$bucket = 'sky_aff_rls_';
			$limit *= 3;
		}

		$key   = $bucket . md5( $ip . '|' . floor( time() / MINUTE_IN_SECONDS ) );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'sky_aff_rate_limited',
				__( 'Too many searches. Please wait a moment and try again.', 'sky-affiliate-search' ),
				array( 'status' => 429 )
			);
		}

		// Two minutes, not one: the window is keyed on the current minute, so
		// a counter created at :59 still has to outlive that minute.
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Best-effort visitor IP.
	 *
	 * Only REMOTE_ADDR is trusted by default: forwarded headers are
	 * attacker-controlled unless a known proxy sits in front, which we cannot
	 * detect from here. Sites behind a reverse proxy can opt in via the filter.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filter the IP used for rate limiting.
		 *
		 * @param string $ip Detected address.
		 */
		$ip = apply_filters( 'sky_aff_client_ip', $ip );

		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Hotel place typeahead, plus the browse list shown before typing.
	 *
	 * Unlike flights there is no endpoint that lists destinations: the API
	 * keeps no hotel catalogue of its own, and `hotels/places` requires a
	 * two-character query because it is a passthrough to each provider's
	 * search. (The platform's own `popular-hotel-destinations` is a
	 * seller-authenticated route, so a public storefront cannot read it.)
	 *
	 * So the browse list is assembled: the destinations configured in settings
	 * are resolved through the same place search, once, and cached for a day.
	 * That matters because a place is only bookable by the provider's own id —
	 * a hand-written list of city names could not be searched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function hotel_places( WP_REST_Request $request ) {
		$q     = trim( (string) $request->get_param( 'q' ) );
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? min( 30, $limit ) : 12;

		if ( '' === $q ) {
			return $this->hotel_browse_list( $limit );
		}

		$result = $this->client->get(
			'hotels/places',
			array(
				'q'     => $q,
				'limit' => $limit,
			)
		);

		return $this->respond( $result );
	}

	/**
	 * The destinations offered before the visitor types anything.
	 *
	 * Resolved one name at a time because the place search takes a single
	 * query. That is up to a dozen upstream calls, so the assembled list is
	 * cached as a whole for a day and each lookup is itself cached — a cold
	 * build costs one round of calls, everything after is free.
	 *
	 * A name that resolves to nothing is skipped rather than failing the list:
	 * providers differ on what they index, and one unknown city should not
	 * empty the dropdown.
	 *
	 * @param int $limit Maximum entries.
	 * @return WP_REST_Response|WP_Error
	 */
	private function hotel_browse_list( $limit ) {
		$rows = $this->resolve_destinations(
			'hoteldest',
			$limit,
			function ( $name ) {
				list( $label ) = $this->split_destination( $name );

				$hit = $this->client->get(
					'hotels/places',
					array(
						'q'     => $label,
						'limit' => 10,
					),
					false,
					DAY_IN_SECONDS
				);

				if ( is_wp_error( $hit ) || ! is_array( $hit['data'] ) ) {
					return null;
				}

				// Prefer a city — searching "Dubai" also matches districts and
				// individual properties, and someone choosing from a list of
				// destinations means the whole city. Among cities, the one with
				// the most properties: "Paris" matches Paris, Texas too, and
				// property count is what separates them.
				$best  = null;
				$score = -1;

				foreach ( $hit['data'] as $row ) {
					if ( ! is_array( $row ) || empty( $row['id'] ) ) {
						continue;
					}

					$is_city = 'city' === ( $row['type'] ?? '' );
					$exact   = mb_strtolower( (string) ( $row['name'] ?? '' ) ) === mb_strtolower( $label );
					$hotels  = (int) ( $row['hotels'] ?? 0 );

					$candidate = ( $is_city ? 1000000 : 0 ) + ( $exact ? 100000 : 0 ) + $hotels;

					if ( $candidate > $score ) {
						$score = $candidate;
						$best  = $row;
					}
				}

				return $best;
			},
			function ( $entry ) {
				return (string) $entry['id'];
			}
		);

		return new WP_REST_Response(
			array(
				'data' => $rows,
				'meta' => array( 'browse' => true ),
			),
			200
		);
	}

	/**
	 * Note destinations that could not be resolved, for the site owner.
	 *
	 * @param int $failed Count that failed.
	 * @param int $total  Count attempted.
	 */
	private function log_browse_failures( $failed, $total ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[Sky Affiliate Search] %d of %d hotel destinations could not be resolved.',
					$failed,
					$total
				)
			);
		}
	}

	/**
	 * Hotel availability search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function hotel_search( WP_REST_Request $request ) {
		$spec = $this->hotel_request( $this->params( $request ) );

		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		return $this->respond( $this->dispatch( $spec ) );
	}

	/**
	 * Validate a hotel search and describe the upstream call it becomes.
	 *
	 * Mirrors the upstream AvailableRequest rules rather than forwarding the
	 * body blind, so a malformed request fails here with a useful message
	 * instead of costing an upstream round trip.
	 *
	 * Kept apart from the route handler because the streaming route needs the
	 * same answer without executing it: a search that is going to be rejected
	 * should fail as an ordinary REST error, before a single line of stream
	 * has gone out and taken the status code with it.
	 *
	 * @param array $body Request parameters.
	 * @return array|WP_Error Upstream call description.
	 */
	private function hotel_request( array $body ) {
		$city  = isset( $body['city'] ) ? sanitize_text_field( (string) $body['city'] ) : '';
		$hotel = isset( $body['hotel'] ) ? sanitize_text_field( (string) $body['hotel'] ) : '';

		if ( '' === $city && '' === $hotel ) {
			return new WP_Error(
				'sky_aff_missing_place',
				__( 'Choose a city or a property to search.', 'sky-affiliate-search' ),
				array( 'status' => 400 )
			);
		}

		$start = $this->sanitize_date( $body['start_date'] ?? '' );
		$end   = $this->sanitize_date( $body['end_date'] ?? '' );

		if ( '' === $start || '' === $end || $end < $start ) {
			return new WP_Error(
				'sky_aff_bad_dates',
				__( 'Enter a valid check-in and check-out date.', 'sky-affiliate-search' ),
				array( 'status' => 400 )
			);
		}

		$rooms = $this->sanitize_rooms( $body['rooms'] ?? array() );

		$payload = array(
			'start_date' => $start,
			'end_date'   => $end,
			'rooms'      => $rooms,
			'lang'       => Sky_Aff_Settings::language(),
		);

		// The upstream rule is `prohibits:hotel` on city — sending both is a
		// validation error, so pick exactly one.
		if ( '' !== $hotel ) {
			$payload['hotel'] = $hotel;
		} else {
			$payload['city'] = $city;
		}

		$page = isset( $body['page'] ) ? max( 1, (int) $body['page'] ) : 1;

		if ( $page > 1 ) {
			$payload['page'] = $page;
		}

		if ( ! empty( $body['sort'] ) && is_string( $body['sort'] ) ) {
			$payload['sort'] = sanitize_text_field( $body['sort'] );
		}

		$filters = $this->sanitize_hotel_filters( $body['filters'] ?? array() );

		if ( ! empty( $filters ) ) {
			$payload['filters'] = $filters;
		}

		return array(
			'method' => 'POST',
			'path'   => 'hotels/available',
			'body'   => $payload,
		);
	}

	/**
	 * Flight availability search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function flight_search( WP_REST_Request $request ) {
		$spec = $this->flight_request( $this->params( $request ) );

		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		return $this->respond( $this->dispatch( $spec ) );
	}

	/**
	 * Validate a flight search and describe the upstream call it becomes.
	 *
	 * @param array $body Request parameters.
	 * @return array|WP_Error Upstream call description.
	 */
	private function flight_request( array $body ) {
		$origin      = strtoupper( sanitize_text_field( (string) ( $body['origin'] ?? '' ) ) );
		$destination = strtoupper( sanitize_text_field( (string) ( $body['destination'] ?? '' ) ) );
		$departing   = $this->sanitize_date( $body['departing'] ?? '' );
		$returning   = $this->sanitize_date( $body['returning'] ?? '' );

		if ( ! preg_match( '/^[A-Z]{3}$/', $origin ) || ! preg_match( '/^[A-Z]{3}$/', $destination ) ) {
			return new WP_Error(
				'sky_aff_bad_route',
				__( 'Choose an origin and a destination.', 'sky-affiliate-search' ),
				array( 'status' => 400 )
			);
		}

		if ( $origin === $destination ) {
			return new WP_Error(
				'sky_aff_same_route',
				__( 'Origin and destination must differ.', 'sky-affiliate-search' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $departing ) {
			return new WP_Error(
				'sky_aff_bad_dates',
				__( 'Enter a departure date.', 'sky-affiliate-search' ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $returning && $returning < $departing ) {
			return new WP_Error(
				'sky_aff_bad_dates',
				__( 'The return date cannot be before the departure date.', 'sky-affiliate-search' ),
				array( 'status' => 400 )
			);
		}

		$payload = array(
			// Upstream requires this flag on every search; the UI derives it
			// from whether both endpoints are domestic airports.
			'is_internal' => ! empty( $body['is_internal'] ) ? 1 : 0,
			'origin'      => $origin,
			'destination' => $destination,
			'departing'   => $departing,
			'adult'       => isset( $body['adult'] ) ? max( 1, (int) $body['adult'] ) : 1,
			'child'       => isset( $body['child'] ) ? max( 0, (int) $body['child'] ) : 0,
			'infant'      => isset( $body['infant'] ) ? max( 0, (int) $body['infant'] ) : 0,
			'cabin_type'  => isset( $body['cabin_type'] ) ? max( 1, (int) $body['cabin_type'] ) : 1,
		);

		if ( '' !== $returning ) {
			$payload['returning'] = $returning;
		}

		return array(
			'method' => 'POST',
			'path'   => 'flights/available',
			// The platform will search out loud if asked: same body, same
			// results, but delivered supplier by supplier instead of when the
			// slowest one has finished. Only the streaming route uses it, and
			// only where the platform is new enough to have it — see
			// Sky_Aff_Stream::relay().
			'stream_path' => 'flights/available/stream',
			'body'        => $payload,
		);
	}

	/**
	 * Departure/arrival options for the flight form.
	 *
	 * Two modes:
	 *
	 * - **Browse** (no `q`): what a visitor sees the moment they click the
	 *   field. Comes from `flight-cities`, which the API left-joins to the
	 *   platform's own `platform_airport_sorts` — so the seller's priority
	 *   destinations come first instead of a global alphabetical list. (An
	 *   airports-first browse list is useless in practice: sorted by name it
	 *   opens on "108 Mile Ranch".)
	 * - **Search** (`q` given): cities and airports, cities first, merged.
	 *
	 * Both go upstream as `filter[search]`, never `q`. These are query-builder
	 * resources with a custom `search` filter; a bare `q` is silently ignored,
	 * which looks like a working search that returns the same page whatever
	 * you type.
	 *
	 * A city code is a valid origin/destination — the flight request validates
	 * against airports OR flight cities — so a city entry is directly usable
	 * and searches every airport serving it.
	 *
	 * Cached for a day: this is reference data, not live fares.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function airports( WP_REST_Request $request ) {
		$q     = trim( (string) $request->get_param( 'q' ) );
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? min( 40, $limit ) : 15;

		if ( '' === $q ) {
			return $this->flight_browse_list( $limit );
		}

		$out = array();

		$cities = $this->client->get(
			'flight-cities',
			array(
				'limit'  => $limit,
				'filter' => array( 'search' => sanitize_text_field( $q ) ),
			),
			false,
			DAY_IN_SECONDS
		);

		if ( ! is_wp_error( $cities ) && is_array( $cities['data'] ) ) {
			foreach ( $cities['data'] as $city ) {
				$entry = $this->normalize_place( $city, 'city' );

				if ( $entry ) {
					// How many airports the city covers, so the label can say
					// "all airports" where that is what picking it means.
					$entry['airports'] = is_array( $city['airports'] ?? null ) ? count( $city['airports'] ) : 0;
					$out[]             = $entry;
				}
			}
		}

		// Airports only matter once someone is narrowing down — browsing a
		// list of individual terminals is noise next to the city they serve.
		if ( '' !== $q && count( $out ) < $limit ) {
			$airports = $this->client->get(
				'airports',
				array(
					'limit'  => $limit,
					'filter' => array( 'search' => sanitize_text_field( $q ) ),
				),
				false,
				DAY_IN_SECONDS
			);

			if ( ! is_wp_error( $airports ) && is_array( $airports['data'] ) ) {
				$seen = wp_list_pluck( $out, 'code' );

				foreach ( $airports['data'] as $airport ) {
					$entry = $this->normalize_place( $airport, 'airport' );

					if ( $entry && ! in_array( $entry['code'], $seen, true ) ) {
						$out[] = $entry;
					}
				}
			}
		}

		if ( empty( $out ) && is_wp_error( $cities ) ) {
			return $cities;
		}

		return new WP_REST_Response(
			array(
				'data' => array_slice( $out, 0, $limit ),
				'meta' => array( 'browse' => '' === $q ),
			),
			200
		);
	}

	/**
	 * Departure cities offered before the visitor types.
	 *
	 * Built from the configured destination names rather than from
	 * `flight-cities` directly: that endpoint only returns a meaningful order
	 * when the platform has set airport priorities, and falls back to internal
	 * id order otherwise — which opens the dropdown on Olavarria and Billiluna.
	 * Resolving names through the city search gives the same list on every
	 * platform, and the owner controls it.
	 *
	 * @param int $limit Maximum entries.
	 * @return WP_REST_Response|WP_Error
	 */
	private function flight_browse_list( $limit ) {
		$rows = $this->resolve_destinations(
			'flightdest',
			$limit,
			function ( $name ) {
				list( $label, $code ) = $this->split_destination( $name );

				$hit = $this->client->get(
					'flight-cities',
					// An explicit code is exact: `code` is a filterable column,
					// so "Paris|CDG" resolves to precisely that city.
					$code
						? array(
							'limit'  => 5,
							'filter' => array( 'code' => $code ),
						)
						: array(
							'limit'  => 10,
							'filter' => array( 'search' => $label ),
						),
					false,
					DAY_IN_SECONDS
				);

				if ( is_wp_error( $hit ) || empty( $hit['data'] ) ) {
					return null;
				}

				$row = $this->best_city_match( $hit['data'], $label );

				if ( ! $row ) {
					return null;
				}

				$entry = $this->normalize_place( $row, 'city' );

				if ( $entry ) {
					$airports          = $row['airports'] ?? null;
					$entry['airports'] = is_array( $airports ) ? count( $airports ) : 0;
				}

				return $entry;
			},
			function ( $entry ) {
				return $entry['code'];
			}
		);

		return new WP_REST_Response(
			array(
				'data' => $rows,
				'meta' => array( 'browse' => true ),
			),
			200
		);
	}

	/**
	 * Split a configured destination into its name and optional code.
	 *
	 * "Paris" searches by name; "Paris|CDG" pins the exact city. The override
	 * exists because city names are not unique and the API has no notion of
	 * which one is famous — searching "London" matches London, Kentucky just
	 * as well as London, England.
	 *
	 * @param string $raw Configured line.
	 * @return array{0:string,1:string} Name, and a three-letter code or ''.
	 */
	private function split_destination( $raw ) {
		$parts = explode( '|', (string) $raw, 2 );
		$label = trim( $parts[0] );
		$code  = isset( $parts[1] ) ? strtoupper( trim( $parts[1] ) ) : '';

		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
			$code = '';
		}

		return array( $label, $code );
	}

	/**
	 * Pick the city a person meant out of several same-named matches.
	 *
	 * Two signals, in order: the name matching exactly (so "Rome" does not
	 * settle for "Rome Municipal"), then the number of airports serving it.
	 * Airport count is a decent stand-in for prominence — Paris, France has
	 * several, Paris, Texas has one — and it is the only ranking the payload
	 * actually offers.
	 *
	 * @param array  $rows  Candidate rows.
	 * @param string $label Name that was searched for.
	 * @return array|null Best row, or null.
	 */
	private function best_city_match( array $rows, $label ) {
		$needle = mb_strtolower( trim( $label ) );
		$best   = null;
		$score  = -1;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name  = mb_strtolower( (string) ( $row['en_name'] ?? $row['name'] ?? '' ) );
			$count = is_array( $row['airports'] ?? null ) ? count( $row['airports'] ) : 0;

			// Exact name dominates: a hundred-airport city called something
			// else is still the wrong answer.
			$candidate = ( $name === $needle ? 1000 : 0 ) + $count;

			if ( $candidate > $score ) {
				$score = $candidate;
				$best  = $row;
			}
		}

		return $best;
	}

	/**
	 * Resolve the configured destination names into bookable entries.
	 *
	 * Shared by both browse lists. One upstream lookup per name, so the whole
	 * assembled list is cached for a day and each individual lookup is cached
	 * too — a cold build costs one round of calls, everything after is free.
	 *
	 * A name that resolves to nothing is skipped rather than failing the list:
	 * suppliers differ on what they cover, and one unknown city should not
	 * empty the dropdown.
	 *
	 * @param string   $bucket  Cache namespace, so the two lists never collide.
	 * @param int      $limit   Maximum entries.
	 * @param callable $resolve Name => entry|null.
	 * @param callable $key_of  Entry => dedupe key.
	 * @return array
	 */
	private function resolve_destinations( $bucket, $limit, callable $resolve, callable $key_of ) {
		$names = Sky_Aff_Settings::popular_destinations();

		if ( empty( $names ) ) {
			return array();
		}

		$cache_key = 'sky_aff_' . $bucket . '_' . md5(
			wp_json_encode(
				array(
					$names,
					$limit,
					Sky_Aff_Settings::get( 'platform_domain' ),
					Sky_Aff_Settings::language(),
					(int) get_option( Sky_Aff_Settings::CACHE_FLAG, 0 ),
				)
			)
		);

		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out    = array();
		$seen   = array();
		$failed = 0;

		foreach ( $names as $name ) {
			if ( count( $out ) >= $limit ) {
				break;
			}

			$entry = $resolve( $name );

			if ( ! $entry ) {
				$failed++;
				continue;
			}

			$key = (string) $key_of( $entry );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = $entry;
		}

		// Only worth keeping if something resolved; caching an empty list for a
		// day would hide a passing upstream outage until it expires.
		if ( ! empty( $out ) ) {
			set_transient( $cache_key, $out, DAY_IN_SECONDS );
		}

		if ( $failed > 0 ) {
			$this->log_browse_failures( $failed, count( $names ) );
		}

		return $out;
	}

	/**
	 * Reduce a flight city or airport row to the one shape the picker renders.
	 *
	 * The two upstream resources overlap but disagree on names (a city's own
	 * name is `en_name`, an airport's city is `city_en_name`), and localized
	 * fields are null on plenty of rows, so each value falls back through the
	 * options rather than trusting one key.
	 *
	 * @param mixed  $row  Upstream row.
	 * @param string $kind 'city' or 'airport'.
	 * @return array|null Normalised entry, or null when there is no usable code.
	 */
	private function normalize_place( $row, $kind ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$code = strtoupper( trim( (string) ( $row['code'] ?? '' ) ) );

		// The flight request only accepts a three-letter code.
		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return null;
		}

		$city = 'city' === $kind
			? ( $row['name'] ?? $row['en_name'] ?? '' )
			: ( $row['city_name'] ?? $row['city_en_name'] ?? '' );

		$name = 'city' === $kind
			? ''
			: ( $row['en_name'] ?? $row['name'] ?? '' );

		return array(
			'kind'    => $kind,
			'code'    => $code,
			'city'    => (string) ( $city ?: $code ),
			'name'    => (string) $name,
			'country' => (string) ( $row['country_en_name'] ?? $row['country_name'] ?? '' ),
		);
	}

	/**
	 * Activity product list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activity_search( WP_REST_Request $request ) {
		$spec = $this->activity_request( $this->params( $request ) );

		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		return $this->respond( $this->dispatch( $spec ) );
	}

	/**
	 * Live suggestions for the activity keyword field.
	 *
	 * The same upstream search as activity_search(), capped at six rows and
	 * cut down to what a suggestion line shows. A full product row carries
	 * every image and link the supplier has; six of those per keystroke pause
	 * would make the dropdown slower than the search it is meant to preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activity_suggest( WP_REST_Request $request ) {
		$result = $this->client->get(
			'activities',
			array(
				'q'        => trim( (string) $request->get_param( 'q' ) ),
				'per_page' => 6,
				'language' => Sky_Aff_Settings::language(),
			),
			false,
			// Someone else typed the same three letters a minute ago; the
			// catalogue has not changed since.
			10 * MINUTE_IN_SECONDS
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) ? $result['data'] : array();
		$rows = array();

		if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
			$rows = $data['products'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$rows = $data['data'];
		} elseif ( is_array( $data ) && isset( $data[0] ) ) {
			$rows = $data;
		}

		$items = array();

		foreach ( array_slice( $rows, 0, 6 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = $row['titleTranslated'] ?? $row['title'] ?? $row['name'] ?? '';

			if ( '' === trim( (string) $title ) ) {
				continue;
			}

			$currency = $row['currency'] ?? '';

			if ( is_array( $currency ) ) {
				$currency = $currency['code'] ?? '';
			}

			$image = $row['image_url'] ?? $row['photo'] ?? $row['image'] ?? '';

			if ( '' === $image && ! empty( $row['images'] ) && is_array( $row['images'] ) ) {
				$image = is_array( $row['images'][0] ) ? ( $row['images'][0]['url'] ?? '' ) : $row['images'][0];
			}

			$items[] = array(
				'id'       => (string) ( $row['productUuid'] ?? $row['uuid'] ?? $row['id'] ?? '' ),
				'title'    => (string) $title,
				'city'     => (string) ( $row['cityName'] ?? $row['city'] ?? '' ),
				'type'     => (string) ( $row['typeName'] ?? '' ),
				'image'    => esc_url_raw( (string) $image ),
				'price'    => isset( $row['basePrice'] ) ? (float) $row['basePrice'] : null,
				'currency' => (string) $currency,
			);
		}

		return new WP_REST_Response( array( 'data' => $items ), 200 );
	}

	/**
	 * Turn activity filters into the upstream call they describe.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error Upstream call description.
	 */
	private function activity_request( array $params ) {
		// Exactly the parameters the provider contract accepts; anything else
		// is dropped rather than forwarded.
		$allowed = array( 'q', 'type', 'category', 'country', 'city', 'tag', 'min_price', 'max_price', 'sort', 'page', 'per_page' );
		$query   = array();

		foreach ( $allowed as $key ) {
			$value = isset( $params[ $key ] ) ? $params[ $key ] : null;

			if ( null === $value || '' === $value ) {
				continue;
			}

			$query[ $key ] = in_array( $key, array( 'min_price', 'max_price', 'page', 'per_page' ), true )
				? (int) $value
				: sanitize_text_field( (string) $value );
		}

		$query['language'] = Sky_Aff_Settings::language();

		return array(
			'method' => 'GET',
			'path'   => 'activities',
			'query'  => $query,
		);
	}

	/**
	 * Activity taxonomy (types, categories, locations) for the filter UI.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function activity_config() {
		return $this->respond( $this->client->get( 'activities/config' ) );
	}

	/**
	 * Tour list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tours( WP_REST_Request $request ) {
		$spec = $this->tour_request( $this->params( $request ) );

		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		return $this->respond( $this->dispatch( $spec ) );
	}

	/**
	 * Turn a tour request into the upstream call it describes.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error Upstream call description.
	 */
	private function tour_request( array $params ) {
		$limit = isset( $params['limit'] ) ? (int) $params['limit'] : 0;

		return array(
			'method' => 'GET',
			'path'   => 'tours',
			'query'  => array( 'limit' => $limit > 0 ? min( 60, $limit ) : 24 ),
		);
	}

	/**
	 * Stream one search instead of answering it in a single response.
	 *
	 * The body is an ordinary search payload plus `kind`, so the client has
	 * one call to make whichever tab the visitor is on. Validation happens
	 * here, while a failure can still be a real HTTP status: once the stream
	 * has opened, the status is 200 and an error can only be another line.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|void
	 */
	public function stream( WP_REST_Request $request ) {
		$params = $this->params( $request );
		$kind   = isset( $params['kind'] ) ? sanitize_key( (string) $params['kind'] ) : '';

		switch ( $kind ) {
			case 'flight':
				$spec = $this->flight_request( $params );
				break;

			case 'hotel':
				$spec = $this->hotel_request( $params );
				break;

			case 'activity':
				$spec = $this->activity_request( $params );
				break;

			case 'tour':
				$spec = $this->tour_request( $params );
				break;

			default:
				$spec = new WP_Error(
					'sky_aff_bad_kind',
					__( 'Unknown search type.', 'sky-affiliate-search' ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		// A host or CDN that insists on buffering makes a stream strictly
		// worse than a plain request — the visitor waits the same time and
		// then gets the whole thing at once anyway, minus the heartbeats. The
		// route stays available with streaming off and simply answers like the
		// classic one, so a full-page-cached copy of the search page whose
		// JavaScript still asks for a stream keeps working.
		if ( ! Sky_Aff_Settings::get( 'enable_stream' ) ) {
			return $this->respond( $this->dispatch( $spec ) );
		}

		$spec['kind'] = $kind;

		$streamer = new Sky_Aff_Stream( $this->client );

		$streamer->run( $spec ); // Writes the response itself and exits.
	}

	/**
	 * Every parameter of a request, however it was sent.
	 *
	 * The classic routes take their arguments in the query string or a JSON
	 * body depending on the verb, and the stream route takes all of them in a
	 * JSON body. Reading them from one merged array is what lets a single
	 * validator serve both.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function params( WP_REST_Request $request ) {
		$json = $request->get_json_params();

		return array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params(),
			is_array( $json ) ? $json : array()
		);
	}

	/**
	 * Execute an upstream call description.
	 *
	 * @param array $spec From one of the *_request() builders.
	 * @return array|WP_Error
	 */
	private function dispatch( array $spec ) {
		if ( 'POST' === $spec['method'] ) {
			return $this->client->post( $spec['path'], isset( $spec['body'] ) ? (array) $spec['body'] : array() );
		}

		return $this->client->get(
			$spec['path'],
			isset( $spec['query'] ) ? (array) $spec['query'] : array(),
			false,
			isset( $spec['ttl'] ) ? $spec['ttl'] : null
		);
	}

	/**
	 * Settings-screen connection check. Reports the resolved platform name so
	 * the owner can see they pointed at the right tenant.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection() {
		$result = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = is_array( $result['data'] ) ? $result['data'] : array();

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'platform' => array(
					'name'   => $data['name'] ?? ( $data['title'] ?? '' ),
					'domain' => $data['domain'] ?? Sky_Aff_Settings::get( 'platform_domain' ),
				),
				// The storefront's language segment, and a link built with it.
				// Worth surfacing: a wrong prefix is invisible here and shows
				// up only as a 404 after a visitor clicks through.
				'links'    => array(
					'locale'  => Sky_Aff_Links::locale_prefix(),
					'example' => Sky_Aff_Links::home(),
				),
			),
			200
		);
	}

	/**
	 * Wrap a client result as a REST response.
	 *
	 * @param array|WP_Error $result Client result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Accept only a real Y-m-d date, and never one in the past — upstream
	 * rejects those anyway.
	 *
	 * @param mixed $value Raw value.
	 * @return string Y-m-d, or '' when unusable.
	 */
	private function sanitize_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $value ) );

		if ( ! checkdate( $m, $d, $y ) ) {
			return '';
		}

		if ( $value < gmdate( 'Y-m-d' ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Normalise room occupancy into the shape upstream validates.
	 *
	 * @param mixed $rooms Raw rooms.
	 * @return array Non-empty list of rooms.
	 */
	private function sanitize_rooms( $rooms ) {
		$clean = array();

		if ( is_array( $rooms ) ) {
			foreach ( $rooms as $room ) {
				if ( ! is_array( $room ) ) {
					continue;
				}

				$adults   = isset( $room['adults'] ) ? max( 1, min( 12, (int) $room['adults'] ) ) : 1;
				$children = isset( $room['children'] ) ? max( 0, min( 8, (int) $room['children'] ) ) : 0;
				$ages     = array();

				if ( $children > 0 && ! empty( $room['ages'] ) && is_array( $room['ages'] ) ) {
					foreach ( $room['ages'] as $age ) {
						if ( is_numeric( $age ) ) {
							// Upstream caps a child's age at 17.
							$ages[] = max( 0, min( 17, (int) $age ) );
						}
					}
				}

				// One age per child, or the provider prices the room wrong.
				$ages = array_slice( $ages, 0, $children );

				while ( count( $ages ) < $children ) {
					$ages[] = 8;
				}

				// `ages` always goes, even empty. Upstream validation marks it
				// nullable, but the hotel providers behind it read the key
				// unconditionally and the request 500s ("Server Error", no
				// detail) when it is absent — which reads as "the search found
				// nothing" rather than as a malformed request.
				$clean[] = array(
					'adults'   => $adults,
					'children' => $children,
					'ages'     => $ages,
				);

				if ( count( $clean ) >= 6 ) {
					break;
				}
			}
		}

		if ( empty( $clean ) ) {
			$clean[] = array(
				'adults'   => 2,
				'children' => 0,
			);
		}

		return $clean;
	}

	/**
	 * Keep only the hotel facets upstream declares, in the types it expects.
	 *
	 * @param mixed $filters Raw filters.
	 * @return array
	 */
	private function sanitize_hotel_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return array();
		}

		$out = array();

		// Integer-list facets, as validated by AvailableRequest. The provider
		// can offer far more groups than these; sending one the API does not
		// allow fails the whole search with a 422, so the list is a whitelist,
		// not a filter of convenience.
		$int_lists = array( 'property_types', 'stars', 'facilities', 'room_facilities', 'chains', 'districts', 'landmarks' );

		foreach ( $int_lists as $key ) {
			if ( empty( $filters[ $key ] ) || ! is_array( $filters[ $key ] ) ) {
				continue;
			}

			$values = array();

			foreach ( $filters[ $key ] as $value ) {
				if ( is_numeric( $value ) ) {
					$values[] = (int) $value;
				}
			}

			if ( ! empty( $values ) ) {
				$out[ $key ] = array_values( array_unique( $values ) );
			}
		}

		// meal_plan ids are provider vocabulary, not numbers
		// ("breakfast_included"), which is why upstream types it as a string.
		if ( isset( $filters['meal_plan'] ) && is_scalar( $filters['meal_plan'] ) && '' !== $filters['meal_plan'] ) {
			$out['meal_plan'] = substr( sanitize_text_field( (string) $filters['meal_plan'] ), 0, 64 );
		}

		if ( isset( $filters['review_score'] ) && is_numeric( $filters['review_score'] ) ) {
			$out['review_score'] = max( 0, min( 100, (int) $filters['review_score'] ) );
		}

		if ( isset( $filters['free_cancellation'] ) ) {
			$out['free_cancellation'] = ! empty( $filters['free_cancellation'] );
		}

		foreach ( array( 'min_price', 'max_price' ) as $key ) {
			if ( isset( $filters[ $key ] ) && is_numeric( $filters[ $key ] ) ) {
				$out[ $key ] = max( 0, (float) $filters[ $key ] );
			}
		}

		// Upstream enforces max >= min; drop an inverted pair rather than
		// letting it 422 the whole search.
		if ( isset( $out['min_price'], $out['max_price'] ) && $out['max_price'] < $out['min_price'] ) {
			unset( $out['max_price'] );
		}

		return $out;
	}
}
