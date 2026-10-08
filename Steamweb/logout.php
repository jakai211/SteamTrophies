<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

if ($_SERVER['REQUEST_METHOD'] != 'POST')
{
    header("Location: login.html");
    exit();
}

if (isset($_COOKIE['session_token']))
{
    $client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");

    $request = array();
    $request['type'] = "logout";
    $request['token'] = $_COOKIE['session_token'];

    $response = $client->send_request($request);

    if ($response !== "yes")
    {
        exit("Logout failed. Please try again.");
    }

    setcookie("session_token", "", time() - 3600, "/");
}

header("Location: login.html");
exit();
?>
