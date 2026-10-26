<?php

declare(strict_types=1);

/**
 * The language-selection AJAX endpoint. This is the only place
 * $_SESSION['selected_language'] is meant to be changed as a result of a
 * user action (a direct ?lang= on a page load is the only other writer,
 * and it goes through the same normalize_selected_language() function via
 * lang/resolve.php). Uses the shared canonical normalizer so this endpoint
 * can never disagree with the rest of the project about what a given
 * language choice means.
 */

require_once __DIR__ . '/normalize.php';

require_once __DIR__ . '/../api/security/AdminSecurity.php';
sfmsStartSecureSession();

$requested = $_GET['lang'] ?? ($_SESSION['selected_language'] ?? 'english');
$lang = normalize_selected_language((string) $requested);

$_SESSION['selected_language'] = $lang;

$languageFiles = [
    'english' => __DIR__ . '/english.php',
    'bisaya' => __DIR__ . '/bisaya.php',
    'tagalog' => __DIR__ . '/tagalog.php',
];
$translations = require $languageFiles[$lang];

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'lang' => $lang,
    'translations' => $translations,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
