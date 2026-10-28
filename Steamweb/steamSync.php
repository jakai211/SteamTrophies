<?php
// sends it to the database to save and asks the DMZ for the newest steam data
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');

set_time_limit(180);

$maxGames = 15;

if (empty($_COOKIE['session_token']))
{
    echo json_encode(array("success" => false, "message" => "not logged in"));
    exit();
}
$sessionId = $_COOKIE['session_token'];

$db = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$dmz = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");

$request = array();
$request['type'] = "get_steam_profile";
$request['sessionId'] = $sessionId;
$linked = $db->send_request($request);

if (!isset($linked['success']) || $linked['success'] !== true)
{
    echo json_encode(array("success" => false, "message" => "Link your Steam account first"));
    exit();
}
$steamId = $linked['profile']['steamId'];

$request = array();
$request['type'] = "get_profile";
$request['user'] = $steamId;
$profile = $dmz->send_request($request);

if (isset($profile['returnCode']) && $profile['returnCode'] == '1')
{
    $save = array();
    $save['type'] = "save_steam_profile";
    $save['sessionId'] = $sessionId;
    $save['profile'] = $profile;
    $db->send_request($save);
}

$request = array();
$request['type'] = "get_owned_games";
$request['user'] = $steamId;
$library = $dmz->send_request($request);

if (!isset($library['returnCode']) || $library['returnCode'] != '1')
{
    echo json_encode(array("success" => false, "message" => "Could not load games. Is your Steam profile public?"));
    exit();
}

$save = array();
$save['type'] = "save_steam_library";
$save['sessionId'] = $sessionId;
$save['library'] = $library;
$db->send_request($save);

$request = array();
$request['type'] = "get_recent_playtime";
$request['user'] = $steamId;
$recent = $dmz->send_request($request);

if (isset($recent['returnCode']) && $recent['returnCode'] == '1')
{
    $save = array();
    $save['type'] = "save_steam_recent";
    $save['sessionId'] = $sessionId;
    $save['recent'] = $recent;
    $db->send_request($save);
}

// the database gives the games back sorted by hours played
$request = array();
$request['type'] = "get_steam_library";
$request['sessionId'] = $sessionId;
$saved = $db->send_request($request);

$count = 0;
foreach ($saved['games'] as $game)
{
    if ($count >= $maxGames || $game['minutesPlayed'] == 0)
    {
        break;
    }

    $request = array();
    $request['type'] = "get_achievement_definitions";
    $request['appId'] = $game['appId'];
    $definitions = $dmz->send_request($request);

    $request = array();
    $request['type'] = "get_achievements";
    $request['user'] = $steamId;
    $request['appId'] = $game['appId'];
    $player = $dmz->send_request($request);

    // skips games with no achievements
    if (!isset($definitions['returnCode']) || $definitions['returnCode'] != '1')
    {
        continue;
    }
    if (!isset($player['returnCode']) || $player['returnCode'] != '1')
    {
        continue;
    }

    $achievements = array();
    $achievements['steamId'] = $steamId;
    $achievements['appId'] = $game['appId'];
    $achievements['definitions'] = $definitions['achievements'];
    $achievements['player'] = $player['achievements'];

    $save = array();
    $save['type'] = "save_steam_achievements";
    $save['sessionId'] = $sessionId;
    $save['achievements'] = $achievements;
    $db->send_request($save);

    $count++;
}

echo json_encode(array("success" => true, "message" => "Synced your games and achievements for " . $count . " games"));
?>