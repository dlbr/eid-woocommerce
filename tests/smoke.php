<?php
/**
 * Dependency-free smoke tests for the plugin's privacy-sensitive claim parsing.
 * Run with: php tests/smoke.php
 */

define('ABSPATH', __DIR__ . '/');

function plugin_dir_path($file) {
    return dirname($file) . '/';
}

function plugin_dir_url($file) {
    return 'https://store.example/plugins/dlbr-eid-woocommerce/';
}

function get_option($name, $default = false) {
    return isset($GLOBALS['dlbr_eid_wc_test_options'][$name])
        ? $GLOBALS['dlbr_eid_wc_test_options'][$name]
        : $default;
}

function esc_url_raw($url, $protocols = array('http', 'https')) {
    if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $parts = parse_url($url);
    return isset($parts['scheme']) && in_array(strtolower($parts['scheme']), $protocols, true) ? $url : '';
}

function wp_parse_url($url) {
    return parse_url($url);
}

function wp_http_validate_url($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (!isset($parts['host']) || in_array(strtolower($parts['host']), array('localhost', 'localhost.localdomain'), true)) {
        return false;
    }
    if (filter_var($parts['host'], FILTER_VALIDATE_IP) && !filter_var($parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    return $url;
}

function untrailingslashit($value) {
    return rtrim($value, '/');
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function sanitize_key($value) {
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
}

function check_ajax_referer() {
    return true;
}

function wp_generate_uuid4() {
    return 'synthetic-idempotency-key';
}

function wp_json_encode($value) {
    return json_encode($value);
}

function is_wp_error($value) {
    return false;
}

function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}

function wp_safe_remote_post($url, $args = array()) {
    $GLOBALS['dlbr_eid_wc_test_http_requests'][] = array('method' => 'POST', 'url' => $url, 'args' => $args);
    return array(
        'response' => array('code' => 201),
        'body' => json_encode(array(
            'session_id' => 'synthetic-session',
            'qr_code_url' => 'openid4vp://?request_uri=https://verifier.example.test/request',
            'expires_at' => gmdate('c', time() + 300),
        )),
    );
}

function wp_safe_remote_get($url, $args = array()) {
    $GLOBALS['dlbr_eid_wc_test_http_requests'][] = array('method' => 'GET', 'url' => $url, 'args' => $args);
    return array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'status' => 'verified',
            'claims' => array('proof-of-age' => array('age_over_18' => true)),
        )),
    );
}

function wp_safe_remote_request($url, $args = array()) {
    $GLOBALS['dlbr_eid_wc_test_http_requests'][] = array('method' => isset($args['method']) ? $args['method'] : 'GET', 'url' => $url, 'args' => $args);
    return array('response' => array('code' => 204), 'body' => '');
}

function wp_send_json_success($data = null) {
    $GLOBALS['dlbr_eid_wc_test_json_response'] = array('success' => true, 'data' => $data);
    throw new RuntimeException('synthetic JSON response');
}

function wp_send_json_error($data = null, $status_code = null) {
    $GLOBALS['dlbr_eid_wc_test_json_response'] = array('success' => false, 'data' => $data, 'status' => $status_code);
    throw new RuntimeException('synthetic JSON response');
}

class DLBR_EID_WC_Test_Session {
    private $data = array();

    public function get($key, $default = false) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set($key, $value) {
        $this->data[$key] = $value;
    }
}

class DLBR_EID_WC_Test_Cart {
    public function is_empty() {
        return false;
    }
}

function WC() {
    return $GLOBALS['dlbr_eid_wc_test_woocommerce'];
}

function add_action() {
    return true;
}

function add_shortcode() {
    return true;
}

require_once dirname(__DIR__) . '/dlbr-eid-woocommerce.php';

/** Fails the smoke run with a useful assertion message. */
function dlbr_eid_wc_check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$class = new ReflectionClass('DLBR_EID_WooCommerce_Age_Verification');
$plugin = $class->newInstanceWithoutConstructor();
$has_true_age_claim = $class->getMethod('has_true_age_claim');
$disclosed_checkout_claim = $class->getMethod('disclosed_checkout_claim');
$gateway_url = $class->getMethod('gateway_url');
$sanitize_verifier_url = $class->getMethod('sanitize_verifier_url');
$is_configured = $class->getMethod('is_configured');
$ajax_start_session = $class->getMethod('ajax_start_session');
$ajax_poll_session = $class->getMethod('ajax_poll_session');
if (PHP_VERSION_ID < 80100) {
    $has_true_age_claim->setAccessible(true);
    $disclosed_checkout_claim->setAccessible(true);
    $gateway_url->setAccessible(true);
    $sanitize_verifier_url->setAccessible(true);
    $is_configured->setAccessible(true);
    $ajax_start_session->setAccessible(true);
    $ajax_poll_session->setAccessible(true);
}

