<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;

/**
 * Self-applying, idempotent schema upgrades.
 *
 * On Railway there is no step where someone runs SQL by hand after a deploy,
 * so any column the code relies on is added here automatically the first time
 * the API boots against a database that doesn't have it yet. Each column is
 * checked in information_schema first (works on MySQL 5.7/8 and MariaDB), and
 * a marker file in the temp dir means the check runs once per container.
 *
 * The same changes are in database/migration_v4.sql for local/XAMPP installs.
 */
final class SchemaUpgrade
{
    /** Bump when adding entries below so running containers re-check. */
    private const VERSION = 'v4';

    /** table => [column => definition] */
    private const COLUMNS = [
        'events' => [
            'email_subject'       => 'VARCHAR(255) NULL',
            'deleted_at'          => 'DATETIME NULL',
            // Ticket mode (1) vs registration-only mode (0, e.g. ceremonies).
            'issue_tickets'       => 'TINYINT(1) NOT NULL DEFAULT 1',
            // Ask the attendee to choose a ticket type (VIP, General...).
            'ask_ticket_type'     => 'TINYINT(1) NOT NULL DEFAULT 1',
            // Comma-separated choices for the ticket type question.
            'ticket_type_options' => 'VARCHAR(500) NULL',
            // Show an "Age" field on the public registration form.
            'ask_age'             => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'registrations' => [
            'first_name'   => 'VARCHAR(100) NULL',
            'last_name'    => 'VARCHAR(100) NULL',
            'phone'        => 'VARCHAR(40) NULL',
            'attendee_age' => 'SMALLINT UNSIGNED NULL',
        ],
        'users' => [
            'last_login_at' => 'DATETIME NULL',
        ],
    ];

    public static function run(PDO $db): void
    {
        $marker = sys_get_temp_dir() . '/smart_event_schema_' . self::VERSION;
        if (is_file($marker)) {
            return;
        }
        try {
            $check = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
            );
            foreach (self::COLUMNS as $table => $columns) {
                foreach ($columns as $column => $definition) {
                    $check->execute([':t' => $table, ':c' => $column]);
                    if ((int) $check->fetchColumn() === 0) {
                        $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                    }
                }
            }
            @file_put_contents($marker, date('c'));
        } catch (\Throwable $e) {
            // Never take the API down over this; the next request retries.
            error_log('SchemaUpgrade failed: ' . $e->getMessage());
        }
    }
}
