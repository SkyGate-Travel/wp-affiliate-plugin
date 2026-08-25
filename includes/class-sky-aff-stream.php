<?php
/**
 * Streams one upstream search to the browser as it happens.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * NDJSON writer for the /stream route.
 *
 * A flight or hotel search is not slow because of anything this plugin does:
 * the platform fans out to every provider it sells and answers when the last
 * one has replied, which its own storefront allows a full minute for. Waiting
 * that long inside a single silent response is what breaks — an nginx or
 * Cloudflare hop in front of WordPress buffers the body and gives up at its
 * own gateway timeout, and the visitor is left staring at a form that looks
 * broken with no way to tell whether anything is still happening.
 *
 * So the answer is written as it arrives, one JSON object per line:
 *
 *     : padding …                 (comment line, flushes hostile proxies)
 *     {"type":"open","kind":"flight"}
 *     {"type":"tick","elapsed":1}
 *     {"type":"meta","meta":{…},"total":42}
 *     {"type":"items","bucket":"departing","items":[…],"sent":6}
 *     {"type":"items","bucket":"departing","items":[…],"replace":true}
 *     {"type":"done","count":42,"total":42,"cached":false,"elapsed":11.4}
 *
 * An `items` frame extends the list unless it says `replace`, which means it
 * IS the list — that is how a live supplier search reports a fare it has
 * withdrawn.
 *
 * The ticks are the load-bearing part. They keep bytes moving through every
 * intermediary while the platform thinks, and they give the search UI a real
 * elapsed time instead of a spinner that says nothing. `items` then arrives in
 * small batches so the first cards paint immediately rather than after the
 * browser has built two hundred of them.
 *
 * How much of that is real progress depends on what the platform offers:
 *
 * - **Flights** are relayed. Sky answers `flights/available/stream` in
 *   Server-Sent Events, one frame per supplier as it reports, and this route
 *   forwards them — first fares on screen in about two seconds on a search
 *   that takes thirty. Each frame is what is on offer *now*, so a frame
 *   replaces the list rather than extending it; see relay().
 * - **Hotels, activities and tours** have no streamed endpoint upstream, so
 *   the earliest any result can exist is when the one blocking call returns.
 *   The heartbeats and the chunked delivery still apply: the wait is visible
 *   and survivable, and the first cards paint before the last are built.
 */
class Sky_Aff_Stream {

	/**
	 * Items per `items` line.
	 *
	 * Small enough that the first batch paints in one frame, large enough that
	 * a 200-result hotel search is not 200 lines of JSON overhead.
	 */
	const CHUNK = 6;

	/** Seconds between heartbeats while waiting on the platform. */
	const TICK = 1.0;

	/**
	 * Bytes of comment sent before anything else.
	 *
	 * Proxies that buffer a response do so until they have "enough" of it;
	 * 2 KB is the usual threshold and costs nothing once.
	 */
	const PAD = 2048;

	/**
	 * Most rows sent in one snapshot of a relayed search.
	 *
	 * The widget shows twenty and hands the rest of the search to the
	 * platform, so a supplier that answers with a hundred fares is trimmed to
	 * the cheapest few rather than pushed through WordPress a dozen times.
	 */
	const SNAPSHOT_CAP = 24;

	/**
	 * Least time between snapshots, in seconds.
	 *
	 * Suppliers re-price in bursts — eight frames in one second is normal —
	 * and each one repaints the list. The first frame and the last are always
	 * sent; the ones in between are worth coalescing.
	 */
	const SNAPSHOT_INTERVAL = 0.7;

	/**
	 * Fare fields, best first. Providers disagree about the name, and
	 * adult_price_final is the only one that is after the platform's markups —
	 * the number the visitor would actually be charged.
	 */
	const PRICE_KEYS = array( 'adult_price_final', 'total_price', 'price_adult', 'price', 'TotalFare' );

	/**
	 * API client.
	 *
	 * @var Sky_Aff_Api_Client
	 */
	private $client;

