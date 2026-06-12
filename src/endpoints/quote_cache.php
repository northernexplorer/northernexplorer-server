<?php
if (count(get_included_files()) === 1) {
    http_response_code(403);
    echo json_encode(["error" => "Direct access denied."]);
    exit();
}

function getQuoteData() {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $today = date('Y-m-d');

    // 1. Check: Is there an assigned quote for today?
    $stmt = $pdo->prepare("SELECT quote_text, author, usage_count FROM quote_cache WHERE assigned_date = :today LIMIT 1");
    $stmt->execute(['today' => $today]);
    $activeQuote = $stmt->fetch();

    // If so, use that one right away
    if ($activeQuote) {
        return [
            "source" => "database_cache",
            "date" => $today,
            "quote" => $activeQuote['quote_text'],
            "author" => $activeQuote['author'],
            "usage_count" => (int)$activeQuote['usage_count']
        ];
    }

    // 2. If not, get the quote with the lowest usage count.
    // Ordering by assigned_date ASC breaks ties by choosing the quote least recently seen.
    $pickStmt = $pdo->query("
        SELECT id, quote_text, author
        FROM quote_cache
        ORDER BY usage_count ASC, assigned_date ASC
        LIMIT 1
    ");
    $selectedQuote = $pickStmt->fetch();

    // Safety net in case you haven't seeded rows into your table yet
    if (!$selectedQuote) {
        return [
            "source" => "empty_database",
            "date" => $today,
            "quote" => "The boundary lines are where the maps end.",
            "author" => "Explorer",
            "usage_count" => 1
        ];
    }

    // 3. Update the selected lowest-count quote's statistics and assign it to today
    $updateStmt = $pdo->prepare("
        UPDATE quote_cache
        SET usage_count = usage_count + 1,
            assigned_date = :today
        WHERE id = :id
    ");
    $updateStmt->execute([
        'today' => $today,
        'id' => $selectedQuote['id']
    ]);

    // 4. Fetch back the freshly updated counter
    $countStmt = $pdo->prepare("SELECT usage_count FROM quote_cache WHERE id = :id LIMIT 1");
    $countStmt->execute(['id' => $selectedQuote['id']]);
    $freshCount = $countStmt->fetch();

    return [
        "source" => "fresh_daily_selection",
        "date" => $today,
        "quote" => $selectedQuote['quote_text'],
        "author" => $selectedQuote['author'],
        "usage_count" => (int)($freshCount ? $freshCount['usage_count'] : 1)
    ];
}