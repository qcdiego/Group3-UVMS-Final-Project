<?php
/* ============================================
   UVMS — Google Calendar sync
   Lets OSA put a "fix / clear this case by" date on a violation.
   Each case gets at most one calendar event — setting/updating
   the date PATCHes the existing event instead of creating a
   duplicate, and removing the deadline (or clearing the case)
   deletes it from the calendar.
   ============================================ */

require_once __DIR__ . '/google_client.php';
require_once __DIR__ . '/google_oauth.php';

const GOOGLE_CALENDAR_SCOPE = ['https://www.googleapis.com/auth/calendar'];

function googleCalendarEventsUrl(?string $eventId = null): string {
    $base = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    return $eventId ? $base . '/' . rawurlencode($eventId) : $base;
}

// Creates a new all-day deadline event, or updates the existing one for
// this case if $existingEventId is given. $dueDate must be 'YYYY-MM-DD'.
// Returns ['ok'=>bool, 'event_id'=>?string, 'event_link'=>?string, 'message'=>string].
function upsertCaseDeadlineEvent(
    string $caseId,
    string $studentName,
    string $violationName,
    string $dueDate,
    ?string $existingEventId
): array {
    $osaId = getSession()['id'] ?? '';
    if (!$osaId || !googleOAuthConnected($osaId)) {
        return ['ok' => false, 'event_id' => null, 'event_link' => null, 'message' => 'Connect the OSA Google Calendar first.'];
    }

    // All-day events use an exclusive end date, so end = start + 1 day.
    $end = (new DateTime($dueDate))->modify('+1 day')->format('Y-m-d');

    $body = [
        'summary'     => "Case $caseId — fix/clear deadline",
        'description' => "Student: $studentName\nViolation: $violationName\nCase: $caseId\n\nSet from UVMS by OSA.",
        'start'       => ['date' => $dueDate],
        'end'         => ['date' => $end],
    ];

    $res = $existingEventId
        ? googleOAuthCalendarCall($osaId, 'PATCH', googleCalendarEventsUrl($existingEventId), $body)
        : googleOAuthCalendarCall($osaId, 'POST', googleCalendarEventsUrl(), $body);

    // If the stored event was deleted on the Calendar side out-of-band,
    // Google returns 404/410 on PATCH — fall back to creating a fresh one.
    if (!$res['ok'] && $existingEventId && in_array($res['status'], [404, 410], true)) {
        $res = googleOAuthCalendarCall($osaId, 'POST', googleCalendarEventsUrl(), $body);
    }

    if (!$res['ok']) {
        return ['ok' => false, 'event_id' => null, 'event_link' => null, 'message' => 'Could not save the deadline to Google Calendar: ' . $res['error']];
    }

    return [
        'ok'         => true,
        'event_id'   => $res['body']['id'] ?? $existingEventId,
        'event_link' => $res['body']['htmlLink'] ?? null,
        'message'    => 'Deadline added to your Google Calendar primary calendar.',
    ];
}

// Best-effort delete — failures are logged but never block the caller
// (e.g. clearing a case should still succeed even if Calendar is down).
function deleteCaseDeadlineEvent(string $eventId): bool {
    $osaId = getSession()['id'] ?? '';
    if (!$osaId || !googleOAuthConnected($osaId)) return false;
    $res = googleOAuthCalendarCall($osaId, 'DELETE', googleCalendarEventsUrl($eventId), null);
    return $res['ok'] || $res['status'] === 410 || $res['status'] === 404; // already gone counts as success
}

function googleOAuthCalendarCall(string $osaId, string $method, string $url, ?array $body): array {
    $token = googleOAuthAccessToken($osaId);
    if (!$token) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'OSA Google Calendar authorization expired.'];
    $headers = ['Authorization: Bearer ' . $token];
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'body' => $decoded, 'error' => $decoded['error']['message'] ?? $raw];
    }
    return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null];
}
