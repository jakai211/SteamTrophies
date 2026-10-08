#!/usr/bin/php
<?php
// steamTestClient.php - sends a test message to the DMZ through RabbitMQ.
// Usage:
//   php steamTestClient.php profile      <steam name or id>
//   php steamTestClient.php games        <steam name or id>
//   php steamTestClient.php achievements <steam name or id> <appId>
// Example: php steamTestClient.php games gaben

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

if ($argc < 3)
{
    echo "Usage: php steamTestClient.php profile|games|achievements <user> [appId]" . PHP_EOL;
    exit();
}

// Build the message array the DMZ expects
$request = array();
$request['user'] = $argv[2];
if ($argv[1] == 'profile')
{
    $request['type'] = 'get_profile';
}
else if ($argv[1] == 'games')
{
    $request['type'] = 'get_owned_games';
}
else if ($argv[1] == 'achievements')
{
    $request['type'] = 'get_achievements';
    $request['appId'] = isset($argv[3]) ? $argv[3] : 440;   // 440 = Team Fortress 2
}
else
{
    echo "Unknown command" . PHP_EOL;
    exit();
}

// Connect to RabbitMQ with the same [dmzServer] settings the DMZ uses,
// send the message and wait for the answer.
$client = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");
$response = $client->send_request($request);

echo "client received response:" . PHP_EOL;
print_r($response);
?>
