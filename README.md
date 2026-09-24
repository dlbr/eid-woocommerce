# DLBR EID Age Verification for WooCommerce

Add privacy-minded, wallet-based age assurance to WooCommerce checkout through
the DLBR EID Gateway. The Gateway is DLBR's developer-first OID4VP service for
EUDI Wallet verification. This integration requests an age predicate from a
supported wallet and uses the verified result in the merchant's checkout flow.

- [Age verification use case](https://dlbr.app/use-cases/age-verification)
- [Free sandbox console](https://console.dlbr.app/)
- [SDK quickstarts](https://docs.dlbr.app/sdk/quickstarts) · [API reference](https://docs.dlbr.app/api/)
- [Plans and pricing](https://dlbr.app/#pricing)

DLBR EID verifies supported wallet presentations and returns a result. The
merchant remains responsible for the checkout decision, issuer configuration,
and applicable age-assurance requirements.

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
- Merchants can offer optional EWC company-credential verification. The wallet
  presents an EU Company Certificate and Signatory Rights credential; the
  Gateway verifies their configured issuers and checks that both identify the
  same company. The order stores only a verification flag and time, never the
  company ID or credential payload.
- Merchants can separately enable optional VAT-number validation through the
  European Commission's VIES service. The checkout stores the VAT number,
  validation status, check time, and a consultation reference when VIES returns
  one. It does not store the VIES company name or address, and it does not
  change WooCommerce tax rates.

### B2B and VAT

EWC company-credential verification is optional and separate from tax
calculation. It verifies an EU Company Certificate and Signatory Rights
credential from merchant-configured trusted issuers, then checks the company
identifier across both credentials. It does not establish that the person
currently operating the wallet is the named signatory. EWC Large Scale Pilot
profiles are not credentials available from every EUDI wallet. Their
approval applies to LSP phase 02 and issuer trust is tenant-specific. See the
[EWC rulebooks and schemas](https://github.com/EWC-consortium/eudi-wallet-rulebooks-and-schemas).

The EU Company Certificate schema identifies a company but does not contain a
VAT number or VAT-registration status. A verified company credential alone
therefore cannot establish eligibility for a zero-rated transaction. VIES is
a separate Commission service that checks whether a VAT number is registered
for cross-border EU trade; its result is not a complete tax decision, and
merchants should retain evidence of checks. See the [Your Europe VIES
guidance](https://europa.eu/youreurope/business/finance-and-tax/vat/check-vat-number-vies/index_en.htm).

The plugin will not infer a 0% rate from an organization credential or a VIES
response alone. Configure **WooCommerce → DLBR EID Verification → EWC business
credentials** with the exact EU Company Certificate and Signatory Rights
issuer IDs trusted by the Gateway tenant. The
`[dlbr_eid_age_verification]` shortcode also displays the optional company
verification panel on classic checkout.

## Install and configure

1. Copy this directory into `wp-content/plugins/dlbr-eid-age-verification-for-woocommerce`
   or package it as a ZIP preserving the folder structure.
2. Activate **DLBR EID Age Verification for WooCommerce**.
3. Open **WooCommerce → DLBR EID Verification**.
4. Select Test mode and configure an `sk_test_` key, trusted Proof of Age
   issuer, and protected product categories. Optional checkout prefill is off
   by default. To offer it, also configure a trusted EUDI PID issuer and enable
   the prefill option. Optional VIES validation is independently controlled by
   the Business VAT number setting. Optional EWC company proof requires both
   credential issuer IDs to be trusted in the Gateway tenant. A restricted API key needs both
   `session:create` and `session:read` scopes.
5. Run checkout using a wallet and credentials accepted by your Gateway tenant.
6. Switch to Live mode with an `sk_live_` key after the merchant's production
   relying-party and issuer configuration is ready.

The API key may instead be defined in `wp-config.php` as
`DLBR_EID_WOOCOMMERCE_API_KEY`. When that constant is set, the key field is
hidden and the stored option is ignored.

Test keys call `https://api-staging.dlbr.app`; live keys call
`https://api.dlbr.app`. The plugin refuses to save a key whose prefix does not
match the selected mode.

## Development notes

Run the dependency-free checks from this directory with `php tests/smoke.php`
and `php tests/vies-smoke.php`. The plugin does not yet include a full
WordPress/WooCommerce integration-test environment. The Checkout Block VAT
field uses WooCommerce's Additional Checkout Fields API, available in
WooCommerce 8.9 and newer.

`readme.txt` is the WordPress.org listing draft. The listing artwork is kept
separately in `wordpress-org-assets/`; copy its PNG files to the root `/assets/`
directory of the WordPress.org SVN repository, outside `/trunk/`. The current
icon, banner, and screenshots are blank white placeholders. Replace them with
finished artwork and captures from a running WordPress/WooCommerce site before
submission.

The plugin targets the current DLBR EID Gateway session API:

- `POST /v1/sessions` with an age-proof `credentials[]` entry and an optional
  second EUDI PID entry when the customer asks to share checkout details.
- The age-proof descriptor requests `age_over_18` from the dedicated
  `eu.europa.ec.av.1` document type and is marked `required: true`. The
  optional PID descriptor is marked `required: false` and requests only the
  allowlisted checkout fields from `eu.europa.ec.eudi.pid.1`. The Gateway
  advertises this choice through Presentation Exchange submission requirements
  and DCQL credential sets, then enforces that the age descriptor was returned.
- When EWC company proof is enabled, a separate session requests only
  `legal_person.legal_person_id` from EU Company Certificate and
  `legal_person_id` from Signatory Rights. `same_subject_groups` binds these
  differently nested paths to the same disclosed company identifier.
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
