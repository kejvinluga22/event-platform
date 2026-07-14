<?php
/**
 * cron/mail_queue_worker.php
 * Processes the decoupled email_queue asynchronously. Schedule under XAMPP via
 * Windows Task Scheduler / cron, e.g. every minute:
 *
 *   * * * * *  php /path/to/backend-php/cron/mail_queue_worker.php
 *
 * Handles scheduled reminders/broadcasts (scheduled_at) and multi-provider
 * failover, updating delivery state: Queued -> Sent -> Failed (+bounce reason).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Utils\MailService;

const BATCH_SIZE   = 25;
const MAX_ATTEMPTS = 5;

$db = Database::connection();

$stmt = $db->prepare(
    "SELECT * FROM email_queue
     WHERE status = 'Queued'
       AND attempts < :max
       AND (scheduled_at IS NULL OR scheduled_at <= NOW())
     ORDER BY created_at
     LIMIT :batch"
);
$stmt->bindValue(':max', MAX_ATTEMPTS, PDO::PARAM_INT);
$stmt->bindValue(':batch', BATCH_SIZE, PDO::PARAM_INT);
$stmt->execute();
$jobs = $stmt->fetchAll();

if (!$jobs) {
    fwrite(STDOUT, "[mail_queue] nothing to send\n");
    exit(0);
}

$sentCount = 0;
$failCount = 0;

foreach ($jobs as $job) {
    $result = MailService::send(
        $job['to_email'],
        $job['subject'],
        $job['body_html'],
        $job['attachment_path'] ?: null
    );

    if ($result['sent']) {
        $db->prepare(
            "UPDATE email_queue
                SET status = 'Sent', provider = :p, sent_at = NOW(), attempts = attempts + 1
              WHERE id = :id"
        )->execute([':p' => $result['provider'], ':id' => $job['id']]);
        $sentCount++;
    } else {
        $attempts = (int) $job['attempts'] + 1;
        $finalState = $attempts >= MAX_ATTEMPTS ? 'Failed' : 'Queued';
        $db->prepare(
            "UPDATE email_queue
                SET status = :st, attempts = :a, bounce_reason = :br
              WHERE id = :id"
        )->execute([
            ':st' => $finalState,
            ':a'  => $attempts,
            ':br' => substr((string) $result['error'], 0, 255),
            ':id' => $job['id'],
        ]);
        $failCount++;
    }
}

fwrite(STDOUT, "[mail_queue] sent=$sentCount failed=$failCount processed=" . count($jobs) . "\n");
exit(0);
