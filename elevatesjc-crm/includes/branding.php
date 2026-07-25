<?php
/**
 * Company logo upload. Unlike receipts (uploads/, always private), the
 * logo must be servable to a logged-out browser (the login screen) and
 * embedded in printed proposals/invoices, so it lives under the public
 * assets/branding/ folder instead — no auth gate on the file itself.
 *
 * SVG is deliberately not accepted: an <img src="..."> reference is safe,
 * but a directly-browsed SVG can carry an executable <script>, and this
 * folder is public by design, so we only take formats with no script
 * capability at all.
 */
require_once __DIR__ . '/uploads.php'; // reuses the UploadException class

const LOGO_MAX_BYTES = 3 * 1024 * 1024; // 3MB
const LOGO_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
];

function logo_upload_root(): string
{
    return __DIR__ . '/../assets/branding';
}

/** Validate and store an uploaded logo from $_FILES['logo'], removing any
 *  previous logo file. Returns the relative (from app root) path to store
 *  in the `company_logo` setting. */
function store_logo_upload(array $file, ?string $previousRelativePath): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new UploadException('Upload failed (error code ' . ($file['error'] ?? 'unknown') . ').');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UploadException('Invalid upload.');
    }
    if ($file['size'] > LOGO_MAX_BYTES) {
        throw new UploadException('Logo file is too large (max 3MB).');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !isset(LOGO_ALLOWED_TYPES[$info[2]])) {
        throw new UploadException('That file is not a supported image (JPEG, PNG, or WebP).');
    }
    $ext = LOGO_ALLOWED_TYPES[$info[2]];

    $dir = logo_upload_root();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new UploadException('Could not create upload directory.');
    }

    $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new UploadException('Could not save the uploaded file.');
    }
    chmod($dest, 0644);

    remove_logo_file($previousRelativePath);
    return 'assets/branding/' . $filename;
}

/** Best-effort delete of a previously stored logo file (never fatal). */
function remove_logo_file(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $root = realpath(logo_upload_root());
    $full = realpath(__DIR__ . '/../' . $relativePath);
    if ($root === false || $full === false || strncmp($full, $root, strlen($root)) !== 0) {
        return; // not one of ours — never delete outside assets/branding/
    }
    @unlink($full);
}
