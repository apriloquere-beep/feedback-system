<?php

declare(strict_types=1);

/**
 * The ONE shared language-resolution entry point every page requires.
 *
 * $_SESSION['selected_language'] is the single source of truth for the
 * active language. A ?lang= query parameter, if present, is treated only
 * as an EXPLICIT ONE-TIME REQUEST that updates the session immediately —
 * it is never read again after this point, and nothing propagates it
 * forward into other URLs. Once this file runs, every part of the page
 * (PHP-rendered HTML and anything handed to JavaScript) reads $t, never
 * the session or the URL directly.
 *
 * Provides: $t (the active translations array), $selectedLanguage
 * ('english' | 'bisaya' | 'tagalog'), $htmlLang (the <html lang="..."> value).
 */

require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/../api/security/AdminSecurity.php';

sfmsStartSecureSession();

if (isset($_GET['lang'])) {
    $_SESSION['selected_language'] = normalize_selected_language((string) $_GET['lang']);
}

$selectedLanguage = normalize_selected_language((string) ($_SESSION['selected_language'] ?? 'english'));
$_SESSION['selected_language'] = $selectedLanguage; // re-store canonical form, never a short code

$languageFiles = [
    'english' => __DIR__ . '/english.php',
    'bisaya' => __DIR__ . '/bisaya.php',
    'tagalog' => __DIR__ . '/tagalog.php',
];
$t = require $languageFiles[$selectedLanguage];

$htmlLangMap = ['english' => 'en', 'bisaya' => 'ceb', 'tagalog' => 'tl'];
$htmlLang = $htmlLangMap[$selectedLanguage];
