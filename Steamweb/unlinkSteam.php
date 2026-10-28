<?php
// the unlink steam button
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');

if (empty($_COOKIE['session_token']))
{
    echo json_encode(array("success" => false, "message" => "not logged in"));
    exit();
}

$request = array();
$request['type'] = "unlink_steam";
$request['sessionId'] = $_COOKIE['session_token'];

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$response = $client->send_request($request);

echo json_encode($response);
?>