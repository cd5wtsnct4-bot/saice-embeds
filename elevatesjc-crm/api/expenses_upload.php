<?php
/**
 * Step 1 of the "scan a slip" flow: accepts a photographed receipt from
 * the expense form (phone camera or file picker), stores it securely, and
 * runs best-effort OCR to suggest amount/date/vendor. The frontend then
 * shows those suggestions in an editable form — nothing here is saved as
 * an expense yet, that happens via a normal POST to expenses.php once the
 * user confirms/corrects the fields.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/ocr.php';

require_login_api();
require_method('POST');
require_csrf();

if (empty($_FILES['receipt'])) {
    json_error('No file received.', 422);
}

try {
    $relativePath = store_receipt_upload($_FILES['receipt']);
} catch (UploadException $e) {
    json_error($e->getMessage(), 422);
}

$guess = ['amount' => null, 'date' => null, 'vendor' => null, 'raw_text' => null];
if (ocr_available()) {
    $abs = resolve_receipt_path($relativePath);
    $text = $abs ? ocr_extract_text($abs) : null;
    if ($text) {
        $guess = ocr_guess_fields($text);
    }
}

json_out([
    'receipt_path' => $relativePath,
    'ocr_available' => ocr_available(),
    'guess' => $guess,
], 201);
