<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- Configuration ---
$DEFAULT_LAT = 13.7563;
$DEFAULT_LON = 100.5018;

$CACHE_DIR = __DIR__ . '/cache';
$TTL_WEATHER = 300;          // weather cache 5 นาที
$TTL_GEO_REVERSE = 86400 * 7; // reverse geocode cache 7 วัน
$TTL_GEO_FORWARD = 86400 * 30; // forward geocode cache 30 วัน

// --- Input Processing ---
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
$province = isset($_GET['province']) ? trim((string)$_GET['province']) : null;

$sourceLabel = isset($_GET['source']) ? trim((string)$_GET['source']) : 'Unknown';
$unit = (isset($_GET['unit']) && $_GET['unit'] === 'fahrenheit') ? 'fahrenheit' : 'celsius';

// --- Helpers ---
function respond(array $payload, int $statusCode = 200): void {
  http_response_code($statusCode);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

function ensure_cache_dir(?string $dir): ?string {
  if (!$dir) return null;
  if (is_dir($dir)) return $dir;
  if (@mkdir($dir, 0777, true)) return $dir;
  return null;
}

$CACHE_DIR = ensure_cache_dir($CACHE_DIR);

function create_stream_context(): mixed {
  return stream_context_create([
    "http" => [
      "method" => "GET",
      "timeout" => 10,
      // สำคัญมากสำหรับ Nominatim: ต้องมี User-Agent ชัดเจน
      "header" => "User-Agent: WeatherMiniProjectPHP/2.0 (contact: local-dev)\r\n"
    ]
  ]);
}

function http_get_json(string $url): ?array {
  $raw = @file_get_contents($url, false, create_stream_context());
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function is_valid_latlon(float $lat, float $lon): bool {
  return is_finite($lat) && is_finite($lon) && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
}

function mb_lower(string $s): string {
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function cache_get(?string $cacheDir, string $key, int $ttl): ?array {
  if (!$cacheDir) return null;
  $path = $cacheDir . '/' . $key;
  if (!file_exists($path)) return null;
  if (time() - filemtime($path) > $ttl) return null;
  $raw = file_get_contents($path);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function cache_put(?string $cacheDir, string $key, array $data): void {
  if (!$cacheDir) return;
  @file_put_contents($cacheDir . '/' . $key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * Forward geocode via Open-Meteo
 */
function forward_geocode_open_meteo(string $name): ?array {
  $q = trim($name);
  if ($q === '') return null;

  // country=TH เพื่อบังคับประเทศไทย
  $url = "https://geocoding-api.open-meteo.com/v1/search"
    . "?name=" . urlencode($q)
    . "&count=10"
    . "&format=json"
    . "&country=TH"
    . "&language=th";

  $data = http_get_json($url);
  if (!$data || empty($data['results']) || !is_array($data['results'])) return null;

  // เลือกผลที่ดีที่สุด (พยายามจับ ADM1=ระดับจังหวัด)
  $best = null;
  $bestScore = -1e9;

  foreach ($data['results'] as $r) {
    if (!is_array($r)) continue;
    if (($r['country_code'] ?? '') !== 'TH') continue;

    $rLat = $r['latitude'] ?? null;
    $rLon = $r['longitude'] ?? null;
    if (!is_numeric($rLat) || !is_numeric($rLon)) continue;

    $score = 0.0;
    $fc = strtoupper((string)($r['feature_code'] ?? ''));

    // ให้คะแนนระดับจังหวัดแรงสุด
    if ($fc === 'ADM1') $score += 10;
    if ($fc === 'PPLC') $score += 6;
    if ($fc === 'PPLA') $score += 4;
    if (str_starts_with($fc, 'PPL')) $score += 2;

    $rName = (string)($r['name'] ?? '');
    $admin1 = (string)($r['admin1'] ?? '');

    // match ภาษาไทย/อังกฤษแบบ lower
    if (mb_lower($rName) === mb_lower($q)) $score += 4;
    if (mb_lower($admin1) === mb_lower($q)) $score += 6;

    $pop = $r['population'] ?? null;
    if (is_numeric($pop)) {
      $score += min(2.0, log10(max(1.0, (float)$pop)) / 3.0);
    }

    if ($score > $bestScore) {
      $bestScore = $score;
      $best = $r;
    }
  }

  if (!$best) return null;

  return [
    'lat' => (float)$best['latitude'],
    'lon' => (float)$best['longitude'],
    'label' => (string)($best['admin1'] ?? $best['name'] ?? $name),
    'provider' => 'open-meteo'
  ];
}

/**
 * Forward geocode via Nominatim (OSM) - รองรับชื่อไทยดี
 * NOTE: ใช้แบบเบา ๆ + cache เพื่อไม่ยิงถี่
 */
function forward_geocode_nominatim(string $name): ?array {
  $q = trim($name);
  if ($q === '') return null;

  // ใส่ Thailand เพื่อช่วย disambiguation
  $query = $q . ", Thailand";

  $url = "https://nominatim.openstreetmap.org/search"
    . "?q=" . urlencode($query)
    . "&format=jsonv2"
    . "&addressdetails=1"
    . "&limit=5"
    . "&accept-language=th";

  $results = http_get_json($url);
  if (!$results || !is_array($results) || count($results) === 0) return null;

  // เลือกผลในประเทศไทย และให้คะแนนถ้า address.state ตรงกับชื่อจังหวัด
  $best = null;
  $bestScore = -1e9;

  foreach ($results as $r) {
    if (!is_array($r)) continue;
    $rLat = $r['lat'] ?? null;
    $rLon = $r['lon'] ?? null;
    if (!is_numeric($rLat) || !is_numeric($rLon)) continue;

    $addr = $r['address'] ?? [];
    $countryCode = $addr['country_code'] ?? '';
    if ($countryCode && strtolower((string)$countryCode) !== 'th') continue;

    $score = 0.0;

    $display = (string)($r['display_name'] ?? '');
    $type = (string)($r['type'] ?? '');
    $class = (string)($r['class'] ?? '');

    // state มักเป็นจังหวัด (บางครั้งเป็น region) แต่ช่วยได้มาก
    $state = (string)($addr['state'] ?? '');
    $provinceLower = mb_lower($q);
    if ($state && mb_lower($state) === $provinceLower) $score += 10;

    // ถ้า display_name มีชื่อจังหวัด ให้เพิ่มคะแนน
    if ($display && str_contains(mb_lower($display), $provinceLower)) $score += 6;

    // ถ้าเป็น administrative boundary ให้คะแนน
    if ($class === 'boundary' || $type === 'administrative') $score += 4;

    // importance จาก nominatim
    $importance = $r['importance'] ?? null;
    if (is_numeric($importance)) $score += (float)$importance;

    if ($score > $bestScore) {
      $bestScore = $score;
      $best = $r;
    }
  }

  if (!$best) return null;

  $addr = $best['address'] ?? [];
  $label = (string)($addr['state'] ?? $best['display_name'] ?? $name);

  return [
    'lat' => (float)$best['lat'],
    'lon' => (float)$best['lon'],
    'label' => $label,
    'provider' => 'nominatim'
  ];
}

/**
 * Resolve province name -> coords with caching + fallback providers
 */
function resolve_place_to_coords(string $name, ?string $cacheDir, int $ttlForward): ?array {
  $q = trim($name);
  if ($q === '') return null;

  $cacheKey = 'forward_' . sha1($q) . '.json';
  $cached = cache_get($cacheDir, $cacheKey, $ttlForward);
  if ($cached && isset($cached['lat'], $cached['lon'])) {
    return $cached;
  }

  // 1) Open-Meteo
  $r1 = forward_geocode_open_meteo($q);
  if ($r1 && is_valid_latlon($r1['lat'], $r1['lon'])) {
    cache_put($cacheDir, $cacheKey, $r1);
    return $r1;
  }

  // 2) Nominatim
  $r2 = forward_geocode_nominatim($q);
  if ($r2 && is_valid_latlon($r2['lat'], $r2['lon'])) {
    cache_put($cacheDir, $cacheKey, $r2);
    return $r2;
  }

  return null;
}

/**
 * Reverse geocode: coords -> locality (รายละเอียดเขต/อำเภอ)
 */
function reverse_locality(float $lat, float $lon, ?string $cacheDir, int $ttl): string {
  $key = sprintf('reverse_%.4f_%.4f.json', $lat, $lon);
  $cached = cache_get($cacheDir, $key, $ttl);
  if ($cached && !empty($cached['locality'])) return (string)$cached['locality'];

  $url = "https://api.bigdatacloud.net/data/reverse-geocode-client?latitude={$lat}&longitude={$lon}&localityLanguage=th";
  $data = http_get_json($url);

  $locality = 'ไม่ทราบตำแหน่ง';
  if ($data) {
    $locality = (string)($data['locality'] ?? $data['city'] ?? $data['principalSubdivision'] ?? $locality);
  }

  cache_put($cacheDir, $key, ['locality' => $locality]);
  return $locality;
}

// --- Resolve input -> coords ---
$provider = null;

if (($lat === null || $lon === null) && $province) {
  $resolved = resolve_place_to_coords($province, $CACHE_DIR, $TTL_GEO_FORWARD);

  if ($resolved) {
    $lat = (float)$resolved['lat'];
    $lon = (float)$resolved['lon'];
    $provider = $resolved['provider'] ?? null;

    // ให้ UI ยึดชื่อจังหวัดที่เลือกเป็นหลัก
    if ($sourceLabel === 'Unknown' || $sourceLabel === '') $sourceLabel = $province;
  } else {
    // หาไม่เจอจริง ๆ ค่อย fallback Bangkok
    $lat = $DEFAULT_LAT;
    $lon = $DEFAULT_LON;
    $provider = 'fallback-bangkok';
    if ($sourceLabel === 'Unknown' || $sourceLabel === '') $sourceLabel = $province;
  }
} elseif ($lat === null || $lon === null) {
  $lat = $DEFAULT_LAT;
  $lon = $DEFAULT_LON;
  $provider = 'fallback-bangkok';
  $sourceLabel = 'กรุงเทพมหานคร';
} else {
  $provider = 'coords';
}

// Validate
if (!is_valid_latlon((float)$lat, (float)$lon)) {
  respond(["ok" => false, "error" => "พิกัดไม่ถูกต้อง"], 400);
}

// Reverse locality (รายละเอียดตำแหน่ง)
$locality = reverse_locality((float)$lat, (float)$lon, $CACHE_DIR, $TTL_GEO_REVERSE);

// --- Weather cache ---
$weatherKey = sprintf('weather_%.4f_%.4f_%s.json', (float)$lat, (float)$lon, $unit);
$cachedWeather = cache_get($CACHE_DIR, $weatherKey, $TTL_WEATHER);

if ($cachedWeather) {
  $cachedWeather['meta']['cache'] = 'HIT';
  $cachedWeather['meta']['source_label'] = $sourceLabel;
  $cachedWeather['meta']['locality'] = $locality;
  $cachedWeather['meta']['geocode_provider'] = $provider;
  respond($cachedWeather);
}

// --- Fetch weather ---
$url = "https://api.open-meteo.com/v1/forecast"
  . "?latitude=" . urlencode((string)$lat)
  . "&longitude=" . urlencode((string)$lon)
  . "&current=temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code,is_day"
  . "&hourly=temperature_2m"
  . "&temperature_unit=" . urlencode($unit)
  . "&past_days=1"
  . "&forecast_days=1"
  . "&timezone=auto";

$data = http_get_json($url);
if (!$data || !isset($data['current'], $data['hourly'])) {
  respond(["ok" => false, "error" => "ไม่สามารถดึงข้อมูลอากาศได้"], 502);
}

$result = [
  "ok" => true,
  "meta" => [
    "lat" => (float)$lat,
    "lon" => (float)$lon,
    "unit" => $unit,
    "source" => "open-meteo",
    "source_label" => $sourceLabel,     // ชื่อที่ user เลือก (จังหวัด)
    "locality" => $locality,            // รายละเอียดตำแหน่งจาก reverse
    "geocode_provider" => $provider,    // debug ได้ว่าใช้ตัวไหน
    "cache" => "MISS"
  ],
  "data" => [
    "time_local" => $data["current"]["time"] ?? null,
    "temperature_c" => (float)($data["current"]["temperature_2m"] ?? 0),
    "humidity_percent" => (float)($data["current"]["relative_humidity_2m"] ?? 0),
    "wind_kmh" => (float)($data["current"]["wind_speed_10m"] ?? 0),
    "weather_code" => (int)($data["current"]["weather_code"] ?? -1),
    "is_day" => ((int)($data["current"]["is_day"] ?? 0) === 1),
  ],
  "hourly" => [
    "time" => $data["hourly"]["time"] ?? [],
    "temperature" => $data["hourly"]["temperature_2m"] ?? [],
  ]
];

// Cache weather
cache_put($CACHE_DIR, $weatherKey, $result);

respond($result);
