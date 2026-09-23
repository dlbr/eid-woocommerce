<?php
/**
 * Plugin Name: dlbr.id Age Verification for WooCommerce
 * Description: Privacy-preserving age verification at WooCommerce checkout using the dlbr.id OID4VP Gateway.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: dlbr.id
 * License: GPL-2.0-or-later
 * Text Domain: dlbr-id-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DLBR_ID_WC_VERSION', '0.1.0');
define('DLBR_ID_WC_FILE', __FILE__);
define('DLBR_ID_WC_DIR', plugin_dir_path(__FILE__));
define('DLBR_ID_WC_URL', plugin_dir_url(__FILE__));

/**
 * WooCommerce age verification integration.
 *
 * The API key and Gateway calls stay on the server. The browser receives only
 * the wallet request URI, which is the public OID4VP request for one session.
 */
final class DLBR_ID_WooCommerce_Age_Verification {
    const OPTION = 'dlbr_id_woocommerce_settings';
    const SESSION_ID = 'dlbr_id_wc_session_id';
    const SESSION_QR = 'dlbr_id_wc_qr_code_url';
    const SESSION_EXPIRY = 'dlbr_id_wc_session_expiry';
    const SESSION_IDEMPOTENCY = 'dlbr_id_wc_idempotency_key';
    const SESSION_VERIFIED_AT = 'dlbr_id_wc_age_verified_at';
    const AGE_PROOF_TTL = 3600;

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_dlbr_id_wc_save_settings', array($this, 'save_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
        add_action('woocommerce_before_checkout_form', array($this, 'render_checkout_widget'), 8);
        add_shortcode('dlbr_id_age_verification', array($this, 'shortcode'));

        add_action('wp_ajax_dlbr_id_wc_start', array($this, 'ajax_start_session'));
        add_action('wp_ajax_nopriv_dlbr_id_wc_start', array($this, 'ajax_start_session'));
        add_action('wp_ajax_dlbr_id_wc_poll', array($this, 'ajax_poll_session'));
        add_action('wp_ajax_nopriv_dlbr_id_wc_poll', array($this, 'ajax_poll_session'));

        add_action('woocommerce_after_checkout_validation', array($this, 'validate_classic_checkout'), 10, 2);
        add_action('woocommerce_store_api_cart_errors', array($this, 'validate_store_api_checkout'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_order_verification_meta'), 10, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'save_store_api_order_verification_meta'), 10, 1);
        add_action('woocommerce_checkout_order_processed', array($this, 'clear_checkout_proof'), 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'clear_checkout_proof'), 20, 1);
    }

    /** @return array<string,mixed> */
    private function settings() {
        $settings = get_option(self::OPTION, array());
        return is_array($settings) ? $settings : array();
    }

    /** @return string */
    private function api_key() {
        if (defined('DLBR_ID_WOOCOMMERCE_API_KEY') && DLBR_ID_WOOCOMMERCE_API_KEY) {
            return trim((string) DLBR_ID_WOOCOMMERCE_API_KEY);
        }
        $settings = $this->settings();
        return isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';
    }

    /** @return string */
    private function gateway_url() {
        $settings = $this->settings();
        return isset($settings['mode']) && 'live' === $settings['mode']
            ? 'https://api.dlbr.app'
            : 'https://api-staging.dlbr.app';
    }

    /** @return bool */
    private function is_configured() {
        $settings = $this->settings();
        $key_prefix = isset($settings['mode']) && 'live' === $settings['mode'] ? 'sk_live_' : 'sk_test_';
        return 0 === strpos($this->api_key(), $key_prefix) && !empty($settings['issuer_id']) &&
            in_array(isset($settings['format']) ? $settings['format'] : '', array('vc+sd-jwt', 'mso_mdoc'), true);
    }

    /** @return bool */
    private function cart_requires_verification() {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return false;
        }

        $settings = $this->settings();
        if (!empty($settings['all_products'])) {
            return true;
        }

