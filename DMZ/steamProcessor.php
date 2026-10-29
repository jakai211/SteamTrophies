#!/usr/bin/php
<?php
// steamProcessor.php - the DMZ "Datasource Processor".
// It waits for messages from RabbitMQ, asks the Steam Web API for the data,
// and sends the cleaned result back through RabbitMQ.
// It never talks to the database or the web server directly.

chdir(__DIR__);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('steamConfig.php');   // defines STEAM_API_KEY (this file is NOT pushed to git)

// Sends one GET request to a Steam URL and returns the answer as a PHP array.
// Returns false if the request failed.
function steamGet($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code != 200)
    {
        return false;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : false;
}

// Turns a profile name (like "gaben") into the numeric SteamID.
// If the input is already a 17-digit SteamID, it is returned unchanged.
function resolveSteamId($input)
{
    if (!is_string($input) || trim($input) == "")
    {
        return false;
    }
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
    $url = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key=" . STEAM_API_KEY . "&steamid=" . $steamId . "&include_appinfo=1&include_played_free_games=1&format=json";
    $data = steamGet($url);
    if (!$data || !isset($data['response']['game_count']))
    {
        return array("returnCode" => '0', "message" => "Game library unavailable; profile may be private");
    }
    $library = $data['response']['games'] ?? array();
    if ($data['response']['game_count'] > 0 && empty($library))
    {
        return array("returnCode" => '0', "message" => "Incomplete game library response");
    }

    // keep only the fields we need (this is the "clean" step)
    $games = array();
    foreach ($library as $g)
    {
        $games[] = array(
            "appId"        => $g['appid'],
            "name"         => $g['name'] ?? "",
            "minutesPlayed" => $g['playtime_forever'] ?? 0,
            "hoursPlayed"  => round(($g['playtime_forever'] ?? 0) / 60, 1),   // Steam gives minutes
            "icon"         => $g['img_icon_url'] ?? ""
        );
    }
    return array("returnCode" => '1', "steamId" => $steamId, "gameCount" => count($games), "games" => $games);
}

// Request type "get_achievements": every achievement for one game, with rarity.
// Rarity = the % of ALL players who unlocked it (low % = rare).
function getAchievements($user, $appId)
{
    $appId = filter_var($appId, FILTER_VALIDATE_INT);
    if ($appId === false || $appId <= 0)
    {
        return array("returnCode" => '0', "message" => "Invalid appId");
    }
    $steamId = resolveSteamId($user);
    if (!$steamId)
    {
        return array("returnCode" => '0', "message" => "Steam user not found");
    }

    // 1) this player's achievements for the game
    $url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?appid=" . intval($appId) . "&key=" . STEAM_API_KEY . "&steamid=" . $steamId . "&l=english";
    $mine = steamGet($url);
    if (!$mine || ($mine['playerstats']['success'] ?? false) !== true ||
        !isset($mine['playerstats']['achievements']) ||
        !is_array($mine['playerstats']['achievements']))
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
            "unlockTime"  => $a['unlocktime'] ?? 0,
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
        "gameName"     => isset($mine["playerstats"]["gameName"]) ? $mine["playerstats"]["gameName"] : "",
        "total"        => count($mine["playerstats"]["achievements"]),
        "unlocked"     => $unlocked,
        "achievements" => $list
    );
}

// Request type "get_game_catalog": one batch of Steam Store games.
// Start with lastAppId = 0 and keep requesting until hasMore is false.
function getGameCatalog($lastAppId = 0)
{
    $lastAppId = filter_var($lastAppId, FILTER_VALIDATE_INT);
    if ($lastAppId === false || $lastAppId < 0)
    {
        return array("returnCode" => '0', "message" => "Invalid lastAppId");
    }

    $parameters = array(
        "include_games" => true,
        "include_dlc" => false,
        "include_software" => false,
        "include_videos" => false,
        "include_hardware" => false,
        "last_appid" => $lastAppId,
        "max_results" => 1000
    );
    $url = "https://api.steampowered.com/IStoreService/GetAppList/v1/?";
    $url .= http_build_query(array(
        "key" => STEAM_API_KEY,
        "input_json" => json_encode($parameters)
    ));
    $data = steamGet($url);
    if (!isset($data['response']['apps']) ||
        !is_array($data['response']['apps']))
    {
        return array("returnCode" => '0', "message" => "Could not load catalog");
    }

    $games = array();
    $nextAppId = $lastAppId;
    foreach ($data['response']['apps'] as $game)
    {
        $games[] = array(
            "appId" => $game['appid'],
            "name" => $game['name']
        );
        $nextAppId = max($nextAppId, (int)$game['appid']);
    }

    $more = $data['response']['have_more_results'] ?? (count($games) == 1000);
    if ($more && $nextAppId <= $lastAppId)
    {
        return array("returnCode" => '0', "message" => "Catalog did not advance");
    }

    return array(
        "returnCode" => '1',
        "games" => $games,
        "gameCount" => count($games),
        "lastAppId" => $nextAppId,
        "hasMore" => (bool)$more
    );
}

