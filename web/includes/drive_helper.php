<?php
/* ============================================
   UVMS — Google Drive integration
   Stores case evidence/attachments in Drive under:
     <shared root folder> / <Program> / <StudentNumber - CaseId> / file
   Failures are LOGGED, never fatal — same policy as mailer.php
   and calendar_helper.php.
   ============================================ */

require_once __DIR__ . '/google_client.php';

/**
 * Finds (or creates) the Drive subfolder for a case. Returns the
 * folder ID, or null on failure / if Google isn't configured.
 */
function getOrCreateCaseFolder(string $caseId, string $studentNumber, string $program): ?string {
    if (!googleIsConfigured()) {
        error_log('[drive] getOrCreateCaseFolder skipped — google_config.php not filled in yet.');
        return null;
    }

    try {
        $service = getDriveService();

        $programFolderId = findOrCreateFolder($service, $program !== '' ? $program : 'Unassigned', GOOGLE_DRIVE_ROOT_FOLDER_ID);
        if (!$programFolderId) return null;

        $caseFolderName = $studentNumber . ' - ' . $caseId;
        return findOrCreateFolder($service, $caseFolderName, $programFolderId);
    } catch (\Throwable $e) {
        error_log('[drive] getOrCreateCaseFolder failed: ' . $e->getMessage());
        return null;
    }
}

// Looks up a folder by exact name under $parentId; creates it if missing.
function findOrCreateFolder(Google_Service_Drive $service, string $name, string $parentId): ?string {
    $safeName = str_replace("'", "\\'", $name);
    $query = "name = '{$safeName}' and '{$parentId}' in parents "
        . "and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

    $result = $service->files->listFiles([
        'q'      => $query,
        'fields' => 'files(id, name)',
        'spaces' => 'drive',
    ]);
    $files = $result->getFiles();
    if (!empty($files)) return $files[0]->getId();

    $folder = new Google_Service_Drive_DriveFile([
        'name'     => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents'  => [$parentId],
    ]);
    $created = $service->files->create($folder, ['fields' => 'id']);
    return $created->getId();
}

/**
 * Uploads a local file (typically a tmp_name from $_FILES) into a
 * case's Drive folder. Returns
 * ['file_id' => ..., 'link' => ..., 'name' => ...] on success,
 * null on failure.
 */
function uploadCaseFile(string $caseId, string $studentNumber, string $program, string $localPath, string $originalName): ?array {
    $folderId = getOrCreateCaseFolder($caseId, $studentNumber, $program);
    if (!$folderId) return null;

    try {
        $service  = getDriveService();
        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';
        $content  = file_get_contents($localPath);

        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name'    => $originalName,
            'parents' => [$folderId],
        ]);

        $created = $service->files->create($fileMetadata, [
            'data'       => $content,
            'mimeType'   => $mimeType,
            'uploadType' => 'multipart',
            'fields'     => 'id, webViewLink',
        ]);

        return ['file_id' => $created->getId(), 'link' => $created->getWebViewLink(), 'name' => $originalName];
    } catch (\Throwable $e) {
        error_log('[drive] uploadCaseFile failed: ' . $e->getMessage());
        return null;
    }
}
