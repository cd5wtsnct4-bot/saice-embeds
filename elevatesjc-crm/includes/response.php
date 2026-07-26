<?php
/**
 * Small JSON response helpers used by every api/*.php endpoint.
 */

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

/** Decode the JSON request body into an assoc array (empty array on failure).
 *  Caches the raw body so it can be safely called more than once per
 *  request (php://input is not reliably re-readable on every SAPI). */
function json_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $cached = [];
    }
    $decoded = json_decode($raw, true);
    return $cached = (is_array($decoded) ? $decoded : []);
}

/** Require the given HTTP method, otherwise 405. */
function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_error('Method not allowed.', 405);
    }
}
