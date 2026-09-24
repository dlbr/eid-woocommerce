(function (wp, wc) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element || !wp.i18n || !wc || !wc.blocksCheckout || !wc.wcSettings) {
        return;
    }

    const blockName = 'dlbr-eid/age-verification';
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
            title: translate('dlbr.id age verification', 'dlbr-eid-age-verification-for-woocommerce'),
            description: translate('Verifies age for restricted products at checkout.', 'dlbr-eid-age-verification-for-woocommerce'),
            category: 'woocommerce',
            parent: parentBlocks,
            attributes,
            edit: function () {
                return element(
                    'div',
                    { className: 'dlbr-eid-wc-block-editor' },
                    translate('dlbr.id age verification is added to Checkout and shown for protected carts.', 'dlbr-eid-age-verification-for-woocommerce')
                );
            },
            save: function () {
                return null;
            },
        });
    }

    const CheckoutAgeVerification = function () {
        const settings = wc.wcSettings.getSetting('dlbr-eid-woocommerce_data', {});
        const config = window.dlbrEidWooCommerce || (window.dlbrEidWooCommerce = {});
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
            { className: 'dlbr-eid-wc-verification', 'aria-labelledby': 'dlbr-eid-wc-title' },
            element('h3', { id: 'dlbr-eid-wc-title' }, translate('Age verification', 'dlbr-eid-age-verification-for-woocommerce')),
            element(
                'p',
                null,
                verified
                    ? (profilePrefilled
                        ? (strings.profilePrefilled || translate('Wallet details were added to the editable checkout fields.', 'dlbr-eid-age-verification-for-woocommerce'))
                        : (strings.verified || translate('Age verified. You can continue checkout.', 'dlbr-eid-age-verification-for-woocommerce')))
                    : translate('This cart contains age-restricted products. Verify your age to continue.', 'dlbr-eid-age-verification-for-woocommerce')
            ),
            canPrefill && !verified && element(
                'div',
                null,
                element(
                    'label',
                    { className: 'dlbr-eid-wc-prefill-option' },
                    element('input', { type: 'checkbox', className: 'dlbr-eid-wc-prefill-profile' }),
                    ' ',
                    strings.prefillLabel || translate('Also share my name and delivery details to fill this checkout.', 'dlbr-eid-age-verification-for-woocommerce')
                ),
                element(
                    'p',
                    { className: 'dlbr-eid-wc-prefill-notice' },
                    strings.prefillNotice || translate('Your wallet will ask before sharing. You can edit these fields after they fill checkout. For signed-in customers, WooCommerce may also update saved account details.', 'dlbr-eid-age-verification-for-woocommerce')
                )
            ),
            showButton && element(
                'button',
                { type: 'button', className: 'button alt dlbr-eid-wc-start', 'data-flow': 'age', ...(verified ? { 'data-include-profile': '1' } : {}) },
                verified
                    ? (strings.prefill || translate('Fill checkout details with your wallet', 'dlbr-eid-age-verification-for-woocommerce'))
                    : (strings.start || translate('Verify age with your digital wallet', 'dlbr-eid-age-verification-for-woocommerce'))
            ),
            element('div', { className: 'dlbr-eid-wc-status', role: 'status', 'aria-live': 'polite' }),
            element(
                'div',
                { className: 'dlbr-eid-wc-request', hidden: true },
                element('img', { className: 'dlbr-eid-wc-qr', alt: '', width: 240, height: 240, hidden: true }),
                element('p', { className: 'dlbr-eid-wc-wallet-link' })
            )
        );
        const businessPanel = config.businessEnabled && element(
            'section',
            { className: 'dlbr-eid-wc-business-verification', 'aria-labelledby': 'dlbr-eid-wc-business-title' },
            element('h3', { id: 'dlbr-eid-wc-business-title' }, strings.businessTitle || translate('Company credential verification', 'dlbr-eid-age-verification-for-woocommerce')),
            element('p', null, strings.businessDescription || translate('Verify EWC company credentials for the same company.', 'dlbr-eid-age-verification-for-woocommerce')),
            config.businessVerified
                ? element('p', null, strings.businessVerified || translate('Company credentials verified for the same company.', 'dlbr-eid-age-verification-for-woocommerce'))
                : element('button', { type: 'button', className: 'button alt dlbr-eid-wc-start', 'data-flow': 'business' }, strings.businessStart || translate('Verify company credentials with your wallet', 'dlbr-eid-age-verification-for-woocommerce')),
            element('div', { className: 'dlbr-eid-wc-status', role: 'status', 'aria-live': 'polite' }),
            element(
                'div',
                { className: 'dlbr-eid-wc-request', hidden: true },
                element('img', { className: 'dlbr-eid-wc-qr', alt: '', width: 240, height: 240, hidden: true }),
                element('p', { className: 'dlbr-eid-wc-wallet-link' })
            )
        );
        return element('div', { className: 'dlbr-eid-wc-checkout' }, agePanel, businessPanel);
    };

    wc.blocksCheckout.registerCheckoutBlock({
        metadata,
        component: CheckoutAgeVerification,
    });
})(window.wp, window.wc);
