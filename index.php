<?php
declare(strict_types=1);

const MAX_UPLOAD_BYTES = 157286400; // 150 MiB
$root = __DIR__;
$catalogPath = $root . '/data/books.json';
$pdfDir = $root . '/pdfs';

if (!is_dir(dirname($catalogPath))) mkdir(dirname($catalogPath), 0755, true);
if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
if (!file_exists($catalogPath)) file_put_contents($catalogPath, "[]\n", LOCK_EX);

function jsonResponse(array $data, int $status = 200): never {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function getCatalog(string $path): array {
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) return ['folders' => [], 'books' => []];
    // Migración transparente del formato antiguo, que era un array de publicaciones.
    if (array_is_list($data)) return ['folders' => [], 'books' => array_values(array_filter($data, 'is_array'))];
    return [
        'folders' => array_values(array_filter($data['folders'] ?? [], 'is_array')),
        'books' => array_values(array_filter($data['books'] ?? [], 'is_array'))
    ];
}
function getBooks(string $path): array { return getCatalog($path)['books']; }
function getFolders(string $path): array { return getCatalog($path)['folders']; }
function saveCatalog(string $path, array $books, array $folders): bool {
    $json = json_encode(['folders' => array_values($folders), 'books' => array_values($books)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) return false;
    return rename($tmp, $path);
}
function defaultSettings(): array {
    return ['description' => '', 'backgroundColor' => '#00745b', 'autoflip' => false, 'autoflipInterval' => 5, 'startPage' => 1, 'pageMode' => 'auto', 'showLogo' => false, 'showThumbnails' => true, 'showArrows' => true, 'showShare' => true, 'allowEmbed' => true, 'uniformCovers' => true, 'folderSlug' => ''];
}
function publicBook(array $book): array {
    $public = array_merge(defaultSettings(), $book, [
        'url' => '/' . rawurlencode((string) ($book['slug'] ?? '')),
        'fileUrl' => '/files/' . rawurlencode((string) ($book['filename'] ?? ''))
    ]);
    unset($public['filename']);
    return $public;
}
function saveBooks(string $path, array $books): bool {
    $catalog = getCatalog($path);
    return saveCatalog($path, $books, $catalog['folders']);
}
function slugify(string $value): string {
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;
    } elseif (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim(substr($value, 0, 70), '-');
}
function requireAdmin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION['admin_authenticated'] ?? false) !== true) {
        jsonResponse(['error' => 'Inicia sesión como administrador.'], 401);
    }
}
function notFound(string $root): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/views/404.html');
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$uriPath = '/' . ltrim($uriPath, '/');
$needsSession = str_starts_with($uriPath, '/admin') || ($uriPath === '/api/books' && $method === 'POST') || (preg_match('#^/api/books/[a-z0-9-]+$#', $uriPath) && in_array($method, ['PATCH', 'PUT', 'DELETE'], true)) || ($uriPath === '/api/folders' && $method === 'POST') || (preg_match('#^/api/folders/[a-z0-9-]+$#', $uriPath) && in_array($method, ['PATCH', 'DELETE'], true));
if ($needsSession) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('flipbook_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

// Static flipbook engine. The PDFs and JSON catalog are deliberately never served as direct files.
if ($method === 'GET' && preg_match('#^/(vendor|assets)/[A-Za-z0-9._/-]+$#', $uriPath, $assetMatch)) {
    $file = realpath($root . $uriPath);
    $assetRoot = realpath($root . '/' . $assetMatch[1]);
    if ($file && $assetRoot && str_starts_with($file, $assetRoot . DIRECTORY_SEPARATOR) && is_file($file)) return false;
}

if ($uriPath === '/admin/login' && $method === 'POST') {
    $configured = getenv('ADMIN_PASSWORD');
    if ($configured === false || $configured === '') $configured = 'change-me';
    $provided = (string) ($_POST['password'] ?? '');
    if (hash_equals((string) $configured, $provided)) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        session_write_close();
        header('Location: /admin', true, 303);
        exit;
    }
    session_write_close();
    header('Location: /admin?error=1', true, 303);
    exit;
}
if ($uriPath === '/admin/logout' && $method === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /admin', true, 303);
    exit;
}
if (in_array($uriPath, ['/admin', '/admin/'], true) && $method === 'GET') {
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow', true);
    if (($_SESSION['admin_authenticated'] ?? false) === true) {
        session_write_close();
        header('Content-Type: text/html; charset=utf-8');
        readfile($root . '/views/admin.html');
    } else {
        session_write_close();
        header('Content-Type: text/html; charset=utf-8');
        readfile($root . '/views/admin-login.html');
    }
    exit;
}