// Request type "get_achievement_definitions": names, descriptions and icons.
// These belong to the game and do not require a player's SteamID.
function getAchievementDefinitions($appId)
{
    $appId = filter_var($appId, FILTER_VALIDATE_INT);
    if ($appId === false || $appId <= 0)
    {
        return array("returnCode" => '0', "message" => "Invalid appId");
    }

    $url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?";
    $url .= http_build_query(array(
        "key" => STEAM_API_KEY,
        "appid" => $appId,
        "l" => "english"
    ));
    $data = steamGet($url);
    if (empty($data['game']) || !is_array($data['game']))
    {
        return array("returnCode" => '0', "message" => "Could not load achievement definitions");
    }

    $definitions = $data['game']['availableGameStats']['achievements'] ?? array();
    $list = array();
    foreach ($definitions as $item)
    {
        $list[] = array(
            "id" => $item['name'],
            "name" => $item['displayName'] ?? $item['name'],
            "description" => $item['description'] ?? "",
            "icon" => $item['icon'] ?? "",
            "iconLocked" => $item['icongray'] ?? "",
            "hidden" => !empty($item['hidden'])
        );
    }

    return array(
        "returnCode" => '1',
        "appId" => $appId,
        "gameName" => $data['game']['gameName'] ?? "",
        "total" => count($list),
        "achievements" => $list
    );
}

// Request type "get_recent_playtime": games played during the last two weeks.
// Keep minutes for storage and provide rounded hours for display.
function getRecentPlaytime($user)
{
    $steamId = resolveSteamId($user);
    if (!$steamId)
    {
        return array("returnCode" => '0', "message" => "Steam user not found");
    }

    $url = "https://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v1/?";
    $url .= http_build_query(array(
        "key" => STEAM_API_KEY,
        "steamid" => $steamId,
        "format" => "json"
    ));
    $data = steamGet($url);
    if (!isset($data['response']['total_count']))
    {
        return array("returnCode" => '0', "message" => "Recent playtime unavailable; check Steam privacy");
    }

    $recent = $data['response']['games'] ?? array();
    if ($data['response']['total_count'] > 0 && empty($recent))
    {
        return array("returnCode" => '0', "message" => "Incomplete recent games response");
    }

    $games = array();
    foreach ($recent as $game)
    {
        $minutes = $game['playtime_2weeks'] ?? 0;
        $totalMinutes = $game['playtime_forever'] ?? 0;
        $games[] = array(
            "appId" => $game['appid'],
            "name" => $game['name'] ?? "",
            "minutesLastTwoWeeks" => $minutes,
            "hoursLastTwoWeeks" => round($minutes / 60, 1),
            "minutesPlayed" => $totalMinutes,
            "hoursPlayed" => round($totalMinutes / 60, 1)
        );
    }

    return array(
        "returnCode" => '1',
        "steamId" => $steamId,
        "gameCount" => count($games),
        "games" => $games
    );
}

// Checks with Steam that a "Sign in through Steam" login is real and returns the 17-digit SteamID.
function verifySteamLogin($params)
{
    if (!is_array($params))
    {
        return array("returnCode" => '0', "message" => "Not a Steam login");
    }

    // PHP turns "openid.mode" into "openid_mode" in $_GET, so convert those keys back
    $openid = array();
    foreach ($params as $key => $value)
    {
        $key = (string)$key;
        if (strpos($key, "openid_") === 0)
        {
            $key = "openid." . substr($key, 7);
        }
        if (strpos($key, "openid.") === 0 && is_string($value))
        {
            $openid[$key] = $value;
        }
    }

    if (($openid['openid.mode'] ?? "") != "id_res")
    {
        return array("returnCode" => '0', "message" => "Not a Steam login");
    }
    if (($openid['openid.op_endpoint'] ?? "") != "https://steamcommunity.com/openid/login")
    {
        return array("returnCode" => '0', "message" => "Not from Steam");
    }

    // claimed_id looks like https://steamcommunity.com/openid/id/76561198000000000
    if (!preg_match('#^https://steamcommunity\.com/openid/id/([0-9]{17})$#', $openid['openid.claimed_id'] ?? "", $match))
    {
        return array("returnCode" => '0', "message" => "No Steam ID found");
    }
    $steamId = $match[1];

    // send everything back to Steam and ask "is this real?"
    $openid['openid.mode'] = "check_authentication";
    $ch = curl_init("https://steamcommunity.com/openid/login");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($openid));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $answer = curl_exec($ch);
    curl_close($ch);

    // Steam answers with "is_valid:true" if the login is real
    if ($answer === false || !preg_match('/^is_valid:true\s*$/m', $answer))
    {
        return array("returnCode" => '0', "message" => "Steam did not confirm the login");
    }

    return array("returnCode" => '1', "steamId" => $steamId);
}

