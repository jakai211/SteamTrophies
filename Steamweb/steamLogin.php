<?php
//sends the user to steams login page
$site = "http://steamtrophies.com";
 
if (empty($_COOKIE['session_token']))
{
    header("Location: login.html");
    exit();
}
$steamParams = array();
$steamParams['openid.ns'] = "http://specs.openid.net/auth/2.0";
$steamParams['openid.mode'] = "checkid_setup";
$steamParams['openid.return_to'] = $site . "/steamCallback.php";
$steamParams['openid.realm'] = $site;
$steamParams['openid.identity'] = "http://specs.openid.net/auth/2.0/identifier_select";
$steamParams['openid.claimed_id'] = "http://specs.openid.net/auth/2.0/identifier_select";
 
header("Location: https://steamcommunity.com/openid/login?" . http_build_query($steamParams));
exit();
?>
 