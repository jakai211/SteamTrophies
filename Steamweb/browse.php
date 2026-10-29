<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');

if (empty($_COOKIE['session_token']))
{
    $response = array();
    $response['success'] = false;
    $response['message'] = "not logged in";
    echo json_encode($response);
    exit();
}
$search = "";
if (isset($_GET['search']))
{
    $search = trim($_GET['search']);
}
$owned = 0;
if (isset($_GET['owned']) && $_GET['owned'] == "1")
{
    $owned = 1;
}
$sort = "name";
if (isset($_GET['sort']) && $_GET['sort'] == "played")
{
    $sort = "played";
}
$page = 1;
if (isset($_GET['page']))
{
    $page = intval($_GET['page']);
}
if ($page < 1)
{
    $page = 1;
}

$request = array();
$request['type'] = "browse_games";
$request['sessionId'] = $_COOKIE['session_token'];
$request['search'] = $search;
$request['ownedOnly'] = $owned;
$request['sort'] = $sort;
$request['page'] = $page;

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$response = $client->send_request($request);

echo json_encode($response);
?>