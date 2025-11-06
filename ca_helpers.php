<?php
defined('MOODLE_INTERNAL') || die();

// Certificates
define('SPE_CA_PUB_B64',         'iuEnM_VNd8OJn9Mxcz4aEp97gIJMAG41nk2CWxj0XhU');
define('SPE_CLIENT_PRIV_B64',    'cSnSlDfvltCuuFqVh80X_0nWwFKLM66rUXIaiIZNqN8');
define('SPE_CLIENT_CERT_JSON',   '{"id":"spe-plugin","pubkey":"NVoaif7KOv-TJ3Flj4vy5gxlYaH2pjS0xrCNlu-qU-I","exp":1793700137,"iss":"SPE-CA"}');
define('SPE_CLIENT_CERT_SIG',    'riqgxsBDCRLtFL4VbWNiS-S0rczoRWsKCdDyUoZuL5GT5DfCGdkNngOAEJdZJpzx-Qa2DZFx6d0FBo21uMCnBQ');
define('SPE_SERVER_CERT_JSON',   '{"id":"spe-api","pubkey":"avOww2r53iLjDacbHGoybcqO10eHw4MUOOaTapiCePA","exp":1793700137,"iss":"SPE-CA"}');
define('SPE_SERVER_CERT_SIG',    '0f75Xhupt6gQYc_mULQHK8JdoUYLxm6wt_u01NYMg726E8-eB4kU2SSGejnwFcDmi8DCMKIgCxJTAlhfGHkACQ');

// Helpers 
function spe_b64u_decode($s) {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'));
}
function spe_b64u_encode($b) {
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}
function spe_lower_header_keys(array $headers): array {
    $norm = [];
    foreach ($headers as $k => $v) {
        $norm[strtolower($k)] = $v;
    }
    return $norm;
}
function spe_normalize_path($path) {
    return $path === '/' ? '/' : rtrim($path, '/');
}

// Client request headers
function spe_ca_build_request_headers(string $path, string $body): array {
    $path = spe_normalize_path($path);

    $cert = json_decode(SPE_CLIENT_CERT_JSON, true);
    if (!$cert || empty($cert['pubkey'])) {
        throw new moodle_exception('Malformed client certificate JSON.');
    }
    if (($cert['exp'] ?? 0) < time()) {
        throw new moodle_exception('Client certificate expired.');
    }
    if (($cert['iss'] ?? '') !== 'SPE-CA' || ($cert['id'] ?? '') !== 'spe-plugin') {
        throw new moodle_exception('Unexpected client certificate claims.');
    }

    $priv_seed = spe_b64u_decode(SPE_CLIENT_PRIV_B64);
    if (strlen($priv_seed) !== 32) {
        throw new moodle_exception('Invalid client private seed length.');
    }
    $pub_raw = spe_b64u_decode($cert['pubkey']);
    if (strlen($pub_raw) !== 32) {
        throw new moodle_exception('Invalid client public key length.');
    }
    $secret_key = $priv_seed . $pub_raw;

    $msg = $path . "\n" . $body;
    $sig = sodium_crypto_sign_detached($msg, $secret_key);

    return [
        'X-SPE-Client-Cert'    => SPE_CLIENT_CERT_JSON,
        'X-SPE-Client-CertSig' => SPE_CLIENT_CERT_SIG,
        'X-SPE-Client-Sig'     => spe_b64u_encode($sig),
    ];
}

// Verify server response
function spe_ca_verify_server_response(string $path, string $body, array $headers): void {
    $path    = spe_normalize_path($path);
    $headers = spe_lower_header_keys($headers);

    $cert_json = $headers['x-spe-server-cert'][0]    ?? $headers['x-spe-server-cert']    ?? '';
    $cert_sig  = $headers['x-spe-server-certsig'][0] ?? $headers['x-spe-server-certsig'] ?? '';
    $srv_sig   = $headers['x-spe-server-sig'][0]     ?? $headers['x-spe-server-sig']     ?? '';

    if (!$cert_json || !$cert_sig || !$srv_sig) {
        throw new moodle_exception('Missing server CA headers.');
    }

    // Verify server certificate signature with CA public key
    $ok = sodium_crypto_sign_verify_detached(
        spe_b64u_decode($cert_sig),
        $cert_json,
        spe_b64u_decode(SPE_CA_PUB_B64)
    );
    if (!$ok) {
        throw new moodle_exception('Invalid server certificate signature.');
    }

    $cert = json_decode($cert_json, true);
    if (!$cert || !isset($cert['pubkey'])) {
        throw new moodle_exception('Malformed server certificate.');
    }
    if (($cert['exp'] ?? 0) < time()) {
        throw new moodle_exception('Server certificate expired.');
    }
    if (($cert['iss'] ?? '') !== 'SPE-CA') {
        throw new moodle_exception('Unexpected server certificate issuer.');
    }
    if (($cert['id'] ?? '') !== 'spe-api') {
        throw new moodle_exception('Unexpected server certificate id.');
    }

    // Verify response signature 
    $msg = $path . "\n" . $body;
    $ok = sodium_crypto_sign_verify_detached(
        spe_b64u_decode($srv_sig),
        $msg,
        spe_b64u_decode($cert['pubkey'])
    );
    if (!$ok) {
        throw new moodle_exception('Invalid server response signature.');
    }
}
