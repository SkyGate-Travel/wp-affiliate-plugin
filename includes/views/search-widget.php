<?php
/**
 * Front-end search widget shell.
 *
 * Only the chrome is rendered here — tabs, forms and empty result regions.
 * Results arrive from this site's REST proxy and are rendered by
 * assets/js/sky-search.js, so a full-page cache never freezes prices.
 *
 * @package SkyAffiliateSearch
 *
 * @var array    $atts  Shortcode attributes. Provided by the caller.
 * @var string[] $types Enabled product types.
 * @var string   $open  Initially selected tab.
 */

defined( 'ABSPATH' ) || exit;

$sky_aff_id        = wp_unique_id( 'sky-aff-' );
$sky_aff_show_tabs = 'no' !== strtolower( (string) $atts['tabs'] ) && count( $types ) > 1;
$sky_aff_today     = gmdate( 'Y-m-d' );
$sky_aff_tomorrow  = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
$sky_aff_next_week = gmdate( 'Y-m-d', strtotime( '+7 days' ) );

$sky_aff_labels = array(
	'flight'   => __( 'Flights', 'sky-affiliate-search' ),
	'hotel'    => __( 'Hotels', 'sky-affiliate-search' ),
	'activity' => __( 'Activities', 'sky-affiliate-search' ),
	'tour'     => __( 'Tours', 'sky-affiliate-search' ),
);
?>
<div
	class="sky-aff"
	id="<?php echo esc_attr( $sky_aff_id ); ?>"
	data-sky-aff
	data-open="<?php echo esc_attr( $open ); ?>"
	<?php echo is_rtl() ? 'dir="rtl"' : ''; ?>
