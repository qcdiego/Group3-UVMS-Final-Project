<?php
/* ============================================
   UVMS — Google Sheets sync
   Pushes the full student directory to one tab of a Google
   Sheet living on Drive, so OSA always has a clean, shareable
   copy outside the app. One-way (app -> Sheet, on demand) —
   the tab is overwritten each time it runs, so don't hand-edit
   it and expect changes to stick.
   ============================================ */

require_once __DIR__ . '/google_client.php';
require_once __DIR__ . '/google_drive.php';

const GOOGLE_SHEETS_SCOPE = ['https://www.googleapis.com/auth/spreadsheets'];

// Overwrites the configured Sheet tab with the current student table.
// Returns ['ok' => bool, 'message' => string] — message is safe to flash.
function syncStudentsToSheet(PDO $pdo, ?string $osaId = null): array {
    if (!googleIsConfigured()) {
        return ['ok' => false, 'message' => "Google isn't set up yet — see SETUP_GOOGLE.md."];
    }

    $students = getAllStudents($pdo);

    $driveOwner = $osaId ?: googleDriveOwnerOsaId();
    $driveSync = $driveOwner
        ? syncStudentViolationDocuments($pdo, $driveOwner)
        : ['ok' => false, 'message' => 'OSA Google account is not connected.'];
    if (!$driveSync['ok']) return $driveSync;
    $driveLinks = $driveSync['links'];

    $header = ['Student Number', 'Last Name', 'First Name', 'Email', 'Program', 'Year Level', 'Status', 'Google Drive Violations'];
    $values = [$header];
    foreach ($students as $s) {
        $values[] = [
            $s['student_number'],
            $s['last_name'],
            $s['first_name'],
            $s['email'] ?? '',
            $s['program'] ?? '',
            $s['year_level'] ?? '',
            $s['status'] ?? '',
            $driveLinks[$s['student_number']] ?? '',
        ];
    }

    $base = 'https://sheets.googleapis.com/v4/spreadsheets/' . GOOGLE_SHEET_ID . '/values/';

    // Clear a generous range first so a shrinking student list doesn't
    // leave stale rows behind, then write the fresh values from A1.
    $clearRange = rawurlencode(GOOGLE_SHEET_TAB . '!A1:H' . (count($values) + 200));
    $clear = googleApiCall('POST', $base . $clearRange . ':clear', GOOGLE_SHEETS_SCOPE, []);
    if (!$clear['ok']) {
        return ['ok' => false, 'message' => 'Could not clear the Sheet: ' . $clear['error']];
    }

    $writeRange = rawurlencode(GOOGLE_SHEET_TAB . '!A1');
    $write = googleApiCall(
        'PUT',
        $base . $writeRange . '?valueInputOption=RAW',
        GOOGLE_SHEETS_SCOPE,
        ['values' => $values]
    );
    if (!$write['ok']) {
        return ['ok' => false, 'message' => 'Could not write to the Sheet: ' . $write['error']];
    }

    $count = count($students);
    return ['ok' => true, 'message' => "$count student" . ($count === 1 ? '' : 's') . ' synced to the Google Sheet.'];
}

// Keeps the app's database authoritative while refreshing the external copy
// whenever a relevant record changes. Google failures are logged so they do
// not interrupt account or violation workflows.
function autoSyncStudentsToSheet(PDO $pdo): void {
    if (!googleIsConfigured()) return;

    $result = syncStudentsToSheet($pdo);
    if (!$result['ok']) {
        error_log('UVMS automatic Google Sheet sync failed: ' . $result['message']);
    }
}

function googleSheetUrl(): string {
    return 'https://docs.google.com/spreadsheets/d/' . GOOGLE_SHEET_ID . '/edit';
}
