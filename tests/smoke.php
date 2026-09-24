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
$has_matching_business_claims = $class->getMethod('has_matching_business_claims');
if (PHP_VERSION_ID < 80100) {
    $has_true_age_claim->setAccessible(true);
    $disclosed_checkout_claim->setAccessible(true);
    $has_matching_business_claims->setAccessible(true);
}

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

$business_verification_details = array(
    'eu-company-certificate' => array('claim_subject_binding' => array('status' => 'PASSED')),
    'signatory-rights' => array('claim_subject_binding' => array('status' => 'PASSED')),
);
dlbr_eid_wc_check(
    true === $has_matching_business_claims->invoke(
        $plugin,
        array(
            'eu-company-certificate' => array('legal_person' => array('legal_person_id' => 'EUID-DE-123')),
            'signatory-rights' => array('legal_person_id' => 'EUID-DE-123'),
        ),
        $business_verification_details
    ),
    'accepts matching EWC company IDs across the nested EUCC and flat Signatory Rights paths'
);
dlbr_eid_wc_check(
    false === $has_matching_business_claims->invoke(
        $plugin,
        array(
            'eu-company-certificate' => array('legal_person' => array('legal_person_id' => 'EUID-DE-123')),
            'signatory-rights' => array('legal_person_id' => 'EUID-DE-999'),
        ),
        $business_verification_details
    ),
    'rejects EWC credentials that identify different companies'
);
$failed_business_details = $business_verification_details;
$failed_business_details['eu-company-certificate']['claim_subject_binding']['status'] = 'FAILED';
dlbr_eid_wc_check(
    false === $has_matching_business_claims->invoke(
        $plugin,
        array(
            'eu-company-certificate' => array('legal_person' => array('legal_person_id' => 'EUID-DE-123')),
            'signatory-rights' => array('legal_person_id' => 'EUID-DE-123'),
        ),
        $failed_business_details
    ),
    'rejects company credentials without a Gateway-passed same-company binding'
);
dlbr_eid_wc_check(
    false === $has_matching_business_claims->invoke(
        $plugin,
        array(
            'eu-company-certificate' => array('legal_person' => array('legal_person_id' => 'EUID-DE-123')),
            'signatory-rights' => array('legal_person_id' => 'EUID-DE-123'),
        ),
        array()
    ),
    'rejects a missing same-company verification result'
);

fwrite(STDOUT, "WooCommerce claim smoke tests passed (16 checks).\n");
