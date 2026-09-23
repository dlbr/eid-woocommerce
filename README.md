# dlbr.id Age Verification for WooCommerce

Initial WooCommerce integration for age checks at checkout. It creates an
OID4VP session on the dlbr.id Gateway, displays a wallet request QR code, and
blocks checkout until the Gateway verifies the requested over-18 claim.

## Current scope

- Age checks for selected WooCommerce product categories, or every cart.
- SD-JWT VC PID and ISO mdoc credential formats.
- Server-side API calls; the Gateway API key is never sent to the browser.
- The request asks for only `is_over_18` or `age_over_18`, with a Gateway-side
  `const: true` predicate. The plugin then checks for the exact boolean `true`.
- Guest customers are supported through the WooCommerce session.
- Classic checkout displays the panel automatically. For the Checkout Block,
  place a Shortcode block containing `[dlbr_id_age_verification]` above the
  Checkout block on the checkout page.
- The order stores only an age-verified flag and timestamp. No credential,
  claim payload, name, date of birth, address, or document number is stored.

This first version does not prefill customer details or calculate B2B VAT
exemptions. Those flows need additional credential schemas and merchant-side
configuration. Checkout Block usage relies on the merchant placing the
shortcode above the block; the plugin does not yet provide a native Checkout
Block extension.

## Install and configure

1. Copy this `woocommerce` directory into `wp-content/plugins/dlbr-id-woocommerce`
   or package it as a ZIP preserving the folder structure.
2. Activate **dlbr.id Age Verification for WooCommerce**.
3. Open **WooCommerce → dlbr.id Verification**.
4. Select Test mode and configure an `sk_test_` key, trusted credential issuer,
   supported credential format, and protected product categories. A restricted
   API key needs both `session:create` and `session:read` scopes.
5. Run checkout using a wallet and credentials accepted by your Gateway tenant.
6. Switch to Live mode with an `sk_live_` key after the merchant's production
   relying-party and issuer configuration is ready.

The API key may instead be defined in `wp-config.php` as
`DLBR_ID_WOOCOMMERCE_API_KEY`. When that constant is set, the key field is
hidden and the stored option is ignored.

Test keys call `https://api-staging.dlbr.app`; live keys call
`https://api.dlbr.app`. The plugin refuses to save a key whose prefix does not
match the selected mode.

## Development notes

The plugin targets the current Gateway session API:

- `POST /v1/sessions` with one `credentials[]` entry and a boolean `const: true`
  claim filter.
- `GET /v1/sessions/{id}` to poll for completion.
- `DELETE /v1/sessions/{id}` immediately after consuming the result.

The Gateway removes verified claims from the session after a short retention
window. The plugin polls while checkout is open and fails closed if it cannot
read the claim before expiry.

The QR asset is bundled from the MIT-licensed `qrcode` npm package. Its license
is included in `assets/qrcode-LICENSE.txt`.
