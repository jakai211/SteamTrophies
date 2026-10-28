<?php
//gets everything the profile page needs from the database
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$maxGames = 25;

if (empty($_COOKIE['session_token']))
{
    echo json_encode(array("loggedIn" => false));
    exit();
}
$sessionId = $_COOKIE['session_token'];

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");

//who is logged in
$request = array();
$request['type'] = "validate_session";
$request['sessionId'] = $sessionId;
$session = $client->send_request($request);

if (!isset($session['success']) || $session['success'] !== true)
{
    echo json_encode(array("loggedIn" => false));
    exit();
}

$data = array();
$data['loggedIn'] = true;
$data['username'] = $session['username'];
$data['linked'] = false;
$data['profile'] = null;
$data['games'] = array();
$data['achievements'] = array();

//their steam profile
$request = array();
$request['type'] = "get_steam_profile";
$request['sessionId'] = $sessionId;
$profileReply = $client->send_request($request);

if (!isset($profileReply['success']) || $profileReply['success'] !== true)
{
    echo json_encode($data);
    exit();
}
$data['linked'] = true;
$data['profile'] = $profileReply['profile'];

// their games
$request = array();
$request['type'] = "get_steam_library";
$request['sessionId'] = $sessionId;
$libraryReply = $client->send_request($request);

if (isset($libraryReply['success']) && $libraryReply['success'] === true)
{
    $data['games'] = $libraryReply['games'];
}

// achievements for the most played games 
$count = 0;
foreach ($data['games'] as $index => $game)
{
    $data['games'][$index]['unlocked'] = 0;
    $data['games'][$index]['total'] = 0;

    if ($count >= $maxGames || $game['minutesPlayed'] == 0)
    {
        continue;
    }
    $count++;

    $request = array();
    $request['type'] = "get_steam_achievements";
    $request['sessionId'] = $sessionId;
    $request['appId'] = $game['appId'];
    $achReply = $client->send_request($request);

    if (!isset($achReply['success']) || $achReply['success'] !== true)
    {
        continue; 
    }

    $data['games'][$index]['unlocked'] = $achReply['unlocked'];
    $data['games'][$index]['total'] = $achReply['total'];

    // keeps the unlocked ones for the trophy counts, rarest list, streak and chart
    foreach ($achReply['achievements'] as $achievement)
    {
        if ($achievement['unlocked'])
        {
            $percent = $achievement['globalPercent'];
            if ($percent === null)
            {
                $percent = 100; 
            }

            $item = array();
            $item['gameName'] = $game['name'];
            $item['appId'] = $game['appId'];
            $item['name'] = $achievement['name'];
            $item['description'] = $achievement['description'];
            $item['globalPercent'] = $percent;
            $item['unlockTime'] = $achievement['unlockTime'];
            $data['achievements'][] = $item;
        }
    }
}

echo json_encode($data);
?>