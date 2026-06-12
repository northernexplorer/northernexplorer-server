<?php
// Clear out these debug lines later once everything tests perfectly
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 1. Load the shared environment core
require_once 'weather_cache.php';

try {
    loadEnv(__DIR__ . '/.env');

    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME'));
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER'));
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS'));
    if (!defined('OPENWEATHER_API_KEY')) define('OPENWEATHER_API_KEY', getenv('OPENWEATHER_API_KEY'));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Configuration Error", "details" => $e->getMessage()]);
    exit();
}

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$lon = isset($_GET['lon']) ? filter_var($_GET['lon'], FILTER_VALIDATE_FLOAT) : null;
$type = isset($_GET['type']) ? trim($_GET['type']) : null;

if ($lat === false || $lon === false || $lat === null || $lon === null) {
    http_response_code(400);
    echo json_encode(["error" => "Valid latitude (lat) and longitude (lon) query parameters are required."]);
    exit();
}

// 2. Added 'city' to the server-side gatekeeper array here
$allowedTypes = ['weather', 'forecast', 'city'];
if ($type === null || !in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode([
        "error" => "Invalid or missing 'type' parameter.",
        "expected" => "The 'type' query parameter must be explicitly set to 'weather', 'forecast', or 'city'."
    ]);
    exit();
}

try {
    if ($type === 'weather') {
        $response = getWeatherData($lat, $lon);
    } elseif ($type === 'forecast') {
        require_once 'forecast_cache.php';
        $response = getForecastData($lat, $lon);
    } else {
        // 3. Dynamically route requests when type=city
        require_once 'city_cache.php';
        $response = getCityData($lat, $lon);
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Internal Server Error",
        "details" => $e->getMessage()
    ]);
}