<?php
/**
 * Secure handling for phone-scanned receipt/slip uploads.
 *
 * Untrusted files never keep their client-supplied name or extension —
 * the type is verified server-side via getimagesize() (a real image
 * decode, not just a MIME-type header check) and the extension is
 * derived from that. Files land under uploads/receipts/, which itself
 * denies direct web access (see uploads/.htaccess) — every read goes
 * through download_receipt.php after an auth check.
 */

const RECEIPT_MAX_BYTES = 10 * 1024 * 1024; // 10MB
const RECEIPT_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
];

class UploadException extends Exception {}

function receipt_upload_root(): string
{
    return __DIR__ . '/../uploads/receipts';
}

/**
 * Validate and store an uploaded receipt image from $_FILES['receipt'].
 * Returns the relative path (from the app root) to store in the DB.
 */
function store_receipt_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new UploadException('Upload failed (error code ' . ($file['error'] ?? 'unknown') . ').');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UploadException('Invalid upload.');
    }
    if ($file['size'] > RECEIPT_MAX_BYTES) {
        throw new UploadException('File is too large (max 10MB).');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !isset(RECEIPT_ALLOWED_TYPES[$info[2]])) {
        throw new UploadException('That file is not a supported image (JPEG, PNG, or WebP).');
    }
    $ext = RECEIPT_ALLOWED_TYPES[$info[2]];

    $subdir = date('Y') . '/' . date('m');
    $dir = receipt_upload_root() . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new UploadException('Could not create upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new UploadException('Could not save the uploaded file.');
    }
    chmod($dest, 0640);

    return 'uploads/receipts/' . $subdir . '/' . $filename;
}

/** Resolve a stored relative receipt path back to an absolute filesystem
 *  path, rejecting anything that tries to escape the uploads directory. */
function resolve_receipt_path(string $relativePath): ?string
{
    $root = realpath(__DIR__ . '/../uploads/receipts');
    $full = realpath(__DIR__ . '/../' . $relativePath);
    if ($root === false || $full === false) {
        return null;
    }
    if (strncmp($full, $root, strlen($root)) !== 0) {
        return null; // path traversal attempt
    }
    return $full;
}
