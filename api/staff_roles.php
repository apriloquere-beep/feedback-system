<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';

/**
 * Admin-only CRUD for the Staff Ratings catalog.
 *
 * IMPORTANT: this does NOT manage individual employees. `staff` rows are
 * staff-ROLE rating categories (Doctor, Dentist, ...) — the 10 originally
 * seeded ones plus any admin-added since. Each row's `role` column (a
 * stable stN key, e.g. st0, st1, ... st10, ...) is the anchor that ties it
 * to its rating question in form/feedback.php's survey and to the matching
 * key already used throughout every historical feedback's ratings JSON;
 * once assigned it never changes. `full_name` (display label) and
 * `is_active` (whether it's offered on NEW feedback submissions) are
 * editable.
 *
 * createRole mints the next unused stN key and inserts a new row, which
 * form/feedback.php immediately starts rendering as a real rating
 * question (see resolveNextRoleKey()) — nothing about the ratings
 * pipeline is hardcoded to st0-st9, so a new key works exactly like the
 * originals with zero other changes.
 *
 * Roles are archived by deactivating the existing row. Their stable stN
 * keys remain available for historical feedback and are never deleted.
 */

function sendStaffRoles(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, full_name, role, is_active, archived_at FROM staff ORDER BY LENGTH(role), role'
    );

    jsonResponse([
        'success' => true,
        'data' => [
            'staffRoles' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'key' => (string) $row['role'],
                'label' => (string) $row['full_name'],
                'isActive' => (bool) $row['is_active'],
                'archivedAt' => $row['archived_at'],
            ], $stmt->fetchAll()),
        ],
    ]);
}

function saveStaffRole(PDO $pdo, int $id, ?string $label, bool $isActive): void
{
    $stmt = $pdo->prepare(
        'UPDATE staff
         SET full_name = COALESCE(:label, full_name),
             is_active = :is_active,
             archived_at = CASE WHEN :is_active2 = 1 THEN NULL ELSE COALESCE(archived_at, NOW()) END
         WHERE id = :id'
    );
    $stmt->execute([
        ':label' => $label,
        ':is_active' => $isActive ? 1 : 0,
        ':is_active2' => $isActive ? 1 : 0,
        ':id' => $id,
    ]);

    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare('SELECT 1 FROM staff WHERE id = :id');
        $existsStmt->execute([':id' => $id]);
        if (!$existsStmt->fetchColumn()) {
            jsonResponse(['success' => false, 'message' => 'Staff role not found'], 404);
        }
    }
}

// Finds the lowest-numbered unused stN key, so a newly created role slots
// in after the highest existing one (st0..st9 -> st10, st10 -> st11, ...)
// without ever colliding with a key already used in historical ratings.
function resolveNextRoleKey(PDO $pdo): string
{
    $keys = $pdo->query('SELECT role FROM staff')->fetchAll(PDO::FETCH_COLUMN);
    $maxN = -1;
    foreach ($keys as $key) {
        if (is_string($key) && preg_match('/^st(\d+)$/', $key, $matches) === 1) {
            $maxN = max($maxN, (int) $matches[1]);
        }
    }
    return 'st' . ($maxN + 1);
}

sfmsStartSecureSession();

if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
sfmsRequireCsrf();

try {
    $pdo = getPdo();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        sendStaffRoles($pdo);
    }

    if ($method !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = readJsonBody();
    $action = trim((string) ($payload['action'] ?? ''));

    if ($action === '') {
        jsonResponse(['success' => false, 'message' => 'Action is required'], 422);
    }

    if ($action === 'updateRole') {
        $id = (int) ($payload['id'] ?? 0);
        $label = trim((string) ($payload['label'] ?? ''));
        $isActive = (bool) ($payload['isActive'] ?? true);

        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid staff role id is required'], 422);
        }
        if ($label === '') {
            jsonResponse(['success' => false, 'message' => 'Staff role label is required'], 422);
        }
        if (mb_strlen($label) > 120) {
            jsonResponse(['success' => false, 'message' => 'Staff role label is too long'], 422);
        }

        // Prevent two rows sharing a display label (case-insensitive) —
        // duplicate labels would make Staff Ratings and the feedback form
        // ambiguous even though the underlying st-keys stay distinct.
        $dupStmt = $pdo->prepare(
            'SELECT id FROM staff WHERE LOWER(full_name) = LOWER(:label) AND id != :id LIMIT 1'
        );
        $dupStmt->execute([':label' => $label, ':id' => $id]);
        if ($dupStmt->fetchColumn()) {
            jsonResponse(['success' => false, 'message' => 'Another staff role already uses that label'], 422);
        }

        saveStaffRole($pdo, $id, $label, $isActive);

        sendStaffRoles($pdo);
    }

    if ($action === 'createRole') {
        $label = trim((string) ($payload['label'] ?? ''));
        $isActive = (bool) ($payload['isActive'] ?? true);

        if ($label === '') {
            jsonResponse(['success' => false, 'message' => 'Staff role label is required'], 422);
        }
        if (mb_strlen($label) > 120) {
            jsonResponse(['success' => false, 'message' => 'Staff role label is too long'], 422);
        }

        $dupStmt = $pdo->prepare('SELECT id FROM staff WHERE LOWER(full_name) = LOWER(:label) LIMIT 1');
        $dupStmt->execute([':label' => $label]);
        if ($dupStmt->fetchColumn()) {
            jsonResponse(['success' => false, 'message' => 'A staff role already uses that label'], 422);
        }

        $roleKey = resolveNextRoleKey($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO staff (full_name, role, is_active) VALUES (:label, :role, :is_active)'
        );
        $stmt->execute([
            ':label' => $label,
            ':role' => $roleKey,
            ':is_active' => $isActive ? 1 : 0,
        ]);

        sendStaffRoles($pdo);
    }

    // Keep the legacy action non-destructive for any older dashboard client.
    if (in_array($action, ['archiveRole', 'deleteRole'], true)) {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid staff role id is required'], 422);
        }

        saveStaffRole($pdo, $id, null, false);
        sendStaffRoles($pdo);
    }

    if ($action === 'restoreRole') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid staff role id is required'], 422);
        }

        saveStaffRole($pdo, $id, null, true);
        sendStaffRoles($pdo);
    }

    jsonResponse(['success' => false, 'message' => 'Unsupported action'], 422);
} catch (Throwable $e) {
    sfmsLogFailure('staff_roles.request');
    jsonResponse(['success' => false, 'message' => 'Unable to process staff roles.'], 500);
}
