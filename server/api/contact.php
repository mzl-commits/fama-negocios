<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_name('fama_quote');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'use_strict_mode' => true]);

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
$to = (string)(getenv('FAMA_MAIL_TO') ?: '');
$from = (string)(getenv('FAMA_MAIL_FROM') ?: '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) respond(503, ['error' => 'El correo está pendiente de configuración. Puedes comunicarte por WhatsApp.']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    respond(200, ['token' => $_SESSION['csrf']]);
}
if ($method !== 'POST') respond(405, ['error' => 'Método no permitido.']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) respond(413, ['error' => 'El mensaje es demasiado extenso.']);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($input['token'] ?? ''))) respond(403, ['error' => 'Recarga la página para volver a enviar la solicitud.']);
if (!empty($input['website'])) respond(400, ['error' => 'No se pudo procesar la solicitud.']);
$values = [];
foreach (['name' => 100, 'company' => 150, 'email' => 150, 'phone' => 30, 'division' => 80, 'message' => 4000] as $field => $max) {
    if (isset($input[$field]) && !is_string($input[$field])) respond(422, ['error' => 'Revisa los campos de la solicitud.']);
    $value = trim((string)($input[$field] ?? ''));
    if (strlen($value) > $max) respond(422, ['error' => 'Revisa la longitud de los campos.']);
    $values[$field] = $value;
}
if ($values['name'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['message']) < 10 || !in_array($values['division'], ['publicitaria', 'promocional', 'servicios-generales', 'star-home', 'orientacion'], true)) respond(422, ['error' => 'Completa el nombre, correo, división y un mensaje de al menos 10 caracteres.']);
$ratePath = sys_get_temp_dir() . '/fama-quote-' . hash('sha256', __DIR__ . ($_SERVER['REMOTE_ADDR'] ?? ''));
$rate = fopen($ratePath, 'c+');
if (!$rate || !flock($rate, LOCK_EX)) respond(503, ['error' => 'Inténtalo de nuevo en unos minutos.']);
$last = (int)stream_get_contents($rate);
if (time() - $last < 60) { flock($rate, LOCK_UN); fclose($rate); respond(429, ['error' => 'Espera un minuto antes de enviar otra solicitud.']); }
rewind($rate); ftruncate($rate, 0); fwrite($rate, (string)time()); fflush($rate); flock($rate, LOCK_UN); fclose($rate);
$body = "Solicitud desde la web de FAMA\n\n";
foreach ($values as $key => $value) $body .= $key . ': ' . $value . "\n";
$headers = ['From' => $from, 'Reply-To' => $values['email'], 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8'];
if (!mail($to, 'Solicitud de cotizacion desde la web de FAMA', $body, $headers)) respond(502, ['error' => 'No se pudo entregar el correo. Puedes usar el enlace de WhatsApp.']);
respond(200, ['message' => 'Tu solicitud fue recibida por el servidor de correo. El equipo comercial se pondrá en contacto contigo.']);
