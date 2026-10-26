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
$is_configured = $class->getMethod('is_configured');
if (PHP_VERSION_ID < 80100) {
    $has_true_age_claim->setAccessible(true);
    $disclosed_checkout_claim->setAccessible(true);
    $gateway_url->setAccessible(true);
    $is_configured->setAccessible(true);
}

$option_name = 'dlbr_eid_woocommerce_settings';
$GLOBALS['dlbr_eid_wc_test_options'][$option_name] = array(
    'mode' => 'test',
    'api_key' => 'sk_test_synthetic',
    'age_issuer_id' => 'synthetic-issuer',
);
dlbr_eid_wc_check(
    'https://api.dlbr.app' === $gateway_url->invoke($plugin),
    'uses the canonical API host in Test mode'
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

fwrite(STDOUT, "WooCommerce claim smoke tests passed (16 checks).\n");