// Request type "get_steam_reviews": Steam's review score and the most helpful reviews for one game.
// No player SteamID needed. Used by the game page (Steamweb/gameData.php).
function getSteamReviews($appId)
{
    $appId = filter_var($appId, FILTER_VALIDATE_INT);
    if ($appId === false || $appId <= 0)
    {
        return array("returnCode" => '0', "message" => "Invalid appId");
    }

    // filter 0 = most helpful, day_range 0 = no date limit, purchase_type 1 = all purchases
    $input = array(
        "appid"            => $appId,
        "filter"           => 0,
        "day_range"        => 0,
        "review_type"      => 0,
        "purchase_type"    => 1,
        "num_per_page"     => 20,
        "display_language" => "english"
    );
    $url = "https://api.steampowered.com/IUserReviewsService/GetAppReviews/v1/?" . http_build_query(array(
        "input_json" => json_encode($input)
    ));
    $data = steamGet($url);
    if (isset($data['response']) && is_array($data['response']))
    {
        $data = $data['response'];
    }
    if (!$data || !isset($data['query_summary']))
    {
        return array("returnCode" => '0', "message" => "Could not load reviews");
    }

    $sum      = $data['query_summary'];
    $positive = (int)($sum['total_positive'] ?? 0);
    $negative = (int)($sum['total_negative'] ?? 0);

    $reviews = array();
    foreach (($data['reviews'] ?? array()) as $r)
    {
        $minutes = (int)($r['author']['playtime_forever'] ?? $r['author']['playtime_at_review'] ?? 0);
        $reviews[] = array(
            "recommended" => !empty($r['voted_up']),
            "text"        => (preg_match('/^.{0,1000}/su', $r['review'] ?? "", $m) ? $m[0] : ""),
            "hoursPlayed" => round($minutes / 60, 1),
            "postedAt"    => (int)($r['timestamp_created'] ?? 0),
            "votesUp"     => (int)($r['votes_up'] ?? 0)
        );
    }

    return array(
        "returnCode" => '1',
        "appId"      => $appId,
        "scoreText"  => $sum['review_score_desc'] ?? "",
        "positive"   => $positive,
        "total"      => (int)($sum['total_reviews'] ?? ($positive + $negative)),
        "reviews"    => $reviews
    );
}

// RabbitMQ calls this for every message that arrives.

// Global achievement definitions and rarity for one game.
// This request does not need a player or website session.
function getGlobalGameData($appId)
{
    $definitions = getAchievementDefinitions($appId);
    if (($definitions['returnCode'] ?? '0') !== '1')
    {
        return $definitions;
    }

    $appId = (int)$definitions['appId'];
    $items = $definitions['achievements'];
    $percentages = array();
    $rarityAvailable = true;

    // A game with no returned definitions needs no rarity request.
    if (count($items) > 0)
    {
        $url = "https://api.steampowered.com/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v2/?";
        $url .= http_build_query(array(
            "gameid" => $appId,
            "format" => "json"
        ));
        $global = steamGet($url);
        $rarityAvailable =
            isset($global['achievementpercentages']['achievements']) &&
            is_array($global['achievementpercentages']['achievements']);

        if ($rarityAvailable)
        {
            foreach ($global['achievementpercentages']['achievements'] as $item)
            {
                if (isset($item['name'], $item['percent']) &&
                    is_numeric($item['percent']) &&
                    $item['percent'] >= 0 && $item['percent'] <= 100)
                {
                    $percentages[$item['name']] = (float)$item['percent'];
                }
            }
        }
    }

    foreach ($items as &$item)
    {
        $item['globalPercent'] = $percentages[$item['id']] ?? null;
        if ($item['globalPercent'] === null)
        {
            $rarityAvailable = false;
        }
    }
    unset($item);

    return array(
        "returnCode" => '1',
        "appId" => $appId,
        "achievements" => $items,
        "rarityAvailable" => $rarityAvailable
    );
}
function requestProcessor($request)
{
    if (!is_array($request) ||
        !isset($request['type']) ||
        !is_string($request['type']))
    {
        return array("returnCode" => '0', "message" => "ERROR: unsupported message type");
    }
    echo "received request: " . $request['type'] . PHP_EOL;

    try
    {
        switch ($request['type'])
        {
            case "get_profile":
                return getProfile($request['user'] ?? "");

            case "get_owned_games":
                return getOwnedGames($request['user'] ?? "");

            case "get_achievements":
                return getAchievements(
                    $request['user'] ?? "",
                    $request['appId'] ?? 0
                );

            case "get_game_catalog":
                return getGameCatalog($request['lastAppId'] ?? 0);

            case "get_achievement_definitions":
                return getAchievementDefinitions($request['appId'] ?? 0);

            case "verify_steam_login":
                return verifySteamLogin($request['params'] ?? array());

            case "get_steam_reviews":
                return getSteamReviews($request['appId'] ?? 0);

            case "get_recent_playtime":
                return getRecentPlaytime($request['user'] ?? "");
        }
    }
    catch (Throwable $error)
    {
        echo "Request failed: " . $request['type'] . PHP_EOL;
        return array("returnCode" => '0', "message" => "Steam request could not be processed");
    }
    return array("returnCode" => '0', "message" => "Unknown request type");
}

// Connect to RabbitMQ using the [dmzServer] section of dmzRabbitMQ.ini
$server = new rabbitMQServer("dmzRabbitMQ.ini", "dmzServer");

echo "steamProcessor BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "steamProcessor END".PHP_EOL;
exit();
