<?php
if (count(get_included_files()) === 1) {
    http_response_code(403);
    echo json_encode(["error" => "Direct access denied."]);
    exit();
}

function getCityData($lat, $lon) {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Check cache using the Haversine Formula (60-day window) targeting city_cache table
    $stmt = $pdo->prepare("
        SELECT city_data, updated_at,
               (6371000 * acos(
                   cos(radians(:lat1)) * cos(radians(lat)) * cos(radians(lon) - radians(:lon1)) +
                   sin(radians(:lat2)) * sin(radians(lat))
               )) AS distance_meters
        FROM city_cache
        WHERE updated_at >= NOW() - INTERVAL 60 DAY
        HAVING distance_meters <= 5000
        ORDER BY distance_meters ASC
        LIMIT 1
    ");

    $stmt->execute([
        'lat1' => $lat,
        'lon1' => $lon,
        'lat2' => $lat
    ]);
    $cachedResult = $stmt->fetch();

    if ($cachedResult) {
        return [
            "source" => "database_cache",
            "distance_offset" => round($cachedResult['distance_meters']) . " meters",
            "cached_at" => $cachedResult['updated_at'],
            "data" => json_decode($cachedResult['city_data'])
        ];
    }

    // Cache Miss: Fetch reverse geocoding info from OpenWeather
    $apiUrl = "https://api.openweathermap.org/geo/1.0/reverse?lat={$lat}&lon={$lon}&limit=1&appid=" . OPENWEATHER_API_KEY;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$apiResponse) {
        throw new Exception("OpenWeather Geocoding API responded with code " . $httpCode);
    }

    $parsedJson = json_decode($apiResponse);

    // Save to cache table
    $insertStmt = $pdo->prepare("
        INSERT INTO city_cache (lat, lon, city_data)
        VALUES (:lat, :lon, :city_data)
    ");
    $insertStmt->execute([
        'lat' => $lat,
        'lon' => $lon,
        'city_data' => $apiResponse
    ]);

    // Housekeeping: Cleanup records older than 90 days
    $pdo->query("DELETE FROM city_cache WHERE updated_at < NOW() - INTERVAL 90 DAY");

    return [
        "source" => "openweather_api",
        "data" => $parsedJson
    ];
}