$option_name = 'dlbr_eid_woocommerce_settings';
$GLOBALS['dlbr_eid_wc_test_options'][$option_name] = array(
    'mode' => 'test',
    'api_key' => 'sk_test_synthetic',
    'age_issuer_id' => 'synthetic-issuer',
);
dlbr_eid_wc_check(
    'https://api.dlbr.app' === $gateway_url->invoke($plugin),
    'uses the canonical API host when the custom verifier URL is empty'
);
dlbr_eid_wc_check(
    true === $is_configured->invoke($plugin),
    'accepts a Test key with the sk_test_ prefix'
);
$GLOBALS['dlbr_eid_wc_test_options'][$option_name]['api_key'] = 'sk_live_synthetic';
dlbr_eid_wc_check(
    false === $is_configured->invoke($plugin),
    'rejects a Live key while Test mode is selected'
);
$GLOBALS['dlbr_eid_wc_test_options'][$option_name] = array(
    'mode' => 'live',
    'api_key' => 'sk_live_synthetic',
    'age_issuer_id' => 'synthetic-issuer',
);
dlbr_eid_wc_check(
    true === $is_configured->invoke($plugin),
    'accepts a Live key with the sk_live_ prefix'
);

$GLOBALS['dlbr_eid_wc_test_options'][$option_name] = array(
    'mode' => 'test',
    'verifier_url' => 'https://verifier.example.test/base/',
    'api_key' => 'custom-bearer-token',
    'age_issuer_id' => 'synthetic-issuer',
);
dlbr_eid_wc_check(
    'https://verifier.example.test/base' === $gateway_url->invoke($plugin),
    'uses and normalizes the configured verifier base URL'
);
dlbr_eid_wc_check(
    true === $is_configured->invoke($plugin),
    'accepts a non-DLBR API key when a valid custom verifier URL is set'
);
dlbr_eid_wc_check(
    false !== $sanitize_verifier_url->invoke($plugin, 'https://verifier.example.test/path'),
    'accepts a valid HTTPS verifier URL'
);
foreach (array(
    'http://verifier.example.test',
    'https://user:pass@verifier.example.test',
    'https://verifier.example.test?target=internal',
    'https://verifier.example.test/#fragment',
    'https://127.0.0.1:8080',
) as $invalid_verifier_url) {
    dlbr_eid_wc_check(
        false === $sanitize_verifier_url->invoke($plugin, $invalid_verifier_url),
        'rejects an unsafe verifier URL'
    );
}
$GLOBALS['dlbr_eid_wc_test_options'][$option_name]['verifier_url'] = 'http://invalid.example.test';
dlbr_eid_wc_check(
    '' === $gateway_url->invoke($plugin),
    'does not silently fall back to the DLBR Gateway for an invalid custom URL'
);
dlbr_eid_wc_check(
    false === $is_configured->invoke($plugin),
    'keeps age verification unconfigured for an invalid custom URL'
);
$GLOBALS['dlbr_eid_wc_test_options'][$option_name]['verifier_url'] = '';
$GLOBALS['dlbr_eid_wc_test_options'][$option_name]['api_key'] = 'custom-bearer-token';
dlbr_eid_wc_check(
    false === $is_configured->invoke($plugin),
    'still enforces DLBR key prefixes when the custom verifier URL is empty'
);

$GLOBALS['dlbr_eid_wc_test_options'][$option_name] = array(
    'mode' => 'test',
    'verifier_url' => 'https://verifier.example.test/base/',
    'api_key' => 'custom-bearer-token',
    'age_issuer_id' => 'synthetic-issuer',
    'all_products' => 1,
);
$GLOBALS['dlbr_eid_wc_test_http_requests'] = array();
$GLOBALS['dlbr_eid_wc_test_json_response'] = null;
$GLOBALS['dlbr_eid_wc_test_woocommerce'] = (object) array(
    'cart' => new DLBR_EID_WC_Test_Cart(),
    'session' => new DLBR_EID_WC_Test_Session(),
);
$_POST = array();
try {
    $ajax_start_session->invoke($plugin);
} catch (RuntimeException $exception) {
    // The JSON response stub stops the AJAX method at its normal response point.
}
$post_request = isset($GLOBALS['dlbr_eid_wc_test_http_requests'][0]) ? $GLOBALS['dlbr_eid_wc_test_http_requests'][0] : array();
dlbr_eid_wc_check(
    isset($post_request['url']) && 'https://verifier.example.test/base/v1/sessions' === $post_request['url'],
    'sends session creation to the configured verifier endpoint'
);
dlbr_eid_wc_check(
    isset($post_request['args']['headers']['Authorization']) && 'Bearer custom-bearer-token' === $post_request['args']['headers']['Authorization'],
    'sends the custom verifier bearer key server-side'
);
dlbr_eid_wc_check(
    isset($post_request['args']['redirection']) && 0 === $post_request['args']['redirection'],
    'disables redirects for the session creation request'
);
dlbr_eid_wc_check(
    true === $GLOBALS['dlbr_eid_wc_test_json_response']['success'] && 'PENDING' === $GLOBALS['dlbr_eid_wc_test_json_response']['data']['status'],
    'returns the created verifier session as pending'
);

