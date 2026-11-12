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
  echo "  php databaseClient.php save_steam_profile <token> <steamId> <name> <avatarUrl> <profileUrl>".PHP_EOL;
  echo "  php databaseClient.php save_steam_library <token> <steamId> <games.json>".PHP_EOL;
  echo "  php databaseClient.php save_steam_recent <token> <steamId> <recent.json>".PHP_EOL;
  echo "  php databaseClient.php save_steam_achievements <token> <steamId> <appId> <definitions.json> <player.json>".PHP_EOL;
  echo "  php databaseClient.php get_steam_profile <token>".PHP_EOL;
  echo "  php databaseClient.php get_steam_library <token>".PHP_EOL;
  echo "  php databaseClient.php get_steam_achievements <token> <appId>".PHP_EOL;
  exit(1);
}

$request = array();
$request['type'] = $argv[1];

if ($argv[1] === 'validate_session' || $argv[1] === 'logout' || $argv[1] === 'unlink_steam' || $argv[1] === 'get_steam_profile' || $argv[1] === 'get_steam_library')  
{
  $request['sessionId'] = $argv[2] ?? '';
}
else if ($argv[1] === 'link_steam')
{
  $request['sessionId'] = $argv[2] ?? '';
  $request['steamId'] = $argv[3] ?? '';
}
else if ($argv[1] === 'save_steam_profile')
{
  $request['sessionId'] = $argv[2] ?? '';
  $request['profile'] = array(
    "steamId" => $argv[3] ?? '',
    "name" => $argv[4] ?? '',
    "avatar" => $argv[5] ?? '',
    "profileUrl" => $argv[6] ?? ''
  );
}
else if ($argv[1] === 'save_steam_library')
{
  $file = $argv[4] ?? '';
  $games = is_file($file) ? json_decode(file_get_contents($file), true) : array();
  $request['sessionId'] = $argv[2] ?? '';
  $request['library'] = array(
    "steamId" => $argv[3] ?? '',
    "games" => is_array($games) ? $games : array()
  );
}
else if ($argv[1] === 'save_steam_recent')
{
  $file = $argv[4] ?? '';
  $games = is_file($file) ? json_decode(file_get_contents($file), true) : array();
  $request['sessionId'] = $argv[2] ?? '';
  $request['recent'] = array(
    "steamId" => $argv[3] ?? '',
    "games" => is_array($games) ? $games : array()
  );
}
else if ($argv[1] === 'save_steam_achievements')
{
  $definitionsFile = $argv[5] ?? '';
  $playerFile = $argv[6] ?? '';
  $definitions = is_file($definitionsFile) ? json_decode(file_get_contents($definitionsFile), true) : array();
  $player = is_file($playerFile) ? json_decode(file_get_contents($playerFile), true) : array();
  $request['sessionId'] = $argv[2] ?? '';
  $request['achievements'] = array(
    "steamId" => $argv[3] ?? '',
    "appId" => $argv[4] ?? 0,
    "definitions" => is_array($definitions) ? $definitions : array(),
    "player" => is_array($player) ? $player : array()
  );
}
else if ($argv[1] === 'get_steam_achievements')
{
  $request['sessionId'] = $argv[2] ?? '';
  $request['appId'] = $argv[3] ?? 0;
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