<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;

final class AuditLogger
{
    public static function log(PDO $db, ?int $actorId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $detail = null): void
    {
        $stmt = $db->prepare(
            'INSERT INTO system_logs (actor_id, action, target_type, target_id, detail, ip_address)
             VALUES (:actor, :action, :ttype, :tid, :detail, :ip)'
        );
        $stmt->execute([
            ':actor'  => $actorId,
            ':action' => $action,
            ':ttype'  => $targetType,
            ':tid'    => $targetId,
            ':detail' => $detail,
            ':ip'     => Request::ip(),
        ]);
    }
}