	/**
	 * When run() started, as a float timestamp.
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * Half-read event-stream text, waiting for the rest of its frame.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * HTTP status of the relayed call, learned from its headers before any of
	 * its body is trusted.
	 *
	 * @var int
	 */
	private $status = 0;

	/**
	 * Which list the platform says this search fills.
	 *
	 * @var string
	 */
	private $bucket = 'departing';

	/**
	 * Every fare currently on offer, keyed. Replaced wholesale on each frame.
	 *
	 * The platform sends what it currently has, not what is new: fares are
	 * re-priced and re-keyed as suppliers report, and a search that showed
	 * twenty-two different fares along the way can end with three genuinely on
	 * offer. Accumulating them would leave a widget advertising fares that
	 * cannot be bought.
	 *
	 * @var array<string,array>
	 */
	private $union = array();

	/**
	 * When the last snapshot went out, as a float timestamp.
	 *
	 * @var float
	 */
	private $snapped = 0.0;

	/**
	 * Fingerprint of the last snapshot written, so a supplier re-reporting the
	 * same fares at the same prices does not repaint the widget.
	 *
	 * @var string
	 */
	private $published = '';

	/**
	 * Frames read from the platform's stream.
	 *
	 * @var int
	 */
	private $frames = 0;

	/**
	 * Whether anything has reached the browser yet. Once it has, falling back
	 * to the whole-answer endpoint would duplicate it.
	 *
	 * @var bool
	 */
	private $emitted = false;

	/**
	 * Total the platform reported when it finished, or null if it never did.
	 *
	 * @var int|null
	 */
	private $total = null;

	/**
	 * An error the platform sent inside its own stream.
	 *
	 * @var string
	 */
	private $upstream_error = '';

	/**
	 * Constructor.
	 *
	 * @param Sky_Aff_Api_Client $client API client.
	 */
	public function __construct( Sky_Aff_Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Stream one search and exit.
	 *
	 * @param array $spec {
	 *     Everything the search needs.
	 *
	 *     @type string     $kind   flight|hotel|activity|tour — decides how the
	 *                              payload is split into item batches.
	 *     @type string     $method HTTP verb for the upstream call.
	 *     @type string     $path   Path below /v1/general.
	 *     @type array      $query  Query arguments.
	 *     @type array|null $body   JSON body.
	 *     @type int|null   $ttl    Cache lifetime override.
	 * }
	 */
	public function run( array $spec ) {
		$this->started = microtime( true );
		$kind          = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';

		$this->open( $kind );

		$plan = $this->client->plan(
			isset( $spec['method'] ) ? $spec['method'] : 'GET',
			isset( $spec['path'] ) ? $spec['path'] : '',
			isset( $spec['query'] ) ? (array) $spec['query'] : array(),
			isset( $spec['body'] ) ? $spec['body'] : null,
			false,
			isset( $spec['ttl'] ) ? $spec['ttl'] : null
		);

		if ( is_wp_error( $plan ) ) {
			$this->fail( $plan );
		}

		// Where the platform can search out loud, relay that instead: the
		// first fares arrive within seconds of asking rather than when the
		// slowest supplier has finished. It keeps its own cache entry — what
		// it delivers is the cheapest few of a live list, not the whole answer
		// the other endpoint gives, and the two must not overwrite each other.
		$streamed = empty( $spec['stream_path'] ) ? null : $this->client->plan(
			isset( $spec['method'] ) ? $spec['method'] : 'POST',
			$spec['stream_path'],
			isset( $spec['query'] ) ? (array) $spec['query'] : array(),
			isset( $spec['body'] ) ? $spec['body'] : null,
			false,
			isset( $spec['ttl'] ) ? $spec['ttl'] : null,
			array( 'Accept' => 'text/event-stream' )
		);

		if ( is_array( $streamed ) ) {
			$cached = $this->client->cached( $streamed );

			if ( false !== $cached && is_array( $cached ) ) {
				$this->deliver( $kind, $cached, true );
			}
		}

		$cached = $this->client->cached( $plan );

		if ( false !== $cached && is_array( $cached ) ) {
			$this->deliver( $kind, $cached, true );
		}

		if ( is_array( $streamed ) ) {
			$relayed = $this->relay( $streamed );

			if ( is_wp_error( $relayed ) ) {
				$this->fail( $relayed );
			}

			if ( is_array( $relayed ) ) {
				// A search cut off halfway is worth showing and not worth
				// keeping — cached, half an answer would be served to
				// everybody for the rest of the cache window.
				if ( null !== $this->total ) {
					$this->client->remember( $streamed, $relayed );
				}

				$this->done( count( $this->union ), false, $this->total );
			}

			// Null means nothing has been written yet and the platform cannot
			// stream this search — an older build answers 404 here. Ask for it
			// whole instead.
		}

		$result = $this->await( $plan );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}

		$this->client->remember( $plan, $result );
		$this->deliver( $kind, $result, false );
	}