if ($uriPath === '/api/folders') {
    if ($method === 'GET') {
        $folders = getFolders($catalogPath);
        $books = getBooks($catalogPath);
        $public = array_map(static function(array $folder) use ($books): array {
            $folder['count'] = count(array_filter($books, static fn(array $book): bool => ($book['folderSlug'] ?? '') === ($folder['slug'] ?? '')));
            return $folder;
        }, $folders);
        jsonResponse($public);
    }
    if ($method === 'POST') {
        requireAdmin();
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) jsonResponse(['error' => 'Datos de carpeta no válidos.'], 400);
        $name = trim(strip_tags((string) ($input['name'] ?? '')));
        $slug = slugify((string) ($input['slug'] ?? $name));
        if ($name === '' || $slug === '') jsonResponse(['error' => 'Escribe un nombre de carpeta válido.'], 400);
        $folders = getFolders($catalogPath);
        foreach ($folders as $folder) if (($folder['slug'] ?? '') === $slug) jsonResponse(['error' => 'Ya existe una carpeta con esa URL.'], 409);
        $folder = ['slug' => $slug, 'name' => $name, 'createdAt' => gmdate('c')];
        $catalog = getCatalog($catalogPath);
        $folders[] = $folder;
        if (!saveCatalog($catalogPath, $catalog['books'], $folders)) jsonResponse(['error' => 'No se pudo guardar la configuración JSON.'], 500);
        jsonResponse($folder, 201);
    }
    header('Allow: GET, POST');
    jsonResponse(['error' => 'Método no permitido.'], 405);
}
if (preg_match('#^/api/folders/([a-z0-9-]+)$#', $uriPath, $folderMatch)) {
    requireAdmin();
    $folderSlug = $folderMatch[1];
    $catalog = getCatalog($catalogPath);
    $folderIndex = null;
    foreach ($catalog['folders'] as $i => $folder) if (($folder['slug'] ?? '') === $folderSlug) { $folderIndex = $i; break; }
    if ($folderIndex === null) jsonResponse(['error' => 'No se encontró la carpeta.'], 404);
    if ($method === 'PATCH') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $name = trim(strip_tags((string) ($input['name'] ?? '')));
        if ($name === '') jsonResponse(['error' => 'El nombre de carpeta no puede quedar vacío.'], 400);
        $catalog['folders'][$folderIndex]['name'] = $name;
        if (!saveCatalog($catalogPath, $catalog['books'], $catalog['folders'])) jsonResponse(['error' => 'No se pudo guardar la configuración JSON.'], 500);
        jsonResponse($catalog['folders'][$folderIndex]);
    }
    if ($method === 'DELETE') {
        $catalog['books'] = array_map(static function(array $book) use ($folderSlug): array {
            if (($book['folderSlug'] ?? '') === $folderSlug) $book['folderSlug'] = '';
            return $book;
        }, $catalog['books']);
        $catalog['folders'] = array_values(array_filter($catalog['folders'], static fn(array $folder): bool => ($folder['slug'] ?? '') !== $folderSlug));
        if (!saveCatalog($catalogPath, $catalog['books'], $catalog['folders'])) jsonResponse(['error' => 'No se pudo guardar la configuración JSON.'], 500);
        jsonResponse(['ok' => true]);
    }
    header('Allow: PATCH, DELETE');
    jsonResponse(['error' => 'Método no permitido.'], 405);
}

