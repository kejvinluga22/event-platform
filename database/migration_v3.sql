-- =====================================================================
--  Smart Event Platform — v3 migration  (THE MISSING ONE)
--  Aligns the database with columns the backend code already uses:
--    • events.deleted_at        (soft delete — list/stats/delete/restore)
--    • events.email_subject     (custom confirmation email subject)
--    • registrations.first_name (public registration form)
--    • registrations.last_name  (public registration form)
--    • registrations.phone      (public registration form)
--    • users.last_login_at      (login tracking)
--  Idempotent — safe to re-run (MariaDB / MySQL 8.0.29+).
-- =====================================================================

USE smart_event;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS email_subject VARCHAR(255) NULL AFTER organizer_name,
    ADD COLUMN IF NOT EXISTS deleted_at    DATETIME     NULL AFTER updated_at;

ALTER TABLE registrations
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER attendee_name,
    ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS phone      VARCHAR(40)  NULL AFTER attendee_email;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER is_active;

-- Helpful index for the soft-delete filter used on every event list query.
-- (CREATE INDEX IF NOT EXISTS works on MariaDB; MySQL users can ignore an
--  "index already exists" error on re-run.)
CREATE INDEX IF NOT EXISTS idx_events_deleted ON events (deleted_at);
