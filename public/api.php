<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$config = file_exists(__DIR__ . '/../config.php')
    ? require __DIR__ . '/../config.php'
    : require __DIR__ . '/../config.example.php';

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

fail('Unbekannte Aktion.');
