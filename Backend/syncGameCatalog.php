#!/usr/bin/php
<?php
chdir(__DIR__);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function logSync($message)
{
    echo date("c") . " " . $message . PHP_EOL;
}

function databaseRequest($client, $request)
{
    $response = $client->send_request($request);
    if (!is_array($response) ||
        ($response['success'] ?? false) !== true)
    {
        throw new Exception("Database request failed: " . $request['type']);
    }
    return $response;
}

try
{
    $dmz = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");
    $db = new rabbitMQClient("databaseRabbitMQ.ini", "databaseServer");
    $lastAppId = 0;
    $total = 0;

    logSync("Catalog sync started");

    // Import the available catalog in batches.
    do
    {
        $batch = $dmz->send_request(array(
            "type" => "get_game_catalog",
            "lastAppId" => $lastAppId
        ));

        if (!is_array($batch) ||
            ($batch['returnCode'] ?? '0') !== '1' ||
            !isset($batch['games'], $batch['lastAppId'], $batch['hasMore']) ||
            !is_array($batch['games']) ||
            !is_bool($batch['hasMore']))
        {
            throw new Exception("Invalid catalog response from DMZ");
        }

        $next = filter_var($batch['lastAppId'], FILTER_VALIDATE_INT);
        if ($next === false || $next < $lastAppId ||
            ($batch['hasMore'] && $next <= $lastAppId))
        {
            throw new Exception("Catalog position did not advance");
        }

        databaseRequest($db, array(
            "type" => "save_game_catalog",
            "games" => $batch['games'],
            "lastAppId" => $next,
            "hasMore" => $batch['hasMore']
        ));

        $total += count($batch['games']);
        $lastAppId = $next;
        $hasMore = $batch['hasMore'];
        logSync("Catalog entries saved: " . $total);
        if ($hasMore) sleep(2);
    }
    while ($hasMore);

    logSync("Catalog complete. Selecting games for achievements.");

    // The database chooses up to 500 games due for collection.
    $pending = databaseRequest($db, array(
        "type" => "get_global_games_due"
    ));

    if (!isset($pending['games']) || !is_array($pending['games']))
    {
        throw new Exception("Invalid pending-games response");
    }

    $savedCount = 0;
    $failedCount = 0;

    foreach ($pending['games'] as $game)
    {
        $appId = (int)$game['appId'];
        logSync("Fetching global achievements for " . $appId);

        databaseRequest($db, array(
            "type" => "mark_global_game_started",
            "appId" => $appId
        ));

        $data = $dmz->send_request(array(
            "type" => "get_global_game_data",
            "appId" => $appId
        ));

        if (!is_array($data) ||
            ($data['returnCode'] ?? '0') !== '1' ||
            !isset($data['achievements'], $data['rarityAvailable']) ||
            !is_array($data['achievements']) ||
            !is_bool($data['rarityAvailable']) ||
            (int)($data['appId'] ?? 0) !== $appId)
        {
            databaseRequest($db, array(
                "type" => "mark_global_game_failed",
                "appId" => $appId,
                "message" => "Steam definitions unavailable"
            ));
            $failedCount++;
            logSync("Unavailable: " . $appId);
            sleep(2);
            continue;
        }

        databaseRequest($db, array(
            "type" => "save_global_game_data",
            "data" => $data
        ));

        $savedCount++;
        logSync("Saved " . $appId . ": " .
            count($data['achievements']) . " achievement definitions");

        if (!$data['rarityAvailable'])
        {
            logSync("Rarity unavailable; previous rarity values preserved.");
        }
        sleep(2);
    }

    logSync("Finished. Achievement batches saved: " . $savedCount .
        "; unavailable: " . $failedCount);
}
catch (Throwable $error)
{
    fwrite(STDERR, date("c") . " ERROR: " .
        $error->getMessage() . PHP_EOL);
    exit(1);
}