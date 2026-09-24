<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Loads the age-verification inner block into the WooCommerce Checkout Block. */
final class DLBR_EID_WC_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
    /** @return string */
    public function get_name() {
        return 'dlbr-eid-woocommerce';
    }

    /** Registers the client scripts and checkout styles used by the inner block. */
    public function initialize() {
        wp_register_script(
            'dlbr-eid-wc-blocks',
            DLBR_EID_WC_URL . 'assets/checkout-blocks.js',
            array('wp-blocks', 'wp-element', 'wp-i18n', 'wc-blocks-checkout', 'wc-settings'),
            DLBR_EID_WC_VERSION,
            true
        );
        wp_register_script(
            'dlbr-eid-wc-qrcode',
            DLBR_EID_WC_URL . 'assets/qrcode.min.js',
            array(),
            '1.5.4',
            true
        );
        wp_register_script(
            'dlbr-eid-wc-checkout',
            DLBR_EID_WC_URL . 'assets/checkout.js',
            array('dlbr-eid-wc-qrcode'),
            DLBR_EID_WC_VERSION,
            true
        );
        wp_register_style(
            'dlbr-eid-wc-checkout',
            DLBR_EID_WC_URL . 'assets/checkout.css',
            array(),
            DLBR_EID_WC_VERSION
        );
        wp_enqueue_style('dlbr-eid-wc-checkout');
    }

    /** @return string[] */
    public function get_script_handles() {
        $settings = DLBR_EID_WooCommerce_Age_Verification::instance()->checkout_client_config();
        if (!empty($settings['active']) || !empty($settings['businessEnabled'])) {
            return array('dlbr-eid-wc-qrcode', 'dlbr-eid-wc-checkout', 'dlbr-eid-wc-blocks');
        }
        return array('dlbr-eid-wc-blocks');
    }

    /** @return string[] */
    public function get_editor_script_handles() {
        return array('dlbr-eid-wc-blocks');
    }

    /** @return array<string,mixed> */
    public function get_script_data() {
        return DLBR_EID_WooCommerce_Age_Verification::instance()->checkout_client_config();
    }
}
