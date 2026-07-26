<?php
/**
 * Best-effort receipt/slip OCR via the `tesseract` CLI, if present on the
 * server. Shared hosting frequently doesn't have it (or disables
 * shell_exec entirely) — every caller must handle ocr_available() being
 * false and fall back to plain manual data entry. Nothing here is ever
 * trusted blindly: extracted fields are only a starting point the user
 * reviews and corrects in the expense form before saving.
 */

function ocr_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    if (!function_exists('shell_exec')) {
        return $available = false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true) || in_array('proc_open', $disabled, true)) {
        return $available = false;
    }
    $path = @shell_exec('command -v tesseract 2>/dev/null');
    return $available = is_string($path) && trim($path) !== '';
}

/** Run tesseract over an image file, returning raw text or null on failure. */
function ocr_extract_text(string $absoluteImagePath): ?string
{
    if (!ocr_available() || !is_file($absoluteImagePath)) {
        return null;
    }
    $cmd = 'tesseract ' . escapeshellarg($absoluteImagePath) . ' stdout -l eng 2>/dev/null';
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }
    return trim($output);
}

/**
 * Heuristically pull an amount, date and vendor name out of raw till-slip
 * OCR text. This is intentionally simple pattern matching, not a real
 * receipt-parsing model — it exists to save typing, not to be authoritative.
 */
function ocr_guess_fields(string $text): array
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));

    // --- Amount: prefer a line mentioning "total" (not "subtotal"/"change"),
    // otherwise fall back to the largest currency-looking number seen.
    $amount = null;
    $currencyPattern = '/(?:R|ZAR)?\s?(\d{1,3}(?:[ ,]\d{3})*(?:[.,]\d{2}))/i';
    foreach ($lines as $line) {
        if (preg_match('/\btotal\b/i', $line) && !preg_match('/\b(sub\s?total|change|balance)\b/i', $line)) {
            if (preg_match($currencyPattern, $line, $m)) {
                $amount = (float)str_replace([' ', ','], ['', '.'], preg_replace('/,(?=\d{2}$)/', '.', $m[1]));
                break;
            }
        }
    }
    if ($amount === null) {
        $max = null;
        foreach ($lines as $line) {
            if (preg_match_all($currencyPattern, $line, $matches)) {
                foreach ($matches[1] as $raw) {
                    $normalised = (float)str_replace([' ', ','], ['', '.'], preg_replace('/,(?=\d{2}$)/', '.', $raw));
                    if ($max === null || $normalised > $max) {
                        $max = $normalised;
                    }
                }
            }
        }
        $amount = $max;
    }

    // --- Date: try a handful of common till-slip formats.
    $date = null;
    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
    } elseif (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](\d{2,4})\b/', $text, $m)) {
        $yr = strlen($m[3]) === 2 ? ('20' . $m[3]) : $m[3];
        $date = sprintf('%04d-%02d-%02d', (int)$yr, (int)$m[2], (int)$m[1]);
    } elseif (preg_match('/\b(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+(\d{4})\b/i', $text, $m)) {
        $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
        if ($ts !== false) {
            $date = date('Y-m-d', $ts);
        }
    }
    if ($date !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > (int)date('Y') + 1) {
            $date = null;
        }
    }

    // --- Vendor: usually the first meaningful line on a till slip.
    $vendor = null;
    foreach ($lines as $line) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9 &.\'-]{2,60}$/', $line)) {
            $vendor = $line;
            break;
        }
    }

    return [
        'amount' => $amount,
        'date' => $date,
        'vendor' => $vendor,
        'raw_text' => $text,
    ];
}
