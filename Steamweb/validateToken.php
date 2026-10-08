<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

//Lets site know not to keep information and how to read it
header('Content-Type: application/json');
header('Cache-Control: no-store');

//If no session token then false
if (empty($_COOKIE['session_token']))
{
    echo json_encode(array("valid" => false));
    exit();
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");

$request = array();
$request['type'] = "validate_session";
$request['session_token'] = $_COOKIE['session_token'];

$response = $client->send_request($request);

//Checks username and returns it if key is valid
if (isset($response['success'], $response['username']) &&
    $response['success'] === true &&
    is_string($response['username']))
{
    echo json_encode(array(
        "valid" => true,
        "username" => $response['username']
    ));
}
else
{
    echo json_encode(array("valid" => false));
}
?>
