(function () {
    'use strict';

    const config = window.dlbrIdWooCommerce;
    if (!config) return;

    const strings = config.strings || {};
    const request = async (action) => {
        const body = new URLSearchParams({
            action,
            nonce: config.nonce,
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
        if (!window.DlbrIdQrCode || typeof window.DlbrIdQrCode.toDataURL !== 'function') {
            throw new Error('QR renderer is unavailable');
        }
        try {
            image.src = await window.DlbrIdQrCode.toDataURL(uri, {
                width: 240,
                margin: 2,
                errorCorrectionLevel: 'M',
            });
            image.alt = strings.qrAlt || '';
            image.hidden = false;
        } catch (error) {
            image.hidden = true;
        }
        linkContainer.textContent = '';
        const link = document.createElement('a');
        link.href = uri;
        link.rel = 'noopener';
        link.textContent = strings.openWallet || 'Open in wallet';
        linkContainer.appendChild(link);
        requestPanel.hidden = false;
    };

    const beginPolling = (widget) => {
        const button = widget.querySelector('.dlbr-id-wc-start');
        let stopped = false;
        const poll = async () => {
            if (stopped) return;
            try {
                const result = await request('dlbr_id_wc_poll');
                if (result.status === 'VERIFIED') {
                    stopped = true;
                    button.disabled = true;
                    setStatus(widget, strings.verified || 'Age verified.', 'verified');
                    widget.querySelector('.dlbr-id-wc-request').hidden = true;
                    window.dispatchEvent(new CustomEvent('dlbr-id-age-verified'));
                    window.location.reload();
                    return;
                }
                if (result.status === 'REJECTED') {
                    stopped = true;
                    setStatus(widget, strings.rejected || 'Age was not verified.', 'error');
                    widget.querySelector('.dlbr-id-wc-request').hidden = true;
                    button.disabled = false;
                    return;
                }
                if (result.status === 'EXPIRED' || result.status === 'FAILED') {
                    stopped = true;
                    setStatus(widget, strings.expired || 'The request expired.', 'error');
                    widget.querySelector('.dlbr-id-wc-request').hidden = true;
                    button.disabled = false;
                    return;
                }
                setStatus(widget, strings.pending || 'Waiting for your wallet…', 'pending');
            } catch (error) {
                // Keep polling through temporary network errors; the server remains authoritative.
            }
            if (!stopped) window.setTimeout(poll, 2500);
        };
        window.setTimeout(poll, 1500);
    };

    document.querySelectorAll('.dlbr-id-wc-verification').forEach((widget) => {
        const button = widget.querySelector('.dlbr-id-wc-start');
        if (!button) return;
        button.addEventListener('click', async () => {
            button.disabled = true;
            setStatus(widget, strings.starting || 'Preparing a secure request…', 'pending');
            try {
                const result = await request('dlbr_id_wc_start');
                if (result.status === 'VERIFIED') {
                    setStatus(widget, strings.verified || 'Age verified.', 'verified');
                    window.dispatchEvent(new CustomEvent('dlbr-id-age-verified'));
                    return;
                }
                if (result.qr_code_url) await showRequest(widget, result.qr_code_url);
                setStatus(widget, strings.waiting || 'Scan the QR code with your wallet.', 'pending');
                beginPolling(widget);
            } catch (error) {
                setStatus(widget, strings.error || 'Age verification is unavailable.', 'error');
                button.disabled = false;
            }
        });
    });
})();
