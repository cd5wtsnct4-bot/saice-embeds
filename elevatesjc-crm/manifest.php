<?php
/**
 * Dynamic Web App Manifest — served as JSON so "Add to Home Screen" (iOS)
 * and native install prompts (Android/desktop Chrome) pick up the current
 * Settings > Branding name/colors/logo instead of a hardcoded default.
 *
 * Note: iOS Safari actually reads the home-screen icon from the
 * <link rel="apple-touch-icon"> tag in index.php/login.php, not from this
 * file's "icons" array — that array is here for Android/Chrome/desktop
 * install support and for iOS splash-screen generation.
 */
require_once __DIR__ . '/includes/db.php';

$settingsRows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
$s = [];
foreach ($settingsRows as $row) {
    $s[$row['setting_key']] = $row['setting_value'];
}
$companyName = $s['company_name'] ?? 'Elevate SJC';
$primaryColor = $s['primary_color'] ?? '#142850';
$icon = $s['company_logo_icon'] ?? null; // square crop generated on upload, see includes/branding.php

$icons = $icon
    ? [
        ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ]
    : [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ];

header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => $companyName . ' CRM',
    'short_name' => $companyName,
    'description' => 'Contacts, pipeline, calendar, proposals and invoicing for ' . $companyName . '.',
    'start_url' => 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#F4F6FA',
    'theme_color' => $primaryColor,
    'icons' => $icons,
], JSON_UNESCAPED_SLASHES);
