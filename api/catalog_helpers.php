<?php

declare(strict_types=1);

/**
 * Canonical service catalog reader shared by public and admin endpoints.
 * IDs are the API values; names are display labels only.
 */
function loadActiveServiceCatalog(PDO $pdo): array
{
    $services = $pdo->query("SELECT id, name FROM services WHERE status = 'active' ORDER BY id ASC")->fetchAll();
    $subtypes = $pdo->query(
        "SELECT id, service_id, name
         FROM service_subtypes
         WHERE status = 'active'
         ORDER BY service_id ASC, CASE WHEN name = 'Other' THEN 1 ELSE 0 END, id ASC"
    )->fetchAll();

    $byService = [];
    foreach ($subtypes as $subtype) {
        $byService[(int) $subtype['service_id']][] = [
            'id' => (int) $subtype['id'],
            'name' => (string) $subtype['name'],
            // UI hint only; subtype ID remains authoritative on submission.
            'allowsCustomText' => (string) $subtype['name'] === 'Other',
        ];
    }

    return array_map(static fn (array $service): array => [
        'id' => (int) $service['id'],
        'name' => (string) $service['name'],
        // The catalog's Others row remains the canonical service for free text.
        'allowsCustomText' => (string) $service['name'] === 'Others',
        'subservices' => $byService[(int) $service['id']] ?? [],
    ], $services);
}
