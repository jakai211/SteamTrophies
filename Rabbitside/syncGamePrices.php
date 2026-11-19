#!/usr/bin/php
<?php
// Ahbab: price sync job
// asks the database which games need a price check, gets their prices from the DMZ
// 50 at a time (get_game_details), and saves them back to the database.
// Run by cron once a night, e.g.:
// 30 3 * * * /usr/bin/php /home/USER/SteamTrophies/Rabbitside/syncGamePrices.php >> /home/USER/SteamTrophies/Rabbitside/price-sync.log 2>&1
// Optional: php syncGamePrices.php 200   (check 200 games instead of 500)
chdir(__DIR__);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function logPrices($message)
{
    echo date("c") . " " . $message . PHP_EOL;
}

function priceDatabaseRequest($client, $request)
{
    $response = $client->send_request($request);
    if (!is_array($response) || ($response['success'] ?? false) !== true)
    {
        throw new Exception("Database request failed: " . $request['type']);
    }
    return $response;
}

// only one copy at a time, in case the last run is still going
$lock = fopen(__DIR__ . "/price-sync.lock", "c");
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
{
    logPrices("Price sync is already running, skipping");
    exit(0);
}

$limit = isset($argv[1]) ? max(1, min(1000, intval($argv[1]))) : 500;

try
{
    $dmz = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");
    $db = new rabbitMQClient("databaseRabbitMQ.ini", "databaseServer");

    logPrices("Price sync started, up to " . $limit . " games");

    $due = priceDatabaseRequest($db, array(
        "type" => "get_games_due_for_prices",
        "limit" => $limit
    ));
    $appIds = is_array($due['appIds'] ?? null) ? $due['appIds'] : array();
    logPrices("Games due for a price check: " . count($appIds));

    $saved = 0;
    $failed = 0;
    foreach (array_chunk($appIds, 50) as $batch)
    {
        $prices = $dmz->send_request(array(
            "type" => "get_game_details",
            "appIds" => $batch
        ));

        if (!is_array($prices) || ($prices['returnCode'] ?? '0') !== '1' || !is_array($prices['games'] ?? null))
        {
            // these games stay "due" and get tried again next run
            $failed += count($batch);
            logPrices("DMZ could not get prices for " . count($batch) . " games, will retry next run");
            sleep(5);
            continue;
        }

        $result = priceDatabaseRequest($db, array(
            "type" => "save_game_prices",
            "games" => $prices['games']
        ));
        $saved += (int)($result['saved'] ?? 0);
        logPrices("Saved prices: " . $saved);
        sleep(2); // stay under Steam's store rate limit
    }

    logPrices("Finished. Saved: " . $saved . ", failed: " . $failed);
}
catch (Throwable $error)
{
    fwrite(STDERR, date("c") . " ERROR: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
