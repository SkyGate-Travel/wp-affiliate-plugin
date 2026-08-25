<?php
/**
 * Admin settings screen.
 *
 * @package SkyAffiliateSearch
 *
 * @var array $s Current settings, defaults merged in. Provided by the caller.
 */

defined( 'ABSPATH' ) || exit;

$sky_aff_page_id  = (int) get_option( Sky_Aff_Plugin::PAGE_OPTION, 0 );
$sky_aff_page_url = $sky_aff_page_id ? get_permalink( $sky_aff_page_id ) : '';
$sky_aff_home     = Sky_Aff_Links::home();
?>
<div class="wrap sky-aff-admin">
	<h1><?php esc_html_e( 'Sky Affiliate Search', 'sky-affiliate-search' ); ?></h1>

	<p class="sky-aff-lede">
		<?php esc_html_e( 'Show live flights, hotels, activities and tours from your Sky travel platform on this site. Visitors search here and finish their booking on the platform, with your affiliate code attached.', 'sky-affiliate-search' ); ?>
	</p>

	<form method="post" action="options.php" id="sky-aff-settings-form">
		<?php settings_fields( Sky_Aff_Settings::GROUP ); ?>

		<h2 class="title"><?php esc_html_e( 'Connection', 'sky-affiliate-search' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Your platform operator provides these two values. Both are required.', 'sky-affiliate-search' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="sky_aff_platform_domain"><?php esc_html_e( 'Platform domain', 'sky-affiliate-search' ); ?> <span class="sky-aff-req">*</span></label>
				</th>
				<td>
					<input
						type="text"
						class="regular-text code"
						id="sky_aff_platform_domain"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[platform_domain]"
						value="<?php echo esc_attr( $s['platform_domain'] ); ?>"
						placeholder="travel.example.com"
						dir="ltr"
					/>
					<p class="description">
						<?php esc_html_e( 'The travel platform’s own domain — where your visitors will land to book. Paste it with or without https://; only the hostname is kept.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sky_aff_api_base_url"><?php esc_html_e( 'API URL', 'sky-affiliate-search' ); ?> <span class="sky-aff-req">*</span></label>
				</th>
				<td>
					<input
						type="url"
						class="regular-text code"
						id="sky_aff_api_base_url"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[api_base_url]"
						value="<?php echo esc_attr( $s['api_base_url'] ); ?>"
						placeholder="https://api.example.com"
						dir="ltr"
					/>
					<p class="description">
						<?php esc_html_e( 'Root of the platform API, without the /v1 part. Searches are fetched from here by your server.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'sky-affiliate-search' ); ?></th>
				<td>
					<button type="button" class="button" id="sky-aff-test">
						<?php esc_html_e( 'Test connection', 'sky-affiliate-search' ); ?>
					</button>
					<span id="sky-aff-test-result" class="sky-aff-test-result" role="status" aria-live="polite"></span>
					<p class="description">
						<?php esc_html_e( 'Asks the platform to identify itself using the values saved above. Save your changes before testing.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Affiliate', 'sky-affiliate-search' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="sky_aff_affiliate_code"><?php esc_html_e( 'Affiliate code', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						class="regular-text code"
						id="sky_aff_affiliate_code"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[affiliate_code]"
						value="<?php echo esc_attr( $s['affiliate_code'] ); ?>"
						placeholder="AB12CD"
						dir="ltr"
					/>
					<p class="description">
						<?php esc_html_e( 'Your referral code from the platform (Profile → Affiliate). Every link this plugin generates carries it, so sign-ups and bookings that start here are credited to you.', 'sky-affiliate-search' ); ?>
					</p>
					<?php if ( '' === $s['affiliate_code'] ) : ?>
						<p class="description sky-aff-warn">
							<?php esc_html_e( 'Without a code the search still works, but you will not be credited for any booking.', 'sky-affiliate-search' ); ?>
						</p>
					<?php endif; ?>
					<?php if ( '' !== $s['affiliate_code'] && '' !== $sky_aff_home ) : ?>
						<p class="description">
							<?php esc_html_e( 'Your referral link:', 'sky-affiliate-search' ); ?>
							<code class="sky-aff-copyable" dir="ltr"><?php echo esc_html( $sky_aff_home ); ?></code>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Search page', 'sky-affiliate-search' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Products', 'sky-affiliate-search' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Products to offer', 'sky-affiliate-search' ); ?></legend>
						<?php
						$sky_aff_products = array(
							'enable_flight'   => __( 'Flights', 'sky-affiliate-search' ),
							'enable_hotel'    => __( 'Hotels', 'sky-affiliate-search' ),
							'enable_activity' => __( 'Activities', 'sky-affiliate-search' ),
							'enable_tour'     => __( 'Tours', 'sky-affiliate-search' ),
						);

						foreach ( $sky_aff_products as $sky_aff_key => $sky_aff_label ) :
							?>
							<label class="sky-aff-check">
								<input
									type="checkbox"
									name="<?php echo esc_attr( SKY_AFF_OPTION . '[' . $sky_aff_key . ']' ); ?>"
									value="1"
									<?php checked( $s[ $sky_aff_key ], 1 ); ?>
								/>
								<?php echo esc_html( $sky_aff_label ); ?>
							</label><br />
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php esc_html_e( 'Only tick what your platform actually sells — a product the platform has no access to returns an empty tab.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sky_aff_language"><?php esc_html_e( 'Language', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						class="small-text code"
						id="sky_aff_language"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[language]"
						value="<?php echo esc_attr( $s['language'] ); ?>"
						placeholder="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>"
						maxlength="2"
						dir="ltr"
					/>
					<p class="description">
						<?php
						printf(
							/* translators: %s: two-letter language code derived from the site locale. */
							esc_html__( 'Two-letter code sent to the platform. Leave blank to follow the site language (%s).', 'sky-affiliate-search' ),
							'<code>' . esc_html( substr( get_locale(), 0, 2 ) ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sky_aff_popular_destinations"><?php esc_html_e( 'Popular destinations', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<textarea
						id="sky_aff_popular_destinations"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[popular_destinations]"
						rows="8"
						class="large-text code"
						dir="ltr"
					><?php echo esc_textarea( $s['popular_destinations'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One city per line, up to 12. These open the moment a visitor clicks the flight or hotel field, before they type — so put the places you most want to sell first.', 'sky-affiliate-search' ); ?>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: 1: example of a plain destination line, 2: example with a pinned airport code. */
							esc_html__( 'Write %1$s on its own, or %2$s to pin an exact city for the flight tab. Pinning matters where a name is ambiguous — Barcelona, Spain and Barcelona, Venezuela look identical to the search.', 'sky-affiliate-search' ),
							'<code dir="ltr">Dubai</code>',
							'<code dir="ltr">Barcelona|BCN</code>'
						);
						?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Each name is looked up against the platform once and remembered for a day, so the list is only as good as what your suppliers actually cover. A city they do not recognise is quietly skipped — check the dropdowns look right on the search page after changing this.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sky_aff_accent_color"><?php esc_html_e( 'Accent colour', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<input
						type="color"
						id="sky_aff_accent_color"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[accent_color]"
						value="<?php echo esc_attr( $s['accent_color'] ); ?>"
					/>
					<p class="description"><?php esc_html_e( 'Used for buttons, the active tab and price highlights.', 'sky-affiliate-search' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Outgoing links', 'sky-affiliate-search' ); ?></th>
				<td>
					<label class="sky-aff-check">
						<input
							type="checkbox"
							name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[link_target_blank]"
							value="1"
							<?php checked( $s['link_target_blank'], 1 ); ?>
						/>
						<?php esc_html_e( 'Open the platform in a new tab', 'sky-affiliate-search' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Recommended: the visitor keeps your page open while they book.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Performance', 'sky-affiliate-search' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="sky_aff_cache_ttl"><?php esc_html_e( 'Cache results for', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="sky_aff_cache_ttl"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[cache_ttl]"
						value="<?php echo esc_attr( $s['cache_ttl'] ); ?>"
						min="0"
						max="3600"
						step="30"
					/>
					<?php esc_html_e( 'seconds', 'sky-affiliate-search' ); ?>
					<p class="description">
						<?php esc_html_e( 'Identical searches are served from cache for this long. Keep it short — these are live prices. 0 disables caching.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sky_aff_request_timeout"><?php esc_html_e( 'Request timeout', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="sky_aff_request_timeout"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[request_timeout]"
						value="<?php echo esc_attr( $s['request_timeout'] ); ?>"
						min="5"
						max="120"
					/>
					<?php esc_html_e( 'seconds', 'sky-affiliate-search' ); ?>
					<p class="description">
						<?php esc_html_e( 'Flight and hotel searches query every supplier the platform sells and can genuinely take 30–60 seconds.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Streaming', 'sky-affiliate-search' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[enable_stream]"
							value="1"
							<?php checked( ! empty( $s['enable_stream'] ) ); ?>
						/>
						<?php esc_html_e( 'Send results to the browser as they arrive', 'sky-affiliate-search' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Shows the search progressing instead of a spinner, and keeps a long search from being cut off by a proxy or CDN timeout. Turn it off only if results never appear on this host — some caching layers hold a response back until it is complete, which makes streaming pointless rather than faster.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="sky_aff_rate_limit"><?php esc_html_e( 'Rate limit', 'sky-affiliate-search' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="sky_aff_rate_limit"
						name="<?php echo esc_attr( SKY_AFF_OPTION ); ?>[rate_limit]"
						value="<?php echo esc_attr( $s['rate_limit'] ); ?>"
						min="1"
						max="240"
					/>
					<?php esc_html_e( 'searches per visitor per minute', 'sky-affiliate-search' ); ?>
					<p class="description">
						<?php esc_html_e( 'Stops your site being used to scrape the platform’s live inventory.', 'sky-affiliate-search' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr />

	<h2 class="title"><?php esc_html_e( 'Adding the search to a page', 'sky-affiliate-search' ); ?></h2>

	<p><?php esc_html_e( 'Paste this shortcode into any page, post or widget:', 'sky-affiliate-search' ); ?></p>

	<p><code class="sky-aff-copyable" dir="ltr">[<?php echo esc_html( Sky_Aff_Shortcode::TAG ); ?>]</code></p>

	<?php if ( $sky_aff_page_url ) : ?>
		<p>
			<?php esc_html_e( 'A ready-made page was created for you:', 'sky-affiliate-search' ); ?>
			<a href="<?php echo esc_url( get_edit_post_link( $sky_aff_page_id ) ); ?>"><?php esc_html_e( 'edit it', 'sky-affiliate-search' ); ?></a>
			<?php if ( 'publish' !== get_post_status( $sky_aff_page_id ) ) : ?>
				— <em><?php esc_html_e( 'still a draft; publish it when you are ready.', 'sky-affiliate-search' ); ?></em>
			<?php else : ?>
				— <a href="<?php echo esc_url( $sky_aff_page_url ); ?>"><?php esc_html_e( 'view it', 'sky-affiliate-search' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Options', 'sky-affiliate-search' ); ?></h3>

	<table class="widefat striped sky-aff-shortcode-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Attribute', 'sky-affiliate-search' ); ?></th>
				<th><?php esc_html_e( 'What it does', 'sky-affiliate-search' ); ?></th>
				<th><?php esc_html_e( 'Example', 'sky-affiliate-search' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>types</code></td>
				<td><?php esc_html_e( 'Limit or reorder the tabs. Cannot enable a product switched off above.', 'sky-affiliate-search' ); ?></td>
				<td><code dir="ltr">[sky_search types="hotel,flight"]</code></td>
			</tr>
			<tr>
				<td><code>open</code></td>
				<td><?php esc_html_e( 'Which tab is selected on load.', 'sky-affiliate-search' ); ?></td>
				<td><code dir="ltr">[sky_search open="hotel"]</code></td>
			</tr>
			<tr>
				<td><code>tabs</code></td>
				<td><?php esc_html_e( 'Set to "no" to hide the tab bar — useful with a single product.', 'sky-affiliate-search' ); ?></td>
				<td><code dir="ltr">[sky_search types="hotel" tabs="no"]</code></td>
			</tr>
			<tr>
				<td><code>title</code></td>
				<td><?php esc_html_e( 'Heading rendered above the widget.', 'sky-affiliate-search' ); ?></td>
				<td><code dir="ltr">[sky_search title="Book your trip"]</code></td>
			</tr>
		</tbody>
	</table>
</div>
