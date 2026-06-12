<?php
if (count(get_included_files()) === 1) {
    http_response_code(403);
    echo json_encode(["error" => "Direct access denied."]);
    exit();
}

function loadEnv($path) {
    if (!file_exists($path)) {
        throw new Exception(".env file missing at: " . $path);
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, "\"'\t ");
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

function getWeatherData($lat, $lon) {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Check cache (within 5km, under 1 hour old)
    $stmt = $pdo->prepare("
        SELECT weather_data, updated_at,
               ST_Distance_Sphere(POINT(lon, lat), POINT(:lon, :lat)) AS distance_meters
        FROM weather_cache
        WHERE updated_at >= NOW() - INTERVAL 1 HOUR
        HAVING distance_meters <= 5000
        ORDER BY distance_meters ASC
        LIMIT 1
    ");
    $stmt->execute(['lat' => $lat, 'lon' => $lon]);
    $cachedResult = $stmt->fetch();

    if ($cachedResult) {
        return [
            "source" => "database_cache",
            "distance_offset" => round($cachedResult['distance_meters']) . " meters",
            "cached_at" => $cachedResult['updated_at'],
            "data" => json_decode($cachedResult['weather_data'])
        ];
    }

    // Cache Miss: Fetch from OpenWeather
    $apiUrl = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&units=metric&appid=" . OPENWEATHER_API_KEY;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$apiResponse) {
        throw new Exception("OpenWeather Weather API responded with code " . $httpCode);
    }

    $parsedJson = json_decode($apiResponse);

    // Save to cache
    $insertStmt = $pdo->prepare("INSERT INTO weather_cache (lat, lon, weather_data) VALUES (:lat, :lon, :weather_data)");
    $insertStmt->execute(['lat' => $lat, 'lon' => $lon, 'weather_data' => $apiResponse]);

    // Cleanup
    $pdo->query("DELETE FROM weather_cache WHERE updated_at < NOW() - INTERVAL 24 HOUR");

    return [
        "source" => "openweather_api",
        "data" => $parsedJson
    ];
}