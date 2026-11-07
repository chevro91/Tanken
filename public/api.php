<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$configFile = array_values(array_filter(
    [__DIR__ . '/config.php', __DIR__ . '/../config.php', __DIR__ . '/../config.example.php'],
    'file_exists'
))[0];
$config = require $configFile;
$config += ['allow_register' => true];

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $pdo = new PDO('sqlite:' . $dir . '/tanken.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL COLLATE NOCASE, pw TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS favorites (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        station_id TEXT NOT NULL, data TEXT NOT NULL,
        PRIMARY KEY (user_id, station_id))');
    return $pdo;
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST erforderlich.', 405);
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) fail('Ungültiges Sicherheitstoken, bitte Seite neu laden.', 403);
}

function require_login(): int {
    if (empty($_SESSION['uid'])) fail('Bitte zuerst anmelden.', 401);
    return (int)$_SESSION['uid'];
}

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function http_get(string $url, string $ua): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => ['Accept-Language: de'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status >= 400) fail('Externer Dienst nicht erreichbar.', 502);
    $data = json_decode($body, true);
    if (!is_array($data)) fail('Ungültige Antwort vom externen Dienst.', 502);
    return $data;
}

function demo_stations(float $lat, float $lng, float $rad, string $type): array {
    $brands = ['Aral', 'Shell', 'Esso', 'Total', 'JET', 'Agip', 'HEM', 'Star'];
    $streets = ['Hauptstraße', 'Bahnhofstraße', 'Industrieweg', 'Ringstraße', 'Berliner Allee'];
    mt_srand((int)(round($lat, 2) * 100) + (int)(round($lng, 2) * 100));
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $dist = round(mt_rand(3, (int)($rad * 10)) / 10, 1);
        $base = ['e5' => 1.83, 'e10' => 1.77, 'diesel' => 1.69][$type] ?? 1.8;
        $out[] = [
            'id' => "demo-$i", 'name' => $brands[$i % 8], 'brand' => $brands[$i % 8],
            'street' => $streets[$i % 5], 'houseNumber' => (string)mt_rand(1, 99),
            'postCode' => 10000 + mt_rand(0, 8999), 'place' => 'Beispielstadt',
            'lat' => $lat + (mt_rand(-100, 100) / 5000), 'lng' => $lng + (mt_rand(-100, 100) / 5000),
            'dist' => $dist, 'price' => round($base + mt_rand(-8, 12) / 100, 3),
            'isOpen' => mt_rand(0, 9) > 1,
        ];
    }
    return $out;
}

$action = $_GET['action'] ?? '';

if ($action === 'geocode') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) > 120) fail('Bitte Ort oder PLZ eingeben.');
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $q, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'de,at,ch,lu',
    ]);
    $res = http_get($url, $config['user_agent']);
    if (!$res) fail('Ort nicht gefunden.', 404);
    echo json_encode(['ok' => true, 'lat' => (float)$res[0]['lat'], 'lng' => (float)$res[0]['lon'],
        'label' => $res[0]['display_name']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search') {
    $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $rad = min(25.0, max(1.0, (float)($_GET['rad'] ?? 5)));
    $type = in_array($_GET['type'] ?? '', ['e5', 'e10', 'diesel'], true) ? $_GET['type'] : 'e5';
    $sort = ($_GET['sort'] ?? 'price') === 'dist' ? 'dist' : 'price';
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        fail('Ungültige Koordinaten.');
    }

    $demo = $config['api_key'] === '';
    if ($demo) {
        $stations = demo_stations($lat, $lng, $rad, $type);
    } else {
        $res = http_get('https://creativecommons.tankerkoenig.de/json/list.php?' . http_build_query([
            'lat' => $lat, 'lng' => $lng, 'rad' => $rad, 'sort' => $sort, 'type' => $type,
            'apikey' => $config['api_key'],
        ]), $config['user_agent']);
        if (empty($res['ok'])) fail($res['message'] ?? 'Tankerkönig-Fehler.', 502);
        $stations = $res['stations'] ?? [];
    }

    $stations = array_values(array_filter($stations, fn($s) => isset($s['price']) && $s['price'] > 0));
    usort($stations, fn($a, $b) => $sort === 'price' ? $a['price'] <=> $b['price'] : $a['dist'] <=> $b['dist']);
    echo json_encode(['ok' => true, 'demo' => $demo, 'stations' => $stations], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'me') {
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetchColumn() ?: null;
        if ($user === null) unset($_SESSION['uid']);
    }
    echo json_encode(['ok' => true, 'user' => $user, 'csrf' => $_SESSION['csrf'],
        'allow_register' => (bool)$config['allow_register']]);
    exit;
}

