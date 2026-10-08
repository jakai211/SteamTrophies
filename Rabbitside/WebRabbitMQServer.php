#!/usr/bin/env php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function requestProcessor($request) {
    if (!isset($request['type'])) {
        return array("success" => false);
    }

    echo "Received: " . $request['type'] . PHP_EOL;

    switch ($request['type']) {
        case "login":
        case "register":
        case "validate_session":
        case "logout":
            $client = new rabbitMQClient "databaseRabbitMQ.ini", "databaseServer");

            echo "Waiting for database reply..." . PHP_EOL;
            $response = $client->send_request($request);
            echo "Database replied." . PHP_EOL;

            return $response;
    }

    return array("success" => false);
}

$server = new rabbitMQServer("WebRabbitMQ.ini", "testServer");
echo "Web request server running..." . PHP_EOL;
$server->process_requests('requestProcessor');