if ($uriPath === '/api/books') {
    if ($method === 'GET') {
        $books = getBooks($catalogPath);
        $public = array_map(static fn(array $book): array => publicBook($book), $books);
        jsonResponse($public);
    }
    if ($method === 'POST') {
        requireAdmin();
        if (!isset($_FILES['pdf']) || !is_array($_FILES['pdf'])) jsonResponse(['error' => 'Selecciona un archivo PDF válido.'], 400);
        $file = $_FILES['pdf'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                ? 'El archivo supera el límite configurado en PHP.' : 'No se pudo recibir el archivo PDF.';
            jsonResponse(['error' => $message], 400);
        }
        if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) jsonResponse(['error' => 'El PDF supera el límite de 150 MB.'], 400);
        $originalName = (string) ($file['name'] ?? 'publicacion.pdf');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!str_ends_with(strtolower($originalName), '.pdf') || $mime !== 'application/pdf') {
            jsonResponse(['error' => 'El archivo seleccionado no parece ser un PDF válido.'], 400);
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') $title = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = slugify((string) ($_POST['slug'] ?? $title));
        if ($slug === '') jsonResponse(['error' => 'Escribe un nombre de URL válido.'], 400);
        $books = getBooks($catalogPath);
        foreach ($books as $book) {
            if (($book['slug'] ?? '') === $slug) jsonResponse(['error' => 'Esa URL ya está en uso.'], 409);
        }
        $filename = bin2hex(random_bytes(16)) . '.pdf';
        $destination = $pdfDir . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) jsonResponse(['error' => 'No se pudo guardar el PDF. Revisa los permisos de la carpeta pdfs/.'], 500);
        $description = trim(strip_tags((string) ($_POST['description'] ?? '')));
        if (preg_match('/^.{0,3000}/us', $description, $descriptionMatch)) $description = $descriptionMatch[0];
        $settings = defaultSettings();
        $settings['description'] = $description;
        $books[] = array_merge($settings, ['slug' => $slug, 'title' => $title, 'filename' => $filename, 'createdAt' => gmdate('c')]);
        if (!saveBooks($catalogPath, $books)) {
            @unlink($destination);
            jsonResponse(['error' => 'No se pudo guardar data/books.json. Revisa los permisos de la carpeta data/.'], 500);
        }
        jsonResponse(['slug' => $slug, 'title' => $title, 'url' => '/' . rawurlencode($slug), 'fileUrl' => '/files/' . rawurlencode($filename)], 201);
    }
    header('Allow: GET, POST');
    jsonResponse(['error' => 'Método no permitido.'], 405);
}

