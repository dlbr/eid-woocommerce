(function (wp, wc) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element || !wp.i18n || !wc || !wc.blocksCheckout || !wc.wcSettings) {
        return;
    }

    const blockName = 'dlbr-id/age-verification';
    const parentBlocks = ['woocommerce/checkout-fields-block'];
    const attributes = {
        lock: {
            type: 'object',
            default: { remove: true, move: true },
        },
    };
    const metadata = {
        name: blockName,
        parent: parentBlocks,
        attributes,
    };
    const element = wp.element.createElement;
    const translate = wp.i18n.__;

    if (!wp.blocks.getBlockType(blockName)) {
        wp.blocks.registerBlockType(blockName, {
            apiVersion: 3,
            title: translate('dlbr.id age verification', 'dlbr-id-woocommerce'),
            description: translate('Verifies age for restricted products at checkout.', 'dlbr-id-woocommerce'),
            category: 'woocommerce',
            parent: parentBlocks,
            attributes,
            edit: function () {
                return element(
                    'div',
                    { className: 'dlbr-id-wc-block-editor' },
                    translate('dlbr.id age verification is added to Checkout and shown for protected carts.', 'dlbr-id-woocommerce')
                );
            },
            save: function () {
                return null;
            },
        });
    }

    const CheckoutAgeVerification = function () {
        const settings = wc.wcSettings.getSetting('dlbr-id-woocommerce_data', {});
        const config = window.dlbrIdWooCommerce || (window.dlbrIdWooCommerce = {});
        Object.assign(config, settings);
        if (!config.active && !config.businessEnabled) {
            return null;
        }

        const strings = config.strings || {};
        const verified = Boolean(config.verified);
        const canPrefill = Boolean(config.canPrefillProfile);
        const profilePrefilled = Boolean(config.profilePrefilled);
        const showButton = !verified || (canPrefill && !profilePrefilled);
        const agePanel = config.active && element(
            'section',
            { className: 'dlbr-id-wc-verification', 'aria-labelledby': 'dlbr-id-wc-title' },
            element('h3', { id: 'dlbr-id-wc-title' }, translate('Age verification', 'dlbr-id-woocommerce')),
            element(
                'p',
                null,
                verified
                    ? (profilePrefilled
                        ? (strings.profilePrefilled || translate('Wallet details were added to the editable checkout fields.', 'dlbr-id-woocommerce'))
                        : (strings.verified || translate('Age verified. You can continue checkout.', 'dlbr-id-woocommerce')))
                    : translate('This cart contains age-restricted products. Verify your age to continue.', 'dlbr-id-woocommerce')
            ),
            canPrefill && !verified && element(
                'div',
                null,
                element(
                    'label',
                    { className: 'dlbr-id-wc-prefill-option' },
                    element('input', { type: 'checkbox', className: 'dlbr-id-wc-prefill-profile' }),
                    ' ',
                    strings.prefillLabel || translate('Also share my name and delivery details to fill this checkout.', 'dlbr-id-woocommerce')
                ),
                element(
                    'p',
                    { className: 'dlbr-id-wc-prefill-notice' },
                    strings.prefillNotice || translate('Your wallet will ask before sharing. You can edit these fields after they fill checkout. For signed-in customers, WooCommerce may also update saved account details.', 'dlbr-id-woocommerce')
                )
            ),
            showButton && element(
                'button',
                { type: 'button', className: 'button alt dlbr-id-wc-start', 'data-flow': 'age', ...(verified ? { 'data-include-profile': '1' } : {}) },
                verified
                    ? (strings.prefill || translate('Fill checkout details with your wallet', 'dlbr-id-woocommerce'))
                    : (strings.start || translate('Verify age with your digital wallet', 'dlbr-id-woocommerce'))
            ),
            element('div', { className: 'dlbr-id-wc-status', role: 'status', 'aria-live': 'polite' }),
            element(
                'div',
                { className: 'dlbr-id-wc-request', hidden: true },
                element('img', { className: 'dlbr-id-wc-qr', alt: '', width: 240, height: 240, hidden: true }),
                element('p', { className: 'dlbr-id-wc-wallet-link' })
            )
        );
        const businessPanel = config.businessEnabled && element(
            'section',
            { className: 'dlbr-id-wc-business-verification', 'aria-labelledby': 'dlbr-id-wc-business-title' },
            element('h3', { id: 'dlbr-id-wc-business-title' }, strings.businessTitle || translate('Company credential verification', 'dlbr-id-woocommerce')),
            element('p', null, strings.businessDescription || translate('Verify EWC company credentials for the same company.', 'dlbr-id-woocommerce')),
            config.businessVerified
                ? element('p', null, strings.businessVerified || translate('Company credentials verified for the same company.', 'dlbr-id-woocommerce'))
                : element('button', { type: 'button', className: 'button alt dlbr-id-wc-start', 'data-flow': 'business' }, strings.businessStart || translate('Verify company credentials with your wallet', 'dlbr-id-woocommerce')),
            element('div', { className: 'dlbr-id-wc-status', role: 'status', 'aria-live': 'polite' }),
            element(
                'div',
                { className: 'dlbr-id-wc-request', hidden: true },
                element('img', { className: 'dlbr-id-wc-qr', alt: '', width: 240, height: 240, hidden: true }),
                element('p', { className: 'dlbr-id-wc-wallet-link' })
            )
        );
        return element('div', { className: 'dlbr-id-wc-checkout' }, agePanel, businessPanel);
    };

    wc.blocksCheckout.registerCheckoutBlock({
        metadata,
        component: CheckoutAgeVerification,
    });
})(window.wp, window.wc);
