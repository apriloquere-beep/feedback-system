-- Apply once to databases created before admin_users had a username column.
ALTER TABLE admin_users
    ADD COLUMN username VARCHAR(50) NULL UNIQUE AFTER id;

UPDATE admin_users
SET username = 'SFMS_Admin'
WHERE role = 'super_admin' AND status = 'active'
ORDER BY id ASC
LIMIT 1;
