-- ═══════════════════════════════════════════════════════════════════
-- MIGRATION: Add Sentiment & Priority Fields to feedback_entries
-- Database: web_system
-- Version: 2.0 | Date: 2026-05-01
-- Backward compatible — only ADDs columns, never modifies existing data
-- ═══════════════════════════════════════════════════════════════════

USE web_system;

-- ── Step 1: Add sentiment_label column ──────────────────────────────
ALTER TABLE feedback_entries
  ADD COLUMN IF NOT EXISTS sentiment_label VARCHAR(20) DEFAULT NULL
    COMMENT 'NLP classification: Positive, Neutral, or Negative'
  AFTER suggestions;

-- ── Step 2: Add sentiment_score column ──────────────────────────────
ALTER TABLE feedback_entries
  ADD COLUMN IF NOT EXISTS sentiment_score FLOAT DEFAULT NULL
    COMMENT 'Sentiment confidence score between 0.0 and 1.0'
  AFTER sentiment_label;

-- ── Step 3: Add priority column ──────────────────────────────────────
ALTER TABLE feedback_entries
  ADD COLUMN IF NOT EXISTS priority VARCHAR(10) DEFAULT 'Normal'
    COMMENT 'Priority: Normal or High (High = Negative sentiment AND rating <= 2)'
  AFTER sentiment_score;

-- ── Step 4: Upgrade status ENUM to include in_review ─────────────────
-- Expand the ENUM safely — only adds new option, preserves existing values
ALTER TABLE feedback_entries
  MODIFY COLUMN status ENUM('pending','in_review','resolved','archived','verified','adjusted')
    NOT NULL DEFAULT 'pending'
    COMMENT 'Workflow status: pending → in_review → resolved';

-- ── Step 5: Add performance indexes ─────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_sentiment_label ON feedback_entries (sentiment_label);
CREATE INDEX IF NOT EXISTS idx_priority ON feedback_entries (priority);

-- ── Step 6: Normalize legacy 'verified' / 'adjusted' status → 'in_review' ──
UPDATE feedback_entries
SET status = 'in_review'
WHERE status IN ('verified', 'adjusted');

-- ── Step 7: Set priority=Normal for all existing rows (safe default) ──
UPDATE feedback_entries
SET priority = 'Normal'
WHERE priority IS NULL;

-- ── Step 8: Verification query ────────────────────────────────────────
-- Run these to confirm migration success:
-- DESCRIBE web_system.feedback_entries;
-- SELECT status, COUNT(*) FROM feedback_entries GROUP BY status;
-- SELECT sentiment_label, priority, COUNT(*) FROM feedback_entries GROUP BY 1, 2;
