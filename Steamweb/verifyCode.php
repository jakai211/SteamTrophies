<?php
// checks the 6 digit code and finally finishes the login
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] != 'POST')
{
    echo json_encode(array("success" => false, "message" => "bad request"));
    exit();
}

if (empty($_COOKIE['pending_token']))
{
    echo json_encode(array("success" => false, "message" => "Please sign in again."));
    exit();
}

$code = isset($_POST['code']) ? trim($_POST['code']) : "";
// has to be exactly 6 digits here
if (!preg_match('/^[0-9]{6}$/', $code))
{
    echo json_encode(array("success" => false, "message" => "Enter the 6 digit code."));
    exit();
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$request = array();
$request['type'] = "verify_2fa_code";
$request['pendingToken'] = $_COOKIE['pending_token'];
$request['code'] = $code;
$response = $client->send_request($request);

if (isset($response['success'], $response['token']) &&
    $response['success'] === true &&
    is_string($response['token']) &&
    $response['token'] !== "")
{
    // using the same cookie settings the old login used
    setcookie("session_token", $response['token'], array(
        "path" => "/",
        "httponly" => true,
        "samesite" => "Lax",
        "secure" => !empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== "off"
    ));

    setcookie("pending_token", "", time() - 3600, "/");
    echo json_encode(array("success" => true, "message" => "login ok"));
    exit();
}
$message = isset($response['message']) ? $response['message'] : "Could not check the code.";
echo json_encode(array("success" => false, "message" => $message));
?>
