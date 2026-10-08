#!/usr/bin/php
<?php
// steamProcessor.php - the DMZ "Datasource Processor".
// It waits for messages from RabbitMQ, asks the Steam Web API for the data,
// and sends the cleaned result back through RabbitMQ.
// It never talks to the database or the web server directly.

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('steamConfig.php');   // defines STEAM_API_KEY (this file is NOT pushed to git)

// Sends one GET request to a Steam URL and returns the answer as a PHP array.
// Returns false if the request failed.
function steamGet($url)
{
    $ch = curl_init($url);                          // start a web request
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // give us the reply as text instead of printing it
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);          // give up after 15 seconds
    $body = curl_exec($ch);                         // run the request
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);  // 200 means OK
    curl_close($ch);

    if ($body === false || $code != 200)
    {
        return false;
    }
    return json_decode($body, true);                // turn the JSON text into an array
}

// Turns a profile name (like "gaben") into the numeric SteamID.
// If the input is already a 17-digit SteamID, it is returned unchanged.
function resolveSteamId($input)
{
    $input = trim($input);
    if (preg_match('/^[0-9]{17}$/', $input))
    {
        return $input;
    }
    $url = "https://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/?key=" . STEAM_API_KEY . "&vanityurl=" . urlencode($input);
    $data = steamGet($url);
    if ($data && isset($data['response']['success']) && $data['response']['success'] == 1)
    {
        return $data['response']['steamid'];
    }
    return false;   // name not found
}

// Request type "get_profile": name, avatar and profile link.
function getProfile($user)
{
    $steamId = resolveSteamId($user);
    if (!$steamId)
    {
        return array("returnCode" => '0', "message" => "Steam user not found");
    }
    $url = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=" . STEAM_API_KEY . "&steamids=" . $steamId;
    $data = steamGet($url);
    if (!$data || empty($data['response']['players']))
    {
        return array("returnCode" => '0', "message" => "Could not load profile");
    }
    $p = $data['response']['players'][0];
    return array(
        "returnCode" => '1',
        "steamId"    => $steamId,
        "name"       => $p['personaname'],
        "avatar"     => $p['avatarfull'],
        "profileUrl" => $p['profileurl']
    );
}

// Request type "get_owned_games": the player's library with hours played.
// The player's game details must be PUBLIC on Steam or the list comes back empty.
function getOwnedGames($user)
{
    $steamId = resolveSteamId($user);
    if (!$steamId)
    {
        return array("returnCode" => '0', "message" => "Steam user not found");
    }
    $url = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key=" . STEAM_API_KEY . "&steamid=" . $steamId . "&include_appinfo=1&format=json";
    $data = steamGet($url);
    if (!$data || !isset($data['response']['games']))
    {
        return array("returnCode" => '0', "message" => "No games found (profile may be private)");
    }

    // keep only the fields we need (this is the "clean" step)
    $games = array();
    foreach ($data['response']['games'] as $g)
    {
        $games[] = array(
            "appId"        => $g['appid'],
            "name"         => $g['name'],
            "hoursPlayed"  => round($g['playtime_forever'] / 60, 1),   // Steam gives minutes
            "icon"         => $g['img_icon_url']
        );
    }
    return array("returnCode" => '1', "steamId" => $steamId, "gameCount" => count($games), "games" => $games);
}

// Request type "get_achievements": every achievement for one game, with rarity.
// Rarity = the % of ALL players who unlocked it (low % = rare).
function getAchievements($user, $appId)
{
    $steamId = resolveSteamId($user);
    if (!$steamId)
    {
        return array("returnCode" => '0', "message" => "Steam user not found");
    }

    // 1) this player's achievements for the game
    $url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?appid=" . intval($appId) . "&key=" . STEAM_API_KEY . "&steamid=" . $steamId . "&l=english";
    $mine = steamGet($url);
    if (!$mine || empty($mine['playerstats']['achievements']))
    {
        return array("returnCode" => '0', "message" => "No achievements found (private profile or game has none)");
    }

    // 2) global unlock percentages for the same game
    $url = "https://api.steampowered.com/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v0002/?gameid=" . intval($appId) . "&format=json";
    $global = steamGet($url);
    $percent = array();   // achievement id => global percent
    if ($global && isset($global['achievementpercentages']['achievements']))
    {
        foreach ($global['achievementpercentages']['achievements'] as $a)
        {
            $percent[$a['name']] = round((float)$a['percent'], 1);
        }
    }

    // 3) combine both lists
    $list = array();
    $unlocked = 0;
    foreach ($mine['playerstats']['achievements'] as $a)
    {
        $pct = isset($percent[$a['apiname']]) ? $percent[$a['apiname']] : null;
        $list[] = array(
            "id"          => $a['apiname'],
            "name"        => isset($a['name']) ? $a['name'] : $a['apiname'],
            "description" => isset($a['description']) ? $a['description'] : '',
            "unlocked"    => ($a['achieved'] == 1),
            "unlockTime"  => $a['unlocktime'],
            "globalPercent" => $pct
        );
        if ($a['achieved'] == 1)
        {
            $unlocked++;
        }
    }
    return array(
        "returnCode"   => '1',
        "steamId"      => $steamId,
        "appId"        => intval($appId),
        "total"        => count($list),
        "unlocked"     => $unlocked,
        "achievements" => $list
    );
}

// RabbitMQ calls this for every message that arrives.
// $request is the array that the web server (or a test client) sent.
function requestProcessor($request)
{
    echo "received request: " . (isset($request['type']) ? $request['type'] : 'none') . PHP_EOL;

    if (!isset($request['type']))
    {
        return array("returnCode" => '0', "message" => "ERROR: unsupported message type");
    }

    // pick what to do based on the message "type"
    switch ($request['type'])
    {
        case "get_profile":
            return getProfile($request['user']);
        case "get_owned_games":
            return getOwnedGames($request['user']);
        case "get_achievements":
            return getAchievements($request['user'], $request['appId']);
    }
    return array("returnCode" => '0', "message" => "Unknown request type");
}

// Connect to RabbitMQ using the [dmzServer] section of dmzRabbitMQ.ini
$server = new rabbitMQServer("dmzRabbitMQ.ini", "dmzServer");

echo "steamProcessor BEGIN" . PHP_EOL;
$server->process_requests('requestProcessor');   // wait for messages forever
echo "steamProcessor END" . PHP_EOL;
exit();
?>