$GLOBALS['dlbr_eid_wc_test_json_response'] = null;
try {
    $ajax_poll_session->invoke($plugin);
} catch (RuntimeException $exception) {
    // The JSON response stub stops the AJAX method at its normal response point.
}
$get_request = isset($GLOBALS['dlbr_eid_wc_test_http_requests'][1]) ? $GLOBALS['dlbr_eid_wc_test_http_requests'][1] : array();
$delete_request = isset($GLOBALS['dlbr_eid_wc_test_http_requests'][2]) ? $GLOBALS['dlbr_eid_wc_test_http_requests'][2] : array();
dlbr_eid_wc_check(
    isset($get_request['url']) && 'https://verifier.example.test/base/v1/sessions/synthetic-session' === $get_request['url'],
    'polls the configured verifier session URL'
);
dlbr_eid_wc_check(
    isset($get_request['args']['headers']['Authorization']) && 'Bearer custom-bearer-token' === $get_request['args']['headers']['Authorization'],
    'authenticates the poll request with the custom verifier key'
);
dlbr_eid_wc_check(
    isset($get_request['args']['redirection']) && 0 === $get_request['args']['redirection'],
    'disables redirects for the poll request'
);
dlbr_eid_wc_check(
    isset($delete_request['method'], $delete_request['url']) && 'DELETE' === $delete_request['method'] && 'https://verifier.example.test/base/v1/sessions/synthetic-session' === $delete_request['url'],
    'deletes the consumed session at the configured verifier endpoint'
);
dlbr_eid_wc_check(
    isset($delete_request['args']['headers']['Authorization']) && 'Bearer custom-bearer-token' === $delete_request['args']['headers']['Authorization'],
    'authenticates session deletion with the custom verifier key'
);
dlbr_eid_wc_check(
    isset($delete_request['args']['redirection']) && 0 === $delete_request['args']['redirection'],
    'disables redirects for session deletion'
);
dlbr_eid_wc_check(
    true === $GLOBALS['dlbr_eid_wc_test_json_response']['success'] && 'VERIFIED' === $GLOBALS['dlbr_eid_wc_test_json_response']['data']['status'],
    'accepts the mock verifier exact-true age response'
);

$accepted_age_claims = array(
    array('proof-of-age' => array('age_over_18' => true)),
    array('proof-of-age' => array('eu.europa.ec.av.1' => array('age_over_18' => true))),
    array('age-over-18' => array('is_over_18' => true)),
    array('age-over-18-mdoc' => array('eu.europa.ec.av.1' => array('age_over_18' => true))),
);
foreach ($accepted_age_claims as $claims) {
    dlbr_eid_wc_check(
        true === $has_true_age_claim->invoke($plugin, $claims),
        'accepts an exact true age claim in a supported descriptor shape'
    );
}

$rejected_age_claims = array(
    array('proof-of-age' => array('age_over_18' => false)),
    array('proof-of-age' => array('age_over_18' => 1)),
    array('proof-of-age' => array('age_over_18' => 'true')),
    array('unrequested-descriptor' => array('age_over_18' => true)),
    'malformed claims',
);
foreach ($rejected_age_claims as $claims) {
    dlbr_eid_wc_check(
        false === $has_true_age_claim->invoke($plugin, $claims),
        'rejects non-boolean, malformed, or unrequested age claims'
    );
}

$pid_claims = array(
    'eudi-pid-profile' => array(
        'given_name' => 'Ada',
        'eu.europa.ec.eudi.pid.1' => array('family_name' => 'Lovelace'),
    ),
    'unrequested-profile' => array('email_address' => 'ada@example.test'),
);
dlbr_eid_wc_check(
    'Ada' === $disclosed_checkout_claim->invoke($plugin, $pid_claims, 'given_name'),
    'reads a requested PID claim from the descriptor result'
);
dlbr_eid_wc_check(
    'Lovelace' === $disclosed_checkout_claim->invoke($plugin, $pid_claims, 'family_name'),
    'reads a requested PID claim from its mDOC namespace'
);
dlbr_eid_wc_check(
    null === $disclosed_checkout_claim->invoke($plugin, $pid_claims, 'email_address'),
    'does not read claims from an unrequested credential descriptor'
);

fwrite(STDOUT, "WooCommerce smoke tests passed (38 checks).\n");
