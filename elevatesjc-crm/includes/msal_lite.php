<?php
/**
 * Minimal Microsoft identity platform (Entra ID) OAuth2 client — no
 * Composer/MSAL dependency, just cURL + openssl. Implements the
 * authorization-code flow and verifies the returned ID token's RS256
 * signature against Microsoft's published JWKS before trusting any claim.
 */
require_once __DIR__ . '/../config.php';

class MsAuthException extends Exception {}

function ms_login_enabled(): bool
{
    return MS_CLIENT_ID !== '' && MS_CLIENT_SECRET !== '';
}

function ms_authorize_url(string $state, string $nonce): string
{
    $params = [
        'client_id' => MS_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => MS_REDIRECT_URI,
        'response_mode' => 'query',
        'scope' => 'openid profile email',
        'state' => $state,
        'nonce' => $nonce,
    ];
    return 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID)
        . '/oauth2/v2.0/authorize?' . http_build_query($params);
}

/** Exchange an authorization code for tokens. Throws MsAuthException on failure. */
function ms_exchange_code(string $code): array
{
    $endpoint = 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID) . '/oauth2/v2.0/token';
    $post = http_build_query([
        'client_id' => MS_CLIENT_ID,
        'client_secret' => MS_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => MS_REDIRECT_URI,
        'scope' => 'openid profile email',
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new MsAuthException('Token request failed: ' . $curlErr);
    }
    $data = json_decode($body, true);
    if ($status !== 200 || !is_array($data) || empty($data['id_token'])) {
        $desc = is_array($data) ? ($data['error_description'] ?? $body) : $body;
        throw new MsAuthException('Token exchange failed: ' . $desc);
    }
    return $data;
}

function ms_b64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/** Build a PEM public key from a JWK's RSA modulus/exponent (both base64url). */
function ms_jwk_to_pem(string $nB64url, string $eB64url): string
{
    $asn1Length = function (int $len): string {
        if ($len < 128) {
            return chr($len);
        }
        $tmp = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($tmp)) . $tmp;
    };
    $asn1Integer = function (string $bytes) use ($asn1Length): string {
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $asn1Length(strlen($bytes)) . $bytes;
    };
    $asn1Sequence = function (string $der) use ($asn1Length): string {
        return "\x30" . $asn1Length(strlen($der)) . $der;
    };
    $asn1Bitstring = function (string $der) use ($asn1Length): string {
        return "\x03" . $asn1Length(strlen($der) + 1) . "\x00" . $der;
    };

    $modulus = ms_b64url_decode($nB64url);
    $exponent = ms_b64url_decode($eB64url);
    $rsaPublicKey = $asn1Sequence($asn1Integer($modulus) . $asn1Integer($exponent));
    $rsaOid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // SEQUENCE{OID rsaEncryption, NULL}
    $publicKeyInfo = $asn1Sequence($rsaOid . $asn1Bitstring($rsaPublicKey));

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKeyInfo), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Fetch (and lightly cache in APCu/tmp when available) Microsoft's signing keys. */
function ms_fetch_jwks(): array
{
    $cacheFile = sys_get_temp_dir() . '/elevatesjc_ms_jwks.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID) . '/discovery/v2.0/keys';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        // fall back to a stale cache rather than hard-failing on a transient network blip
        if (is_file($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        throw new MsAuthException('Could not fetch Microsoft signing keys.');
    }

    $jwks = json_decode($body, true);
    if (!is_array($jwks) || empty($jwks['keys'])) {
        throw new MsAuthException('Malformed JWKS response from Microsoft.');
    }
    @file_put_contents($cacheFile, $body);
    return $jwks;
}

/**
 * Verify an ID token's signature and standard claims, returning the decoded
 * claims array. Throws MsAuthException on any failure — callers must not
 * treat an unverified token as authenticated.
 */
function ms_verify_id_token(string $idToken, string $expectedNonce): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new MsAuthException('Malformed ID token.');
    }
    [$headerB64, $payloadB64, $sigB64] = $parts;

    $header = json_decode(ms_b64url_decode($headerB64), true);
    $claims = json_decode(ms_b64url_decode($payloadB64), true);
    $signature = ms_b64url_decode($sigB64);
    if (!is_array($header) || !is_array($claims)) {
        throw new MsAuthException('Malformed ID token contents.');
    }
    if (($header['alg'] ?? '') !== 'RS256') {
        throw new MsAuthException('Unexpected ID token signing algorithm.');
    }

    $jwks = ms_fetch_jwks();
    $key = null;
    foreach ($jwks['keys'] as $candidate) {
        if (($candidate['kid'] ?? null) === ($header['kid'] ?? null)) {
            $key = $candidate;
            break;
        }
    }
    if (!$key) {
        throw new MsAuthException('Signing key not found for this token.');
    }

    $pem = ms_jwk_to_pem($key['n'], $key['e']);
    $signedInput = $headerB64 . '.' . $payloadB64;
    $verified = openssl_verify($signedInput, $signature, $pem, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        throw new MsAuthException('ID token signature verification failed.');
    }

    // Standard claim checks.
    $now = time();
    if (empty($claims['exp']) || $now >= (int)$claims['exp']) {
        throw new MsAuthException('ID token has expired.');
    }
    if (!empty($claims['nbf']) && $now < (int)$claims['nbf']) {
        throw new MsAuthException('ID token not yet valid.');
    }
    if (($claims['aud'] ?? null) !== MS_CLIENT_ID) {
        throw new MsAuthException('ID token audience mismatch.');
    }
    $iss = (string)($claims['iss'] ?? '');
    if (!str_starts_with($iss, 'https://login.microsoftonline.com/')) {
        throw new MsAuthException('ID token issuer mismatch.');
    }
    if (!hash_equals($expectedNonce, (string)($claims['nonce'] ?? ''))) {
        throw new MsAuthException('ID token nonce mismatch (possible replay).');
    }

    return $claims;
}
