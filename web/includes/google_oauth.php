<?php

$oauthConfig = __DIR__ . '/../google_oauth_config.php';
if (is_file($oauthConfig)) require_once $oauthConfig;

const GOOGLE_OAUTH_SCOPE = 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/documents';

function googleOAuthConfigured(): bool {
    return defined('GOOGLE_OAUTH_CLIENT_ID')
        && defined('GOOGLE_OAUTH_CLIENT_SECRET')
        && GOOGLE_OAUTH_CLIENT_ID !== 'your-client-id.apps.googleusercontent.com'
        && GOOGLE_OAUTH_CLIENT_SECRET !== '';
}

function googleOAuthRedirectUri(): string {
    return defined('GOOGLE_OAUTH_REDIRECT_URI')
        ? GOOGLE_OAUTH_REDIRECT_URI
        : '';
}

function googleOAuthTokenPath(string $osaId): string {
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $osaId);
    $dir = __DIR__ . '/../storage/google_oauth';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . $safeId . '.json';
}

function googleOAuthAuthorizationUrl(string $osaId): ?string {
    if (!googleOAuthConfigured() || googleOAuthRedirectUri() === '') return null;
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(24));
    $_SESSION['google_oauth_osa_id'] = $osaId;
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_OAUTH_CLIENT_ID,
        'redirect_uri' => googleOAuthRedirectUri(),
        'response_type' => 'code',
        'scope' => GOOGLE_OAUTH_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $_SESSION['google_oauth_state'],
    ]);
}

function googleOAuthExchangeCode(string $code): bool {
    if (!googleOAuthConfigured() || empty($_SESSION['google_oauth_osa_id'])) return false;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'redirect_uri' => googleOAuthRedirectUri(),
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $token = json_decode((string) $raw, true);
    if ($status !== 200 || empty($token['refresh_token'])) return false;
    file_put_contents(
        googleOAuthTokenPath($_SESSION['google_oauth_osa_id']),
        json_encode(['refresh_token' => $token['refresh_token']]),
        LOCK_EX
    );
    return true;
}

function googleOAuthAccessToken(string $osaId): ?string {
    if (!googleOAuthConfigured()) return null;
    $path = googleOAuthTokenPath($osaId);
    $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (empty($stored['refresh_token'])) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
            'refresh_token' => $stored['refresh_token'],
            'grant_type' => 'refresh_token',
        ]),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $token = json_decode((string) $raw, true);
    return $status === 200 ? ($token['access_token'] ?? null) : null;
}

function googleOAuthConnected(string $osaId): bool {
    return is_file(googleOAuthTokenPath($osaId));
}