        $category_ids = isset($settings['category_ids']) && is_array($settings['category_ids'])
            ? array_map('absint', $settings['category_ids'])
            : array();
        if (!$category_ids) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = isset($item['data']) ? $item['data'] : null;
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }
            $product_ids = array($product->get_id());
            if ($product->get_parent_id()) {
                $product_ids[] = $product->get_parent_id();
            }
            foreach ($product_ids as $product_id) {
                if (has_term($category_ids, 'product_cat', $product_id)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Adds the dlbr.id settings page below WooCommerce. */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('dlbr.id Age Verification', 'dlbr-id-woocommerce'),
            __('dlbr.id Verification', 'dlbr-id-woocommerce'),
            'manage_woocommerce',
            'dlbr-id-woocommerce',
            array($this, 'render_settings_page')
        );
    }

    /** Renders the plugin settings form. */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $settings = $this->settings();
        $mode = isset($settings['mode']) ? $settings['mode'] : 'test';
        $format = isset($settings['format']) ? $settings['format'] : 'vc+sd-jwt';
        $selected = isset($settings['category_ids']) && is_array($settings['category_ids'])
            ? array_map('absint', $settings['category_ids'])
            : array();
        $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('dlbr.id Age Verification', 'dlbr-id-woocommerce'); ?></h1>
            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'dlbr-id-woocommerce'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('The API key prefix must match the selected Gateway mode (sk_test_ for Test or sk_live_ for Live).', 'dlbr-id-woocommerce'); ?></p></div>
            <?php endif; ?>
            <?php if (defined('DLBR_ID_WOOCOMMERCE_API_KEY')) : ?>
                <div class="notice notice-info"><p><?php esc_html_e('The API key is supplied by DLBR_ID_WOOCOMMERCE_API_KEY in wp-config.php.', 'dlbr-id-woocommerce'); ?></p></div>
            <?php endif; ?>
            <?php if (!$this->is_configured()) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Complete the API key, mode, and issuer settings before enabling protected product categories.', 'dlbr-id-woocommerce'); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e('The age check requests only a boolean age claim. The Gateway enforces that the value is true; the plugin does not request a name, birth date, address, or document number.', 'dlbr-id-woocommerce'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dlbr_id_wc_save_settings" />
                <?php wp_nonce_field('dlbr_id_wc_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dlbr-id-mode"><?php esc_html_e('Gateway mode', 'dlbr-id-woocommerce'); ?></label></th>
                        <td><select id="dlbr-id-mode" name="mode">
                            <option value="test" <?php selected($mode, 'test'); ?>><?php esc_html_e('Test / staging', 'dlbr-id-woocommerce'); ?></option>
                            <option value="live" <?php selected($mode, 'live'); ?>><?php esc_html_e('Live / production', 'dlbr-id-woocommerce'); ?></option>
                        </select><p class="description"><?php esc_html_e('Test mode uses api-staging.dlbr.app. Live mode uses api.dlbr.app.', 'dlbr-id-woocommerce'); ?></p></td>
                    </tr>
                    <?php if (!defined('DLBR_ID_WOOCOMMERCE_API_KEY')) : ?>
                    <tr>
                        <th scope="row"><label for="dlbr-id-api-key"><?php esc_html_e('Gateway API key', 'dlbr-id-woocommerce'); ?></label></th>
                        <td><input type="password" autocomplete="new-password" id="dlbr-id-api-key" name="api_key" class="regular-text" value="" />
                            <p class="description"><?php echo esc_html(!empty($settings['api_key']) ? __('A key is saved. Leave blank to keep it, or enter a replacement.', 'dlbr-id-woocommerce') : __('The key is stored on this WordPress server and is never sent to the browser.', 'dlbr-id-woocommerce')); ?></p></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label for="dlbr-id-issuer"><?php esc_html_e('Trusted credential issuer ID', 'dlbr-id-woocommerce'); ?></label></th>
                        <td><input type="text" id="dlbr-id-issuer" name="issuer_id" class="regular-text" value="<?php echo esc_attr(isset($settings['issuer_id']) ? $settings['issuer_id'] : ''); ?>" required />
                            <p class="description"><?php esc_html_e('Use the issuer identifier trusted by your dlbr.id Gateway tenant.', 'dlbr-id-woocommerce'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dlbr-id-format"><?php esc_html_e('Credential format', 'dlbr-id-woocommerce'); ?></label></th>
                        <td><select id="dlbr-id-format" name="format">
                            <option value="vc+sd-jwt" <?php selected($format, 'vc+sd-jwt'); ?>>SD-JWT VC (PID)</option>
                            <option value="mso_mdoc" <?php selected($format, 'mso_mdoc'); ?>>ISO mdoc (mDL)</option>
                        </select><p class="description"><?php esc_html_e('Choose the format supported by the issuer and wallets you accept.', 'dlbr-id-woocommerce'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Protected products', 'dlbr-id-woocommerce'); ?></th>
                        <td>
                            <label><input type="checkbox" name="all_products" value="1" <?php checked(!empty($settings['all_products'])); ?> /> <?php esc_html_e('Require age verification for every product in the cart', 'dlbr-id-woocommerce'); ?></label>
                            <p><strong><?php esc_html_e('Or select product categories:', 'dlbr-id-woocommerce'); ?></strong></p>
                            <?php if (!is_wp_error($categories)) : foreach ($categories as $category) : ?>
                                <label style="display:block;margin:4px 0"><input type="checkbox" name="category_ids[]" value="<?php echo esc_attr($category->term_id); ?>" <?php checked(in_array((int) $category->term_id, $selected, true)); ?> /> <?php echo esc_html($category->name); ?></label>
                            <?php endforeach; endif; ?>
                            <p class="description"><?php esc_html_e('The check runs when a cart contains at least one selected category. With no categories selected, only the all-products option activates verification.', 'dlbr-id-woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'dlbr-id-woocommerce')); ?>
            </form>
            <hr />
            <h2><?php esc_html_e('Checkout Blocks', 'dlbr-id-woocommerce'); ?></h2>
            <p><?php esc_html_e('Classic checkout inserts the verification panel automatically. For the Checkout Block, place a Shortcode block containing [dlbr_id_age_verification] above the Checkout block on the checkout page.', 'dlbr-id-woocommerce'); ?></p>
        </div>
        <?php
    }

    /** Saves and validates the settings form. */
    public function save_settings() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'dlbr-id-woocommerce'));
        }
        check_admin_referer('dlbr_id_wc_save_settings');

        $previous = $this->settings();
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'test';
        $mode = in_array($mode, array('test', 'live'), true) ? $mode : 'test';
        $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
        if ('' === $api_key) {
            $api_key = isset($previous['api_key']) ? (string) $previous['api_key'] : '';
        }
        if (!defined('DLBR_ID_WOOCOMMERCE_API_KEY') && $api_key) {
            $prefix = 'live' === $mode ? 'sk_live_' : 'sk_test_';
            if (0 !== strpos($api_key, $prefix)) {
                wp_safe_redirect(add_query_arg(array('page' => 'dlbr-id-woocommerce', 'error' => 'key-mode'), admin_url('admin.php')));
                exit;
            }
        }

        $format = isset($_POST['format']) ? sanitize_text_field(wp_unslash($_POST['format'])) : 'vc+sd-jwt';
        if (!in_array($format, array('vc+sd-jwt', 'mso_mdoc'), true)) {
            $format = 'vc+sd-jwt';
        }
        $categories = isset($_POST['category_ids']) && is_array($_POST['category_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', wp_unslash($_POST['category_ids'])))))
            : array();
        $settings = array(
            'mode' => $mode,
            'issuer_id' => isset($_POST['issuer_id']) ? sanitize_text_field(wp_unslash($_POST['issuer_id'])) : '',
            'format' => $format,
            'category_ids' => $categories,
            'all_products' => isset($_POST['all_products']) ? 1 : 0,
        );
        if (!defined('DLBR_ID_WOOCOMMERCE_API_KEY')) {
            $settings['api_key'] = $api_key;
        }
        update_option(self::OPTION, $settings, false);

        wp_safe_redirect(add_query_arg(array('page' => 'dlbr-id-woocommerce', 'saved' => '1'), admin_url('admin.php')));
        exit;
    }

    /** Enqueues the local checkout client and QR renderer on checkout pages. */
    public function enqueue_checkout_assets() {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page() || !$this->cart_requires_verification()) {
            return;
        }
        wp_enqueue_script('dlbr-id-wc-qrcode', DLBR_ID_WC_URL . 'assets/qrcode.min.js', array(), '1.5.4', true);
        wp_enqueue_script('dlbr-id-wc-checkout', DLBR_ID_WC_URL . 'assets/checkout.js', array('dlbr-id-wc-qrcode'), DLBR_ID_WC_VERSION, true);
        wp_enqueue_style('dlbr-id-wc-checkout', DLBR_ID_WC_URL . 'assets/checkout.css', array(), DLBR_ID_WC_VERSION);
        wp_localize_script('dlbr-id-wc-checkout', 'dlbrIdWooCommerce', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dlbr_id_wc_checkout'),
            'strings' => array(
                'start' => __('Verify age with your digital wallet', 'dlbr-id-woocommerce'),
                'starting' => __('Preparing a secure request…', 'dlbr-id-woocommerce'),
                'waiting' => __('Scan the QR code with your digital identity wallet.', 'dlbr-id-woocommerce'),
                'pending' => __('Waiting for your wallet…', 'dlbr-id-woocommerce'),
                'verified' => __('Age verified. You can continue checkout.', 'dlbr-id-woocommerce'),
                'rejected' => __('The wallet did not confirm that you are over 18. Checkout cannot continue.', 'dlbr-id-woocommerce'),
                'expired' => __('This verification request expired. Please start again.', 'dlbr-id-woocommerce'),
                'error' => __('Age verification is temporarily unavailable. Please try again.', 'dlbr-id-woocommerce'),
                'openWallet' => __('Open in wallet', 'dlbr-id-woocommerce'),
                'qrAlt' => __('Scan this QR code with your digital identity wallet', 'dlbr-id-woocommerce'),
            ),
        ));
    }

    /** Outputs the age-verification panel on classic checkout. */
    public function render_checkout_widget() {
        echo $this->shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode returns escaped markup.
    }

    /** Returns the checkout panel markup when the cart needs verification. */
    public function shortcode() {
        if (!$this->cart_requires_verification()) {
            return '';
        }
        $is_verified = $this->age_verified();
        if ($is_verified) {
            $message = __('Age verified. You can continue checkout.', 'dlbr-id-woocommerce');
        } else {
            $message = $this->is_configured()
                ? __('This cart contains age-restricted products. Verify your age to continue.', 'dlbr-id-woocommerce')
                : __('Age verification is not available right now. Please contact the store.', 'dlbr-id-woocommerce');
        }
        ob_start();
        ?>
        <section class="dlbr-id-wc-verification" aria-labelledby="dlbr-id-wc-title">
            <h3 id="dlbr-id-wc-title"><?php esc_html_e('Age verification', 'dlbr-id-woocommerce'); ?></h3>
            <p><?php echo esc_html($message); ?></p>
            <?php if ($this->is_configured() && !$is_verified) : ?>
                <button type="button" class="button alt dlbr-id-wc-start"><?php esc_html_e('Verify age with your digital wallet', 'dlbr-id-woocommerce'); ?></button>
            <?php endif; ?>
            <div class="dlbr-id-wc-status" role="status" aria-live="polite"></div>
            <div class="dlbr-id-wc-request" hidden>
                <img class="dlbr-id-wc-qr" alt="" width="240" height="240" />
                <p class="dlbr-id-wc-wallet-link"></p>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** Creates a Gateway verification session for the current WooCommerce session. */
    public function ajax_start_session() {
        $this->verify_ajax_request();
        if (!$this->cart_requires_verification() || !$this->is_configured() || !function_exists('WC') || !WC()->session) {
            wp_send_json_error(array('message' => __('Age verification is not configured for this cart.', 'dlbr-id-woocommerce')), 400);
        }

        $session = WC()->session;
        $verified_at = (int) $session->get(self::SESSION_VERIFIED_AT, 0);
        if ($verified_at && time() - $verified_at < self::AGE_PROOF_TTL) {
            wp_send_json_success(array('status' => 'VERIFIED'));
        }

        $session_id = (string) $session->get(self::SESSION_ID, '');
        $qr_url = (string) $session->get(self::SESSION_QR, '');
        $expiry = (int) $session->get(self::SESSION_EXPIRY, 0);
        if ($session_id && $qr_url && $expiry > time()) {
            wp_send_json_success(array('status' => 'PENDING', 'qr_code_url' => $qr_url));
        }

        $settings = $this->settings();
        $credential = array(
            'id' => 'age-over-18',
            'format' => $settings['format'],
            'issuer_id' => $settings['issuer_id'],
            'trust_domain' => 'mso_mdoc' === $settings['format'] ? 'mdoc' : 'pid',
            'claims' => array('mso_mdoc' === $settings['format'] ? 'age_over_18' : 'is_over_18'),
            'claim_filters' => array(
                ('mso_mdoc' === $settings['format'] ? 'age_over_18' : 'is_over_18') => array('const' => true),
            ),
        );
        if ('mso_mdoc' === $settings['format']) {
            $credential['namespace'] = 'org.iso.18013.5.1';
            $credential['doc_type'] = 'org.iso.18013.5.1.mDL';
        }

        $idempotency_key = (string) $session->get(self::SESSION_IDEMPOTENCY, '');
        if ('' === $idempotency_key) {
            $idempotency_key = wp_generate_uuid4();
            $session->set(self::SESSION_IDEMPOTENCY, $idempotency_key);
        }
        $response = wp_remote_post($this->gateway_url() . '/v1/sessions', array(
            'timeout' => 12,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key(),
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotency_key,
            ),
            'body' => wp_json_encode(array('credentials' => array($credential))),
        ));
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Could not start an age verification request.', 'dlbr-id-woocommerce')), 502);
        }
        if (201 !== (int) wp_remote_retrieve_response_code($response)) {
            if ((int) wp_remote_retrieve_response_code($response) >= 400 && (int) wp_remote_retrieve_response_code($response) < 500) {
                $session->set(self::SESSION_IDEMPOTENCY, null);
            }
            wp_send_json_error(array('message' => __('Could not start an age verification request.', 'dlbr-id-woocommerce')), 502);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['session_id']) || !is_string($body['session_id']) || '' === $body['session_id'] || !isset($body['qr_code_url']) || !is_string($body['qr_code_url']) || 0 !== strpos($body['qr_code_url'], 'openid4vp://')) {
            $session->set(self::SESSION_IDEMPOTENCY, null);
            wp_send_json_error(array('message' => __('The Gateway returned an invalid verification request.', 'dlbr-id-woocommerce')), 502);
        }

        $wallet_uri = $this->sanitize_wallet_request_uri($body['qr_code_url']);
        if ('' === $wallet_uri) {
            $session->set(self::SESSION_IDEMPOTENCY, null);
            wp_send_json_error(array('message' => __('The Gateway returned an invalid wallet request URI.', 'dlbr-id-woocommerce')), 502);
        }
        $session->set(self::SESSION_ID, sanitize_text_field($body['session_id']));
        $session->set(self::SESSION_QR, $wallet_uri);
        $expires_at = isset($body['expires_at']) && is_string($body['expires_at']) ? strtotime($body['expires_at']) : false;
        $session->set(self::SESSION_EXPIRY, $expires_at ? $expires_at : time() + 600);
        $session->set(self::SESSION_IDEMPOTENCY, null);
        wp_send_json_success(array('status' => 'PENDING', 'qr_code_url' => $wallet_uri));
    }

    /** Polls the Gateway and stores only a short-lived boolean age result in the WooCommerce session. */
    public function ajax_poll_session() {
        $this->verify_ajax_request();
        if (!function_exists('WC') || !WC()->session || !$this->cart_requires_verification()) {
            wp_send_json_error(array('message' => __('Age verification session is unavailable.', 'dlbr-id-woocommerce')), 400);
        }
        $session = WC()->session;
        $verified_at = (int) $session->get(self::SESSION_VERIFIED_AT, 0);
        if ($verified_at && time() - $verified_at < self::AGE_PROOF_TTL) {
            wp_send_json_success(array('status' => 'VERIFIED'));
        }

        $session_id = (string) $session->get(self::SESSION_ID, '');
        if (!$session_id) {
            wp_send_json_success(array('status' => 'NONE'));
        }
        $url = $this->gateway_url() . '/v1/sessions/' . rawurlencode($session_id);
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array('Authorization' => 'Bearer ' . $this->api_key()),
        ));
        if (is_wp_error($response)) {
            wp_send_json_success(array('status' => 'PENDING'));
        }
        $http_status = (int) wp_remote_retrieve_response_code($response);
        if (404 === $http_status) {
            $this->clear_session_request($session);
            wp_send_json_success(array('status' => 'EXPIRED'));
        }
        if ($http_status >= 400 && $http_status < 500 && 429 !== $http_status) {
            $this->clear_session_request($session);
            wp_send_json_success(array('status' => 'FAILED'));
        }
        if (200 !== $http_status) {
            wp_send_json_success(array('status' => 'PENDING'));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = is_array($body) && isset($body['status']) ? sanitize_key(strtolower($body['status'])) : '';
        if ('verified' === $status) {
            if ($this->has_true_age_claim(isset($body['claims']) ? $body['claims'] : array())) {
                $session->set(self::SESSION_VERIFIED_AT, time());
                $this->delete_gateway_session($session_id);
                $this->clear_session_request($session);
                wp_send_json_success(array('status' => 'VERIFIED'));
            }
            $this->delete_gateway_session($session_id);
            $this->clear_session_request($session);
            wp_send_json_success(array('status' => 'REJECTED'));
        }
        if (in_array($status, array('failed', 'expired'), true)) {
            $this->delete_gateway_session($session_id);
            $this->clear_session_request($session);
            wp_send_json_success(array('status' => strtoupper($status)));
        }
        wp_send_json_success(array('status' => 'PENDING'));
    }

    /** Verifies the public AJAX nonce before reading or creating any session. */
    private function verify_ajax_request() {
        check_ajax_referer('dlbr_id_wc_checkout', 'nonce');
    }

    /** Allows only the Gateway's OID4VP custom scheme through WordPress URL sanitization. */
    private function sanitize_wallet_request_uri($value) {
        $uri = trim(sanitize_text_field((string) $value));
        if (0 !== strpos($uri, 'openid4vp://') || preg_match('/[\x00-\x20<>"\']/', $uri)) {
            return '';
        }
        return $uri;
    }

    /** Accepts only the exact boolean claim requested from the selected credential. */
    private function has_true_age_claim($claims) {
        if (!is_array($claims) || !isset($claims['age-over-18']) || !is_array($claims['age-over-18'])) {
            return false;
        }
        $claim_set = $claims['age-over-18'];
        if (isset($claim_set['is_over_18']) && true === $claim_set['is_over_18']) {
            return true;
        }
        if (isset($claim_set['age_over_18']) && true === $claim_set['age_over_18']) {
            return true;
        }
        foreach ($claim_set as $namespace_claims) {
            if (is_array($namespace_claims) && isset($namespace_claims['age_over_18']) && true === $namespace_claims['age_over_18']) {
                return true;
            }
        }
        return false;
    }

    /** Deletes a completed or abandoned Gateway session after its result is consumed. */
    private function delete_gateway_session($session_id) {
        wp_remote_request($this->gateway_url() . '/v1/sessions/' . rawurlencode($session_id), array(
            'method' => 'DELETE',
            'timeout' => 5,
            'headers' => array('Authorization' => 'Bearer ' . $this->api_key()),
        ));
    }

    /** Removes transient request identifiers and the QR payload from WooCommerce session storage. */
    private function clear_session_request($session) {
        $session->set(self::SESSION_ID, null);
        $session->set(self::SESSION_QR, null);
        $session->set(self::SESSION_EXPIRY, null);
        $session->set(self::SESSION_IDEMPOTENCY, null);
    }

    /** Adds a checkout error when an age-restricted cart has not been verified. */
    public function validate_classic_checkout($data, $errors) {
        if ($this->cart_requires_verification() && !$this->age_verified()) {
            $errors->add('dlbr_id_age_verification_required', __('Verify that you are over 18 before placing this order.', 'dlbr-id-woocommerce'));
        }
    }

    /** Adds a Store API checkout error for Checkout Block requests. */
    public function validate_store_api_checkout($errors, $cart) {
        if ($this->cart_requires_verification() && !$this->age_verified()) {
            $errors->add('dlbr_id_age_verification_required', __('Verify that you are over 18 before placing this order.', 'dlbr-id-woocommerce'));
        }
    }

    /** @return bool */
    private function age_verified() {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }
        $verified_at = (int) WC()->session->get(self::SESSION_VERIFIED_AT, 0);
        return $verified_at > 0 && time() - $verified_at < self::AGE_PROOF_TTL;
    }

    /** Adds non-identifying verification metadata to an order being created from classic checkout. */
    public function save_order_verification_meta($order, $data) {
        if ($this->cart_requires_verification() && $this->age_verified()) {
            $order->update_meta_data('_dlbr_id_age_verified', 'yes');
            $order->update_meta_data('_dlbr_id_age_verified_at', gmdate('c', (int) WC()->session->get(self::SESSION_VERIFIED_AT, 0)));
        }
    }

    /** Adds non-identifying verification metadata to an order created through the Store API. */
    public function save_store_api_order_verification_meta($order) {
        if (is_a($order, 'WC_Order') && $this->cart_requires_verification() && $this->age_verified()) {
            $order->update_meta_data('_dlbr_id_age_verified', 'yes');
            $order->update_meta_data('_dlbr_id_age_verified_at', gmdate('c', (int) WC()->session->get(self::SESSION_VERIFIED_AT, 0)));
            $order->save();
        }
    }

    /** Clears the one-hour proof after an order is created, so it cannot authorize another order. */
    public function clear_checkout_proof($order_id) {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_VERIFIED_AT, null);
        }
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        DLBR_ID_WooCommerce_Age_Verification::instance();
    }
}, 20);