if ($action === 'register' || $action === 'login') {
    require_post();
    $name = trim((string)($_POST['username'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[\w.\-]{3,30}$/u', $name)) fail('Benutzername: 3–30 Zeichen (Buchstaben, Ziffern, . _ -).');
    if ($action === 'register') {
        if (!$config['allow_register']) fail('Registrierung ist deaktiviert.', 403);
        if (strlen($pw) < 8) fail('Passwort muss mindestens 8 Zeichen haben.');
        try {
            db()->prepare('INSERT INTO users (username, pw) VALUES (?, ?)')
                ->execute([$name, password_hash($pw, PASSWORD_DEFAULT)]);
        } catch (PDOException) { fail('Benutzername bereits vergeben.', 409); }
        $uid = (int)db()->lastInsertId();
    } else {
        $st = db()->prepare('SELECT id, pw FROM users WHERE username = ?');
        $st->execute([$name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($pw, $row['pw'])) { sleep(1); fail('Benutzername oder Passwort falsch.', 401); }
        $uid = (int)$row['id'];
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    echo json_encode(['ok' => true, 'user' => $name, 'csrf' => $_SESSION['csrf']]);
    exit;
}

if ($action === 'logout') {
    require_post();
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    echo json_encode(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    exit;
}

if ($action === 'fav_list') {
    $uid = require_login();
    $st = db()->prepare('SELECT station_id, data FROM favorites WHERE user_id = ? ORDER BY rowid');
    $st->execute([$uid]);
    $favs = [];
    foreach ($st as $r) $favs[$r['station_id']] = json_decode($r['data'], true) + ['id' => $r['station_id']];
    if ($favs && $config['api_key'] !== '') {
        foreach (array_chunk(array_keys($favs), 10) as $chunk) {
            $res = http_get('https://creativecommons.tankerkoenig.de/json/prices.php?' . http_build_query([
                'ids' => implode(',', $chunk), 'apikey' => $config['api_key']]), $config['user_agent']);
            foreach ($res['prices'] ?? [] as $id => $p) {
                if (isset($favs[$id])) $favs[$id]['prices'] = [
                    'e5' => $p['e5'] ?? null, 'e10' => $p['e10'] ?? null, 'diesel' => $p['diesel'] ?? null,
                    'open' => ($p['status'] ?? '') === 'open'];
            }
        }
    }
    echo json_encode(['ok' => true, 'favorites' => array_values($favs)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'fav_add') {
    require_post();
    $uid = require_login();
    $id = (string)($_POST['id'] ?? '');
    if (!preg_match('/^[\w\-]{1,64}$/', $id)) fail('Ungültige Tankstelle.');
    $data = [];
    foreach (['name', 'brand', 'street', 'houseNumber', 'postCode', 'place'] as $k) $data[$k] = mb_substr((string)($_POST[$k] ?? ''), 0, 100);
    $data['lat'] = (float)($_POST['lat'] ?? 0);
    $data['lng'] = (float)($_POST['lng'] ?? 0);
    $n = db()->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
    $n->execute([$uid]);
    if ($n->fetchColumn() >= 50) fail('Maximal 50 Favoriten.');
    db()->prepare('INSERT OR REPLACE INTO favorites (user_id, station_id, data) VALUES (?, ?, ?)')
        ->execute([$uid, $id, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'fav_del') {
    require_post();
    $uid = require_login();
    db()->prepare('DELETE FROM favorites WHERE user_id = ? AND station_id = ?')->execute([$uid, (string)($_POST['id'] ?? '')]);
    echo json_encode(['ok' => true]);
    exit;
}

fail('Unbekannte Aktion.');
