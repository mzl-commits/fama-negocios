<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'");
session_name('fama_admin');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'use_strict_mode' => true]);

function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function setting(string $name): string { return (string)(getenv($name) ?: ''); }
function getValue(array $data, string $path): string {
    $value = $data;
    foreach (explode('.', $path) as $key) $value = $value[$key] ?? '';
    return is_string($value) ? $value : '';
}
function setValue(array &$data, string $path, string $value): void {
    $target = &$data;
    foreach (explode('.', $path) as $key) $target = &$target[$key];
    $target = $value;
}
function upload(string $field, bool $pdf = false): ?string {
    $file = $_FILES[$field] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 12 * 1024 * 1024) throw new RuntimeException('El archivo supera el límite de 12 MB o no se pudo recibir.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = $pdf ? ['application/pdf' => 'pdf'] : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException($pdf ? 'El catálogo debe ser un PDF.' : 'La imagen debe ser JPG, PNG o WebP.');
    if (!$pdf && !getimagesize($file['tmp_name'])) throw new RuntimeException('La imagen no es válida.');
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('No se pudo preparar la carpeta de archivos.');
    $name = bin2hex(random_bytes(18)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('No se pudo guardar el archivo.');
    return '/uploads/' . $name;
}

$hash = setting('FAMA_ADMIN_PASSWORD_HASH');
$configured = $hash !== '' && (password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$error = '';
$success = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('La sesión venció. Recarga la página para continuar.');
    }
    if (isset($_POST['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }
    if (isset($_POST['password']) && $configured) {
        // A shared per-address counter also covers attempts across separate sessions.
        $ratePath = sys_get_temp_dir() . '/fama-login-' . hash('sha256', __DIR__ . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $rateFile = fopen($ratePath, 'c+');
        if (!$rateFile || !flock($rateFile, LOCK_EX)) { http_response_code(503); exit('Acceso temporalmente no disponible.'); }
        $rate = json_decode(stream_get_contents($rateFile), true) ?: ['since' => time(), 'count' => 0];
        if (time() - $rate['since'] > 900) $rate = ['since' => time(), 'count' => 0];
        if ($rate['count'] >= 8) {
            http_response_code(429);
            $error = 'Demasiados intentos. Intenta de nuevo en 15 minutos.';
        } elseif (password_verify((string)$_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['last_active'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $rate = ['since' => time(), 'count' => 0];
        } else {
            $rate['count']++;
            $error = 'La contraseña no es correcta.';
        }
        rewind($rateFile); ftruncate($rateFile, 0); fwrite($rateFile, json_encode($rate)); fflush($rateFile); flock($rateFile, LOCK_UN); fclose($rateFile);
    }
}
if (!empty($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 1800) unset($_SESSION['authenticated']);
$authenticated = $configured && !empty($_SESSION['authenticated']);
if ($authenticated) $_SESSION['last_active'] = time();

$filePath = dirname(__DIR__) . '/content/site.json';
$raw = is_file($filePath) ? file_get_contents($filePath) : '';
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(503); exit('No se encontró el contenido del sitio.'); }
$groups = [
    'Inicio' => ['home.title' => 'Título principal', 'home.description' => 'Descripción principal'],
    'Nosotros' => ['about.title' => 'Título', 'about.text' => 'Presentación', 'about.approach' => 'Forma de trabajo'],
    'Contacto' => ['contact.email' => 'Correo comercial', 'contact.phone' => 'Central visible', 'contact.phoneHref' => 'Central con código internacional', 'contact.mobile' => 'WhatsApp visible', 'contact.whatsapp' => 'WhatsApp internacional solo dígitos', 'contact.address' => 'Dirección', 'contact.hours' => 'Horarios'],
    'Star Home' => ['starHome.title' => 'Nombre', 'starHome.description' => 'Presentación', 'starHome.notice' => 'Información de la línea'],
];
foreach ($data['divisions'] as $i => $division) {
    $fields = ["divisions.$i.name" => 'Nombre de la división', "divisions.$i.short" => 'Título', "divisions.$i.description" => 'Descripción'];
    foreach ($division['services'] as $j => $service) {
        $fields["divisions.$i.services.$j.name"] = 'Servicio ' . ($j + 1);
        $fields["divisions.$i.services.$j.description"] = 'Descripción del servicio ' . ($j + 1);
    }
    $groups[$division['name']] = $fields;
}
if ($authenticated && $method === 'POST' && isset($_POST['save'])) {
    $lock = fopen(dirname($filePath) . '/.write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { http_response_code(503); exit('No se pudo bloquear el contenido para guardar.'); }
    $created = [];
    try {
        $current = file_get_contents($filePath);
        if (!hash_equals(hash('sha256', $current), (string)($_POST['revision'] ?? ''))) throw new RuntimeException('Otra persona actualizó el contenido. Recarga la página antes de guardar tus cambios.');
        $updated = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
        foreach ($groups as $fields) foreach ($fields as $path => $label) {
            $key = str_replace('.', '_', $path);
            if (!isset($_POST[$key]) || !is_string($_POST[$key])) throw new RuntimeException('Falta el campo ' . $label . '.');
            $value = trim($_POST[$key]);
            if ($value === '' || strlen($value) > 6000) throw new RuntimeException('Revisa el campo ' . $label . '.');
            setValue($updated, $path, $value);
        }
        if (!filter_var($updated['contact']['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Revisa el correo comercial.');
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $updated['contact']['whatsapp'])) throw new RuntimeException('WhatsApp requiere entre 8 y 15 dígitos, incluido el código de país.');
        if (!preg_match('/^\+?[0-9]{7,15}$/', $updated['contact']['phoneHref'])) throw new RuntimeException('Revisa el número internacional de la central.');
        $imagePaths = ['home.image'];
        foreach ($updated['divisions'] as $i => $division) $imagePaths[] = "divisions.$i.image";
        foreach ($imagePaths as $path) {
            $asset = upload(str_replace('.', '_', $path));
            if ($asset) { $created[] = $asset; setValue($updated, $path, $asset); }
        }
        $catalogs = [];
        foreach ($updated['catalogs'] as $i => $catalog) {
            if (!isset($_POST['remove_catalog_' . $i])) $catalogs[] = $catalog;
        }
        $catalogFile = upload('catalog_file', true);
        if ($catalogFile) {
            $created[] = $catalogFile;
            $name = trim((string)($_POST['catalog_name'] ?? ''));
            $division = (string)($_POST['catalog_division'] ?? '');
            if ($name === '' || strlen($name) > 150 || !in_array($division, array_column($updated['divisions'], 'slug'), true)) throw new RuntimeException('Indica el nombre y la división del catálogo.');
            $catalogs[] = ['name' => $name, 'division' => $division, 'file' => $catalogFile];
        }
        $updated['catalogs'] = $catalogs;
        $encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam(dirname($filePath), '.content-');
        if ($temporary === false || file_put_contents($temporary, $encoded) !== strlen($encoded)) throw new RuntimeException('No se pudo escribir el contenido.');
        chmod($temporary, 0644);
        if (!rename($temporary, $filePath)) { unlink($temporary); throw new RuntimeException('No se pudo completar el guardado.'); }
        $data = $updated;
        $raw = $encoded;
        $success = 'Cambios guardados. Abre la web y recarga la página para revisarlos.';
    } catch (Throwable $e) {
        foreach ($created as $asset) @unlink(dirname(__DIR__) . $asset);
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudieron guardar los cambios. Revisa los datos e inténtalo de nuevo.';
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administrar contenidos | FAMA Negocios</title>
<style>body{font:16px/1.6 system-ui,sans-serif;color:#202323;background:#f2f3f2;margin:0}main{max-width:880px;margin:40px auto;padding:0 24px}h1{font-size:30px;line-height:1.2}h2{font-size:22px}h3{font-size:17px}header{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:25px}a{color:#9c2026}fieldset{background:white;border:1px solid #d2d8d3;border-radius:6px;padding:24px;margin:30px 0}legend{font-weight:700;background:#f2f3f2;padding:0 8px}label{display:block;font-size:14px;font-weight:600;margin:18px 0 7px}input:not([type=checkbox]),textarea,select{box-sizing:border-box;width:100%;font:inherit;padding:10px;border:1px solid #aeb8b1;border-radius:4px;background:white;color:#202323}textarea{min-height:90px;resize:vertical}input[type=file]{font-size:13px}button{background:#b82329;color:white;font:600 15px system-ui;border:0;border-radius:4px;padding:13px 22px;cursor:pointer}button:hover{background:#951b21}.status{padding:18px;background:#e2eee4}.error{background:#fbe4e4}.login{max-width:430px;margin:70px auto}.sticky-save{position:sticky;bottom:0;background:#f2f3f2;padding:20px 0}small{display:block;color:#555d57}input:focus-visible,textarea:focus-visible,select:focus-visible,button:focus-visible{outline:3px solid #1769a0;outline-offset:3px}.file-row{display:flex;align-items:center;justify-content:space-between;gap:15px;border-bottom:1px solid #ddd;padding:12px 0}.file-row label{margin:0;font-weight:400}</style></head><body><main>
<header><div><small>FAMA NEGOCIOS</small><h1>Administrar contenidos</h1></div><a href="/" target="_blank" rel="noopener">Ver web ↗</a></header>
<?php if ($error): ?><p class="status error" role="alert"><?= escape($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="status" role="status"><?= escape($success) ?></p><?php endif; ?>
<?php if (!$configured): ?>
<section class="login"><h2>Panel pendiente de activación</h2><p>El responsable del alojamiento debe configurar FAMA_ADMIN_PASSWORD_HASH para habilitar el acceso.</p><p>El panel requiere un servidor con PHP 8.1 o posterior.</p></section>
<?php elseif (!$authenticated): ?>
<form method="post" class="login"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><label for="password">Contraseña de administración</label><input id="password" type="password" name="password" required autocomplete="current-password"><p><button type="submit">Ingresar</button></p></form>
<?php else: ?>
<p>Actualiza textos, imágenes y catálogos. Los cambios se muestran al recargar la web. La estructura de páginas se administra desde el proyecto.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><button name="logout" value="1">Cerrar sesión</button></form>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="revision" value="<?= hash('sha256', $raw) ?>">
<?php foreach ($groups as $group => $fields): ?><fieldset><legend><?= escape($group) ?></legend><?php foreach ($fields as $path => $label): $key = str_replace('.', '_', $path); ?><label for="<?= escape($key) ?>"><?= escape($label) ?></label><textarea id="<?= escape($key) ?>" name="<?= escape($key) ?>" maxlength="6000" required><?= escape(getValue($data, $path)) ?></textarea><?php endforeach; ?></fieldset><?php endforeach; ?>
<fieldset><legend>Imágenes</legend><small>JPG, PNG o WebP. Máximo 12 MB por archivo. Si dejas un campo vacío se conserva la imagen actual.</small><label for="home_image">Imagen de Inicio</label><input type="file" id="home_image" name="home_image" accept="image/jpeg,image/png,image/webp"><?php foreach ($data['divisions'] as $i => $division): ?><label for="divisions_<?= $i ?>_image"><?= escape($division['name']) ?></label><input id="divisions_<?= $i ?>_image" name="divisions_<?= $i ?>_image" type="file" accept="image/jpeg,image/png,image/webp"><?php endforeach; ?></fieldset>
<fieldset><legend>Catálogos PDF</legend><?php foreach ($data['catalogs'] as $i => $catalog): ?><div class="file-row"><a href="<?= escape($catalog['file']) ?>" target="_blank" rel="noopener"><?= escape($catalog['name']) ?></a><label><input type="checkbox" name="remove_catalog_<?= $i ?>" value="1"> Retirar del listado</label></div><?php endforeach; ?><h3>Añadir catálogo</h3><small>Para reemplazar un catálogo, retira el anterior del listado y sube su nueva versión. Máximo 12 MB.</small><label for="catalog_name">Nombre del catálogo</label><input id="catalog_name" name="catalog_name" maxlength="150"><label for="catalog_division">División</label><select id="catalog_division" name="catalog_division"><?php foreach ($data['divisions'] as $division): ?><option value="<?= escape($division['slug']) ?>"><?= escape($division['name']) ?></option><?php endforeach; ?></select><label for="catalog_file">Archivo PDF</label><input type="file" id="catalog_file" name="catalog_file" accept="application/pdf"></fieldset>
<div class="sticky-save"><button name="save" value="1">Guardar cambios</button></div></form>
<?php endif; ?>
</main></body></html>
