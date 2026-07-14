<?php
/**
 * api/index.php — single front controller for the REST API.
 * All client entry-points hit this stateless router.
 */

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\EventController;
use App\Controllers\RegistrationController;
use App\Controllers\ScannerController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Utils\Response;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

$cfg = require __DIR__ . '/../config/credentials.php';

// ---- CORS ----
$origin = $cfg['security']['cors_allowed_origin'];
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db     = Database::connection();
$method = $_SERVER['REQUEST_METHOD'];

// Derive the path after /api/ regardless of mount point.
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = preg_replace('#^.*/api/?#', '', $uri);
$path = '/' . trim((string) $path, '/');

// Route table: [METHOD, regex, handler]. {n} captures become handler args.
$routes = [
    ['POST',  '#^/auth/login$#',              fn ()        => (new AuthController($db))->login()],
    ['POST',  '#^/auth/logout$#',             fn ()        => (new AuthController($db))->logout()],
    ['GET',   '#^/auth/me$#',                 fn ()        => (new AuthController($db))->me()],
    ['POST',  '#^/auth/users$#',              fn ()        => (new AuthController($db))->createUser()],

    // settings (admin SMTP config)
    ['GET',   '#^/settings$#',                fn ()        => (new SettingsController($db))->index()],
    ['PUT',   '#^/settings/smtp$#',           fn ()        => (new SettingsController($db))->saveSmtp()],
    ['POST',  '#^/settings/smtp/test$#',      fn ()        => (new SettingsController($db))->testSmtp()],

    // user management (super_admin)
    ['GET',   '#^/users$#',                   fn ()        => (new UserController($db))->index()],
    ['POST',  '#^/users$#',                   fn ()        => (new UserController($db))->store()],
    ['PUT',   '#^/users/(\d+)$#',             fn ($id)     => (new UserController($db))->update((int) $id)],
    ['POST',  '#^/users/(\d+)/reset-password$#', fn ($id)  => (new UserController($db))->resetPassword((int) $id)],
    ['DELETE','#^/users/(\d+)$#',             fn ($id)     => (new UserController($db))->destroy((int) $id)],

    ['GET',   '#^/events$#',                  fn ()        => (new EventController($db))->index()],
    ['GET',   '#^/events/stats$#',            fn ()        => (new EventController($db))->stats()],
    ['POST',  '#^/events$#',                  fn ()        => (new EventController($db))->store()],
    ['GET',   '#^/events/public/([\w-]+)$#',  fn ($slug)   => (new EventController($db))->publicShow($slug)],
    ['GET',   '#^/events/(\d+)$#',            fn ($id)     => (new EventController($db))->show((int) $id)],
    ['PUT',   '#^/events/(\d+)$#',            fn ($id)     => (new EventController($db))->update((int) $id)],
    ['PATCH', '#^/events/(\d+)/status$#',     fn ($id)     => (new EventController($db))->setStatus((int) $id)],
    ['DELETE','#^/events/(\d+)$#',            fn ($id)     => (new EventController($db))->destroy((int) $id)],
    ['POST',  '#^/events/(\d+)/restore$#',    fn ($id)     => (new EventController($db))->restore((int) $id)],
    ['POST',  '#^/events/(\d+)/clone$#',      fn ($id)     => (new EventController($db))->clone((int) $id)],
    ['GET',   '#^/events/(\d+)/analytics$#',  fn ($id)     => (new EventController($db))->analytics((int) $id)],
    ['PUT',   '#^/events/(\d+)/form$#',       fn ($id)     => (new EventController($db))->saveForm((int) $id)],
    ['GET',   '#^/events/(\d+)/export$#',     fn ($id)     => (new RegistrationController($db))->exportCsv((int) $id)],
    ['GET',   '#^/events/(\d+)/export/xlsx$#', fn ($id)    => (new RegistrationController($db))->exportXlsx((int) $id)],
    ['GET',   '#^/events/(\d+)/export/pdf$#',  fn ($id)    => (new RegistrationController($db))->exportPdf((int) $id)],
    ['POST',  '#^/events/(\d+)/walkin$#',     fn ($id)     => (new RegistrationController($db))->walkIn((int) $id)],

    ['POST',  '#^/register/([\w-]+)$#',       fn ($slug)   => (new RegistrationController($db))->register($slug)],
    ['POST',  '#^/registrations/(\d+)/resend$#', fn ($id)  => (new RegistrationController($db))->resendTicket((int) $id)],
    ['GET',   '#^/registrations/(\d+)/ticket\.pdf$#', fn ($id) => (new RegistrationController($db))->ticketPdf((int) $id)],
    ['GET',   '#^/registrations/(\d+)/badge$#', fn ($id)   => (new RegistrationController($db))->badge((int) $id)],
    ['GET',   '#^/registrations/(\d+)/certificate$#', fn ($id) => (new RegistrationController($db))->certificate((int) $id)],
    ['GET',   '#^/ticket/(\d+)$#',            fn ($id)     => (new RegistrationController($db))->ticket((int) $id)],

    ['POST',  '#^/scan$#',                    fn ()        => (new ScannerController($db))->scan()],
    ['POST',  '#^/scan/sync$#',               fn ()        => (new ScannerController($db))->syncBatch()],
    ['GET',   '#^/scan/lookup$#',             fn ()        => (new ScannerController($db))->lookup()],

    ['GET',   '#^/?$#',                       fn ()        => Response::ok(['name' => $cfg['app']['name'], 'status' => 'online'])],
];

try {
    foreach ($routes as [$verb, $pattern, $handler]) {
        if ($verb === $method && preg_match($pattern, $path, $m)) {
            array_shift($m);
            $handler(...$m);
            exit;
        }
    }
    Response::error('Route not found: ' . $method . ' ' . $path, 404);
} catch (\Throwable $e) {
    $debug = $cfg['app']['debug'];
    Response::error('Server error', 500, $debug ? [
        'detail' => $e->getMessage(),
        'where'  => $e->getFile() . ':' . $e->getLine(),
    ] : []);
}
