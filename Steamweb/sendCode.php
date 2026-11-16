<?php
// the "Send me a code" button will calls this
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('sendMail.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] != 'POST')
{
    echo json_encode(array("success" => false, "message" => "bad request"));
    exit();
}

// there we set the pending token cookie
if (empty($_COOKIE['pending_token']))
{
    echo json_encode(array("success" => false, "message" => "Please sign in again."));
    exit();
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$request = array();
$request['type'] = "send_2fa_code";
$request['pendingToken'] = $_COOKIE['pending_token'];

$response = $client->send_request($request);

if (!isset($response['success'], $response['code'], $response['email']) || $response['success'] !== true)
{
    $message = isset($response['message']) ? $response['message'] : "Could not make code.";
    echo json_encode(array("success" => false, "message" => $message));
    exit();
}

$body = "Your SteamTrophies login code is: " . $response['code'] . "\n\n" .
        "It works for 10 minutes. If it wasn't you who tried to login go kick rocks.";

if (!sendMail($response['email'], "Your SteamTrophies login code", $body))
{
    echo json_encode(array("success" => false, "message" => "Could not send the email. Try again in a minute."));
    exit();
}
echo json_encode(array("success" => true, "message" => "Code sent, check your email."));
?>
