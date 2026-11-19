<?php
// Ahbab: game prices for the price sync job and the website
// requests: get_games_due_for_prices, save_game_prices, get_game_prices

function handleGamePrices($request)
{
    $mydb = connectDB();
    if (!$mydb)
    {
        return array("success" => false, "message" => "database unavailable");
    }

    switch ($request['type'])
    {
        case "get_games_due_for_prices":
            return getGamesDueForPrices($mydb, intval($request['limit'] ?? 500));
        case "save_game_prices":
            return saveGamePrices($mydb, $request['games'] ?? array());
        case "get_game_prices":
            return getGamePrices($mydb, $request['appIds'] ?? array());
    }
    return array("success" => false, "message" => "Unknown price request");
}

// picks the games that need a price check: never checked, or checked more than a day ago
// wishlist games go first, then games someone owns, then everything else (oldest check first)
function getGamesDueForPrices($mydb, $limit)
{
    $limit = max(1, min($limit, 1000));
    $staleBefore = time() - 86400;

    $stmt = $mydb->prepare("SELECT g.app_id
                            FROM games g
                            LEFT JOIN game_prices p ON p.app_id = g.app_id
                            WHERE p.app_id IS NULL OR p.price_updated_at < ?
                            ORDER BY EXISTS(SELECT 1 FROM wishlist_items w WHERE w.app_id = g.app_id) DESC,
                                     EXISTS(SELECT 1 FROM player_games pg WHERE pg.app_id = g.app_id) DESC,
                                     COALESCE(p.price_updated_at, 0) ASC
                            LIMIT ?");
    $stmt->bind_param("ii", $staleBefore, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $appIds = array();
    while ($row = $result->fetch_assoc())
    {
        $appIds[] = (int)$row['app_id'];
    }
    return array("success" => true, "message" => "games due", "appIds" => $appIds);
}

// saves the "games" list that the DMZ get_game_details request sends back
function saveGamePrices($mydb, $games)
{
    if (!is_array($games))
    {
        return array("success" => false, "message" => "games must be a list");
    }

    // previous_final_cents = final_cents runs first, so it keeps the old price before it gets replaced
    $stmt = $mydb->prepare("INSERT INTO game_prices
                            (app_id, found, has_price, currency, initial_cents, final_cents, discount_percent, price_updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                            previous_final_cents = final_cents,
                            found = VALUES(found),
                            has_price = VALUES(has_price),
                            currency = VALUES(currency),
                            initial_cents = VALUES(initial_cents),
                            final_cents = VALUES(final_cents),
                            discount_percent = VALUES(discount_percent),
                            price_updated_at = VALUES(price_updated_at)");

    $now = time();
    $saved = 0;
    foreach ($games as $game)
    {
        $appId = filter_var($game['appId'] ?? null, FILTER_VALIDATE_INT);
        if ($appId === false || $appId <= 0)
        {
            continue;
        }
        $found    = !empty($game['found']) ? 1 : 0;
        $hasPrice = !empty($game['hasPrice']) ? 1 : 0;
        $currency = $hasPrice ? substr((string)($game['currency'] ?? "USD"), 0, 3) : null;
        $initial  = $hasPrice ? max(0, (int)($game['initialCents'] ?? 0)) : null;
        $final    = $hasPrice ? max(0, (int)($game['finalCents'] ?? 0)) : null;
        $discount = $hasPrice ? max(0, min(100, (int)($game['discountPercent'] ?? 0))) : 0;

        $stmt->bind_param("iiisiiii", $appId, $found, $hasPrice, $currency, $initial, $final, $discount, $now);
        try
        {
            if ($stmt->execute())
            {
                $saved++;
            }
        }
        catch (mysqli_sql_exception $e)
        {
            echo "price not saved for " . $appId . ": " . $e->getMessage() . PHP_EOL; //usually a game that is not in the games table
        }
    }
    return array("success" => true, "message" => "prices saved", "saved" => $saved);
}

// prices for a list of games, for the game page or the wishlist page
function getGamePrices($mydb, $appIds)
{
    if (!is_array($appIds))
    {
        $appIds = array($appIds);
    }
    $ids = array();
    foreach ($appIds as $id)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0)
        {
            $ids[$id] = $id;
        }
    }
    if (count($ids) == 0 || count($ids) > 200)
    {
        return array("success" => false, "message" => "Send 1 to 200 appIds");
    }

    $marks = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $mydb->prepare("SELECT g.app_id, g.name, p.has_price, p.currency, p.initial_cents, p.final_cents,
                                   p.previous_final_cents, p.discount_percent, p.price_updated_at
                            FROM games g JOIN game_prices p ON p.app_id = g.app_id
                            WHERE g.app_id IN ($marks)");
    $values = array_values($ids);
    $stmt->bind_param(str_repeat("i", count($values)), ...$values);
    $stmt->execute();
    $result = $stmt->get_result();

    $prices = array();
    while ($row = $result->fetch_assoc())
    {
        $prices[] = array(
            "appId"              => (int)$row['app_id'],
            "name"               => $row['name'],
            "hasPrice"           => (bool)$row['has_price'],
            "currency"           => $row['currency'],
            "initialCents"       => $row['initial_cents'] === null ? null : (int)$row['initial_cents'],
            "finalCents"         => $row['final_cents'] === null ? null : (int)$row['final_cents'],
            "previousFinalCents" => $row['previous_final_cents'] === null ? null : (int)$row['previous_final_cents'],
            "discountPercent"    => (int)$row['discount_percent'],
            "onSale"             => (int)$row['discount_percent'] > 0,
            "updatedAt"          => (int)$row['price_updated_at']
        );
    }
    return array("success" => true, "message" => "prices loaded", "prices" => $prices);
}
?>
