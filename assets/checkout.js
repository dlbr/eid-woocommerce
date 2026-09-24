(function () {
    'use strict';

    const config = window.dlbrIdWooCommerce || (window.dlbrIdWooCommerce = {});
    const text = (key, fallback) => (config.strings && config.strings[key]) || fallback;
    const request = async (action, parameters) => {
        const body = new URLSearchParams({
            action,
            nonce: config.nonce,
            ...(parameters || {}),
        });
        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        });
        const json = await response.json();
        if (!response.ok || !json.success) throw new Error('Request failed');
        return json.data || {};
    };

    const setStatus = (widget, message, state) => {
        const status = widget.querySelector('.dlbr-id-wc-status');
        status.textContent = message;
        status.dataset.state = state || '';
    };

    const showRequest = async (widget, uri) => {
        const requestPanel = widget.querySelector('.dlbr-id-wc-request');
        const image = widget.querySelector('.dlbr-id-wc-qr');
        const linkContainer = widget.querySelector('.dlbr-id-wc-wallet-link');
        try {
            if (!window.DlbrIdQrCode || typeof window.DlbrIdQrCode.toDataURL !== 'function') {
                throw new Error('QR renderer is unavailable');
            }
            image.src = await window.DlbrIdQrCode.toDataURL(uri, {
                width: 240,
                margin: 2,
                errorCorrectionLevel: 'M',
            });
            image.alt = text('qrAlt', '');
            image.hidden = false;
        } catch (error) {
            image.hidden = true;
        }
        linkContainer.textContent = '';
        const link = document.createElement('a');
        link.href = uri;
        link.rel = 'noopener';
        link.textContent = text('openWallet', 'Open in wallet');
        linkContainer.appendChild(link);
        requestPanel.hidden = false;
    };

    const beginPolling = (widget) => {
        const button = widget.querySelector('.dlbr-id-wc-start');
        const profileChoice = widget.querySelector('.dlbr-id-wc-prefill-profile');
        let stopped = false;
        const poll = async () => {
            if (stopped) return;
            try {
                const result = await request('dlbr_id_wc_poll');
                if (result.status === 'VERIFIED') {
                    stopped = true;
                    button.disabled = true;
                    setStatus(widget, text('verified', 'Age verified.'), 'verified');
                    widget.querySelector('.dlbr-id-wc-request').hidden = true;
                    window.dispatchEvent(new CustomEvent('dlbr-id-age-verified'));
                    window.location.reload();
                    return;
                }
                if (result.status === 'REJECTED') {
                    stopped = true;
                    setStatus(widget, text('rejected', 'Age was not verified.'), 'error');
                    widget.querySelector('.dlbr-id-wc-request').hidden = true;
                    button.disabled = false;
                    if (profileChoice) profileChoice.disabled = false;
                    return;
                }
                if (result.status === 'EXPIRED' || result.status === 'FAILED') {
                    stopped = true;
                    setStatus(widget, text('expired', 'The request expired.'), 'error');
                    widget.querySelector('.dlbr-id-wc-request').hidden = true;
                    button.disabled = false;
                    if (profileChoice) profileChoice.disabled = false;
                    return;
                }
                setStatus(widget, text('pending', 'Waiting for your wallet…'), 'pending');
            } catch (error) {
                // Keep polling through temporary network errors; the server remains authoritative.
            }
            if (!stopped) window.setTimeout(poll, 2500);
        };
        window.setTimeout(poll, 1500);
    };

    const initializedWidgets = new WeakSet();
    const initializeWidget = (widget) => {
        const button = widget.querySelector('.dlbr-id-wc-start');
        if (!button || initializedWidgets.has(widget)) return;
        initializedWidgets.add(widget);
        button.addEventListener('click', async () => {
            const profileChoice = widget.querySelector('.dlbr-id-wc-prefill-profile');
            const includeProfile = button.dataset.includeProfile === '1' || Boolean(profileChoice && profileChoice.checked);
            button.disabled = true;
            if (profileChoice) profileChoice.disabled = true;
            setStatus(widget, text('starting', 'Preparing a secure request…'), 'pending');
            try {
                const result = await request('dlbr_id_wc_start', { include_profile: includeProfile ? '1' : '0' });
                if (result.status === 'VERIFIED') {
                    setStatus(widget, text('verified', 'Age verified.'), 'verified');
                    window.dispatchEvent(new CustomEvent('dlbr-id-age-verified'));
                    window.location.reload();
                    return;
                }
                if (result.qr_code_url) await showRequest(widget, result.qr_code_url);
                setStatus(widget, text('waiting', 'Scan the QR code with your wallet.'), 'pending');
                beginPolling(widget);
            } catch (error) {
                setStatus(widget, text('error', 'Age verification is unavailable.'), 'error');
                button.disabled = false;
                if (profileChoice) profileChoice.disabled = false;
            }
        });
    };

    const scanForWidgets = (root) => {
        if (!root || typeof root.querySelectorAll !== 'function') return;
        if (root.nodeType === Node.ELEMENT_NODE && root.matches('.dlbr-id-wc-verification')) {
            initializeWidget(root);
        }
        root.querySelectorAll('.dlbr-id-wc-verification').forEach(initializeWidget);
    };

    scanForWidgets(document);
    if (window.MutationObserver && document.documentElement) {
        const observer = new MutationObserver((records) => {
            records.forEach((record) => record.addedNodes.forEach(scanForWidgets));
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
})();
