<?php
/* ============================================
   UVMS — Google API client (Sheets + Calendar)
   Talks to Google's REST APIs directly using a service-account
   JWT — no Composer / google/apiclient needed, same philosophy
   as the bundled PHPMailer library for email.

   Failures here are LOGGED and returned as ['ok' => false, ...],
   never fatal — a bad key file or a network hiccup should never
   break the OSA dashboard.
   ============================================ */

require_once __DIR__ . '/../google_config.php';

// True once google_config.php points at a real key file and the
// Sheet ID has been filled in. Calendar uses per-user OAuth instead.
function googleIsConfigured(): bool {
    return GOOGLE_SERVICE_ACCOUNT_FILE !== ''
        && is_file(GOOGLE_SERVICE_ACCOUNT_FILE)
    && GOOGLE_SHEET_ID !== 'your-spreadsheet-id-here';
}

function googleTokenCachePath(): string {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir . '/google_token_cache.json';
}

// Base64url encode, no padding — what JWTs use everywhere.
function googleB64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Mints (or reuses a cached) OAuth2 access token for the given scopes,
// authenticating as the service account in GOOGLE_SERVICE_ACCOUNT_FILE.
// Returns null (and logs why) on any failure.
function getGoogleAccessToken(array $scopes): ?string {
    if (!googleIsConfigured()) {
        error_log('UVMS google: skipped — google_config.php / service account key not set up yet.');
        return null;
    }

    $scopeKey  = md5(implode(' ', $scopes));
    $cacheFile = googleTokenCachePath();
    $cache     = [];
    if (is_file($cacheFile)) {
        $cache = json_decode((string) file_get_contents($cacheFile), true) ?: [];
        if (($cache[$scopeKey]['expires_at'] ?? 0) > time() + 60) {
            return $cache[$scopeKey]['access_token'];
        }
    }

    $key = json_decode((string) file_get_contents(GOOGLE_SERVICE_ACCOUNT_FILE), true);
    if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
        error_log('UVMS google: service account JSON is missing or malformed.');
        return null;
    }

    $now       = time();
    $tokenUri  = $key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $header    = googleB64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload   = googleB64Url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => implode(' ', $scopes),
        'aud'   => $tokenUri,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signature = '';
    $signed = openssl_sign("$header.$payload", $signature, $key['private_key'], 'sha256WithRSAEncryption');
    if (!$signed) {
        error_log('UVMS google: failed to sign JWT — check the private_key in the service account file.');
        return null;
    }
    $jwt = "$header.$payload." . googleB64Url($signature);

    $ch = curl_init($tokenUri);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res = json_decode((string) $raw, true);
    if ($status !== 200 || empty($res['access_token'])) {
        error_log('UVMS google: token request failed (' . $status . ') — ' . $raw);
        return null;
    }

    $cache[$scopeKey] = [
        'access_token' => $res['access_token'],
        'expires_at'   => $now + (int) ($res['expires_in'] ?? 3600),
    ];
    @file_put_contents($cacheFile, json_encode($cache));

    return $res['access_token'];
}

// Generic authenticated REST call against a Google API.
// $jsonBody: null for a plain GET/DELETE, [] for an empty JSON object
// body (some endpoints like values:clear require one), or an array
// that gets JSON-encoded as the request body otherwise.
// Returns ['ok'=>bool, 'status'=>int, 'body'=>decoded-json, 'error'=>?string].
function googleApiCall(string $method, string $url, array $scopes, ?array $jsonBody = null): array {
    $token = getGoogleAccessToken($scopes);
    if (!$token) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => "Not authenticated with Google — see SETUP_GOOGLE.md."];
    }

    $headers = ['Authorization: Bearer ' . $token];
    $opts = [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = $jsonBody === [] ? '{}' : json_encode($jsonBody);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log('UVMS google: curl error — ' . $err);
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $err];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = $body['error']['message'] ?? $raw;
        error_log("UVMS google: $method $url failed ($status) — $msg");
        return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => $msg];
    }
    return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => null];
}