if (preg_match('#^/api/books/([a-z0-9-]+)$#', $uriPath, $match)) {
    $slug = $match[1];
    $books = getBooks($catalogPath);
    $bookIndex = null;
    foreach ($books as $i => $book) if (($book['slug'] ?? '') === $slug) { $bookIndex = $i; break; }
    if ($bookIndex === null) jsonResponse(['error' => 'No se encontró la publicación.'], 404);
    if (in_array($method, ['PATCH', 'PUT'], true)) {
        requireAdmin();
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) jsonResponse(['error' => 'Configuración JSON no válida.'], 400);
        $book = array_merge(defaultSettings(), $books[$bookIndex]);
        if (array_key_exists('title', $input)) $book['title'] = trim(substr((string) $input['title'], 0, 720)) ?: $book['title'];
        if (array_key_exists('folderSlug', $input)) {
            $folderSlug = trim((string) $input['folderSlug']);
            if ($folderSlug !== '' && !in_array($folderSlug, array_column(getFolders($catalogPath), 'slug'), true)) jsonResponse(['error' => 'La carpeta seleccionada no existe.'], 400);
            $book['folderSlug'] = $folderSlug;
        }
        if (array_key_exists('description', $input)) {
            $description = trim(strip_tags((string) $input['description']));
            if (preg_match('/^.{0,3000}/us', $description, $descriptionMatch)) $description = $descriptionMatch[0];
            $book['description'] = $description;
        }
        if (array_key_exists('backgroundColor', $input)) {
            $color = (string) $input['backgroundColor'];
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) jsonResponse(['error' => 'El color debe ser hexadecimal, por ejemplo #00745b.'], 400);
            $book['backgroundColor'] = strtolower($color);
        }
        if (array_key_exists('autoflip', $input)) $book['autoflip'] = filter_var($input['autoflip'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('autoflipInterval', $input)) $book['autoflipInterval'] = max(2, min(60, (int) $input['autoflipInterval']));
        if (array_key_exists('startPage', $input)) $book['startPage'] = max(1, min(10000, (int) $input['startPage']));
        if (array_key_exists('pageMode', $input)) {
            if (!in_array($input['pageMode'], ['auto', 'single', 'double'], true)) jsonResponse(['error' => 'Modo de página no válido.'], 400);
            $book['pageMode'] = $input['pageMode'];
        }
        foreach (['showLogo', 'showThumbnails', 'showArrows', 'showShare', 'allowEmbed', 'uniformCovers'] as $flag) {
            if (array_key_exists($flag, $input)) $book[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
        }
        $books[$bookIndex] = $book;
        if (!saveBooks($catalogPath, $books)) jsonResponse(['error' => 'No se pudo actualizar data/books.json.'], 500);
        jsonResponse(publicBook($book));
    }
    if ($method === 'DELETE') {
        requireAdmin();
        $found = $books[$bookIndex];
        $remaining = array_values(array_filter($books, static fn(array $b): bool => ($b['slug'] ?? '') !== $slug));
        if (!saveBooks($catalogPath, $remaining)) jsonResponse(['error' => 'No se pudo actualizar data/books.json.'], 500);
        $filename = basename((string) ($found['filename'] ?? ''));
        if ($filename !== '') @unlink($pdfDir . '/' . $filename);
        jsonResponse(['ok' => true]);
    }
    header('Allow: PATCH, PUT, DELETE');
    jsonResponse(['error' => 'Método no permitido.'], 405);
}

if (in_array($method, ['GET', 'HEAD'], true) && preg_match('#^/files/([a-f0-9]{32}\.pdf)$#', $uriPath, $match)) {
    $filename = $match[1];
    $exists = false;
    foreach (getBooks($catalogPath) as $book) if (($book['filename'] ?? '') === $filename) { $exists = true; break; }
    $file = $pdfDir . '/' . $filename;
    if (!$exists || !is_file($file)) notFound($root);
    $size = filesize($file);
    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    header('Content-Type: application/pdf');
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="publication.pdf"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    if ($range !== '' && preg_match('/^bytes=(\\d*)-(\\d*)$/', trim($range), $parts)) {
        if ($parts[1] === '' && $parts[2] !== '') {
            $suffix = (int) $parts[2];
            $start = max(0, $size - $suffix);
        } else {
            $start = (int) $parts[1];
            if ($parts[2] !== '') $end = min($end, (int) $parts[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if ($method === 'HEAD') exit;
    $handle = fopen($file, 'rb');
    if ($handle === false) { http_response_code(500); exit; }
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

if ($method === 'GET' && ($uriPath === '/' || $uriPath === '/index.php')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/views/library.html');
    exit;
}
if ($method === 'GET' && preg_match('#^/carpeta/([a-z0-9-]+)$#', $uriPath, $folderRoute)) {
    foreach (getFolders($catalogPath) as $folder) {
        if (($folder['slug'] ?? '') === $folderRoute[1]) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($root . '/views/library.html');
            exit;
        }
    }
    notFound($root);
}
if ($method === 'GET' && preg_match('#^/([a-z0-9-]+)$#', $uriPath, $match)) {
    foreach (getBooks($catalogPath) as $book) {
        if (($book['slug'] ?? '') === $match[1]) {
            if (isset($_GET['embed']) && $_GET['embed'] === '1' && !($book['allowEmbed'] ?? true)) {
                http_response_code(403);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><meta charset="utf-8"><p style="font:16px system-ui">La inserción está desactivada para esta publicación.</p>';
                exit;
            }
            header('Content-Type: text/html; charset=utf-8');
            readfile($root . '/views/reader.html');
            exit;
        }
    }
}
notFound($root);
