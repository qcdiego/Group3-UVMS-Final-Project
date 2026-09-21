<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/google_oauth.php';

$session = requireRole('osa');
$stateValid = !empty($_GET['state'])
    && hash_equals($_SESSION['google_oauth_state'] ?? '', $_GET['state']);
$osaId = $_SESSION['google_oauth_osa_id'] ?? $session['id'];

if (!$stateValid || $osaId !== $session['id'] || empty($_GET['code']) || !googleOAuthExchangeCode($_GET['code'])) {
    $_SESSION['flash'] = 'Google Calendar could not be connected. Please try again.';
} else {
    $_SESSION['flash'] = 'Your Google Calendar is connected. New deadlines will be added to your primary calendar.';
}

unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_osa_id']);
header('Location: osa.php?view=review');
exit;