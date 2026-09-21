<?php
/* ============================================
   UVMS — Google Calendar integration
   Creates/updates a hearing event on OSA's shared calendar for a
   case. Failures are LOGGED, never fatal — same policy as
   mailer.php: a broken Google connection should never block OSA
   from doing their job in the app.
   ============================================ */

require_once __DIR__ . '/google_client.php';

/**
 * Creates a new hearing event for a case, or updates the existing
 * one if this case already has a calendar_event_id. Returns
 * ['event_id' => ..., 'link' => ...] on success, null on failure
 * (including when Google isn't configured yet).
 */
function scheduleHearing(array $case, array $student, array $type, string $startDateTime, int $durationMinutes = 30): ?array {
    if (!googleIsConfigured()) {
        error_log('[calendar] scheduleHearing skipped — google_config.php not filled in yet.');
        return null;
    }

    try {
        $service = getCalendarService();

        $start = new DateTime($startDateTime, new DateTimeZone('Asia/Manila'));
        $end   = (clone $start)->modify("+{$durationMinutes} minutes");

        $studentName = trim($student['first_name'] . ' ' . $student['last_name']);

        $event = new Google_Service_Calendar_Event([
            'summary'     => 'OSA Hearing — ' . $case['case_id'] . ' (' . $studentName . ')',
            'description' =>
                "Case: {$case['case_id']}\n" .
                "Violation: {$type['name']} ({$type['severity']})\n" .
                "Student: {$studentName} ({$student['student_number']})\n" .
                "Program: " . ($student['program'] ?? '—') . ' — ' . ($student['year_level'] ?? '—'),
            'location' => OSA_OFFICE_ADDRESS,
            'start'    => ['dateTime' => $start->format(DateTime::RFC3339), 'timeZone' => 'Asia/Manila'],
            'end'      => ['dateTime' => $end->format(DateTime::RFC3339), 'timeZone' => 'Asia/Manila'],
        ]);

        $existingId = $case['calendar_event_id'] ?? null;
        $result = $existingId
            ? $service->events->update(GOOGLE_CALENDAR_ID, $existingId, $event)
            : $service->events->insert(GOOGLE_CALENDAR_ID, $event);

        return ['event_id' => $result->getId(), 'link' => $result->getHtmlLink()];
    } catch (\Throwable $e) {
        error_log('[calendar] scheduleHearing failed: ' . $e->getMessage());
        return null;
    }
}

