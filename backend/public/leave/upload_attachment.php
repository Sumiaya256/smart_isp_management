<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Leave Management', 'edit', true);

$leaveId = (int) ($_POST['leave_id'] ?? 0);
if ($leaveId <= 0) {
    send_json(['ok' => false, 'message' => 'leave_id is required'], 422);
}

if (!isset($_FILES['attachment'])) {
    send_json(['ok' => false, 'message' => 'No attachment uploaded'], 422);
}

$file = $_FILES['attachment'];
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    send_json(['ok' => false, 'message' => 'Attachment upload failed'], 422);
}

$maxBytes = 5 * 1024 * 1024;
$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
    send_json(['ok' => false, 'message' => 'File must be between 1 byte and 5MB'], 422);
}

$originalName = (string) ($file['name'] ?? '');
$tmpPath = (string) ($file['tmp_name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
if (!in_array($ext, $allowedExt, true)) {
    send_json(['ok' => false, 'message' => 'Only PDF, JPG, JPEG, and PNG files are allowed'], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = strtolower((string) $finfo->file($tmpPath));
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mimeType, $allowedMimes, true)) {
    send_json(['ok' => false, 'message' => 'Invalid file type'], 422);
}

try {
    $pdo = db();
    ensure_leave_schema($pdo);
    enforce_leave_scope($pdo, $user, $leaveId);

    $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string) $safeBase, '._-');
    if ($safeBase === '') {
        $safeBase = 'attachment';
    }

    $storedName = $leaveId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;

    $projectRoot = dirname(__DIR__, 3);
    $relativeDir = 'uploads/leave_attachments';
    $absoluteDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'leave_attachments';
    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0777, true);
    }

    $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        send_json(['ok' => false, 'message' => 'Failed to store uploaded file'], 500);
    }

    $filePath = $relativeDir . '/' . $storedName;

    $stmt = $pdo->prepare(
        'INSERT INTO leave_attachments (
            leave_request_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_by_employee_id
         ) VALUES (
            :leave_request_id, :original_name, :stored_name, :file_path, :mime_type, :file_size, :uploaded_by_employee_id
         )'
    );

    $stmt->execute([
        'leave_request_id' => $leaveId,
        'original_name' => leave_str_cut($originalName, 255),
        'stored_name' => leave_str_cut($storedName, 255),
        'file_path' => leave_str_cut($filePath, 500),
        'mime_type' => leave_str_cut($mimeType, 120),
        'file_size' => $size,
        'uploaded_by_employee_id' => (int) ($user['id'] ?? 0) ?: null,
    ]);

    send_json([
        'ok' => true,
        'message' => 'Attachment uploaded',
        'attachment' => [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $originalName,
            'path' => $filePath,
            'size' => $size,
            'mime_type' => $mimeType,
        ],
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to upload attachment', 'error' => $e->getMessage()], 500);
}
