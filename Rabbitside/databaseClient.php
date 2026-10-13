#!/usr/bin/php 
<?php
// utilized the base testRabbitMQ code for this
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$client = new rabbitMQClient("databaseRabbitMQ.ini","databaseServer");

// $request = array();
// $request['type'] = $argv[1];
// $request['username'] = "steve";
// $request['password'] = "password";

if (isset($argv[2]))
{
  $request['sessionId'] = $argv[2];
}

$response = $client->send_request($request);

echo "client received response: ".PHP_EOL;
print_r($response);
echo "\n\n";

echo $argv[0]." END".PHP_EOL;
?>