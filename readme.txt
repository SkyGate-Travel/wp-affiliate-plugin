=== Sky Affiliate Search ===
Contributors: nextpay
Tags: travel, affiliate, hotels, flights, search
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Put a live travel search — flights, hotels, activities and tours — on any WordPress site, and earn a commission on what your visitors book.

== Description ==

Sky Affiliate Search turns any WordPress page into a travel search page backed by a Sky travel platform.

Visitors search and filter real inventory without leaving your site. When they pick something, they follow a link to the platform that carries **your affiliate code**, so the sign-up and the booking are credited to you.

You need two things from the platform operator, both entered on the plugin's settings screen:

1. **Platform domain** — the travel site your visitors will book on.
2. **API URL** — where the plugin fetches search results from.

Plus your own **affiliate code**, which you generate in your platform profile.

= What visitors get =

* **Flights** — click the origin or destination field and the airport list opens straight away, the platform's recommended airports first; typing narrows it. One-way or round trip, cabin class, passenger counts.
* **Hotels** — city or property typeahead, dates, multi-room occupancy with child ages, star filtering, paging.
* **Activities** — keyword search with country, city and category filters, and a price range.
* **Tours** — the platform's own curated tour list.

= How attribution works =

Every outgoing link carries `?affiliateCode=…`. The platform stores it in a long-lived cookie the moment the visitor lands, and applies it when they register — the referral is recorded once, on that account, permanently. You are then paid a share of the seller's margin on what that visitor books, at the percentage the platform operator configured for each travel type.

Because attribution happens at sign-up rather than at click time, a visitor who browses today and books next month is still yours.

= Privacy =

The plugin sends no visitor data anywhere. Searches are relayed by *your* server to the platform API; the platform sees your server, not your visitors. Nothing is stored about who searched for what — only anonymous, short-lived response caching keyed on the search parameters.

One exception worth stating plainly: flight results carry the airline's logo, and that image is loaded by the visitor's browser from the platform's own asset host. The platform therefore sees an image request from each visitor who is shown flight results. The request carries no referrer, so it does not say which page they were on.

Following a link to the platform is, of course, a normal outbound visit, and the platform's own privacy policy applies from that point.

== Installation ==

1. Upload the `sky-affiliate-search` folder to `/wp-content/plugins/`, or install the ZIP through **Plugins → Add New → Upload**.
2. Activate it. A draft page called **Travel Search** is created for you.
3. Go to **Sky Search** in the admin menu and fill in the platform domain, API URL and your affiliate code.
4. Press **Test connection**. It should name your platform back to you.
5. Publish the Travel Search page, or paste `[sky_search]` anywhere you like.

== Frequently Asked Questions ==

= Why does the plugin call the API from my server instead of the browser? =

The platform works out which tenant a request belongs to from the request's `Origin` header. A browser sets that to your WordPress domain and forbids scripts from changing it, so a direct call from the page would arrive labelled as the wrong site and be rejected. Your server has no such restriction, so it can present the platform domain you configured. This is also why search results keep working behind a full-page cache.

= Test connection says the domain was not recognised. =

The platform domain must be the one registered with the platform, not your WordPress site's domain. Enter the host only — `travel.example.com`, not a full page URL.

= Searches are slow. =

The platform asks every supplier it sells, and a cold search of thirty seconds is ordinary. What the plugin does about it is stop making visitors wait for the last supplier before seeing anything.

**Flights** arrive supplier by supplier: the first fares are usually on screen within a couple of seconds, and the list refines itself — new fares appear, withdrawn ones go — until the search settles. **Hotels, activities and tours** have no such endpoint on the platform, so they arrive in one piece, but behind a progress bar with a running clock rather than a spinner that says nothing. A repeat of the same search inside **Cache results for** is instant.

If searches are cut short with "temporarily unavailable", raise **Request timeout** — 45 seconds is the default and 60 is not excessive for international routes.

= Results never appear, or they all appear at once at the end. =

Something between WordPress and the visitor is buffering the response — some CDN configurations and a few managed hosts do. The plugin sends the headers that ask them not to (`X-Accel-Buffering: no`, no compression, no caching), but where that is ignored there is nothing to be gained: turn **Streaming** off on the settings screen and the search goes back to a single response.

= Can I show only hotels? =

Yes: `[sky_search types="hotel" tabs="no"]`. You can also switch products off entirely on the settings screen.

= Will I still be credited if the visitor does not book straight away? =

Yes. The code is applied when they create their account on the platform, and the referral stays attached to that account.

== Shortcode ==

`[sky_search]`

* `types` — limit or reorder tabs: `types="hotel,flight"`
* `open` — which tab opens first: `open="hotel"`
* `tabs` — `tabs="no"` hides the tab bar
* `title` — heading above the widget

== Screenshots ==

1. The search widget with the hotel tab open.
2. Settings screen with a successful connection test.

== Changelog ==

= 1.1.0 =
* Flight cards redesigned around what a fare actually is: the airline's logo and name, the journey with its stops and duration on a rail, chips for cabin, aircraft and baggage, and the price set apart — with "N seats left" only when the number is small enough to matter.
* Flight results now arrive supplier by supplier, relayed from the platform's own streamed search: first fares on screen in about two seconds instead of after half a minute. Where the platform is too old to offer it, the search falls back to the ordinary one on its own.
* Every search — flights, hotels, activities, tours — now runs behind a progress bar with a running clock instead of a spinner, and results are written to the browser as they arrive.
* A long search can no longer be cut off by a proxy or CDN idle timeout: the connection keeps sending while the platform is still thinking.
* Starting a new search cancels the one before it, so a slow answer can no longer land on top of a newer one.
* Default request timeout raised from 25 to 45 seconds, which is what real international flight searches need; the ceiling is now 120.
* Streaming can be switched off on the settings screen for hosts that buffer responses regardless.

= 1.0.0 =
* First release: flight, hotel, activity and tour search with affiliate-tagged deep links, a server-side API proxy, response caching and per-visitor rate limiting.
* Airport pickers open a browsable list on click, so a visitor can choose a destination without knowing what to type.
