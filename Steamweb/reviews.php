<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_COOKIE['session_token']))
{
    echo json_encode(array("success" => false, "message" => "not logged in"));
    exit();
}

$request = array();
$request['sessionId'] = $_COOKIE['session_token'];

if ($_SERVER['REQUEST_METHOD'] == 'GET')
{
    $request['type'] = "get_reviews";
    $request['appId'] = intval($_GET['appId'] ?? 0);
}
else
{
    $action = "";
    if (isset($_POST['action']))
    {
        $action = $_POST['action'];
    }
    $request['appId'] = intval($_POST['appId'] ?? 0);

    if ($action == "save")
    {
        $request['type'] = "save_review";
        $request['rating'] = intval($_POST['rating'] ?? 0);
        $request['text'] = $_POST['text'] ?? "";
    }
    else if ($action == "delete")
    {
        $request['type'] = "delete_review";
    }
    else
    {
        echo json_encode(array("success" => false, "message" => "unknown action"));
        exit();
    }
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$response = $client->send_request($request);

echo json_encode($response);
?>