	/**
	 * Run the plan, writing a heartbeat while it is in flight.
	 *
	 * curl_multi is used for a single transfer purely so this loop keeps
	 * control: curl_exec() would block the worker with nothing written until
	 * the platform answered, which is the situation this whole route exists to
	 * avoid. Without the extension the call still happens, just silently.
	 *
	 * @param array $plan From Sky_Aff_Api_Client::plan().
	 * @return array|WP_Error
	 */
	private function await( array $plan ) {
		$handle = function_exists( 'curl_multi_init' ) ? $this->client->curl_handle( $plan ) : null;

		if ( ! $handle ) {
			return $this->client->fetch( $plan );
		}

		$raw     = '';
		$outcome = $this->pump( $handle, $plan['timeout'], $raw );

		if ( CURLE_OK !== $outcome ) {
			return $this->client->transport_failure(
				$this->curl_reason( $outcome ),
				$plan['path']
			);
		}

		return $this->client->interpret( $plan, $this->status, $raw );
	}

	/**
	 * Drive one transfer to the end, writing a heartbeat while it runs.
	 *
	 * curl_multi is used for a single transfer purely so this loop keeps
	 * control: curl_exec() would block the worker with nothing written until
	 * the platform answered, which is the situation this whole route exists to
	 * avoid.
	 *
	 * @param resource|CurlHandle $handle  Configured handle.
	 * @param int                 $timeout The handle's own timeout, seconds.
	 * @param string|null         $body    Receives the response body, for a
	 *                                     handle that buffers one.
	 * @return int cURL result code; CURLE_OK when the transfer completed.
	 */
	private function pump( $handle, $timeout, &$body = null ) {
		// Per transfer: a relay that fell back to the whole-answer endpoint
		// must not be judged by the 404 the streamed one gave.
		$this->status = 0;

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init
		$multi = curl_multi_init();
		curl_multi_add_handle( $multi, $handle );

		$running  = 0;
		$outcome  = CURLE_OK;
		$next     = microtime( true ) + self::TICK;
		$deadline = microtime( true ) + $timeout + 5;

		do {
			curl_multi_exec( $multi, $running );

			// How a transfer ended is only ever reported here. curl_errno()
			// on an easy handle inside a multi answers 0 even for a transfer
			// that timed out with nothing received — which turned a slow
			// platform into "the platform sent us nonsense".
			while ( $message = curl_multi_info_read( $multi ) ) {
				if ( $message['handle'] === $handle ) {
					$outcome = (int) $message['result'];
				}
			}

			if ( $running > 0 ) {
				// Wakes early the moment there is something to read, so this
				// is a 200 ms ceiling on latency rather than a poll interval.
				curl_multi_select( $multi, 0.2 );
			}

			$now = microtime( true );

			if ( $now >= $next ) {
				$this->emit(
					array(
						'type'    => 'tick',
						'elapsed' => round( $now - $this->started, 1 ),
					)
				);

				$next = $now + self::TICK;

				// Only true once something has been written to a browser that
				// has gone away — which is exactly what the heartbeat above
				// just did. Abandoning the search saves the worker, and the
				// platform's answer would have had nowhere to go.
				if ( connection_aborted() ) {
					curl_multi_remove_handle( $multi, $handle );
					curl_close( $handle );
					curl_multi_close( $multi );
					exit;
				}
			}
		} while ( $running > 0 && microtime( true ) < $deadline );

		if ( $running > 0 ) {
			// The loop, not cURL, called time: the handle's own timeout should
			// have fired five seconds ago and did not.
			$outcome = CURLE_OPERATION_TIMEDOUT;
		}

		$this->status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$body         = (string) curl_multi_getcontent( $handle );

		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
		curl_multi_close( $multi );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_multi_init

		return $outcome;
	}

