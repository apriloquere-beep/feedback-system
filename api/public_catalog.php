<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalog_helpers.php';

/**
 * Public, read-only Service catalog. SFMS is service-only — there is no
 * Department entity in the active application anymore.
 *
 * Deliberately separate from api/catalog.php: that endpoint requires an
 * admin session and also exposes create/update/delete actions, none of
 * which belong on the public internet. This file only ever runs the same
 * active-only SELECT api/catalog.php already runs (see its sendCatalog())
 * and returns nothing else — no admin data, no mutation of any kind.
 *
 * The form uses the main names as its fallback and hydrates optional
 * sub-services from this endpoint.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = getPdo();

    jsonResponse([
        'success' => true,
        'data' => [
            'services' => loadActiveServiceCatalog($pdo),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[sfms] public_catalog.read failed.');
    jsonResponse(['success' => false, 'message' => 'Unable to load the service catalog.'], 500);
}
