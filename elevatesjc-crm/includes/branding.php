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
 *
 * Every upload also generates a square "icon" crop (see generate_square_icon)
 * for use as the PWA/apple-touch-icon — most real logos are a wide or tall
 * lockup (mark + wordmark), which looks squashed or illegible if used as-is
 * for a tiny home-screen icon.
 */
require_once __DIR__ . '/uploads.php'; // reuses the UploadException class

const LOGO_MAX_BYTES = 3 * 1024 * 1024; // 3MB
const LOGO_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp',
];
const LOGO_ICON_SIZE = 512;

function logo_upload_root(): string
{
    return __DIR__ . '/../assets/branding';
}

function logo_load_image(string $path, int $imagetype)
{
    switch ($imagetype) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG: return @imagecreatefrompng($path);
        case IMAGETYPE_WEBP: return @imagecreatefromwebp($path);
        default: return false;
    }
}

/**
 * Crop the source image to a centered square and resize to LOGO_ICON_SIZE.
 * Tall/portrait lockups (mark-on-top, text-below — the common convention)
 * are anchored to the TOP of the image rather than dead-center, since that's
 * where the actual mark usually sits; wide/landscape or already-square
 * images are center-cropped. Returns the relative path of the generated PNG.
 */
function generate_square_icon(string $sourcePath, int $imagetype, string $destDir, string $baseName): ?string
{
    $src = logo_load_image($sourcePath, $imagetype);
    if (!$src) {
        return null;
    }
    $width = imagesx($src);
    $height = imagesy($src);
    $square = min($width, $height);
    $srcX = (int) round(($width - $square) / 2);
    $srcY = $height > $width ? 0 : (int) round(($height - $square) / 2);

    $icon = imagecreatetruecolor(LOGO_ICON_SIZE, LOGO_ICON_SIZE);
    imagealphablending($icon, false);
    imagesavealpha($icon, true);
    $transparent = imagecolorallocatealpha($icon, 0, 0, 0, 127);
    imagefilledrectangle($icon, 0, 0, LOGO_ICON_SIZE, LOGO_ICON_SIZE, $transparent);
    imagealphablending($icon, true);

    imagecopyresampled($icon, $src, 0, 0, $srcX, $srcY, LOGO_ICON_SIZE, LOGO_ICON_SIZE, $square, $square);
    imagedestroy($src);

    $filename = $baseName . '-icon.png';
    $ok = imagepng($icon, $destDir . '/' . $filename);
    imagedestroy($icon);
    if (!$ok) {
        return null;
    }
    chmod($destDir . '/' . $filename, 0644);
    return 'assets/branding/' . $filename;
}

/**
 * Validate and store an uploaded logo from $_FILES['logo'], removing any
 * previously stored logo + icon files. Returns
 * ['logo' => <path for in-app display>, 'icon' => <square path for app icons, or null>].
 */
function store_logo_upload(array $file, ?string $previousLogoPath, ?string $previousIconPath): array
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

    $baseName = 'logo-' . bin2hex(random_bytes(8));
    $filename = $baseName . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new UploadException('Could not save the uploaded file.');
    }
    chmod($dest, 0644);

    $iconPath = generate_square_icon($dest, $info[2], $dir, $baseName);

    remove_logo_file($previousLogoPath);
    remove_logo_file($previousIconPath);
    return ['logo' => 'assets/branding/' . $filename, 'icon' => $iconPath];
}

/** Best-effort delete of a previously stored logo/icon file (never fatal). */
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
