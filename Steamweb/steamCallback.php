<?php
//steam sends the user back here after they log in
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
 
$site = "http://steamtrophies.com";
 
if (empty($_COOKIE['session_token']))
{
    header("Location: login.html");
    exit();
}
$steamParams = array();
foreach ($_GET as $key => $value)
{
    if (substr($key, 0, 7) == "openid_")
    {
        $newKey = "openid." . substr($key, 7);
        $steamParams[$newKey] = $value;
    }
}
 if (!isset($steamParams['openid.mode']) || $steamParams['openid.mode'] != "id_res")
{
    header("Location: profile.html?steam=cancelled");
    exit();
}
 if (!isset($steamParams['openid.return_to']) || $steamParams['openid.return_to'] != $site . "/steamCallback.php")
{
    header("Location: profile.html?steam=invalid");
    exit();
}
 $dmzRequest = array();
$dmzRequest['type'] = "verify_steam_login";
$dmzRequest['params'] = $steamParams;
 
$dmzClient = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");
$dmzResponse = $dmzClient->send_request($dmzRequest);
 
if (!isset($dmzResponse['returnCode']) || $dmzResponse['returnCode'] != '1')
{
    header("Location: profile.html?steam=invalid");
    exit();
}
$steamId = $dmzResponse['steamId'];
 
$dbRequest = array();
$dbRequest['type'] = "link_steam";
$dbRequest['sessionId'] = $_COOKIE['session_token'];
$dbRequest['steamId'] = $steamId;
 
$dbClient = new rabbitMQClient("WebRabbitMQ.ini", "testServer");
$dbResponse = $dbClient->send_request($dbRequest);
 
if (!isset($dbResponse['success']) || $dbResponse['success'] !== true)
{
    header("Location: profile.html?steam=link_failed");
    exit();
}
header("Location: profile.html?steam=linked");
exit();
?>
 