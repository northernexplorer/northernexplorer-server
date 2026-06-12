<?php
if (count(get_included_files()) === 1) {
    http_response_code(403);
    echo json_encode(["error" => "Direct access denied."]);
    exit();
}

function getForecastData($lat, $lon) {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Check cache using unique parameter bindings for the math operations
    $stmt = $pdo->prepare("
        SELECT forecast_data, updated_at,
               (6371000 * acos(
                   cos(radians(:lat1)) * cos(radians(lat)) * cos(radians(lon) - radians(:lon1)) +
                   sin(radians(:lat2)) * sin(radians(lat))
               )) AS distance_meters
        FROM forecast_cache
        WHERE updated_at >= NOW() - INTERVAL 6 HOUR
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
            "data" => json_decode($cachedResult['forecast_data'])
        ];
    }

    // Cache Miss: Fetch fresh forecast from OpenWeather
    $apiUrl = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&units=metric&appid=" . OPENWEATHER_API_KEY;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$apiResponse) {
        throw new Exception("OpenWeather Forecast API responded with code " . $httpCode);
    }

    $parsedJson = json_decode($apiResponse);

    // Save to cache using clear, simple assignments
    $insertStmt = $pdo->prepare("
        INSERT INTO forecast_cache (lat, lon, forecast_data)
        VALUES (:lat, :lon, :forecast_data)
    ");
    $insertStmt->execute([
        'lat' => $lat,
        'lon' => $lon,
        'forecast_data' => $apiResponse
    ]);

    $pdo->query("DELETE FROM forecast_cache WHERE updated_at < NOW() - INTERVAL 24 HOUR");

    return [
        "source" => "openweather_api",
        "data" => $parsedJson
    ];
}