>
	<?php if ( '' !== trim( (string) $atts['title'] ) ) : ?>
		<h2 class="sky-aff__title"><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( $sky_aff_show_tabs ) : ?>
		<div class="sky-aff__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Product type', 'sky-affiliate-search' ); ?>">
			<?php foreach ( $types as $sky_aff_type ) : ?>
				<button
					type="button"
					class="sky-aff__tab<?php echo $sky_aff_type === $open ? ' is-active' : ''; ?>"
					role="tab"
					id="<?php echo esc_attr( $sky_aff_id . '-tab-' . $sky_aff_type ); ?>"
					aria-controls="<?php echo esc_attr( $sky_aff_id . '-panel-' . $sky_aff_type ); ?>"
					aria-selected="<?php echo $sky_aff_type === $open ? 'true' : 'false'; ?>"
					data-sky-tab="<?php echo esc_attr( $sky_aff_type ); ?>"
				>
					<?php echo esc_html( $sky_aff_labels[ $sky_aff_type ] ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php foreach ( $types as $sky_aff_type ) : ?>
		<section
			class="sky-aff__panel<?php echo $sky_aff_type === $open ? ' is-active' : ''; ?>"
			id="<?php echo esc_attr( $sky_aff_id . '-panel-' . $sky_aff_type ); ?>"
			role="tabpanel"
			aria-labelledby="<?php echo esc_attr( $sky_aff_id . '-tab-' . $sky_aff_type ); ?>"
			data-sky-panel="<?php echo esc_attr( $sky_aff_type ); ?>"
			<?php echo $sky_aff_type === $open ? '' : 'hidden'; ?>
		>
			<?php if ( 'flight' === $sky_aff_type ) : ?>
				<form class="sky-aff__form sky-aff__form--flight" data-sky-form="flight" novalidate>
					<div class="sky-aff__triptype" role="radiogroup" aria-label="<?php esc_attr_e( 'Trip type', 'sky-affiliate-search' ); ?>">
						<label class="sky-aff__pill">
							<input type="radio" name="trip_type" value="one_way" checked />
							<span><?php esc_html_e( 'One way', 'sky-affiliate-search' ); ?></span>
						</label>
						<label class="sky-aff__pill">
							<input type="radio" name="trip_type" value="round_trip" />
							<span><?php esc_html_e( 'Round trip', 'sky-affiliate-search' ); ?></span>
						</label>
					</div>

					<div class="sky-aff__row">
						<div class="sky-aff__field sky-aff__field--grow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-origin' ); ?>"><?php esc_html_e( 'From', 'sky-affiliate-search' ); ?></label>
							<input
								type="text"
								id="<?php echo esc_attr( $sky_aff_id . '-origin' ); ?>"
								name="origin"
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'City or airport', 'sky-affiliate-search' ); ?>"
								data-sky-airport="origin"
								required
							/>
							<input type="hidden" name="origin_code" data-sky-code="origin" />
							<ul class="sky-aff__suggest" data-sky-suggest="origin" role="listbox" hidden></ul>
						</div>

						<button
							type="button"
							class="sky-aff__swap"
							data-sky-swap
							aria-label="<?php esc_attr_e( 'Swap origin and destination', 'sky-affiliate-search' ); ?>"
						>&#8646;</button>

						<div class="sky-aff__field sky-aff__field--grow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-destination' ); ?>"><?php esc_html_e( 'To', 'sky-affiliate-search' ); ?></label>
							<input
								type="text"
								id="<?php echo esc_attr( $sky_aff_id . '-destination' ); ?>"
								name="destination"
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'City or airport', 'sky-affiliate-search' ); ?>"
								data-sky-airport="destination"
								required
							/>
							<input type="hidden" name="destination_code" data-sky-code="destination" />
							<ul class="sky-aff__suggest" data-sky-suggest="destination" role="listbox" hidden></ul>
						</div>
					</div>

					<div class="sky-aff__row">
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-departing' ); ?>"><?php esc_html_e( 'Departure', 'sky-affiliate-search' ); ?></label>
							<input
								type="date"
								id="<?php echo esc_attr( $sky_aff_id . '-departing' ); ?>"
								name="departing"
								min="<?php echo esc_attr( $sky_aff_today ); ?>"
								value="<?php echo esc_attr( $sky_aff_tomorrow ); ?>"
								required
							/>
						</div>
						<div class="sky-aff__field" data-sky-return hidden>
							<label for="<?php echo esc_attr( $sky_aff_id . '-returning' ); ?>"><?php esc_html_e( 'Return', 'sky-affiliate-search' ); ?></label>
							<input
								type="date"
								id="<?php echo esc_attr( $sky_aff_id . '-returning' ); ?>"
								name="returning"
								min="<?php echo esc_attr( $sky_aff_today ); ?>"
							/>
						</div>
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-cabin' ); ?>"><?php esc_html_e( 'Cabin', 'sky-affiliate-search' ); ?></label>
							<select id="<?php echo esc_attr( $sky_aff_id . '-cabin' ); ?>" name="cabin_type">
								<option value="1"><?php esc_html_e( 'Economy', 'sky-affiliate-search' ); ?></option>
								<option value="2"><?php esc_html_e( 'Premium economy', 'sky-affiliate-search' ); ?></option>
								<option value="3"><?php esc_html_e( 'Business', 'sky-affiliate-search' ); ?></option>
								<option value="4"><?php esc_html_e( 'First', 'sky-affiliate-search' ); ?></option>
							</select>
						</div>
					</div>

					<div class="sky-aff__row">
						<div class="sky-aff__field sky-aff__field--narrow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-adult' ); ?>"><?php esc_html_e( 'Adults', 'sky-affiliate-search' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $sky_aff_id . '-adult' ); ?>" name="adult" value="1" min="1" max="9" />
						</div>
						<div class="sky-aff__field sky-aff__field--narrow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-child' ); ?>"><?php esc_html_e( 'Children', 'sky-affiliate-search' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $sky_aff_id . '-child' ); ?>" name="child" value="0" min="0" max="8" />
						</div>
						<div class="sky-aff__field sky-aff__field--narrow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-infant' ); ?>"><?php esc_html_e( 'Infants', 'sky-affiliate-search' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $sky_aff_id . '-infant' ); ?>" name="infant" value="0" min="0" max="4" />
						</div>
						<div class="sky-aff__field sky-aff__field--submit">
							<button type="submit" class="sky-aff__submit"><?php esc_html_e( 'Search', 'sky-affiliate-search' ); ?></button>
						</div>
					</div>
				</form>

			<?php elseif ( 'hotel' === $sky_aff_type ) : ?>
				<form class="sky-aff__form sky-aff__form--hotel" data-sky-form="hotel" novalidate>
					<div class="sky-aff__row">
						<div class="sky-aff__field sky-aff__field--grow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-place' ); ?>"><?php esc_html_e( 'City or hotel', 'sky-affiliate-search' ); ?></label>
							<input
								type="text"
								id="<?php echo esc_attr( $sky_aff_id . '-place' ); ?>"
								name="place"
								autocomplete="off"
								placeholder="<?php esc_attr_e( 'Where are you going?', 'sky-affiliate-search' ); ?>"
								data-sky-place
								required
							/>
							<input type="hidden" name="place_id" data-sky-place-id />
							<input type="hidden" name="place_kind" data-sky-place-kind value="city" />
							<input type="hidden" name="place_name" data-sky-place-name />
							<ul class="sky-aff__suggest" data-sky-suggest="place" role="listbox" hidden></ul>
						</div>
					</div>

					<div class="sky-aff__row">
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-checkin' ); ?>"><?php esc_html_e( 'Check-in', 'sky-affiliate-search' ); ?></label>
							<input
								type="date"
								id="<?php echo esc_attr( $sky_aff_id . '-checkin' ); ?>"
								name="start_date"
								min="<?php echo esc_attr( $sky_aff_today ); ?>"
								value="<?php echo esc_attr( $sky_aff_tomorrow ); ?>"
								required
							/>
						</div>
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-checkout' ); ?>"><?php esc_html_e( 'Check-out', 'sky-affiliate-search' ); ?></label>
							<input
								type="date"
								id="<?php echo esc_attr( $sky_aff_id . '-checkout' ); ?>"
								name="end_date"
								min="<?php echo esc_attr( $sky_aff_today ); ?>"
								value="<?php echo esc_attr( $sky_aff_next_week ); ?>"
								required
							/>
						</div>
						<div class="sky-aff__field sky-aff__field--submit">
							<button type="submit" class="sky-aff__submit"><?php esc_html_e( 'Search', 'sky-affiliate-search' ); ?></button>
						</div>
					</div>

					<fieldset class="sky-aff__rooms" data-sky-rooms>
						<legend><?php esc_html_e( 'Rooms', 'sky-affiliate-search' ); ?></legend>
						<div data-sky-rooms-list></div>
						<button type="button" class="sky-aff__link-btn" data-sky-add-room>
							+ <?php esc_html_e( 'Add room', 'sky-affiliate-search' ); ?>
						</button>
					</fieldset>
				</form>

				<?php
				/*
				 * A <details>, closed by default. The suppliers return around
				 * twenty facet groups and the ten this plugin can forward still
				 * run to dozens of chips — left open they push the results
				 * themselves off the screen, which is the opposite of helpful.
				 * Native <details> also means the toggle works before the
				 * script has run.
				 */
				?>
				<details class="sky-aff__filters" data-sky-filters="hotel" hidden>
					<summary class="sky-aff__filters-head">
						<span><?php esc_html_e( 'Filters', 'sky-affiliate-search' ); ?></span>
						<span class="sky-aff__filters-count" data-sky-filters-count hidden></span>
					</summary>
					<div class="sky-aff__filters-body" data-sky-filters-body></div>
					<div class="sky-aff__filters-foot">
						<button type="button" class="sky-aff__link-btn" data-sky-clear-filters>
							<?php esc_html_e( 'Clear all filters', 'sky-affiliate-search' ); ?>
						</button>
					</div>
				</details>

			<?php elseif ( 'activity' === $sky_aff_type ) : ?>
				<form class="sky-aff__form sky-aff__form--activity" data-sky-form="activity" novalidate>
					<div class="sky-aff__row">
						<div class="sky-aff__field sky-aff__field--grow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-keyword' ); ?>"><?php esc_html_e( 'What are you looking for?', 'sky-affiliate-search' ); ?></label>
							<input
								type="search"
								id="<?php echo esc_attr( $sky_aff_id . '-keyword' ); ?>"
								name="q"
								placeholder="<?php esc_attr_e( 'Museum tickets, day trips, transfers…', 'sky-affiliate-search' ); ?>"
							/>
						</div>
						<div class="sky-aff__field sky-aff__field--submit">
							<button type="submit" class="sky-aff__submit"><?php esc_html_e( 'Search', 'sky-affiliate-search' ); ?></button>
						</div>
					</div>

					<div class="sky-aff__row" data-sky-activity-filters>
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-country' ); ?>"><?php esc_html_e( 'Country', 'sky-affiliate-search' ); ?></label>
							<select id="<?php echo esc_attr( $sky_aff_id . '-country' ); ?>" name="country" data-sky-country>
								<option value=""><?php esc_html_e( 'Any country', 'sky-affiliate-search' ); ?></option>
							</select>
						</div>
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-city' ); ?>"><?php esc_html_e( 'City', 'sky-affiliate-search' ); ?></label>
							<select id="<?php echo esc_attr( $sky_aff_id . '-city' ); ?>" name="city" data-sky-city>
								<option value=""><?php esc_html_e( 'Any city', 'sky-affiliate-search' ); ?></option>
							</select>
						</div>
						<div class="sky-aff__field">
							<label for="<?php echo esc_attr( $sky_aff_id . '-category' ); ?>"><?php esc_html_e( 'Category', 'sky-affiliate-search' ); ?></label>
							<select id="<?php echo esc_attr( $sky_aff_id . '-category' ); ?>" name="category" data-sky-category>
								<option value=""><?php esc_html_e( 'Any category', 'sky-affiliate-search' ); ?></option>
							</select>
						</div>
					</div>

					<div class="sky-aff__row">
						<div class="sky-aff__field sky-aff__field--narrow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-minprice' ); ?>"><?php esc_html_e( 'Min price', 'sky-affiliate-search' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $sky_aff_id . '-minprice' ); ?>" name="min_price" min="0" step="1" />
						</div>
						<div class="sky-aff__field sky-aff__field--narrow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-maxprice' ); ?>"><?php esc_html_e( 'Max price', 'sky-affiliate-search' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $sky_aff_id . '-maxprice' ); ?>" name="max_price" min="0" step="1" />
						</div>
					</div>
				</form>

			<?php elseif ( 'tour' === $sky_aff_type ) : ?>
				<form class="sky-aff__form sky-aff__form--tour" data-sky-form="tour" novalidate>
					<div class="sky-aff__row">
						<div class="sky-aff__field sky-aff__field--grow">
							<label for="<?php echo esc_attr( $sky_aff_id . '-tour-q' ); ?>"><?php esc_html_e( 'Search tours', 'sky-affiliate-search' ); ?></label>
							<input
								type="search"
								id="<?php echo esc_attr( $sky_aff_id . '-tour-q' ); ?>"
								name="q"
								placeholder="<?php esc_attr_e( 'Destination or tour name', 'sky-affiliate-search' ); ?>"
							/>
						</div>
						<div class="sky-aff__field sky-aff__field--submit">
							<button type="submit" class="sky-aff__submit"><?php esc_html_e( 'Search', 'sky-affiliate-search' ); ?></button>
						</div>
					</div>
				</form>
			<?php endif; ?>

			<div class="sky-aff__status" data-sky-status role="status" aria-live="polite"></div>
			<div class="sky-aff__results" data-sky-results></div>
			<div class="sky-aff__more" data-sky-more hidden>
				<button type="button" class="sky-aff__secondary" data-sky-load-more>
					<?php esc_html_e( 'Load more', 'sky-affiliate-search' ); ?>
				</button>
			</div>
			<p class="sky-aff__all" data-sky-all hidden></p>
		</section>
	<?php endforeach; ?>

	<p class="sky-aff__disclosure">
		<?php esc_html_e( 'Booking happens on our travel partner’s site. We may earn a commission.', 'sky-affiliate-search' ); ?>
	</p>
</div>
