<?php
declare(strict_types=1);

namespace App\Utils;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = [], string $message = 'OK'): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    public static function created(array $data = [], string $message = 'Created'): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], 201);
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }
}
