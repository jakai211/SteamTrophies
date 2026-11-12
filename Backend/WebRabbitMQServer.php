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
        case "link_steam":
        case "unlink_steam":
        case "add_wishlist":
        case "remove_wishlist":
        case "set_wishlist_notify":
        case "get_wishlist":
        case "get_steam_profile":
        case "get_steam_library":
        case "get_steam_achievements":
        case "save_steam_profile":
        case "save_steam_library":
        case "save_steam_recent":
        case "save_steam_achievements":
        case "browse_games":
        case "save_review":
        case "delete_review":
        case "get_reviews":
        case "logout":
            $client = new rabbitMQClient ("databaseRabbitMQ.ini", "databaseServer");

            echo "Waiting for database reply..." . PHP_EOL;
            $response = $client->send_request($request);

	    echo "Success: " . json_encode($response['success'] ?? null) . PHP_EOL;
	    echo "Message: " . ($response['message'] ?? "No message") . PHP_EOL;

            return $response;
    }

    return array("success" => false);
}

$server = new rabbitMQServer("WebRabbitMQ.ini", "testServer");
echo "Web request server running..." . PHP_EOL;
$server->process_requests('requestProcessor');
