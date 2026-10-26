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
 * deleteRole only hard-deletes a row that has never received a rating
 * (see roleKeyHasHistory()); a role with historical feedback is
 * deactivated instead (same effect as updateRole with isActive=false),
 * which preserves every existing rating recorded under that st-key.
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

// True if any feedback (either pipeline's table) has ever recorded an
// answer for this role's st-key — i.e. hard-deleting the row would orphan
// real historical rating data. Matches the key as a quoted JSON object key
// (`"stN":`) so e.g. "st1" never false-matches inside "st10".
function roleKeyHasHistory(PDO $pdo, string $roleKey): bool
{
    $pattern = '%"' . $roleKey . '":%';

    $stmt = $pdo->prepare('SELECT 1 FROM feedbacks WHERE ratings_json LIKE :pattern LIMIT 1');
    $stmt->execute([':pattern' => $pattern]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM feedback_entries WHERE ratings LIKE :pattern LIMIT 1');
    $stmt->execute([':pattern' => $pattern]);
    return (bool) $stmt->fetchColumn();
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

        // Deactivating stamps archived_at (soft-delete marker, mirrors
        // migration 001's original intent); reactivating clears it.
        $stmt = $pdo->prepare(
            'UPDATE staff
             SET full_name = :label,
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
            // Either the id doesn't exist, or the row already matched
            // these exact values — confirm which before erroring.
            $existsStmt = $pdo->prepare('SELECT 1 FROM staff WHERE id = :id');
            $existsStmt->execute([':id' => $id]);
            if (!$existsStmt->fetchColumn()) {
                jsonResponse(['success' => false, 'message' => 'Staff role not found'], 404);
            }
        }

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

    if ($action === 'deleteRole') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid staff role id is required'], 422);
        }

        $roleStmt = $pdo->prepare('SELECT role FROM staff WHERE id = :id');
        $roleStmt->execute([':id' => $id]);
        $roleKey = $roleStmt->fetchColumn();
        if ($roleKey === false) {
            jsonResponse(['success' => false, 'message' => 'Staff role not found'], 404);
        }

        if (roleKeyHasHistory($pdo, (string) $roleKey)) {
            // Referenced by real feedback — deactivate instead of losing
            // the historical rating data a hard delete would orphan.
            $stmt = $pdo->prepare(
                'UPDATE staff SET is_active = 0, archived_at = COALESCE(archived_at, NOW()) WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            sendStaffRoles($pdo);
        }

        $stmt = $pdo->prepare('DELETE FROM staff WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendStaffRoles($pdo);
    }

    jsonResponse(['success' => false, 'message' => 'Unsupported action'], 422);
} catch (Throwable $e) {
    sfmsLogFailure('staff_roles.request');
    jsonResponse(['success' => false, 'message' => 'Unable to process staff roles.'], 500);
}
