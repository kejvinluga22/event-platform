-- =====================================================================
--  Smart Event Attendance & Registration Platform
--  Combined Schema (includes v2 migration: settings table +
--  events.price / currency / minimum_age / organizer_name)
--  MySQL 8.x or MariaDB 10.4+ (XAMPP compatible)
--  Run:  mysql -u root -p < smart_event_full.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS smart_event
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_event;

-- ---------------------------------------------------------------------
--  USERS  (RBAC accounts: super_admin / event_manager / staff / scanner)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name       VARCHAR(150)    NOT NULL,
    email           VARCHAR(190)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    role            ENUM('super_admin','event_manager','staff','scanner')
                                    NOT NULL DEFAULT 'staff',
    tenant_id       BIGINT UNSIGNED NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  EVENTS  (includes v2 ticket fields: price, currency, minimum_age,
--  organizer_name)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id        BIGINT UNSIGNED NOT NULL,          -- FK -> users.id
    title           VARCHAR(200)    NOT NULL,
    slug            VARCHAR(220)    NOT NULL,
    description     TEXT            NULL,
    venue           VARCHAR(255)    NULL,
    venue_lat       DECIMAL(10,7)   NULL,
    venue_lng       DECIMAL(10,7)   NULL,
    timezone        VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    starts_at_utc   DATETIME        NULL,
    ends_at_utc     DATETIME        NULL,
    capacity        INT             NOT NULL DEFAULT 0,   -- 0 = unlimited
    price           DECIMAL(10,2)   NULL,
    currency        VARCHAR(8)      NOT NULL DEFAULT 'ALL',
    minimum_age     TINYINT         NOT NULL DEFAULT 0,
    organizer_name  VARCHAR(120)    NULL,
    waitlist_enabled TINYINT(1)     NOT NULL DEFAULT 0,
    branding_json   JSON            NULL,                 -- colors, logo, css overrides
    status          ENUM('draft','published','live','paused','archived')
                                    NOT NULL DEFAULT 'draft',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_slug (slug),
    KEY idx_events_owner (owner_id),
    KEY idx_events_status (status),
    CONSTRAINT fk_events_owner FOREIGN KEY (owner_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  FORM_CONFIGS  (schema-driven dynamic form per event)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS form_configs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        BIGINT UNSIGNED NOT NULL,           -- FK -> events.id
    version         INT             NOT NULL DEFAULT 1,
    field_json      JSON            NOT NULL,           -- array of field definitions
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_formcfg_event (event_id),
    CONSTRAINT fk_formcfg_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  REGISTRATIONS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registrations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        BIGINT UNSIGNED NOT NULL,           -- FK -> events.id
    user_id         BIGINT UNSIGNED NULL,               -- FK -> users.id (NULL for public guests)
    attendee_name   VARCHAR(190)    NOT NULL,
    attendee_email  VARCHAR(190)    NOT NULL,
    access_level    VARCHAR(80)     NOT NULL DEFAULT 'general',
    qr_token        VARCHAR(512)    NULL,               -- signed JWT payload
    form_data       JSON            NULL,               -- dynamic answers
    status          ENUM('pending','confirmed','waitlisted','cancelled')
                                    NOT NULL DEFAULT 'pending',
    checkin_status  ENUM('Pending','CheckedIn')         NOT NULL DEFAULT 'Pending',
    source          ENUM('public','walkin','import')    NOT NULL DEFAULT 'public',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reg_event (event_id),
    KEY idx_reg_user (user_id),
    KEY idx_reg_email (attendee_email),
    KEY idx_reg_checkin (checkin_status),
    CONSTRAINT fk_reg_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  ATTENDANCE_LOG
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,           -- FK -> registrations.id
    scanned_by      BIGINT UNSIGNED NULL,               -- FK -> users.id
    scan_result     ENUM('success','duplicate','invalid') NOT NULL DEFAULT 'success',
    gate            VARCHAR(80)     NULL,
    scanned_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_att_reg (registration_id),
    KEY idx_att_scanner (scanned_by),
    CONSTRAINT fk_att_reg FOREIGN KEY (registration_id)
        REFERENCES registrations (id) ON DELETE CASCADE,
    CONSTRAINT fk_att_scanner FOREIGN KEY (scanned_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  EMAIL_QUEUE  (decoupled async sender, processed by cron worker)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NULL,
    to_email        VARCHAR(190)    NOT NULL,
    subject         VARCHAR(255)    NOT NULL,
    body_html       MEDIUMTEXT      NOT NULL,
    attachment_path VARCHAR(512)    NULL,
    provider        VARCHAR(40)     NULL,
    status          ENUM('Queued','Sent','Delivered','Failed') NOT NULL DEFAULT 'Queued',
    attempts        INT             NOT NULL DEFAULT 0,
    bounce_reason   VARCHAR(255)    NULL,
    scheduled_at    DATETIME        NULL,               -- for reminders / broadcasts
    sent_at         DATETIME        NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mq_status (status),
    KEY idx_mq_sched (scheduled_at),
    CONSTRAINT fk_mq_reg FOREIGN KEY (registration_id)
        REFERENCES registrations (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  SYSTEM_LOGS  (granular audit trail)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id        BIGINT UNSIGNED NULL,               -- FK -> users.id
    action          VARCHAR(120)    NOT NULL,
    target_type     VARCHAR(60)     NULL,
    target_id       BIGINT UNSIGNED NULL,
    detail          TEXT            NULL,
    ip_address      VARCHAR(45)     NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_actor (actor_id),
    KEY idx_logs_action (action)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  RATE_LIMITS  (brute-force protection persistence)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address      VARCHAR(45)     NOT NULL,
    route_key       VARCHAR(120)    NOT NULL,
    hits            INT             NOT NULL DEFAULT 1,
    locked_until    DATETIME        NULL,
    window_start    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rl (ip_address, route_key)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  SETTINGS  (key/value store; edited from admin UI)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key    VARCHAR(80)   NOT NULL,
    setting_value  TEXT          NULL,
    updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('smtp.provider',   'gmail'),
    ('smtp.host',       'smtp.gmail.com'),
    ('smtp.port',       '587'),
    ('smtp.encryption', 'tls'),
    ('smtp.user',       ''),
    ('smtp.pass',       ''),
    ('smtp.from_email', 'no-reply@smartevent.local'),
    ('smtp.from_name',  'Smart Event');

-- =====================================================================
--  SEED DATA  (default super admin -> password: Admin@123)
--  Hash generated with PHP password_hash(..., PASSWORD_BCRYPT)
-- =====================================================================
INSERT INTO users (full_name, email, password_hash, role)
VALUES (
    'System Administrator',
    'admin@smartevent.local',
    '$2y$10$fJt6XCrbMzf7Jl9nPWl4ku5A2LIqNELJ.7KTrw4gMUaHggQ1VJ4BW',
    'super_admin'
)
ON DUPLICATE KEY UPDATE email = email;