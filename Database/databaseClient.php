#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$client = new rabbitMQClient("databaseRabbitMQ.ini","databaseServer");

if (!isset($argv[1]))
{
  echo "Usage:".PHP_EOL;
  echo "  php databaseClient.php register <username> <password> <first> <last> <email>".PHP_EOL;
  echo "  php databaseClient.php login <username> <password>".PHP_EOL;
  echo "  php databaseClient.php validate_session <token>".PHP_EOL;
  echo "  php databaseClient.php logout <token>".PHP_EOL;
  echo "  php databaseClient.php link_steam <token> <17-digit steamId>".PHP_EOL;
  echo "  php databaseClient.php unlink_steam <token>".PHP_EOL;
  exit(1);
}

$request = array();
$request['type'] = $argv[1];

if ($argv[1] === 'validate_session' || $argv[1] === 'logout' || $argv[1] === 'unlink_steam')
{
  $request['sessionId'] = $argv[2] ?? '';
}
else if ($argv[1] === 'link_steam')
{
  $request['sessionId'] = $argv[2] ?? '';
  $request['steamId'] = $argv[3] ?? '';
}
else
{
  $request['username'] = $argv[2] ?? '';
  $request['password'] = $argv[3] ?? '';
  $request['first_name'] = $argv[4] ?? '';
  $request['last_name'] = $argv[5] ?? '';
  $request['email'] = $argv[6] ?? '';
}

$response = $client->send_request($request);

echo "client received response: ".PHP_EOL;
print_r($response);
echo "\n\n";

echo $argv[0]." END".PHP_EOL;
?>