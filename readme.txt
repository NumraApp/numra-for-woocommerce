=== Numra for WooCommerce ===
Tags: woocommerce, fraud prevention, cash on delivery, cod, phone verification
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.18.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect your WooCommerce store from cash-on-delivery fraud. Connect to your Numra account to score orders and reduce failed deliveries.

== Description ==

Numra for WooCommerce connects your store to the Numra platform, a fraud prevention and phone intelligence service built for cash-on-delivery e-commerce.

Cash on delivery means the risk sits with the merchant: a fake or careless order costs shipping, handling, and a return. Numra scores the phone numbers behind your orders so you can act before you ship.

= Features =

* Connect your store to your Numra account
* Connection status and diagnostics from the WordPress admin
* Growth Center notices — announcements and recommendations for your store
* Secure by design: your API key is stored server-side and never exposed to the browser

= Requirements =

* WooCommerce 5.0 or later
* PHP 7.4 or later
* A Numra account with an active license (https://app.numra.ma)

= Data and Privacy =

This plugin communicates with the Numra API at https://api.numra.ma using an API key issued to your account. No customer data is transmitted except what is required to perform the requested check. See https://numra.ma/privacy for the full privacy policy.

== Installation ==

1. Upload the `numra-for-woocommerce` folder to `/wp-content/plugins/`, or install the ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to **Numra → API Key** and enter the API key from your Numra dashboard.
4. Click **Test Connection** to confirm the connection.

== Frequently Asked Questions ==

= Do I need a Numra account? =

Yes. The plugin is a client for the Numra platform. Create an account at https://app.numra.ma.

= Where is my API key stored? =

In your WordPress options table. It is sent to the Numra API as a Bearer token from your server and is never printed into a page or exposed to JavaScript.

= Is the plugin translation ready? =

Yes. All merchant-facing strings use the `numra-for-woocommerce` text domain and the plugin loads translations from its `languages/` directory. It works with WPML, Polylang, TranslatePress, and Loco Translate.

= Which languages are supported? =

The plugin ships in English. WordPress automatically uses your site language when a translation is available.

== Changelog ==

= 1.17.0 =
* Fixed: the store's status check never reached Numra. It was sent to the wrong address, so every check failed silently — stores kept whatever status they last had and never received their customer-type list. This is the fix; connection status, plan and quota now update every 15 minutes as intended.
* Fixed: holding orders by customer type never worked. The setting saved, and did nothing. It now does what it says.
* New: protection settings can be managed from your Numra account and apply to every store you sell on, instead of being configured separately on each one.
* New: "Any rated risk" added as a risk level, for stores where holding an order costs far less than losing one.
* New: you are told inside WordPress when a new version is available, and what changed.

= 1.0.0 =
* Initial release: connection management, connection testing, and Growth Center notices.