	/**
	 * Readable reason for a cURL result code.
	 *
	 * @param int $outcome Result code.
	 * @return string
	 */
	private function curl_reason( $outcome ) {
		$reason = function_exists( 'curl_strerror' )
			? (string) curl_strerror( $outcome )
			: 'unknown error';

		return sprintf( 'cURL error %d: %s', $outcome, $reason );
	}

	/**
	 * Relay the platform's own event stream.
	 *
	 * `flights/available/stream` answers in Server-Sent Events: one `meta`
	 * frame, then a `flights` frame each time a supplier reports, then `done`.
	 * Each frame carries what is on offer *now* — not what is new — because
	 * suppliers re-price and re-key their fares as they go. So each frame
	 * replaces the list rather than extending it, which is what the platform's
	 * own front-end does too, and what keeps the widget from advertising a
	 * fare that has already been withdrawn.
	 *
	 * What goes out is the cheapest few of that list, at most one snapshot
	 * every SNAPSHOT_INTERVAL, plus a final one when the platform says it has
	 * finished.
	 *
	 * @param array $plan Streamed call, from Sky_Aff_Api_Client::plan().
	 * @return array|WP_Error|null Assembled payload in the shape the
	 *                             whole-answer endpoint would have returned;
	 *                             null when this platform cannot stream the
	 *                             search and nothing has been written yet.
	 */
	private function relay( array $plan ) {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			return null;
		}

