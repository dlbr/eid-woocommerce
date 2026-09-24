<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Server-side client for the European Commission's VIES on-the-Web API. */
final class DLBR_EID_WC_VIES_Client {
    const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /** @return array<string,string>|null */
    public static function parse_vat_number($value) {
        if (!is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));
        $value = preg_replace('/[\s.\-]+/', '', $value);
        if (!is_string($value) || !preg_match('/^([A-Z]{2})([A-Z0-9]{1,12})$/', $value, $matches)) {
            return null;
        }

        $country_code = $matches[1];
        if ('GR' === $country_code) {
            $country_code = 'EL';
        }

        $supported_countries = array(
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
            'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
            'SE', 'SI', 'SK', 'XI',
        );
        if (!in_array($country_code, $supported_countries, true)) {
            return null;
        }

        return array(
            'country_code' => $country_code,
            'vat_number' => $matches[2],
            'formatted' => $country_code . $matches[2],
        );
    }

    /**
     * Checks a prefixed VAT number. VIES outages and malformed replies are
     * returned as unavailable so callers never mistake them for invalid IDs.
     *
     * @param string $value Customer VAT number, including its country prefix.
     * @param string $requester_vat_number Optional merchant VAT number.
     * @return array<string,mixed>
     */
    public function check($value, $requester_vat_number = '') {
        $vat = self::parse_vat_number($value);
        if (!$vat) {
            return array('status' => 'invalid_input');
        }

        $payload = array(
            'countryCode' => $vat['country_code'],
            'vatNumber' => $vat['vat_number'],
        );
        $requester = self::parse_vat_number($requester_vat_number);
        if ($requester) {
            $payload['requesterMemberStateCode'] = $requester['country_code'];
            $payload['requesterNumber'] = $requester['vat_number'];
        }

        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 10,
            'redirection' => 2,
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return array('status' => 'unavailable', 'code' => 'network_error');
        }

        return self::interpret_response(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response)
        );
    }

    /** @return array<string,mixed> */
    public static function interpret_response($http_status, $body) {
        if (200 !== (int) $http_status || !is_string($body)) {
            return array('status' => 'unavailable', 'code' => 'http_error');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return array('status' => 'unavailable', 'code' => 'invalid_response');
        }

        // The Commission UI has used both response spellings across API
        // versions. Accept only a real JSON boolean from either field.
        $valid = array_key_exists('valid', $data) ? $data['valid'] : (isset($data['isValid']) ? $data['isValid'] : null);
        if (!is_bool($valid)) {
            return array('status' => 'unavailable', 'code' => self::response_error_code($data, 'invalid_response'));
        }

        $code = self::response_error_code($data, '');
        if (!$valid && self::is_outage_code($code)) {
            return array('status' => 'unavailable', 'code' => $code);
        }
        if (!$valid && '' !== $code && 'INVALID' !== strtoupper($code)) {
            return array('status' => 'unavailable', 'code' => $code);
        }
        if ($valid && '' !== $code) {
            return array('status' => 'unavailable', 'code' => $code);
        }

        $result = array(
            'status' => $valid ? 'valid' : 'invalid',
            'checked_at' => self::response_date($data),
        );
        if (!empty($data['requestIdentifier']) && is_string($data['requestIdentifier'])) {
            $result['request_identifier'] = sanitize_text_field($data['requestIdentifier']);
        }
        if ($code) {
            $result['code'] = $code;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    private static function response_error_code($data, $fallback) {
        foreach (array('userError', 'errorCode', 'code') as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && '' !== $data[$key] && 'VALID' !== $data[$key]) {
                return sanitize_key($data[$key]);
            }
        }

        if (!empty($data['errorWrappers']) || !empty($data['error'])) {
            return 'service_error';
        }
        return $fallback;
    }

    /** @param string $code */
    private static function is_outage_code($code) {
        return in_array(strtoupper((string) $code), array(
            'MS_UNAVAILABLE',
            'MS_MAX_CONCURRENT_REQ',
            'GLOBAL_MAX_CONCURRENT_REQ',
            'SERVICE_UNAVAILABLE',
            'TIMEOUT',
            'SERVICE_ERROR',
        ), true);
    }

    /** @param array<string,mixed> $data */
    private static function response_date($data) {
        if (isset($data['requestDate']) && is_string($data['requestDate'])) {
            $timestamp = strtotime($data['requestDate']);
            if (false !== $timestamp) {
                return gmdate('c', $timestamp);
            }
        }
        return gmdate('c');
    }
}
