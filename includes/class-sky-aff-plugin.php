<?php
/**
 * Plugin orchestrator: wires the pieces together and owns activation.
 *
 * @package SkyAffiliateSearch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Composition root.
 */
class Sky_Aff_Plugin {

	/** Option holding the id of the auto-created search page. */
	const PAGE_OPTION = 'sky_aff_search_page_id';

	/**
	 * Settings component.
	 *
	 * @var Sky_Aff_Settings
	 */
	private $settings;

	/**
	 * REST proxy.
	 *
	 * @var Sky_Aff_Rest_Controller
	 */
	private $rest;

	/**
	 * Shortcode renderer.
	 *
	 * @var Sky_Aff_Shortcode
	 */
	private $shortcode;

	/**
	 * Build the object graph.
	 */
	public function __construct() {
		$client = new Sky_Aff_Api_Client();

		$this->settings  = new Sky_Aff_Settings();
		$this->rest      = new Sky_Aff_Rest_Controller( $client );
		$this->shortcode = new Sky_Aff_Shortcode();
	}

	/**
	 * Cache-busting version for one bundled asset.
	 *
	 * The plugin version alone is not enough during development, and not even
	 * enough between releases if a hotfix ships without a version bump: the
	 * URL never changes, so browsers keep serving the stylesheet they already
	 * have and the page renders against stale CSS with no clue as to why.
	 * Folding in the file's modification time makes every edit a new URL.
	 *
	 * @param string $relative Path below the plugin directory.
	 * @return string Version string for wp_register_* .
	 */
	public static function asset_version( $relative ) {
		$path = SKY_AFF_PATH . ltrim( $relative, '/' );
		$time = file_exists( $path ) ? filemtime( $path ) : 0;

		return $time ? SKY_AFF_VERSION . '.' . $time : SKY_AFF_VERSION;
	}

	/**
	 * Register every hook. Called on plugins_loaded.
	 */
	public function boot() {
		load_plugin_textdomain(
			'sky-affiliate-search',
			false,
			dirname( plugin_basename( SKY_AFF_FILE ) ) . '/languages'
		);

		$this->settings->hooks();
		$this->rest->hooks();
		$this->shortcode->hooks();

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SKY_AFF_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings-screen assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function admin_assets( $hook ) {
		if ( 'toplevel_page_' . Sky_Aff_Settings::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sky-aff-admin',
			SKY_AFF_URL . 'assets/css/sky-admin.css',
			array(),
			self::asset_version( 'assets/css/sky-admin.css' )
		);

		wp_enqueue_script(
			'sky-aff-admin',
			SKY_AFF_URL . 'assets/js/sky-admin.js',
			array(),
			self::asset_version( 'assets/js/sky-admin.js' ),
			true
		);

		wp_localize_script(
			'sky-aff-admin',
			'skyAffAdmin',
			array(
				'restBase' => esc_url_raw( rest_url( SKY_AFF_REST_NS ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'testing' => __( 'Testing…', 'sky-affiliate-search' ),
					'test'    => __( 'Test connection', 'sky-affiliate-search' ),
					'ok'      => /* translators: %s: platform name. */ __( 'Connected to %s', 'sky-affiliate-search' ),
					'okBare'  => __( 'Connected.', 'sky-affiliate-search' ),
					'failed'  => __( 'Connection failed', 'sky-affiliate-search' ),
					'unsaved' => __( 'Save your changes first, then test.', 'sky-affiliate-search' ),
					'exampleLink' => __( 'Links will look like:', 'sky-affiliate-search' ),
				),
			)
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Sky_Aff_Settings::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'sky-affiliate-search' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Activation: create a search page carrying the shortcode, so the owner
	 * has a working URL before they have configured anything.
	 *
	 * Idempotent — a reactivation reuses the existing page rather than
	 * stacking up duplicates.
	 */
	public static function activate() {
		$existing = (int) get_option( self::PAGE_OPTION, 0 );

		if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Travel Search', 'sky-affiliate-search' ),
				'post_name'    => 'travel-search',
				'post_content' => '<!-- wp:shortcode -->[' . Sky_Aff_Shortcode::TAG . ']<!-- /wp:shortcode -->',
				'post_status'  => 'draft',
				'post_type'    => 'page',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::PAGE_OPTION, (int) $page_id, false );
		}
	}

	/**
	 * Deactivation: drop cached upstream responses so a re-activated plugin
	 * never serves stale prices. Settings and the page are left alone.
	 */
	public static function deactivate() {
		self::flush_cache();
	}

	/**
	 * Delete every cached response and rate-limit counter.
	 *
	 * Transients have no group API, so this goes to the options table
	 * directly — the same approach core's own cleanup takes.
	 *
	 * @return int Number of transients removed.
	 */
	public static function flush_cache() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_sky_aff_' ) . '%';
		$time = $wpdb->esc_like( '_transient_timeout_sky_aff_' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$time
			)
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		// Object-cache backed sites keep a copy outside the options table.
		// The grouped flush only landed in WP 6.1 and not every drop-in
		// implements it, so ask before calling.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
		}

		return count( $names );
	}
}
