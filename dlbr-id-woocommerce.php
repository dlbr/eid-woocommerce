<?php
/**
 * Plugin Name: dlbr.id Age Verification for WooCommerce
 * Description: Privacy-preserving age verification and optional VAT number validation at WooCommerce checkout.
 * Version: 0.4.0
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

define('DLBR_ID_WC_VERSION', '0.4.0');
define('DLBR_ID_WC_FILE', __FILE__);
define('DLBR_ID_WC_DIR', plugin_dir_path(__FILE__));
define('DLBR_ID_WC_URL', plugin_dir_url(__FILE__));

require_once DLBR_ID_WC_DIR . 'includes/class-dlbr-id-wc-vies-client.php';

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
    const SESSION_PROFILE_REQUESTED = 'dlbr_id_wc_profile_requested';
    const SESSION_PROFILE_PREFILLED = 'dlbr_id_wc_profile_prefilled';
    const SESSION_VIES_RESULT = 'dlbr_id_wc_vies_result';
    const VIES_FIELD_ID = 'dlbr-id-woocommerce/vat-number';
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
        add_action('woocommerce_init', array($this, 'register_vies_checkout_field'), 20);
        add_filter('woocommerce_checkout_fields', array($this, 'add_classic_vies_checkout_field'));
        add_action('woocommerce_before_checkout_form', array($this, 'render_checkout_widget'), 8);
        add_shortcode('dlbr_id_age_verification', array($this, 'shortcode'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_checkout_block_integration'));

        add_action('wp_ajax_dlbr_id_wc_start', array($this, 'ajax_start_session'));
        add_action('wp_ajax_nopriv_dlbr_id_wc_start', array($this, 'ajax_start_session'));
        add_action('wp_ajax_dlbr_id_wc_poll', array($this, 'ajax_poll_session'));
        add_action('wp_ajax_nopriv_dlbr_id_wc_poll', array($this, 'ajax_poll_session'));

        add_action('woocommerce_after_checkout_validation', array($this, 'validate_classic_checkout'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'capture_classic_vies_result'), 20, 2);
        add_action('woocommerce_store_api_cart_errors', array($this, 'validate_store_api_checkout'), 10, 2);
        add_action('woocommerce_validate_additional_field', array($this, 'capture_blocks_vies_result'), 10, 3);
        add_action('woocommerce_set_additional_field_value', array($this, 'save_blocks_vies_result'), 10, 4);
        add_action('woocommerce_checkout_create_order', array($this, 'save_order_verification_meta'), 10, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'save_store_api_order_verification_meta'), 10, 1);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'render_vies_order_meta'));
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

    /** @return string */
    private function age_issuer_id() {
        $settings = $this->settings();
        if (!empty($settings['age_issuer_id'])) {
            return trim((string) $settings['age_issuer_id']);
        }
        // Continue to honor settings saved before the separate issuer fields.
        return isset($settings['issuer_id']) ? trim((string) $settings['issuer_id']) : '';
    }

    /** @return string */
    private function pid_issuer_id() {
        $settings = $this->settings();
        return isset($settings['pid_issuer_id']) ? trim((string) $settings['pid_issuer_id']) : '';
    }

    /** @return bool */
    private function is_configured() {
        $settings = $this->settings();
        $key_prefix = isset($settings['mode']) && 'live' === $settings['mode'] ? 'sk_live_' : 'sk_test_';
        return 0 === strpos($this->api_key(), $key_prefix) && '' !== $this->age_issuer_id();
    }

    /** @return bool */
    private function profile_prefill_enabled() {
        $settings = $this->settings();
        return !empty($settings['prefill_profile']) && '' !== $this->pid_issuer_id();
    }

    /** @return bool */
    private function vies_enabled() {
        $settings = $this->settings();
        return !empty($settings['vies_enabled']);
    }

    /** @return string */
    private function vies_requester_vat_number() {
        $settings = $this->settings();
        return isset($settings['vies_requester_vat_number'])
            ? trim((string) $settings['vies_requester_vat_number'])
            : '';
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

    /** Registers the optional order-only VAT field for Checkout Blocks (WooCommerce 8.9+). */
    public function register_vies_checkout_field() {
        if (!$this->vies_enabled() || !function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        try {
            woocommerce_register_additional_checkout_field(array(
                'id' => self::VIES_FIELD_ID,
                'label' => __('Business VAT number (include country prefix)', 'dlbr-id-woocommerce'),
                'location' => 'order',
                'type' => 'text',
                'required' => false,
                'attributes' => array(
                    'autocomplete' => 'off',
                    'maxlength' => 32,
                ),
            ));
        } catch (Throwable $error) {
            // A field registration conflict must not prevent checkout from loading.
        }
    }

    /** Adds the same optional VAT field to classic shortcode checkout. */
    public function add_classic_vies_checkout_field($fields) {
        if (!$this->vies_enabled() || !is_array($fields)) {
            return $fields;
        }
        if (!isset($fields['billing']) || !is_array($fields['billing'])) {
            $fields['billing'] = array();
        }

        $fields['billing']['billing_dlbr_id_vat_number'] = array(
            'type' => 'text',
            'label' => __('Business VAT number (include country prefix)', 'dlbr-id-woocommerce'),
            'placeholder' => 'DE123456789',
            'required' => false,
            'class' => array('form-row-wide'),
            'priority' => 115,
            'autocomplete' => 'off',
            'custom_attributes' => array('maxlength' => 32),
            'description' => __('If entered, the number is checked with VIES and the result is saved with this order. A valid result does not change tax rates.', 'dlbr-id-woocommerce'),
        );
        return $fields;
    }

    /** Captures a VIES result during classic checkout validation without blocking a regular purchase. */
    public function capture_classic_vies_result($data, $errors) {
        if (!$this->vies_enabled() || !is_array($data)) {
            return;
        }

        $vat_number = isset($data['billing_dlbr_id_vat_number'])
            ? (string) $data['billing_dlbr_id_vat_number']
            : '';
        $this->get_vies_result($vat_number);
    }

    /** Captures the optional field result for Checkout Blocks before order creation. */
    public function capture_blocks_vies_result($errors, $field_key, $value) {
        if ($this->vies_enabled() && self::VIES_FIELD_ID === $field_key && is_string($value)) {
            $this->get_vies_result($value);
        }
    }

    /** Saves VIES evidence with a Checkout Block order as its additional field is persisted. */
    public function save_blocks_vies_result($field_key, $value, $group, $wc_object) {
        if (
            !$this->vies_enabled() || self::VIES_FIELD_ID !== $field_key || 'other' !== $group ||
            !is_a($wc_object, 'WC_Order') || !is_string($value)
        ) {
            return;
        }

        $result = $this->get_vies_result($value);
        if ($result) {
            $this->save_vies_order_meta($wc_object, $value, $result);
        }
    }

    /** @param string $value */
    private function get_vies_result($value) {
        static $request_cache = array();

        $value = sanitize_text_field(trim((string) $value));
        if ('' === $value) {
            if (function_exists('WC') && WC()->session) {
                WC()->session->set(self::SESSION_VIES_RESULT, null);
            }
            return null;
        }

        $requester = strtoupper(preg_replace('/\s+/', '', $this->vies_requester_vat_number()));
        $input_hash = hash('sha256', strtoupper(preg_replace('/\s+/', '', $value)) . "\0" . $requester);
        if (isset($request_cache[$input_hash])) {
            return $request_cache[$input_hash];
        }
        if (function_exists('WC') && WC()->session) {
            $previous = WC()->session->get(self::SESSION_VIES_RESULT);
            $previous_at = is_array($previous) && isset($previous['attempted_at']) ? strtotime($previous['attempted_at']) : false;
            if (
                is_array($previous) && isset($previous['input_hash']) && hash_equals($previous['input_hash'], $input_hash) &&
                false !== $previous_at && $previous_at <= time() + 5 && time() - $previous_at <= 60
            ) {
                $request_cache[$input_hash] = $previous;
                return $previous;
            }
        }

        $client = new DLBR_ID_WC_VIES_Client();
        $result = $client->check($value, $this->vies_requester_vat_number());
        $result['input_hash'] = $input_hash;
        $result['attempted_at'] = gmdate('c');
        $request_cache[$input_hash] = $result;
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_VIES_RESULT, $result);
        }
        return $result;
    }

    /** Stores only the supplied VAT ID and the minimum VIES result needed for merchant evidence. */
    private function save_vies_order_meta($order, $vat_number, $result = null) {
        if (!is_a($order, 'WC_Order') || !is_string($vat_number) || '' === trim($vat_number)) {
            return;
        }
        if (!is_array($result)) {
            $result = $this->get_vies_result($vat_number);
        }
        if (!is_array($result) || empty($result['status'])) {
            return;
        }

        $parsed_vat = DLBR_ID_WC_VIES_Client::parse_vat_number($vat_number);
        $normalized_vat = $parsed_vat
            ? $parsed_vat['formatted']
            : substr(strtoupper(preg_replace('/\s+/', '', sanitize_text_field($vat_number))), 0, 32);

        $order->update_meta_data('_dlbr_id_wc_vat_number', $normalized_vat);
        $order->update_meta_data('_dlbr_id_wc_vies_status', sanitize_key($result['status']));
        $order->update_meta_data('_dlbr_id_wc_vies_attempted_at', isset($result['attempted_at']) ? sanitize_text_field($result['attempted_at']) : gmdate('c'));
        if (!empty($result['checked_at'])) {
            $order->update_meta_data('_dlbr_id_wc_vies_checked_at', sanitize_text_field($result['checked_at']));
        }
        if (!empty($result['request_identifier'])) {
            $order->update_meta_data('_dlbr_id_wc_vies_request_identifier', sanitize_text_field($result['request_identifier']));
        }
        if (!empty($parsed_vat['country_code'])) {
            $order->update_meta_data('_dlbr_id_wc_vies_country_code', sanitize_key($parsed_vat['country_code']));
        }
        if (!empty($result['code'])) {
            $order->update_meta_data('_dlbr_id_wc_vies_code', sanitize_key($result['code']));
        }
    }

    /** Displays the recorded status and consultation reference in the merchant's order screen. */
    public function render_vies_order_meta($order) {
        if (!is_a($order, 'WC_Order')) {
            return;
        }
        $status = (string) $order->get_meta('_dlbr_id_wc_vies_status');
        if ('' === $status) {
            return;
        }

        $labels = array(
            'valid' => __('Valid', 'dlbr-id-woocommerce'),
            'invalid' => __('Invalid', 'dlbr-id-woocommerce'),
            'invalid_input' => __('Could not be checked: invalid format or unsupported country', 'dlbr-id-woocommerce'),
            'unavailable' => __('Could not be checked: VIES unavailable', 'dlbr-id-woocommerce'),
        );
        $label = isset($labels[$status]) ? $labels[$status] : __('Unknown', 'dlbr-id-woocommerce');
        echo '<p><strong>' . esc_html__('VIES VAT check', 'dlbr-id-woocommerce') . ':</strong> ' . esc_html($label) . '</p>';

        $vat_number = (string) $order->get_meta('_dlbr_id_wc_vat_number');
        if ('' !== $vat_number) {
            echo '<p><strong>' . esc_html__('VAT number', 'dlbr-id-woocommerce') . ':</strong> ' . esc_html($vat_number) . '</p>';
        }
        $checked_at = (string) $order->get_meta('_dlbr_id_wc_vies_checked_at');
        if ('' !== $checked_at) {
            echo '<p><strong>' . esc_html__('VIES response time', 'dlbr-id-woocommerce') . ':</strong> ' . esc_html($checked_at) . '</p>';
        }
        $attempted_at = (string) $order->get_meta('_dlbr_id_wc_vies_attempted_at');
        if ('' !== $attempted_at) {
            echo '<p><strong>' . esc_html__('VIES check attempted at', 'dlbr-id-woocommerce') . ':</strong> ' . esc_html($attempted_at) . '</p>';
        }
        $reference = (string) $order->get_meta('_dlbr_id_wc_vies_request_identifier');
        if ('' !== $reference) {
            echo '<p><strong>' . esc_html__('VIES consultation reference', 'dlbr-id-woocommerce') . ':</strong> ' . esc_html($reference) . '</p>';
        }
        $code = (string) $order->get_meta('_dlbr_id_wc_vies_code');
        if ('' !== $code && 'invalid' !== $code) {
            echo '<p><strong>' . esc_html__('VIES response code', 'dlbr-id-woocommerce') . ':</strong> ' . esc_html($code) . '</p>';
        }
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
        $age_issuer_id = $this->age_issuer_id();
        $pid_issuer_id = $this->pid_issuer_id();
        $prefill_profile = !empty($settings['prefill_profile']);
        $vies_enabled = !empty($settings['vies_enabled']);
        $vies_requester_vat_number = $this->vies_requester_vat_number();
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
            <p><?php esc_html_e('The age check requests only the standard EUDI Proof of Age boolean. Optional checkout prefill makes a separate, minimal EUDI PID request for delivery details; it never requests a birth date or document number.', 'dlbr-id-woocommerce'); ?></p>
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
                        <th scope="row"><label for="dlbr-id-age-issuer"><?php esc_html_e('Proof of Age issuer ID', 'dlbr-id-woocommerce'); ?></label></th>
                        <td><input type="text" id="dlbr-id-age-issuer" name="age_issuer_id" class="regular-text" value="<?php echo esc_attr($age_issuer_id); ?>" required />
                            <p class="description"><?php esc_html_e('Use the trusted issuer for the EUDI Proof of Age attestation (eu.europa.ec.av.1).', 'dlbr-id-woocommerce'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dlbr-id-pid-issuer"><?php esc_html_e('EUDI PID issuer ID for checkout prefill', 'dlbr-id-woocommerce'); ?></label></th>
                        <td><input type="text" id="dlbr-id-pid-issuer" name="pid_issuer_id" class="regular-text" value="<?php echo esc_attr($pid_issuer_id); ?>" />
                            <p class="description"><?php esc_html_e('Optional. Use the trusted issuer for the separate EUDI PID mDOC (eu.europa.ec.eudi.pid.1). Required only when checkout detail prefill is enabled.', 'dlbr-id-woocommerce'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Checkout details', 'dlbr-id-woocommerce'); ?></th>
                        <td>
                            <label><input type="checkbox" name="prefill_profile" value="1" <?php checked($prefill_profile); ?> /> <?php esc_html_e('Offer wallet-based name and delivery-detail prefill at checkout', 'dlbr-id-woocommerce'); ?></label>
                            <p class="description"><?php esc_html_e('Customers choose whether to share these details. The wallet receives separate requests: an age-only Proof of Age attestation and a minimal EUDI PID request for name and delivery address. The fields remain editable in checkout.', 'dlbr-id-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Business VAT number', 'dlbr-id-woocommerce'); ?></th>
                        <td>
                            <label><input type="checkbox" name="vies_enabled" value="1" <?php checked($vies_enabled); ?> /> <?php esc_html_e('Offer optional VIES validation at checkout', 'dlbr-id-woocommerce'); ?></label>
                            <p class="description"><?php esc_html_e('When enabled, checkout asks for the customer VAT number including its country prefix and checks it with the European Commission VIES service. The result is recorded on the order. It does not change WooCommerce tax rates. Checkout Blocks requires WooCommerce 8.9 or newer for this field.', 'dlbr-id-woocommerce'); ?></p>
                            <label for="dlbr-id-vies-requester"><?php esc_html_e('Store VAT number for VIES request evidence (optional)', 'dlbr-id-woocommerce'); ?></label><br />
                            <input type="text" id="dlbr-id-vies-requester" name="vies_requester_vat_number" class="regular-text" value="<?php echo esc_attr($vies_requester_vat_number); ?>" placeholder="DE123456789" />
                            <p class="description"><?php esc_html_e('Enter the store VAT ID with country prefix. VIES may return a consultation reference when a requester VAT ID is supplied.', 'dlbr-id-woocommerce'); ?></p>
                        </td>
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
            <p><?php esc_html_e('The Checkout Block receives a locked dlbr.id age-verification block automatically. It appears only when the cart requires verification. Classic checkout inserts the panel automatically as well.', 'dlbr-id-woocommerce'); ?></p>
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

        $categories = isset($_POST['category_ids']) && is_array($_POST['category_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', wp_unslash($_POST['category_ids'])))))
            : array();
        $settings = array(
            'mode' => $mode,
            'age_issuer_id' => isset($_POST['age_issuer_id']) ? sanitize_text_field(wp_unslash($_POST['age_issuer_id'])) : $this->age_issuer_id(),
            'pid_issuer_id' => isset($_POST['pid_issuer_id']) ? sanitize_text_field(wp_unslash($_POST['pid_issuer_id'])) : '',
            'prefill_profile' => isset($_POST['prefill_profile']) ? 1 : 0,
            'vies_enabled' => isset($_POST['vies_enabled']) ? 1 : 0,
            'vies_requester_vat_number' => isset($_POST['vies_requester_vat_number']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['vies_requester_vat_number']))) : '',
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
        wp_localize_script('dlbr-id-wc-checkout', 'dlbrIdWooCommerce', $this->checkout_client_config());
    }

    /** Returns public checkout settings shared by shortcode and block clients. */
    public function checkout_client_config() {
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dlbr_id_wc_checkout'),
            'active' => $this->cart_requires_verification() && $this->is_configured(),
            'verified' => $this->age_verified(),
            'canPrefillProfile' => $this->profile_prefill_enabled() && $this->is_configured(),
            'profilePrefilled' => function_exists('WC') && WC()->session && (bool) WC()->session->get(self::SESSION_PROFILE_PREFILLED, false),
            'strings' => array(
                'start' => __('Verify age with your digital wallet', 'dlbr-id-woocommerce'),
                'starting' => __('Preparing a secure request…', 'dlbr-id-woocommerce'),
                'waiting' => __('Scan the QR code with your digital identity wallet.', 'dlbr-id-woocommerce'),
                'pending' => __('Waiting for your wallet…', 'dlbr-id-woocommerce'),
                'verified' => __('Age verified. You can continue checkout.', 'dlbr-id-woocommerce'),
                'prefill' => __('Fill checkout details with your wallet', 'dlbr-id-woocommerce'),
                'prefillLabel' => __('Also share my name and delivery details to fill this checkout.', 'dlbr-id-woocommerce'),
                'prefillNotice' => __('Your wallet will ask before sharing. You can edit these fields after they fill checkout. For signed-in customers, WooCommerce may also update saved account details.', 'dlbr-id-woocommerce'),
                'profilePrefilled' => __('Wallet details were added to the editable checkout fields.', 'dlbr-id-woocommerce'),
                'rejected' => __('The wallet did not confirm that you are over 18. Checkout cannot continue.', 'dlbr-id-woocommerce'),
                'expired' => __('This verification request expired. Please start again.', 'dlbr-id-woocommerce'),
                'error' => __('Age verification is temporarily unavailable. Please try again.', 'dlbr-id-woocommerce'),
                'openWallet' => __('Open in wallet', 'dlbr-id-woocommerce'),
                'qrAlt' => __('Scan this QR code with your digital identity wallet', 'dlbr-id-woocommerce'),
            ),
        );
    }

    /** Registers the native Checkout Block inner block integration when WooCommerce Blocks is available. */
    public function register_checkout_block_integration() {
        $integration_file = DLBR_ID_WC_DIR . 'includes/class-dlbr-id-wc-blocks-integration.php';
        if (!interface_exists('Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface') || !file_exists($integration_file)) {
            return;
        }
        require_once $integration_file;
        add_action('woocommerce_blocks_checkout_block_registration', function ($registry) {
            $registry->register(new DLBR_ID_WC_Blocks_Integration());
        });
    }

    /** Outputs the age-verification panel on classic checkout. */
    public function render_checkout_widget() {
        if (function_exists('has_block') && has_block('woocommerce/checkout')) {
            return;
        }
        echo $this->shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode returns escaped markup.
    }

    /** Returns the checkout panel markup when the cart needs verification. */
    public function shortcode() {
        if (!$this->cart_requires_verification()) {
            return '';
        }
        $is_verified = $this->age_verified();
        $client_config = $this->checkout_client_config();
        $profile_prefilled = !empty($client_config['profilePrefilled']);
        $can_prefill = !empty($client_config['canPrefillProfile']);
        if ($is_verified && $can_prefill && !$profile_prefilled) {
            $message = __('Age verified. You can continue checkout or ask your wallet to fill the delivery details.', 'dlbr-id-woocommerce');
        } elseif ($is_verified) {
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
            <?php if ($this->is_configured() && (!$is_verified || ($can_prefill && !$profile_prefilled))) : ?>
                <?php if ($can_prefill && !$is_verified) : ?>
                    <label class="dlbr-id-wc-prefill-option"><input type="checkbox" class="dlbr-id-wc-prefill-profile" /> <?php esc_html_e('Also share my name and delivery details to fill this checkout.', 'dlbr-id-woocommerce'); ?></label>
                    <p class="dlbr-id-wc-prefill-notice"><?php esc_html_e('Your wallet will ask before sharing. You can edit these fields after they fill checkout. For signed-in customers, WooCommerce may also update saved account details.', 'dlbr-id-woocommerce'); ?></p>
                <?php endif; ?>
                <button type="button" class="button alt dlbr-id-wc-start"<?php echo $is_verified ? ' data-include-profile="1"' : ''; ?>><?php echo $is_verified ? esc_html__('Fill checkout details with your wallet', 'dlbr-id-woocommerce') : esc_html__('Verify age with your digital wallet', 'dlbr-id-woocommerce'); ?></button>
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
        $include_profile = $this->profile_prefill_enabled() && isset($_POST['include_profile']) && '1' === sanitize_text_field(wp_unslash($_POST['include_profile']));
        $profile_prefilled = (bool) $session->get(self::SESSION_PROFILE_PREFILLED, false);
        $verified_at = (int) $session->get(self::SESSION_VERIFIED_AT, 0);
        if ($verified_at && time() - $verified_at < self::AGE_PROOF_TTL && (!$include_profile || $profile_prefilled)) {
            wp_send_json_success(array('status' => 'VERIFIED'));
        }

        $session_id = (string) $session->get(self::SESSION_ID, '');
        $qr_url = (string) $session->get(self::SESSION_QR, '');
        $expiry = (int) $session->get(self::SESSION_EXPIRY, 0);
        $previously_requested_profile = (bool) $session->get(self::SESSION_PROFILE_REQUESTED, false);
        if (($session_id || $session->get(self::SESSION_IDEMPOTENCY)) && $previously_requested_profile !== $include_profile) {
            if ($session_id) {
                $this->delete_gateway_session($session_id);
            }
            $this->clear_session_request($session);
            $session_id = '';
            $qr_url = '';
            $expiry = 0;
        }
        if ($session_id && $qr_url && $expiry > time()) {
            wp_send_json_success(array('status' => 'PENDING', 'qr_code_url' => $qr_url));
        }

        $credentials = array(
            array(
                'id' => 'proof-of-age',
                'format' => 'mso_mdoc',
                'issuer_id' => $this->age_issuer_id(),
                'trust_domain' => 'pub_eaa',
                'namespace' => 'eu.europa.ec.av.1',
                'doc_type' => 'eu.europa.ec.av.1',
                'claims' => array('age_over_18'),
                'required' => true,
                'claim_filters' => array(
                    'age_over_18' => array('const' => true),
                ),
            ),
        );
        if ($include_profile) {
            $credentials[] = array(
                'id' => 'eudi-pid-profile',
                'format' => 'mso_mdoc',
                'issuer_id' => $this->pid_issuer_id(),
                'trust_domain' => 'pid',
                'namespace' => 'eu.europa.ec.eudi.pid.1',
                'doc_type' => 'eu.europa.ec.eudi.pid.1',
                'required' => false,
                'claims' => array(
                    'given_name',
                    'family_name',
                    'resident_street',
                    'resident_city',
                    'resident_postal_code',
                    'resident_country',
                ),
            );
        }

        $session->set(self::SESSION_PROFILE_REQUESTED, $include_profile ? 1 : 0);
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
            'body' => wp_json_encode(array(
                'credentials' => $credentials,
            )),
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
        $profile_requested = (bool) $session->get(self::SESSION_PROFILE_REQUESTED, false);
        if ($verified_at && time() - $verified_at < self::AGE_PROOF_TTL && !$profile_requested) {
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
                if ($profile_requested && $this->apply_checkout_profile(isset($body['claims']) ? $body['claims'] : array())) {
                    $session->set(self::SESSION_PROFILE_PREFILLED, true);
                }
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
        if (!is_array($claims)) {
            return false;
        }
        foreach (array('proof-of-age', 'age-over-18', 'age-over-18-mdoc') as $descriptor_id) {
            if (!isset($claims[$descriptor_id]) || !is_array($claims[$descriptor_id])) {
                continue;
            }
            $claim_set = $claims[$descriptor_id];
            if ((isset($claim_set['age_over_18']) && true === $claim_set['age_over_18']) ||
                (isset($claim_set['is_over_18']) && true === $claim_set['is_over_18'])) {
                return true;
            }
            foreach ($claim_set as $namespace_claims) {
                if (is_array($namespace_claims) && isset($namespace_claims['age_over_18']) && true === $namespace_claims['age_over_18']) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Returns a requested claim from its descriptor, flattening one mDOC namespace level. */
    private function disclosed_checkout_claim($claims, $claim_name, $descriptor_id = 'eudi-pid-profile') {
        if (!is_array($claims) || !isset($claims[$descriptor_id]) || !is_array($claims[$descriptor_id])) {
            return null;
        }
        $descriptor_claims = $claims[$descriptor_id];
        if (array_key_exists($claim_name, $descriptor_claims)) {
            return $descriptor_claims[$claim_name];
        }
        foreach ($descriptor_claims as $namespace_claims) {
            if (is_array($namespace_claims) && array_key_exists($claim_name, $namespace_claims)) {
                return $namespace_claims[$claim_name];
            }
        }
        return null;
    }

    /** Copies only allowlisted PID attributes into editable WooCommerce customer checkout fields. */
    private function apply_checkout_profile($claims) {
        if (!$this->profile_prefill_enabled() || !function_exists('WC') || !WC()->customer) {
            return false;
        }

        $customer = WC()->customer;
        $applied = false;
        $given_name = $this->checkout_text_value($this->disclosed_checkout_claim($claims, 'given_name'));
        $family_name = $this->checkout_text_value($this->disclosed_checkout_claim($claims, 'family_name'));
        if ('' !== $given_name && '' !== $family_name) {
            $customer->set_billing_first_name($given_name);
            $customer->set_shipping_first_name($given_name);
            $customer->set_billing_last_name($family_name);
            $customer->set_shipping_last_name($family_name);
            $applied = true;
        }

        $address_fields = array(
            'resident_street' => 'set_shipping_address_1',
            'resident_city' => 'set_shipping_city',
            'resident_postal_code' => 'set_shipping_postcode',
        );
        foreach ($address_fields as $claim => $setter) {
            $value = $this->checkout_text_value($this->disclosed_checkout_claim($claims, $claim));
            if ('' !== $value) {
                $customer->{$setter}($value);
                $applied = true;
            }
        }
        $country = strtoupper($this->checkout_text_value($this->disclosed_checkout_claim($claims, 'resident_country')));
        $countries = WC()->countries ? WC()->countries->get_countries() : array();
        if (preg_match('/^[A-Z]{2}$/', $country) && isset($countries[$country])) {
            $customer->set_shipping_country($country);
            $applied = true;
        }

        if ($applied) {
            $customer->save();
        }
        return $applied;
    }

    /** Sanitizes a scalar identity value before it is copied into checkout fields. */
    private function checkout_text_value($value) {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }
        $value = sanitize_text_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 255) : substr($value, 0, 255);
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
        $session->set(self::SESSION_PROFILE_REQUESTED, null);
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

        if ($this->vies_enabled() && is_array($data) && isset($data['billing_dlbr_id_vat_number'])) {
            $this->save_vies_order_meta($order, (string) $data['billing_dlbr_id_vat_number']);
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
            WC()->session->set(self::SESSION_PROFILE_PREFILLED, null);
            WC()->session->set(self::SESSION_VIES_RESULT, null);
        }
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        DLBR_ID_WooCommerce_Age_Verification::instance();
    }
}, 20);

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', DLBR_ID_WC_FILE, true);
    }
});
