<?php
//everything the game page needs for one game(progress, achievements, wishlist, Steam reviews, and users reviews)
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_COOKIE['session_token']))
{
    echo json_encode(array("loggedIn" => false));
    exit();
}
$sessionId = $_COOKIE['session_token'];

$appId = 0;
if (isset($_GET['appId']))
{
    $appId = intval($_GET['appId']);
}
if ($appId <= 0)
{
    echo json_encode(array("loggedIn" => true, "error" => "No game picked"));
    exit();
}

$db = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$dmz = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");

$request = array();
$request['type'] = "validate_session";
$request['sessionId'] = $sessionId;
$session = $db->send_request($request);

if (!isset($session['success']) || $session['success'] !== true)
{
    echo json_encode(array("loggedIn" => false));
    exit();
}

$data = array();
$data['loggedIn'] = true;
$data['username'] = $session['username'];
$data['appId'] = $appId;
$data['name'] = "Game #" . $appId;
$data['owned'] = false;
$data['hoursPlayed'] = 0;
$data['inWishlist'] = false;
$data['achievements'] = array();
$data['unlocked'] = 0;
$data['total'] = 0;
$data['steamReviews'] = null;
$data['reviews'] = array();

$request = array();
$request['type'] = "get_steam_library";
$request['sessionId'] = $sessionId;
$library = $db->send_request($request);

if (isset($library['success']) && $library['success'] === true)
{
    foreach ($library['games'] as $game)
    {
        if ($game['appId'] == $appId)
        {
            $data['name'] = $game['name'];
            $data['owned'] = true;
            $data['hoursPlayed'] = $game['hoursPlayed'];
        }
    }
}

$request = array();
$request['type'] = "get_steam_achievements";
$request['sessionId'] = $sessionId;
$request['appId'] = $appId;
$achievements = $db->send_request($request);

if (isset($achievements['success']) && $achievements['success'] === true)
{
    $data['achievements'] = $achievements['achievements'];
    $data['unlocked'] = $achievements['unlocked'];
    $data['total'] = $achievements['total'];
}

$request = array();
$request['type'] = "get_wishlist";
$request['sessionId'] = $sessionId;
$wishlist = $db->send_request($request);

if (isset($wishlist['success']) && $wishlist['success'] === true)
{
    foreach ($wishlist['items'] as $item)
    {
        if ($item['app_id'] == $appId)
        {
            $data['inWishlist'] = true;
            if (!$data['owned'])
            {
                $data['name'] = $item['name'];
            }
        }
    }
}

$request = array();
$request['type'] = "get_reviews";
$request['sessionId'] = $sessionId;
$request['appId'] = $appId;
$reviews = $db->send_request($request);

if (isset($reviews['success']) && $reviews['success'] === true)
{
    $data['reviews'] = $reviews['reviews'];
}

$request = array();
$request['type'] = "get_steam_reviews";
$request['appId'] = $appId;
$steamReviews = $dmz->send_request($request);

if (isset($steamReviews['returnCode']) && $steamReviews['returnCode'] == '1')
{
    $data['steamReviews'] = $steamReviews;
}

echo json_encode($data);
?>