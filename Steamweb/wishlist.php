<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');

// has to be logged in
if (empty($_COOKIE['session_token']))
{
    $response = array();
    $response['success'] = false;
    $response['message'] = "not logged in";
    echo json_encode($response);
    exit();
}

$request = array();
$request['sessionId'] = $_COOKIE['session_token'];

//GET = show the wishlist, POST = add, remove, or notify
if ($_SERVER['REQUEST_METHOD'] == 'GET')
{
    $request['type'] = "get_wishlist";
}
else
{
    $action = "";
    if (isset($_POST['action']))
    {
        $action = $_POST['action'];
    }

    $appId = 0;
    if (isset($_POST['appId']))
    {
        $appId = intval($_POST['appId']);
    }
    $request['appId'] = $appId;

    if ($action == "add")
    {
        $request['type'] = "add_wishlist";
    }
    else if ($action == "remove")
    {
        $request['type'] = "remove_wishlist";
    }
    else if ($action == "notify")
    {
        $request['type'] = "set_wishlist_notify";
        
        $notify = 0;
        if (isset($_POST['notify']))
        {
            $notify = intval($_POST['notify']); //1 = on, 0 = off
        }
        $request['notify'] = $notify;
    }
    else
    {
        $response = array();
        $response['success'] = false;
        $response['message'] = "unknown action";
        echo json_encode($response);
        exit();
    }
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$response = $client->send_request($request);

echo json_encode($response);
?>