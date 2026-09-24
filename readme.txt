=== dlbr.id Age Verification for WooCommerce ===
Contributors: dlbr
Tags: age-verification, eudi-wallet, identity-verification, oid4vp, woocommerce
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Age checks, wallet checkout prefill, EWC company proof, and VAT validation for WooCommerce.

== Description ==

Add consent-based digital-wallet verification to WooCommerce checkout through the dlbr.id Gateway.

= Features =

* Verify an age threshold with an EUDI Proof of Age credential.
* Optionally request a minimal set of EUDI PID claims to prefill editable checkout fields.
* Optionally verify an EWC EU Company Certificate and Signatory Rights credential for the same company.
* Optionally check a customer-entered VAT number using the European Commission VIES service.
* Support classic checkout and the WooCommerce Checkout Block.
* Keep Gateway API keys and verification requests on the WordPress server.

The plugin records the age or company verification result and time with the order. It does not save the age credential, date of birth, or EWC company identifier. If VIES validation is enabled, the VAT number entered by the customer and its check result are saved with the order. Wallet-based checkout prefill is optional; the customer approves the requested claims in their wallet and can edit the checkout fields afterward.

The plugin is free. Live and sandbox wallet verification are provided by the dlbr.id Gateway, which has its own account, usage, and plan terms. Create or manage an account at https://console.dlbr.app/ and review plans at https://dlbr.app/#pricing.

Age verification rules, accepted credentials, issuer trust, and relying-party setup depend on the merchant's jurisdiction and Gateway configuration. This plugin does not provide legal advice or guarantee regulatory compliance.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin ZIP through **Plugins > Add New Plugin > Upload Plugin**, or copy the plugin folder to `/wp-content/plugins/`.
3. Activate **dlbr.id Age Verification for WooCommerce**.
4. Open **WooCommerce > dlbr.id Verification**.
5. Choose Test mode, enter an `sk_test_` Gateway API key, and configure a trusted Proof of Age issuer.
6. Select protected product categories, or require age verification for every product in the cart.
7. Configure optional PID prefill, VIES validation, or EWC company credential verification as needed. Issuers and EWC VCT values must match the Gateway tenant configuration.
8. Test the checkout flow with a wallet and credentials supported by the selected Gateway tenant before switching to Live mode.

The Checkout Block age-verification panel appears for protected carts. Classic checkout displays the panel automatically. The optional VIES field in Checkout Blocks requires WooCommerce 8.9 or newer; classic checkout can use the VIES field on supported WooCommerce versions.

== Screenshots ==

1. Blank white placeholder for Gateway and checkout settings. Replace with a capture from a running WordPress site before submission.
2. Blank white placeholder for the age-verification checkout panel. Replace with a capture from a running WooCommerce checkout before submission.
3. Blank white placeholder for optional company-credential and VAT checks. Replace with a capture from a running checkout before submission.

== Frequently Asked Questions ==

= Does age verification ask for a date of birth? =

No. The age flow requests the `age_over_18` boolean claim from a Proof of Age credential. The plugin does not save the credential or date of birth.

= Does the plugin automatically change VAT or tax rates? =

No. VIES validation reports whether a customer-entered VAT number is valid at the time of the check. EWC credentials can confirm that two credentials identify the same company. Neither result changes WooCommerce tax rates or determines tax eligibility.

= Does Signatory Rights verification prove that the person using the wallet is the named signatory? =

No. The business flow checks the Gateway's issuer verification and that the EU Company Certificate and Signatory Rights credential disclose the same company identifier. It does not match the wallet operator to the person named in the credential.

= What information is sent to external services? =

See **External Services** below for details about the dlbr.id Gateway and the European Commission VIES service. The plugin does not send installation analytics or marketing data.

= Where can I get help? =

For plugin support, use the WordPress.org support forum for this plugin. For Gateway account and integration questions, visit https://console.dlbr.app/ or email hello@dlbr.app.

== External Services ==

= dlbr.id Gateway =

The plugin connects to the dlbr.id Gateway when a shopper starts an age, PID-prefill, or EWC business-credential request and while it checks the result. Test mode uses `https://api-staging.dlbr.app`; Live mode uses `https://api.dlbr.app`.

The request includes the merchant's configured issuer IDs, credential formats and types, requested claim names, and a short-lived verification session. The shopper's wallet sends the selected credential presentation to the Gateway as part of the verification flow. The plugin sends its server-side API key and session ID when it polls the Gateway. The Gateway verifies the presentation and returns a result. The plugin stores only the verification outcome and time for age and EWC checks; optional PID claims are used to prefill the checkout fields the shopper approved and are not retained as a credential payload.

The service is provided by dlbr.id. See the [Terms](https://dlbr.app/terms) and [Privacy Policy](https://dlbr.app/privacy).

= European Commission VIES =

When the merchant enables VIES validation and a customer enters a VAT number, the plugin sends that VAT number, and optionally the merchant's requester VAT number, to the European Commission VIES REST API at `https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number`. The response is used to report the result of the check and is stored with the order. The plugin does not store a company name or address returned by VIES. See the [VIES service](https://ec.europa.eu/taxation_customs/vies/).

== Privacy ==

The plugin sends no installation or usage telemetry. The merchant's WordPress server contacts the configured Gateway during shopper-initiated verification and contacts VIES only when the merchant enables that feature and a VAT number is submitted. The merchant should disclose these flows in their own privacy information. Order metadata may contain age or EWC verification status and time; VIES-enabled orders may also contain the submitted VAT number and VIES result.

== Development ==

Source code is maintained at https://github.com/dlbr/eid/tree/main/integrations/woocommerce.

The QR-code renderer is the precompiled `qrcode` 1.5.4 browser bundle by soldair. Its MIT license is included in `assets/qrcode-LICENSE.txt`; the readable upstream source is available at https://github.com/soldair/node-qrcode/tree/v1.5.4.

Run the dependency-free checks from the plugin directory with `php tests/smoke.php` and `php tests/vies-smoke.php`.

WordPress.org listing graphics are kept separately in `wordpress-org-assets/`. Copy the PNG files there to the root `/assets/` directory of the WordPress.org SVN repository. The icon, banner, and screenshot files are blank white placeholders and must be replaced with finished artwork and captures before submission.

== Changelog ==

= 0.5.0 =
* Add optional EWC EU Company Certificate and Signatory Rights verification.
* Add optional VIES VAT-number validation and EUDI PID checkout prefill.
* Support classic checkout and WooCommerce Checkout Blocks.
