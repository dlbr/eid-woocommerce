# dlbr.id Age Verification for WooCommerce

WooCommerce integration for age checks at checkout. It creates an
OID4VP session on the dlbr.id Gateway, displays a wallet request QR code, and
blocks checkout until the Gateway verifies an EUDI Proof of Age attestation.

## Current scope

- Age checks for selected WooCommerce product categories, or every cart.
- The EUDI Proof of Age mDOC profile (`eu.europa.ec.av.1`) and its
  `age_over_18` boolean claim.
- Server-side API calls; the Gateway API key is never sent to the browser.
- The age request asks only for `age_over_18`, with a Gateway-side
  `const: true` predicate. The plugin checks for the exact boolean `true`.
  Proof of Age is a separate attestation from the EUDI PID profile.
- Guest customers are supported through the WooCommerce session.
- Classic checkout displays the panel automatically. The Checkout Block gets a
  locked verification inner block automatically, shown only for protected carts.
- By default, no name, birth date, address, or document number is requested.
- Merchants can configure a separate EUDI PID issuer and enable optional
  detail prefill. With customer consent, the wallet receives a second
  descriptor requesting only `given_name`, `family_name`, `resident_street`,
  `resident_city`, `resident_postal_code`, and `resident_country` from the
  `eu.europa.ec.eudi.pid.1` profile. Returned fields remain editable.
- The plugin does not retain the Gateway claim payload or credential. With
  prefill enabled, only allowlisted name and delivery fields are applied to
  WooCommerce customer data. For signed-in shoppers this may update saved
  account details before an order is placed; checkout also stores submitted
  values with the order.
- The order stores an age-verified flag and timestamp. It does not store a
  credential, claim payload, birth date, or document number.

This version does not calculate B2B VAT exemptions. That flow needs additional
organization credential schemas and merchant-side configuration. The
`[dlbr_id_age_verification]` shortcode remains available for custom
classic-checkout layouts.

## Install and configure

1. Copy this `woocommerce` directory into `wp-content/plugins/dlbr-id-woocommerce`
   or package it as a ZIP preserving the folder structure.
2. Activate **dlbr.id Age Verification for WooCommerce**.
3. Open **WooCommerce → dlbr.id Verification**.
4. Select Test mode and configure an `sk_test_` key, trusted Proof of Age
   issuer, and protected product categories. Optional checkout prefill is off
   by default. To offer it, also configure a trusted EUDI PID issuer and enable
   the prefill option. A restricted API key needs both `session:create` and
   `session:read` scopes.
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

Run the dependency-free checks for age-claim and PID-result parsing from this
directory with `php tests/smoke.php`. The plugin does not yet include a full
WordPress/WooCommerce integration-test environment.

The plugin targets the current Gateway session API:

- `POST /v1/sessions` with an age-proof `credentials[]` entry and an optional
  second EUDI PID entry when the customer asks to share checkout details.
- The age-proof descriptor requests `age_over_18` from the dedicated
  `eu.europa.ec.av.1` document type and is marked `required: true`. The
  optional PID descriptor is marked `required: false` and requests only the
  allowlisted checkout fields from `eu.europa.ec.eudi.pid.1`. The Gateway
  advertises this choice through Presentation Exchange submission requirements
  and DCQL credential sets, then enforces that the age descriptor was returned.
- `GET /v1/sessions/{id}` to poll for completion.
- `DELETE /v1/sessions/{id}` immediately after consuming the result.

The Gateway removes verified claims from the session after a short retention
window. The plugin polls while checkout is open and fails closed if it cannot
read the claim before expiry. The Proof of Age profile is defined in the
[EUDI Age Verification technical specification](https://github.com/eu-digital-identity-wallet/av-doc-technical-specification/blob/main/docs/annexes/annex-A/annex-A-av-profile.md);
PID attributes and their mDOC names are defined in the
[EUDI PID Rulebook](https://github.com/eu-digital-identity-wallet/eudi-doc-attestation-rulebooks-catalog/blob/main/rulebooks/pid/pid-rulebook.md).

The QR asset is bundled from the MIT-licensed `qrcode` npm package. Its license
is included in `assets/qrcode-LICENSE.txt`.
