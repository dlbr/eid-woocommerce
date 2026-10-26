=== DLBR EID Age Verification for WooCommerce ===
Contributors: dlbr
Tags: age verification, eudi wallet, checkout, woocommerce
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.5.0
License: MIT
License URI: https://opensource.org/license/mit/

Add privacy-minded, wallet-based 18+ checks to WooCommerce checkout through the DLBR EID Gateway.

== Description ==

* Require age verification for individual products, selected categories, or all products in the cart.
* Supports classic checkout and the WooCommerce Checkout Block.
* Requests only the Proof of Age `age_over_18` predicate and accepts only the exact boolean `true`.
* Optional EUDI PID checkout prefill is separate and off by default. The wallet asks before sharing; returned fields remain editable.
* Stores the age verification result and timestamp with the order. Does not store credentials, claim payloads, birth dates, or document numbers.
* API keys stay server-side. No installation analytics or telemetry.

The merchant remains responsible for checkout decisions, trusted issuer configuration, and applicable age-assurance requirements.

== Installation ==

1. Install and activate WooCommerce.
2. Upload this plugin directory to `/wp-content/plugins/` and activate **DLBR EID Age Verification for WooCommerce**.
3. Open **WooCommerce → DLBR EID Verification**.
4. Select Test or Live mode and enter the matching API key prefix: `sk_test_` for Test or `sk_live_` for Live. Both modes use `https://api.dlbr.app`.
5. Configure a trusted Proof of Age issuer and choose products, categories, or all products that require verification.
6. For per-product checks, enable **Requires age verification (18+)** under **Product data → General**. A variable product's parent setting applies to all variations.
7. Optionally configure a trusted PID issuer and enable checkout prefill. Restricted API keys need `session:create` and `session:read` scopes.

The API key may instead be defined in `wp-config.php` as `DLBR_EID_WOOCOMMERCE_API_KEY`. When set, the settings field is hidden and the saved key is ignored.

== Privacy ==

The merchant's WordPress server contacts `https://api.dlbr.app` when a shopper starts verification and while checking its result. The server sends the configured issuer ID, requested credential claims, a short-lived session ID, and the API key. The wallet submits the presentation to the Gateway. The plugin stores only the age verification outcome and time. If optional PID prefill is enabled, the shopper-approved allowlisted claims are applied to editable WooCommerce checkout fields; the credential payload is not retained. Merchants should describe these flows in their own privacy information.

== Frequently Asked Questions ==

= Do Test and Live keys use different API hosts? =

No. Both use `https://api.dlbr.app`. The selected mode must match the key prefix: `sk_test_` for Test and `sk_live_` for Live.

= Can I require age checks only for certain products? =

Yes. Mark products individually, select protected categories, or enable checks for all products. Checkout is blocked when a restricted product is in the cart and no valid age proof is present.

= Does the plugin collect a date of birth? =

No. The age request asks the wallet for an over-18 predicate, not a birth date.

= Does PID prefill happen automatically? =

No. It is optional and disabled by default. The shopper must choose to request it and approve the data sharing in their wallet.

== Development ==

Run the dependency-free claim smoke checks with `php tests/smoke.php`.

== Changelog ==

= 0.5.0 =
* Add per-product 18+ checkout validation.
* Use `api.dlbr.app` for Test and Live keys, validating the key prefix against the selected mode.
* Focus the plugin on age verification and optional PID checkout prefill.
