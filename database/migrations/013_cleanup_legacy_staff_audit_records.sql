-- SFMS has one Super Admin and no individual staff-management audit scope.
-- Keep legitimate feedback/system events while removing obsolete legacy rows.
DELETE FROM audit_trail
WHERE action IN ('staff_created', 'staff_updated', 'staff_archived');
