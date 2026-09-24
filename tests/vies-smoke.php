<?php
/**
 * Dependency-free smoke tests for VIES input and response handling.
 * Run with: php tests/vies-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dlbr_id_wc_vies_mock'] = array();

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function sanitize_key($value) {
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
}

function wp_json_encode($value) {
    return json_encode($value);
}

function is_wp_error($value) {
    return is_array($value) && !empty($value['wp_error']);
}

function wp_remote_post($url, $args) {
    $GLOBALS['dlbr_id_wc_vies_mock']['url'] = $url;
    $GLOBALS['dlbr_id_wc_vies_mock']['args'] = $args;
    return isset($GLOBALS['dlbr_id_wc_vies_mock']['response'])
        ? $GLOBALS['dlbr_id_wc_vies_mock']['response']
        : array('response' => array('code' => 200), 'body' => '{"valid":true}');
}

function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}

require_once dirname(__DIR__) . '/includes/class-dlbr-id-wc-vies-client.php';

function vies_check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$de = DLBR_ID_WC_VIES_Client::parse_vat_number(' de 123 456 789 ');
vies_check(is_array($de) && 'DE' === $de['country_code'] && '123456789' === $de['vat_number'], 'normalizes a prefixed VAT number');

$el = DLBR_ID_WC_VIES_Client::parse_vat_number('GR123456789');
vies_check(is_array($el) && 'EL' === $el['country_code'], 'maps Greece to VIES country code EL');

$xi = DLBR_ID_WC_VIES_Client::parse_vat_number('XI123456789');
vies_check(is_array($xi) && 'XI' === $xi['country_code'], 'accepts Northern Ireland VAT prefix XI');

vies_check(null === DLBR_ID_WC_VIES_Client::parse_vat_number('GB123456789'), 'does not send GB numbers to VIES');
vies_check(null === DLBR_ID_WC_VIES_Client::parse_vat_number('DE-'), 'rejects an empty VAT number');

$valid = DLBR_ID_WC_VIES_Client::interpret_response(200, json_encode(array(
    'valid' => true,
    'requestDate' => '2026-09-24T10:00:00Z',
    'requestIdentifier' => 'vies-ref-123',
    'name' => 'Company Name',
    'address' => 'Company Address',
)));
vies_check('valid' === $valid['status'], 'accepts a JSON boolean VIES success response');
vies_check('vies-ref-123' === $valid['request_identifier'], 'retains the VIES consultation reference');
vies_check(!isset($valid['name']) && !isset($valid['address']), 'does not retain VIES name or address data');

$invalid = DLBR_ID_WC_VIES_Client::interpret_response(200, '{"isValid":false,"userError":"INVALID"}');
vies_check('invalid' === $invalid['status'], 'distinguishes a completed invalid result');

$unavailable = DLBR_ID_WC_VIES_Client::interpret_response(200, '{"isValid":false,"userError":"MS_UNAVAILABLE"}');
vies_check('unavailable' === $unavailable['status'], 'does not mistake a Member State outage for an invalid VAT ID');

$malformed = DLBR_ID_WC_VIES_Client::interpret_response(200, '{"valid":"true"}');
vies_check('unavailable' === $malformed['status'], 'rejects string booleans in API responses');

$bad_json = DLBR_ID_WC_VIES_Client::interpret_response(200, 'not JSON');
vies_check('unavailable' === $bad_json['status'], 'treats malformed API output as unavailable');

$http_error = DLBR_ID_WC_VIES_Client::interpret_response(503, '{"valid":false}');
vies_check('unavailable' === $http_error['status'], 'treats non-success HTTP status as unavailable');

$GLOBALS['dlbr_id_wc_vies_mock']['response'] = array('response' => array('code' => 200), 'body' => '{"valid":true}');
$client = new DLBR_ID_WC_VIES_Client();
$checked = $client->check('GR123456789', 'DE111222333');
$payload = json_decode($GLOBALS['dlbr_id_wc_vies_mock']['args']['body'], true);
vies_check('valid' === $checked['status'], 'uses the server-side VIES request');
vies_check('EL' === $payload['countryCode'] && '123456789' === $payload['vatNumber'], 'sends normalized customer VAT data');
vies_check('DE' === $payload['requesterMemberStateCode'] && '111222333' === $payload['requesterNumber'], 'includes configured merchant details as requester');
vies_check('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' === $GLOBALS['dlbr_id_wc_vies_mock']['url'], 'calls the fixed Commission VIES endpoint');

$previous_url = $GLOBALS['dlbr_id_wc_vies_mock']['url'];
$invalid_input = $client->check('GB123456789');
vies_check('invalid_input' === $invalid_input['status'] && $previous_url === $GLOBALS['dlbr_id_wc_vies_mock']['url'], 'does not send unsupported input to VIES');

$GLOBALS['dlbr_id_wc_vies_mock']['response'] = array('wp_error' => true);
$network_error = $client->check('DE123456789');
vies_check('unavailable' === $network_error['status'], 'treats network errors as unavailable');

fwrite(STDOUT, "WooCommerce VIES smoke tests passed (19 checks).\n");
