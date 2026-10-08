<?php

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

if ($_SERVER['REQUEST_METHOD'] != 'POST')
{
    header("Location: login.html");
    exit();
}

if (!isset($_POST['type'], $_POST['username'], $_POST['password']))
{
    exit("Missing form information.");
}

$type = $_POST['type'];

if ($type != "login" && $type != "register")
{
    exit("Unsupported request.");
}

if ($type == "register")
{
    if (!isset($_POST['confirm_password']) ||
        $_POST['password'] != $_POST['confirm_password'])
    {
        exit("Passwords do not match.");
    }
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");

$request = array();
$request['type'] = $type;
$request['username'] = $_POST['username'];
$request['password'] = $_POST['password'];

$response = $client->send_request($request);

if ($type == "login")
{
    if (isset($response['success'], $response['token']) &&
        $response['success'] === true &&
        is_string($response['token']) &&
        $response['token'] !== "")
    {
        setcookie("session_token", $response['token'], array(
            "path" => "/",
            "httponly" => true,
            "samesite" => "Lax",
            "secure" => !empty($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== "off"
        ));

        header("Location: welcome.html");
        exit();
    }

    echo "Incorrect username or password.";
}
else
{
    if (isset($response['success']) && $response['success'] === true)
    {
        echo "Registration successful.";
    }
    else
    {
        echo "Registration failed.";
    }
}
?>
