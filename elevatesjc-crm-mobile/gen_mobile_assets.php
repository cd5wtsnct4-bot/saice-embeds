<?php
/**
 * Generates the native iOS/Android app icons + splash screens for the
 * Capacitor shell.
 *
 * No argument: draws the default brand mark (navy background, teal rounded
 * square, block "E") — the same placeholder used by the web app.
 *
 * With an argument: `php gen_mobile_assets.php /path/to/real-logo.png` uses
 * that image instead, square-cropped the same way the web app's logo
 * upload does (top-anchored for a tall mark-then-wordmark lockup, centred
 * otherwise) — see elevatesjc-crm/includes/branding.php for the original.
 * Re-run this any time you replace the logo, then re-open the project in
 * Xcode / Android Studio (or run `npx cap sync`) before rebuilding.
 */
$MOBILE_ROOT = __DIR__;
$NAVY = [0x14, 0x28, 0x50];
$TEAL = [0x16, 0xC7, 0x9A];
$sourceLogoPath = $argv[1] ?? null;

function draw_rounded_rect($im, $x1, $y1, $x2, $y2, $r, $color): void
{
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

/** Draws the default teal rounded-square "E" mark centered in a
 *  $markSize x $markSize box at ($offsetX, $offsetY). */
function draw_default_mark($im, int $offsetX, int $offsetY, int $markSize, array $navy, ?array $teal): void
{
    $navyColor = imagecolorallocate($im, ...$navy);
    if ($teal !== null) {
        $tealColor = imagecolorallocate($im, ...$teal);
        $corner = (int) round($markSize * 0.22);
        draw_rounded_rect($im, $offsetX, $offsetY, $offsetX + $markSize, $offsetY + $markSize, $corner, $tealColor);
    }
    $ex = $offsetX + (int) round($markSize * 0.24);
    $ey = $offsetY + (int) round($markSize * 0.20);
    $ew = (int) round($markSize * 0.52);
    $eh = (int) round($markSize * 0.60);
    $bar = max(2, (int) round($markSize * 0.13));
    imagefilledrectangle($im, $ex, $ey, $ex + $bar, $ey + $eh, $navyColor);
    imagefilledrectangle($im, $ex, $ey, $ex + $ew, $ey + $bar, $navyColor);
    imagefilledrectangle($im, $ex, $ey + (int) round($eh / 2 - $bar / 2), $ex + (int) round($ew * 0.82), $ey + (int) round($eh / 2 + $bar / 2), $navyColor);
    imagefilledrectangle($im, $ex, $ey + $eh - $bar, $ex + $ew, $ey + $eh, $navyColor);
}

/** Loads $sourceLogoPath and returns a square-cropped GD image resource
 *  sized $size x $size, or null if no source logo was given. */
function load_square_source(?string $path, int $size)
{
    if (!$path) return null;
    $info = @getimagesize($path);
    if (!$info) {
        fwrite(STDERR, "Warning: could not read $path, falling back to default mark.\n");
        return null;
    }
    $src = match ($info[2]) {
        IMAGETYPE_PNG => imagecreatefrompng($path),
        IMAGETYPE_JPEG => imagecreatefromjpeg($path),
        IMAGETYPE_WEBP => imagecreatefromwebp($path),
        default => null,
    };
    if (!$src) return null;

    $width = imagesx($src);
    $height = imagesy($src);
    $square = min($width, $height);
    $srcX = (int) round(($width - $square) / 2);
    $srcY = $height > $width ? 0 : (int) round(($height - $square) / 2); // top-anchor tall lockups

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
    imagealphablending($out, true);
    imagecopyresampled($out, $src, 0, 0, $srcX, $srcY, $size, $size, $square, $square);
    imagedestroy($src);
    return $out;
}

/** Full square icon: opaque navy background + centered mark (App Store
 *  icons must have no transparency, hence the flatten-onto-navy step even
 *  when a real logo with alpha is supplied). */
function make_flat_icon(int $size, string $outPath, array $navy, array $teal, ?string $sourceLogoPath): void
{
    $im = imagecreatetruecolor($size, $size);
    $navyColor = imagecolorallocate($im, ...$navy);
    imagefill($im, 0, 0, $navyColor);

    $pad = (int) round($size * 0.20);
    $markSize = $size - 2 * $pad;
    $source = load_square_source($sourceLogoPath, $markSize);
    if ($source) {
        imagecopy($im, $source, $pad, $pad, 0, 0, $markSize, $markSize);
        imagedestroy($source);
    } else {
        draw_default_mark($im, $pad, $pad, $markSize, $navy, $teal);
    }
    imagepng($im, $outPath);
    imagedestroy($im);
}

/** Transparent foreground-only layer for Android adaptive icons — content
 *  kept within the ~66% "safe zone" so it survives circle/squircle/rounded
 *  masks applied by different launchers. */
function make_adaptive_foreground(int $canvasSize, string $outPath, array $navy, array $teal, ?string $sourceLogoPath): void
{
    $im = imagecreatetruecolor($canvasSize, $canvasSize);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefilledrectangle($im, 0, 0, $canvasSize, $canvasSize, $transparent);
    imagealphablending($im, true);

    $safeZone = (int) round($canvasSize * 0.66);
    $offset = (int) round(($canvasSize - $safeZone) / 2);
    $source = load_square_source($sourceLogoPath, $safeZone);
    if ($source) {
        imagecopy($im, $source, $offset, $offset, 0, 0, $safeZone, $safeZone);
        imagedestroy($source);
    } else {
        draw_default_mark($im, $offset, $offset, $safeZone, $navy, $teal);
    }
    imagepng($im, $outPath);
    imagedestroy($im);
}

function make_splash(int $size, string $outPath, array $navy, array $teal, ?string $sourceLogoPath): void
{
    $im = imagecreatetruecolor($size, $size);
    $navyColor = imagecolorallocate($im, ...$navy);
    imagefill($im, 0, 0, $navyColor);
    $markSize = (int) round($size * 0.22);
    $offset = (int) round(($size - $markSize) / 2);
    $source = load_square_source($sourceLogoPath, $markSize);
    if ($source) {
        imagecopy($im, $source, $offset, $offset, 0, 0, $markSize, $markSize);
        imagedestroy($source);
    } else {
        draw_default_mark($im, $offset, $offset, $markSize, $navy, $teal);
    }
    imagepng($im, $outPath);
    imagedestroy($im);
}

// --- iOS ---
$iosIconDir = "$MOBILE_ROOT/ios/App/App/Assets.xcassets/AppIcon.appiconset";
make_flat_icon(1024, "$iosIconDir/AppIcon-512@2x.png", $NAVY, $TEAL, $sourceLogoPath);
echo "iOS icon: 1024x1024\n";

$iosSplashDir = "$MOBILE_ROOT/ios/App/App/Assets.xcassets/Splash.imageset";
make_splash(2732, "$iosSplashDir/splash-2732x2732.png", $NAVY, $TEAL, $sourceLogoPath);
copy("$iosSplashDir/splash-2732x2732.png", "$iosSplashDir/splash-2732x2732-1.png");
copy("$iosSplashDir/splash-2732x2732.png", "$iosSplashDir/splash-2732x2732-2.png");
echo "iOS splash: 2732x2732 (x3)\n";

// --- Android legacy launcher icons ---
$legacySizes = ['mdpi' => 48, 'hdpi' => 72, 'xhdpi' => 96, 'xxhdpi' => 144, 'xxxhdpi' => 192];
foreach ($legacySizes as $bucket => $size) {
    $dir = "$MOBILE_ROOT/android/app/src/main/res/mipmap-$bucket";
    make_flat_icon($size, "$dir/ic_launcher.png", $NAVY, $TEAL, $sourceLogoPath);
    copy("$dir/ic_launcher.png", "$dir/ic_launcher_round.png");
    echo "Android legacy icon [$bucket]: {$size}x{$size}\n";
}

// --- Android adaptive icon foreground layers (108dp safe canvas per density) ---
$adaptiveSizes = ['mdpi' => 108, 'hdpi' => 162, 'xhdpi' => 216, 'xxhdpi' => 324, 'xxxhdpi' => 432];
foreach ($adaptiveSizes as $bucket => $size) {
    $dir = "$MOBILE_ROOT/android/app/src/main/res/mipmap-$bucket";
    make_adaptive_foreground($size, "$dir/ic_launcher_foreground.png", $NAVY, $TEAL, $sourceLogoPath);
    echo "Android adaptive foreground [$bucket]: {$size}x{$size}\n";
}

// --- Android splash (same image rendered fresh for every density/orientation bucket) ---
foreach (glob("$MOBILE_ROOT/android/app/src/main/res/drawable*/splash.png") as $target) {
    make_splash(1200, $target, $NAVY, $TEAL, $sourceLogoPath);
}
echo "Android splash regenerated for all density/orientation buckets\n";

// --- Adaptive icon background colour to match brand navy ---
$bgXmlPath = "$MOBILE_ROOT/android/app/src/main/res/values/ic_launcher_background.xml";
file_put_contents($bgXmlPath, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<resources>\n    <color name=\"ic_launcher_background\">#142850</color>\n</resources>\n");
echo "Updated ic_launcher_background.xml to brand navy\n";
