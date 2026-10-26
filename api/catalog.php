<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security/AdminSecurity.php';
require_once __DIR__ . '/catalog_helpers.php';

/**
 * Admin-only Service catalog CRUD (SFMS is service-only — there is no
 * Department entity anymore). The public, read-only equivalent used by
 * form/feedback.php is api/public_catalog.php.
 *
 * services.department_id may still physically exist on the live database
 * (the schema migration that drops it runs separately, after this code is
 * deployed) so writes defensively include a placeholder value only when
 * that column is still present, and never read/expose it either way.
 */

function servicesDepartmentIdStillExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => 'services',
        ':column_name' => 'department_id',
    ]);
    return (bool) $stmt->fetchColumn();
}

function sendCatalog(PDO $pdo): void
{
    jsonResponse([
        'success' => true,
        'data' => [
            'services' => loadActiveServiceCatalog($pdo),
        ],
    ]);
}

function catalogPositiveId(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? (int) $id : null;
}

function catalogName(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $name = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    return $name !== '' && $length <= 120 ? $name : null;
}

function activeServiceExists(PDO $pdo, int $serviceId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM services WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute([':id' => $serviceId]);
    return (bool) $stmt->fetchColumn();
}

function findSubservice(PDO $pdo, int $subserviceId): ?array
{
    $stmt = $pdo->prepare('SELECT id, service_id, name, status FROM service_subtypes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $subserviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function catalogReferenceExists(PDO $pdo, string $sql, array $params): bool
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function serviceDeleteBlocker(PDO $pdo, int $serviceId, string $serviceName): ?string
{
    if (catalogReferenceExists($pdo, 'SELECT 1 FROM service_subtypes WHERE service_id = :id LIMIT 1', [':id' => $serviceId])) {
        return 'it still has sub-services';
    }
    if (catalogReferenceExists($pdo, 'SELECT 1 FROM feedbacks WHERE service_id = :id LIMIT 1', [':id' => $serviceId])
        || catalogReferenceExists($pdo, 'SELECT 1 FROM feedback_entries WHERE service_id = :id LIMIT 1', [':id' => $serviceId])) {
        return 'it is referenced by feedback history';
    }
    if (catalogReferenceExists($pdo, 'SELECT 1 FROM feedbacks WHERE service_id IS NULL AND service = :name LIMIT 1', [':name' => $serviceName])
        || catalogReferenceExists($pdo, 'SELECT 1 FROM feedback_entries WHERE service_id IS NULL AND service = :name LIMIT 1', [':name' => $serviceName])) {
        return 'legacy feedback records use its name';
    }
    return null;
}

function subserviceDeleteBlocker(PDO $pdo, int $subserviceId): ?string
{
    if (catalogReferenceExists($pdo, 'SELECT 1 FROM feedbacks WHERE service_subtype_id = :id LIMIT 1', [':id' => $subserviceId])
        || catalogReferenceExists($pdo, 'SELECT 1 FROM feedback_entries WHERE service_subtype_id = :id LIMIT 1', [':id' => $subserviceId])) {
        return 'it is referenced by feedback history';
    }
    return null;
}

function subserviceNameExists(PDO $pdo, int $serviceId, string $name, ?int $excludeId = null): bool
{
    $sql = 'SELECT 1 FROM service_subtypes WHERE service_id = :service_id AND name = :name';
    $params = [':service_id' => $serviceId, ':name' => $name];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $excludeId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
        sendCatalog($pdo);
    }

    if ($method !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = readJsonBody();
    $action = trim((string) ($payload['action'] ?? ''));

    if ($action === '') {
        jsonResponse(['success' => false, 'message' => 'Action is required'], 422);
    }

    if ($action === 'createService') {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'Service name is required'], 422);
        }

        if (servicesDepartmentIdStillExists($pdo)) {
            // Placeholder only: the live services.department_id column is
            // still NOT NULL until the pending schema migration drops it.
            // This value is never read or exposed anywhere.
            $stmt = $pdo->prepare('INSERT INTO services (department_id, name) VALUES (1, :name)');
        } else {
            $stmt = $pdo->prepare('INSERT INTO services (name) VALUES (:name)');
        }
        $stmt->execute([':name' => $name]);
        sendCatalog($pdo);
    }

    if ($action === 'updateService') {
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));

        if ($id <= 0 || $name === '') {
            jsonResponse(['success' => false, 'message' => 'Valid service id and name are required'], 422);
        }

        $stmt = $pdo->prepare('UPDATE services SET name = :name WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
        ]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'Service not found or unchanged'], 404);
        }
        sendCatalog($pdo);
    }

    if ($action === 'deleteService') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid service id is required'], 422);
        }

        // Archive/deactivate service instead of permanent deletion
        $stmt = $pdo->prepare('UPDATE services SET status = "inactive" WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendCatalog($pdo);
    }

    if ($action === 'reactivateService') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid service id is required'], 422);
        }

        $stmt = $pdo->prepare('UPDATE services SET status = "active" WHERE id = :id AND status = "inactive"');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'Inactive service not found'], 404);
        }
        sendCatalog($pdo);
    }

    if ($action === 'deleteServicePermanently') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Valid service id is required'], 422);
        }

        $serviceStmt = $pdo->prepare('SELECT id, name FROM services WHERE id = :id LIMIT 1');
        $serviceStmt->execute([':id' => $id]);
        $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
        if ($service === false) {
            jsonResponse(['success' => false, 'message' => 'Service not found'], 404);
        }
        $blocker = serviceDeleteBlocker($pdo, (int) $service['id'], (string) $service['name']);
        if ($blocker !== null) {
            jsonResponse(['success' => false, 'message' => 'Cannot permanently delete this service because ' . $blocker . '. Deactivate it instead.'], 409);
        }

        $stmt = $pdo->prepare('DELETE FROM services WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendCatalog($pdo);
    }

    if ($action === 'create_subservice') {
        $name = catalogName($payload['name'] ?? null);
        $serviceId = catalogPositiveId($payload['service_id'] ?? null);

        if ($name === null || $serviceId === null) {
            jsonResponse(['success' => false, 'message' => 'Valid service id and sub-service name are required'], 422);
        }
        if (!activeServiceExists($pdo, $serviceId)) {
            jsonResponse(['success' => false, 'message' => 'Selected service is not available'], 422);
        }
        if (subserviceNameExists($pdo, $serviceId, $name)) {
            jsonResponse(['success' => false, 'message' => 'A sub-service with this name already exists for the selected service'], 422);
        }

        $stmt = $pdo->prepare('INSERT INTO service_subtypes (service_id, name, status) VALUES (:service_id, :name, "active")');
        $stmt->execute([':service_id' => $serviceId, ':name' => $name]);
        sendCatalog($pdo);
    }

    if ($action === 'update_subservice') {
        $id = catalogPositiveId($payload['id'] ?? null);
        $name = catalogName($payload['name'] ?? null);
        $serviceId = catalogPositiveId($payload['service_id'] ?? null);

        if ($id === null || $name === null || $serviceId === null) {
            jsonResponse(['success' => false, 'message' => 'Valid sub-service id, service id, and name are required'], 422);
        }
        if (findSubservice($pdo, $id) === null) {
            jsonResponse(['success' => false, 'message' => 'Sub-service not found'], 404);
        }
        if (!activeServiceExists($pdo, $serviceId)) {
            jsonResponse(['success' => false, 'message' => 'Selected service is not available'], 422);
        }
        if (subserviceNameExists($pdo, $serviceId, $name, $id)) {
            jsonResponse(['success' => false, 'message' => 'A sub-service with this name already exists for the selected service'], 422);
        }

        $stmt = $pdo->prepare('UPDATE service_subtypes SET service_id = :service_id, name = :name WHERE id = :id');
        $stmt->execute([':id' => $id, ':service_id' => $serviceId, ':name' => $name]);
        sendCatalog($pdo);
    }

    if ($action === 'deactivate_subservice') {
        $id = catalogPositiveId($payload['id'] ?? null);
        if ($id === null) {
            jsonResponse(['success' => false, 'message' => 'Valid sub-service id is required'], 422);
        }
        if (findSubservice($pdo, $id) === null) {
            jsonResponse(['success' => false, 'message' => 'Sub-service not found'], 404);
        }

        $stmt = $pdo->prepare('UPDATE service_subtypes SET status = "inactive" WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendCatalog($pdo);
    }

    if ($action === 'reactivate_subservice') {
        $id = catalogPositiveId($payload['id'] ?? null);
        if ($id === null) {
            jsonResponse(['success' => false, 'message' => 'Valid sub-service id is required'], 422);
        }

        $subservice = findSubservice($pdo, $id);
        if ($subservice === null || (string) $subservice['status'] !== 'inactive') {
            jsonResponse(['success' => false, 'message' => 'Inactive sub-service not found'], 404);
        }
        if (!activeServiceExists($pdo, (int) $subservice['service_id'])) {
            jsonResponse(['success' => false, 'message' => 'Reactivate the parent service before reactivating this sub-service'], 422);
        }

        $stmt = $pdo->prepare('UPDATE service_subtypes SET status = "active" WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendCatalog($pdo);
    }

    if ($action === 'delete_subservice_permanently') {
        $id = catalogPositiveId($payload['id'] ?? null);
        if ($id === null) {
            jsonResponse(['success' => false, 'message' => 'Valid sub-service id is required'], 422);
        }
        if (findSubservice($pdo, $id) === null) {
            jsonResponse(['success' => false, 'message' => 'Sub-service not found'], 404);
        }
        $blocker = subserviceDeleteBlocker($pdo, $id);
        if ($blocker !== null) {
            jsonResponse(['success' => false, 'message' => 'Cannot permanently delete this sub-service because ' . $blocker . '. Deactivate it instead.'], 409);
        }

        $stmt = $pdo->prepare('DELETE FROM service_subtypes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        sendCatalog($pdo);
    }

    jsonResponse(['success' => false, 'message' => 'Unsupported action'], 422);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Unable to update the service catalog'], 500);
}
