<?php

require_once __DIR__ . '/google_oauth.php';

const GOOGLE_DRIVE_FOLDER_NAME = 'UVMS Student Violations';

function googleDriveOwnerOsaId(): ?string {
    return defined('GOOGLE_DRIVE_OWNER_OSA_ID') && GOOGLE_DRIVE_OWNER_OSA_ID !== ''
        ? GOOGLE_DRIVE_OWNER_OSA_ID
        : null;
}

function googleDriveMetadataPath(string $osaId): string {
    return googleOAuthTokenPath($osaId) . '.drive.json';
}

function googleDriveCall(string $osaId, string $method, string $url, ?array $body = null): array {
    $token = googleOAuthAccessToken($osaId);
    if (!$token) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'OSA Google authorization expired.'];
    $headers = ['Authorization: Bearer ' . $token];
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
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

function googleDriveFolderId(string $osaId): ?string {
    $path = googleDriveMetadataPath($osaId);
    $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!empty($stored['folder_id'])) return $stored['folder_id'];

    $query = rawurlencode("name = '" . GOOGLE_DRIVE_FOLDER_NAME . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
    $res = googleDriveCall($osaId, 'GET', 'https://www.googleapis.com/drive/v3/files?q=' . $query . '&spaces=drive&fields=files(id,name)');
    if (!$res['ok']) return null;
    $folderId = $res['body']['files'][0]['id'] ?? null;
    if (!$folderId) {
        $res = googleDriveCall($osaId, 'POST', 'https://www.googleapis.com/drive/v3/files?fields=id,name', [
            'name' => GOOGLE_DRIVE_FOLDER_NAME,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        if (!$res['ok']) return null;
        $folderId = $res['body']['id'] ?? null;
    }
    if (!$folderId) return null;
    file_put_contents($path, json_encode(['folder_id' => $folderId]), LOCK_EX);
    return $folderId;
}

function googleDriveStudentFolderId(string $osaId, string $parentFolderId, string $studentNumber, string $folderName): ?string {
    $query = rawurlencode("'$parentFolderId' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder' and appProperties has { key='tip_vms_student_folder' and value='" . addslashes($studentNumber) . "' }");
    $url = 'https://www.googleapis.com/drive/v3/files?q=' . $query . '&spaces=drive&fields=files(id,name)';
    $res = googleDriveCall($osaId, 'GET', $url);
    if (!$res['ok']) return null;

    $folderId = $res['body']['files'][0]['id'] ?? null;
    if (!$folderId) {
        $res = googleDriveCall($osaId, 'POST', 'https://www.googleapis.com/drive/v3/files?fields=id,name', [
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentFolderId],
            'appProperties' => ['tip_vms_student_folder' => $studentNumber],
        ]);
        if (!$res['ok']) return null;
        $folderId = $res['body']['id'] ?? null;
    }
    return $folderId ?: null;
}

function googleDriveStudentDocument(string $osaId, string $folderId, string $studentNumber): ?array {
    $query = rawurlencode("'$folderId' in parents and trashed = false and appProperties has { key='tip_vms_student' and value='" . addslashes($studentNumber) . "' }");
    $url = 'https://www.googleapis.com/drive/v3/files?q=' . $query . '&spaces=drive&fields=files(id,name,webViewLink)';
    $res = googleDriveCall($osaId, 'GET', $url);
    return $res['ok'] ? ($res['body']['files'][0] ?? null) : null;
}

function googleDriveMoveStudentDocument(string $osaId, string $documentId, string $oldFolderId, string $newFolderId): ?array {
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($documentId)
        . '?addParents=' . rawurlencode($newFolderId)
        . '&removeParents=' . rawurlencode($oldFolderId)
        . '&fields=id,name,webViewLink';
    $res = googleDriveCall($osaId, 'PATCH', $url);
    return $res['ok'] ? $res['body'] : null;
}

function googleDriveCreateStudentDocument(string $osaId, string $folderId, string $studentNumber, string $title): ?array {
    $res = googleDriveCall($osaId, 'POST', 'https://docs.googleapis.com/v1/documents', ['title' => $title]);
    if (!$res['ok'] || empty($res['body']['documentId'])) return null;
    $documentId = $res['body']['documentId'];
    $metadata = ['appProperties' => ['tip_vms_student' => $studentNumber]];
    $move = googleDriveCall($osaId, 'PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($documentId) . '?addParents=' . rawurlencode($folderId) . '&removeParents=root&fields=id,name,webViewLink', $metadata);
    return $move['ok'] ? $move['body'] : ['id' => $documentId, 'name' => $title, 'webViewLink' => 'https://docs.google.com/document/d/' . $documentId . '/edit'];
}

function googleDriveUpdateStudentDocument(string $osaId, string $documentId, string $content): bool {
    $doc = googleDriveCall($osaId, 'GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($documentId));
    if (!$doc['ok']) return false;
    $endIndex = (int) ($doc['body']['body']['content'][0]['endIndex'] ?? 1);
    $requests = [];
    if ($endIndex > 2) {
        $requests[] = ['deleteContentRange' => ['range' => ['startIndex' => 1, 'endIndex' => $endIndex - 1]]];
    }
    $requests[] = ['insertText' => ['location' => ['index' => 1], 'text' => $content]];
    $res = googleDriveCall($osaId, 'POST', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($documentId) . ':batchUpdate', ['requests' => $requests]);
    return $res['ok'];
}

function syncStudentViolationDocuments(PDO $pdo, string $osaId): array {
    $ownerOsaId = googleDriveOwnerOsaId();
    if (!$ownerOsaId) return ['ok' => false, 'message' => 'Google Drive owner is not configured.'];
    if (!googleOAuthConnected($ownerOsaId)) {
        return ['ok' => false, 'message' => 'The designated Google Drive owner account must connect Google services before syncing.'];
    }
    $folderId = googleDriveFolderId($ownerOsaId);
    if (!$folderId) return ['ok' => false, 'message' => 'Could not create or open the UVMS folder in Google Drive.'];

    $types = [];
    foreach (getViolationTypes($pdo) as $type) $types[$type['type_id']] = $type;
    $links = [];
    foreach (getAllStudents($pdo) as $student) {
        $studentNumber = $student['student_number'];
        $title = 'Violations - ' . $student['first_name'] . ' ' . $student['last_name'] . ' (' . $studentNumber . ')';
        $studentFolderName = $studentNumber . ' - ' . trim($student['first_name'] . ' ' . $student['last_name']);
        $studentFolderId = googleDriveStudentFolderId($ownerOsaId, $folderId, $studentNumber, $studentFolderName);
        if (!$studentFolderId) return ['ok' => false, 'message' => 'Could not create a student folder in Google Drive.'];

        $file = googleDriveStudentDocument($ownerOsaId, $studentFolderId, $studentNumber);
        if (!$file) {
            // Move documents created by older versions out of the shared root.
            $file = googleDriveStudentDocument($ownerOsaId, $folderId, $studentNumber);
            if ($file) $file = googleDriveMoveStudentDocument($ownerOsaId, $file['id'], $folderId, $studentFolderId);
        }
        if (!$file) $file = googleDriveCreateStudentDocument($ownerOsaId, $studentFolderId, $studentNumber, $title);
        if (!$file || empty($file['id'])) return ['ok' => false, 'message' => 'Could not create a student violation document in Google Drive.'];

        $lines = [
            'UVMS - STUDENT VIOLATION HISTORY',
            '',
            'Student: ' . $student['first_name'] . ' ' . $student['last_name'],
            'Student Number: ' . $studentNumber,
            'Program: ' . ($student['program'] ?? ''),
            'Year Level: ' . ($student['year_level'] ?? ''),
            '',
            'VIOLATIONS',
            '',
        ];
        $violations = violationsForStudent($pdo, $studentNumber);
        if (!$violations) $lines[] = 'No violations on record.';
        foreach ($violations as $violation) {
            $lines[] = 'Case ' . $violation['case_id'];
            $lines[] = 'Violation: ' . ($types[$violation['type_id']]['name'] ?? $violation['type_id']);
            $lines[] = 'Status: ' . $violation['status'];
            $lines[] = 'Date recorded: ' . ($violation['date_recorded'] ?? '');
            $lines[] = 'Details: ' . ($violation['details'] ?? '');
            if (!empty($violation['due_date'])) $lines[] = 'Fix/clear by: ' . $violation['due_date'];
            $lines[] = '';
        }
        if (!googleDriveUpdateStudentDocument($ownerOsaId, $file['id'], implode("\n", $lines))) {
            return ['ok' => false, 'message' => 'Could not update a student violation document in Google Drive.'];
        }
        $links[$studentNumber] = $file['webViewLink'] ?? ('https://docs.google.com/document/d/' . $file['id'] . '/edit');
    }
    return ['ok' => true, 'links' => $links];
}
