# DLBR EID Age Verification for WooCommerce

Privacy-minded, wallet-based 18+ checks for WooCommerce checkout through the DLBR EID Gateway.

- Age verification is configurable per product, by product category, or for every product in the cart.
- Requests the EUDI Proof of Age `age_over_18` predicate and accepts only the exact boolean `true`.
- The Checkout Block and classic checkout are supported. Restricted carts fail closed until verified.
- Optional EUDI PID prefill is separate and off by default. With consent, it requests allowlisted name and delivery fields; shoppers can edit the fields afterward.
- The order stores the age verification result and timestamp, not the credential, claim payload, birth date, or document number.
- The API key stays on the server. No installation analytics or telemetry is sent.

## Configure

1. Install and activate WooCommerce and this plugin.
2. Open **WooCommerce → DLBR EID Verification**.
3. Choose Test or Live mode and enter the corresponding Gateway API key: `sk_test_` for Test, `sk_live_` for Live. Both modes call `https://api.dlbr.app`; the selected mode validates the key prefix.
4. Set a trusted Proof of Age issuer and choose whether age checks apply to selected products, categories, or all products.
5. For individual products, enable **Requires age verification (18+)** under **Product data → General**. A variable product's parent setting applies to all variations.
6. Optionally configure a trusted EUDI PID issuer and enable checkout prefill. A restricted API key needs `session:create` and `session:read` scopes.

The key may also be defined as `DLBR_EID_WOOCOMMERCE_API_KEY` in `wp-config.php`; when set, the settings field is hidden and the stored key is ignored.

## Development

Run the dependency-free claim checks with `php tests/smoke.php`. A local synthetic WooCommerce checkout hook validation was also run for unrestricted products, restricted products, mixed carts, valid proof, and expired proof. These checks do not establish production API or real-wallet interoperability.

The integration uses `POST /v1/sessions`, `GET /v1/sessions/{id}` and `DELETE /v1/sessions/{id}`. The age descriptor requests `age_over_18` from the Proof of Age profile. Optional PID prefill requests only the configured allowlisted checkout fields.

The QR asset is bundled from the MIT-licensed `qrcode` npm package; its license is in `assets/qrcode-LICENSE.txt`.
