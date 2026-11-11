-- =====================================================================
--  Smart Event Platform — v4 migration
--  Registration options per event:
--    • events.issue_tickets        1 = send a ticket, 0 = registration only
--    • events.ask_ticket_type      ask attendee to pick a ticket type (VIP...)
--    • events.ticket_type_options  comma-separated choices for that question
--    • events.ask_age              show an "Age" field on the public form
--    • registrations.attendee_age  the age the attendee entered
--
--  NOTE: the API applies these automatically on boot (SchemaUpgrade.php),
--  so on Railway you don't need to run this by hand. It's here for
--  local / XAMPP installs. Idempotent on MariaDB / MySQL 8.0.29+.
-- =====================================================================

USE smart_event;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS issue_tickets       TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS ask_ticket_type     TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS ticket_type_options VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS ask_age             TINYINT(1)   NOT NULL DEFAULT 0;

ALTER TABLE registrations
    ADD COLUMN IF NOT EXISTS attendee_age SMALLINT UNSIGNED NULL AFTER phone;
