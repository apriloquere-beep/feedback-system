-- Apply once before enabling final Others submissions.
-- Keeps the canonical services.id=14 / services.name=Others identity intact.
ALTER TABLE feedback_entries ADD COLUMN service_other VARCHAR(120) NULL AFTER service_subtype_other;
ALTER TABLE feedbacks ADD COLUMN service_other VARCHAR(120) NULL AFTER service_subtype_other;