		$handle = $this->client->curl_handle(
			$plan,
			function ( $chunk ) {
				$this->absorb( $chunk );
			},
			function ( $line ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $match ) ) {
					$this->status = (int) $match[1];
				}
			}
		);

		if ( ! $handle ) {
			return null;
		}

		$outcome = $this->pump( $handle, $plan['timeout'] );

		if ( ! $this->emitted ) {
			// Nothing has reached the browser, so the whole-answer endpoint is
			// still a clean option — and it is the one that will produce a
			// proper error if the platform is simply down. An older platform
			// build answers 404 here and lands in exactly this branch.
			return null;
		}

		if ( '' !== $this->upstream_error ) {
			$this->log( 'stream error for ' . $plan['path'] . ': ' . $this->upstream_error );

			return new WP_Error(
				'sky_aff_upstream',
				__( 'Search is temporarily unavailable. Please try again.', 'sky-affiliate-search' ),
				array( 'status' => 502 )
			);
		}

		if ( CURLE_OK !== $outcome ) {
			// Partial results are still results: the visitor keeps what
			// arrived, and $this->total staying null keeps the half-answer out
			// of the cache.
			$this->log( 'stream cut short for ' . $plan['path'] . ': ' . $this->curl_reason( $outcome ) );
		}

		$final = $this->snapshot();

		// The settling frame. Suppliers withdraw fares as often as they add
		// them, so the last thing the widget hears must be the whole list as
		// it finally stands.
		$this->publish( $final );

		return array(
			'data' => array(
				'departing'  => 'departing' === $this->bucket ? $final : array(),
				'round_trip' => 'round_trip' === $this->bucket ? $final : array(),
				'multi_city' => 'multi_city' === $this->bucket ? $final : array(),
			),
			'meta' => null,
		);
	}

	/**
	 * Take in stream text as cURL hands it over, a frame at a time.
	 *
	 * @param string $chunk Whatever arrived.
	 */
	private function absorb( $chunk ) {
		// The body of a 404 or a 500 is not an event stream, and reading it as
		// one would emit nonsense to the browser instead of falling back.
		if ( $this->status >= 400 ) {
			return;
		}

		$this->buffer .= str_replace( "\r\n", "\n", $chunk );

		while ( false !== ( $at = strpos( $this->buffer, "\n\n" ) ) ) {
			$frame        = substr( $this->buffer, 0, $at );
			$this->buffer = substr( $this->buffer, $at + 2 );

			$this->frame( $frame );
		}
	}

	/**
	 * Act on one `event:`/`data:` frame.
	 *
	 * @param string $raw Frame, without its blank-line terminator.
	 */
	private function frame( $raw ) {
		$event = '';
		$data  = '';

		foreach ( explode( "\n", $raw ) as $line ) {
			if ( 0 === strpos( $line, 'event:' ) ) {
				$event = trim( substr( $line, 6 ) );
			} elseif ( 0 === strpos( $line, 'data:' ) ) {
				// SSE allows a payload to span several data lines.
				$data .= trim( substr( $line, 5 ) );
			}
		}

		if ( '' === $event || '' === $data ) {
			return;
		}

		$payload = json_decode( $data, true );

		if ( ! is_array( $payload ) ) {
			return;
		}

		++$this->frames;

		if ( 'meta' === $event ) {
			$this->bucket = ( ! empty( $payload['is_multi_city'] ) || 3 === (int) ( $payload['trip_type'] ?? 0 ) )
				? 'multi_city'
				: ( ! empty( $payload['is_round_trip'] ) ? 'round_trip' : 'departing' );

			// The count is not known until the platform says `done`, and
			// claiming one now would have the widget announce a total that
			// then changes under the visitor.
			$this->emit(
				array(
					'type'  => 'meta',
					'meta'  => null,
					'total' => null,
					'count' => 0,
				)
			);

			$this->emitted = true;

			return;
		}

		if ( 'flights' === $event ) {
			$this->offer( isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array() );

			return;
		}

		if ( 'error' === $event ) {
			$this->upstream_error = isset( $payload['message'] ) ? (string) $payload['message'] : 'unknown';

			return;
		}

		if ( 'done' === $event ) {
			$this->total = isset( $payload['total'] ) ? (int) $payload['total'] : 0;
		}
	}

	/**
	 * Take a frame's list of fares as the new truth, and show it — unless the
	 * one before it went out a moment ago.
	 *
	 * @param array $items Fares as the platform currently has them.
	 */
	private function offer( array $items ) {
		$union = array();

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$union[ $this->key_of( $item ) ] = $item;
			}
		}

		$this->union = $union;

		$now = microtime( true );

		// The first frame always goes: it is the one that turns a page of
		// placeholders into fares, and it is worth more than every frame after
		// it put together.
		if ( $this->emitted && $this->snapped > 0.0 && $now - $this->snapped < self::SNAPSHOT_INTERVAL ) {
			return;
		}

		$this->snapped = $now;

		$this->publish( $this->snapshot() );
	}

	/**
	 * Write one list of fares, replacing whatever the widget was showing.
	 *
	 * @param array $items Fares, already ordered and capped.
	 */
	private function publish( array $items ) {
		if ( ! $items ) {
			return;
		}

		// Suppliers re-report constantly, and most of those frames say exactly
		// what the last one said. Only what changed is worth a repaint.
		$fingerprint = array();

		foreach ( $items as $item ) {
			$fingerprint[] = $this->key_of( $item ) . ':' . $this->price_of( $item );
		}

		$fingerprint = md5( implode( '|', $fingerprint ) );

		if ( $fingerprint === $this->published ) {
			return;
		}

		$this->published = $fingerprint;

		$this->emit(
			array(
				'type'    => 'items',
				'bucket'  => $this->bucket,
				'items'   => $items,
				// Not an addition: this is the list now.
				'replace' => true,
				'sent'    => count( $items ),
			)
		);

		$this->emitted = true;
	}

	/**
	 * Stable identity for one fare.
	 *
	 * @param array $item Fare.
	 * @return string
	 */
	private function key_of( array $item ) {
		if ( ! empty( $item['flight_id'] ) && is_scalar( $item['flight_id'] ) ) {
			return (string) $item['flight_id'];
		}

		return md5( (string) wp_json_encode( $item ) );
	}

	/**
	 * What the visitor is charged for one adult, if the fare says.
	 *
	 * @param array $item Fare.
	 * @return float|null
	 */
	private function price_of( array $item ) {
		foreach ( self::PRICE_KEYS as $key ) {
			if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
				return (float) $item[ $key ];
			}
		}

		return null;
	}

	/**
	 * The list as it stands: cheapest first, priceless fares last, capped.
	 *
	 * @return array
	 */
	private function snapshot() {
		$items = array_values( $this->union );

		usort(
			$items,
			function ( $a, $b ) {
				$left  = $this->price_of( $a );
				$right = $this->price_of( $b );

				if ( null === $left || null === $right ) {
					// A fare nobody priced cannot be compared, and belongs
					// below every fare that was.
					return ( null === $left ? 1 : 0 ) - ( null === $right ? 1 : 0 );
				}

				return $left <=> $right;
			}
		);

		return array_slice( $items, 0, self::SNAPSHOT_CAP );
	}

	/**
	 * Record something for the site owner, when the site keeps a log.
	 *
	 * @param string $message What happened.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Sky Affiliate Search] ' . $message );
		}
	}

	/**
	 * Send the headers and the first bytes.
	 *
	 * @param string $kind Search kind, echoed back so the client can tell a
	 *                     late reply from a superseded one.
	 */
	private function open( $kind ) {
		if ( ! headers_sent() ) {
			// Replaces the application/json the REST server already queued.
			header( 'Content-Type: application/x-ndjson; charset=' . get_option( 'blog_charset' ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'X-Robots-Tag: noindex' );
			// nginx and several CDNs read this as "do not buffer this body".
			header( 'X-Accel-Buffering: no' );
		}

		// phpcs:disable WordPress.PHP.IniSet.Risky
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		@ini_set( 'implicit_flush', '1' );
		// phpcs:enable WordPress.PHP.IniSet.Risky

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}

		// Anything already buffering — WordPress' own, a theme's, an output
		// filter — would hold every line until the script ended. A buffer that
		// refuses to close is not worth spinning on.
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_flush() ) {
				break;
			}
		}

		// A search outliving the visitor is wasted work; the heartbeat notices
		// and stops. The time limit has to go, though: 30 seconds of upstream
		// is normal here, and the default would kill the script mid-wait.
		ignore_user_abort( false );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		echo ': ' . str_repeat( ' ', self::PAD ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->emit(
			array(
				'type' => 'open',
				'kind' => $kind,
			)
		);
	}

	/**
	 * Write one payload out as meta plus item batches, then finish.
	 *
	 * @param string $kind   Search kind.
	 * @param array  $result { data, meta } from the client.
	 * @param bool   $cached Whether it came from the response cache.
	 */
	private function deliver( $kind, array $result, $cached ) {
		$data    = isset( $result['data'] ) ? $result['data'] : null;
		$meta    = isset( $result['meta'] ) ? $result['meta'] : null;
		$buckets = $this->buckets( $kind, $data );
		$total   = 0;

		foreach ( $buckets as $rows ) {
			$total += count( $rows );
		}

		$this->emit(
			array(
				'type'  => 'meta',
				'meta'  => $meta,
				// Hotels report a catalogue-wide total in meta; everything else
				// only has what it just sent.
				'total' => $this->reported_total( $kind, $data, $meta, $total ),
				'count' => $total,
			)
		);

		foreach ( $buckets as $bucket => $rows ) {
			$sent = 0;

			foreach ( array_chunk( $rows, self::CHUNK ) as $batch ) {
				$sent += count( $batch );

				$sent_ok = $this->emit(
					array(
						'type'   => 'items',
						'bucket' => $bucket,
						'items'  => $batch,
						'sent'   => $sent,
					)
				);

				if ( ! $sent_ok ) {
					$this->fail(
						new WP_Error(
							'sky_aff_unencodable',
							__( 'Search is temporarily unavailable. Please try again.', 'sky-affiliate-search' ),
							array( 'status' => 502 )
						)
					);
				}
			}
		}

		$this->done( $total, $cached, $this->reported_total( $kind, $data, $meta, $total ) );
	}

	/**
	 * Close the stream on a finished search.
	 *
	 * @param int      $count  Rows actually written.
	 * @param bool     $cached Whether the answer came from the cache.
	 * @param int|null $total  What to report as the result count, when the
	 *                         platform counts more than it sent.
	 */
	private function done( $count, $cached, $total = null ) {
		$this->emit(
			array(
				'type'    => 'done',
				'count'   => (int) $count,
				'total'   => null === $total ? (int) $count : (int) $total,
				'cached'  => (bool) $cached,
				'elapsed' => round( microtime( true ) - $this->started, 1 ),
			)
		);

		exit;
	}

	/**
	 * Split a payload into named lists of rows.
	 *
	 * The names match what the search UI already renders, so a bucket is
	 * enough for it to know which renderer to use and whether the batch
	 * appends to what is on screen.
	 *
	 * @param string $kind Search kind.
	 * @param mixed  $data Decoded `data` payload.
	 * @return array<string,array> Bucket name => rows.
	 */
	private function buckets( $kind, $data ) {
		if ( 'flight' === $kind ) {
			$out = array();

			// The contract is all three keys always present, exactly one
			// populated — but a provider build that returns only the one it
			// filled should still render.
			foreach ( array( 'departing', 'round_trip', 'multi_city' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
					$out[ $key ] = array_values( $data[ $key ] );
				}
			}

			return $out;
		}

		if ( 'activity' === $kind ) {
			// Activities have moved between envelopes across API builds; the
			// UI already tries all three, so the stream does too.
			foreach ( array( 'products', 'data' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
					return array( 'items' => array_values( $data[ $key ] ) );
				}
			}
		}

		return array( 'items' => is_array( $data ) ? array_values( $data ) : array() );
	}

	/**
	 * The result count worth showing, which is not always how many rows came
	 * back: a paged hotel search says "312 results" while sending 20.
	 *
	 * @param string $kind  Search kind.
	 * @param mixed  $data  Decoded payload.
	 * @param mixed  $meta  Decoded meta.
	 * @param int    $rows  Rows actually in this payload.
	 * @return int
	 */
	private function reported_total( $kind, $data, $meta, $rows ) {
		if ( 'hotel' === $kind && isset( $meta['total'] ) ) {
			return (int) $meta['total'];
		}

		if ( 'activity' === $kind && isset( $data['total'] ) ) {
			return (int) $data['total'];
		}

		return $rows;
	}

	/**
	 * Report a failure in the stream's own vocabulary and stop.
	 *
	 * The HTTP status is long gone — it was 200 the moment the first line went
	 * out — so the error has to be a line like any other. The client raises it
	 * exactly as it would a failed fetch.
	 *
	 * @param WP_Error $error What went wrong.
	 */
	private function fail( WP_Error $error ) {
		$data = $error->get_error_data();

		$this->emit(
			array(
				'type'    => 'error',
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'status'  => isset( $data['status'] ) ? (int) $data['status'] : 500,
				'detail'  => isset( $data['detail'] ) ? (string) $data['detail'] : '',
			)
		);

		exit;
	}

	/**
	 * Write one line and push it out of every buffer between here and the
	 * browser.
	 *
	 * @param array $line Event.
	 * @return bool False when the event could not be encoded, which for a
	 *              batch of supplier data means text that is not valid UTF-8
	 *              however hard WordPress tried. Saying so beats sending the
	 *              empty line the client would silently skip.
	 */
	private function emit( array $line ) {
		$json = wp_json_encode( $line );

		if ( false === $json ) {
			return false;
		}

		echo $json, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ob_get_level() > 0 ) {
			@ob_flush();
		}

		flush();

		return true;
	}
}
