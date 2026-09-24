<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Loads the age-verification inner block into the WooCommerce Checkout Block. */
final class DLBR_ID_WC_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
    /** @return string */
    public function get_name() {
        return 'dlbr-id-woocommerce';
    }

    /** Registers the client scripts and checkout styles used by the inner block. */
    public function initialize() {
        wp_register_script(
            'dlbr-id-wc-blocks',
            DLBR_ID_WC_URL . 'assets/checkout-blocks.js',
            array('wp-blocks', 'wp-element', 'wp-i18n', 'wc-blocks-checkout', 'wc-settings'),
            DLBR_ID_WC_VERSION,
            true
        );
        wp_register_script(
            'dlbr-id-wc-qrcode',
            DLBR_ID_WC_URL . 'assets/qrcode.min.js',
            array(),
            '1.5.4',
            true
        );
        wp_register_script(
            'dlbr-id-wc-checkout',
            DLBR_ID_WC_URL . 'assets/checkout.js',
            array('dlbr-id-wc-qrcode'),
            DLBR_ID_WC_VERSION,
            true
        );
        wp_register_style(
            'dlbr-id-wc-checkout',
            DLBR_ID_WC_URL . 'assets/checkout.css',
            array(),
            DLBR_ID_WC_VERSION
        );
        wp_enqueue_style('dlbr-id-wc-checkout');
    }

    /** @return string[] */
    public function get_script_handles() {
        $settings = DLBR_ID_WooCommerce_Age_Verification::instance()->checkout_client_config();
        if (!empty($settings['active']) || !empty($settings['businessEnabled'])) {
            return array('dlbr-id-wc-qrcode', 'dlbr-id-wc-checkout', 'dlbr-id-wc-blocks');
        }
        return array('dlbr-id-wc-blocks');
    }

    /** @return string[] */
    public function get_editor_script_handles() {
        return array('dlbr-id-wc-blocks');
    }

    /** @return array<string,mixed> */
    public function get_script_data() {
        return DLBR_ID_WooCommerce_Age_Verification::instance()->checkout_client_config();
    }
}
