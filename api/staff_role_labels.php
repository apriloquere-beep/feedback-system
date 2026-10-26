<?php

declare(strict_types=1);

/**
 * Overrides the fb2_staff_st0-st9 entries in a loaded translations array
 * with the live label from the `staff` table — the single source of truth
 * for staff-role display names, managed via the admin dashboard's "Manage
 * Staff Roles" (see api/staff_roles.php). Without this, feedback_submitted.php,
 * api/send_feedback_copy.php and api/feedback_detail.php would each keep
 * showing the static label baked into lang/english.php / lang/bisaya.php /
 * lang/tagalog.php even after an admin renames a role.
 *
 * Includes inactive roles too — a role no longer offered on NEW feedback
 * must still show its real (current) label when displaying a historical
 * record that already rated it under that st-key.
 *
 * Silently keeps the existing static labels if the database can't be
 * reached; this must never be the reason a receipt/detail page fails.
 */
function applyStaffRoleLabelOverrides(array &$labels): void
{
    try {
        require_once __DIR__ . '/db.php';
        $rows = getPdo()->query('SELECT role, full_name FROM staff')->fetchAll();
        foreach ($rows as $row) {
            $labels['fb2_staff_' . $row['role']] = $row['full_name'];
        }
    } catch (Throwable $e) {
        // Keep whatever labels were already loaded.
    }
}
