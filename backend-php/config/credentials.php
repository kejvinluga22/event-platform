<?php
/**
 * config/credentials.php
 * Loads runtime configuration. Real secrets live in backend-php/.env (NOT committed).
 * Falls back to sane XAMPP defaults for local development.
 */

declare(strict_types=1);

// Load .env if present (vlucas/phpdotenv). Safe to skip if vendor not installed yet.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\Dotenv\Dotenv::class) && is_file(__DIR__ . '/../.env')) {
        \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
    }
}

$env = static fn (string $key, $default = null) => $_ENV[$key] ?? getenv($key) ?: $default;

return [
    'app' => [
        'name'      => $env('APP_NAME', 'Smart Event Platform'),
        'env'       => $env('APP_ENV', 'local'),
        'debug'     => filter_var($env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOL),
        'base_url'  => $env('APP_BASE_URL', 'http://localhost/backend-php/api'),
        'front_url' => $env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'smart_event'),
        'user'    => $env('DB_USER', 'root'),
        'pass'    => $env('DB_PASS', ''),          // default XAMPP root has empty password
        'charset' => 'utf8mb4',
    ],

    'jwt' => [
        // CHANGE THIS in .env for production.
        'secret'      => $env('JWT_SECRET', 'change-me-super-secret-key-please-rotate'),
        'qr_secret'   => $env('QR_SECRET', 'change-me-qr-signing-secret'),
        'issuer'      => $env('JWT_ISSUER', 'smart-event-platform'),
        'ttl'         => (int) $env('JWT_TTL', '3600'),        // access token seconds
        'algo'        => 'HS256',
    ],

    'mail' => [
        // Multi-provider failover order; first reachable wins.
        'from_email'  => $env('MAIL_FROM', 'no-reply@smartevent.local'),
        'from_name'   => $env('MAIL_FROM_NAME', 'Smart Event'),
        'providers'   => [
            'gmail'    => ['host' => 'smtp.gmail.com',      'port' => 587, 'user' => $env('GMAIL_USER'),    'pass' => $env('GMAIL_PASS'),    'enc' => 'tls'],
            'outlook'  => ['host' => 'smtp.office365.com',  'port' => 587, 'user' => $env('OUTLOOK_USER'),  'pass' => $env('OUTLOOK_PASS'),  'enc' => 'tls'],
            'sendgrid' => ['host' => 'smtp.sendgrid.net',   'port' => 587, 'user' => 'apikey',              'pass' => $env('SENDGRID_KEY'),  'enc' => 'tls'],
            'mailgun'  => ['host' => 'smtp.mailgun.org',    'port' => 587, 'user' => $env('MAILGUN_USER'),  'pass' => $env('MAILGUN_PASS'),  'enc' => 'tls'],
            'brevo'    => ['host' => 'smtp-relay.brevo.com','port' => 587, 'user' => $env('BREVO_USER'),    'pass' => $env('BREVO_PASS'),    'enc' => 'tls'],
        ],
    ],

    'security' => [
        'rate_limit_window'   => 60 * 15, // 15-minute window
        'rate_limit_max'      => 30,      // failed attempts before lock
        'rate_limit_lock'     => 60 * 15, // 15-minute cool-down lock
        'cors_allowed_origin' => $env('FRONTEND_URL', 'http://localhost:5173'),
    ],
];
