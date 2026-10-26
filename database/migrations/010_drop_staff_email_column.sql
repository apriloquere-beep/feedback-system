-- Migration 010: Drop the unused `staff.email` column
--
-- Inspection (2026-09-11): the live `staff` table already holds the 10
-- canonical staff-role rows (full_name = display label, role = the stable
-- st0-st9 key used throughout ratings_json/ratings, is_active + archived_at
-- for soft-deactivation) rather than the individually-named employees from
-- the original web_system.sql seed. `email` is NULL for all 10 rows and is
-- never read or written anywhere in the live application (api/*.php,
-- js/admin/*.js, form/feedback.php) — only referenced by the old
-- web_system.sql seed script and database/scripts/seed_demo_data.php, both
-- of which target the pre-pivot individually-named-staff schema and are
-- already incompatible with the current `role` column. Safe to drop.
--
-- Per project instructions: this does NOT repurpose/rename the column into
-- a rating field — ratings continue to come exclusively from
-- feedbacks.ratings_json / feedback_entries.ratings (st0-st9 keys).

ALTER TABLE staff DROP COLUMN email;
