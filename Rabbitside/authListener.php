#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

$db = new mysqli('127.0.0.1', 'authuser', 'Auth2026pass', 'authdb');
if ($db->connect_error) {
    die("DB connection failed: " . $db->connect_error . PHP_EOL);
}

function doRegister($username, $password)
{
    global $db;
    $username = trim($username);
    if ($username == '' || strlen($password) < 6) {
        return array("returnCode" => '0', "message" => "Username required, password min 6 characters");
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $st = $db->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $st->bind_param('ss', $username, $hash);
    if (!@$st->execute()) {
        return array("returnCode" => '0', "message" => "Username already taken");
    }
    return array("returnCode" => '1', "message" => "Registered");
}

function doLogin($username, $password)
{
    global $db;
    $username = trim($username);
    $st = $db->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $st->bind_param('s', $username);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return array("returnCode" => '0', "message" => "Invalid username or password");
    }
    $key = bin2hex(random_bytes(32));
    $st = $db->prepare("INSERT INTO sessions (user_id, session_key, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $st->bind_param('is', $row['id'], $key);
    $st->execute();
    return array("returnCode" => '1', "message" => "Login ok", "sessionId" => $key, "username" => $username);
}

function doValidate($sessionId)
{
    global $db;
    $st = $db->prepare("SELECT u.username FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.session_key = ? AND s.expires_at > NOW()");
    $st->bind_param('s', $sessionId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) {
        return array("returnCode" => '0', "message" => "Invalid or expired session");
    }
    return array("returnCode" => '1', "message" => "Session valid", "username" => $row['username']);
}

function requestProcessor($request)
{
    echo "received request: " . (isset($request['type']) ? $request['type'] : 'none') . PHP_EOL;
    if (!isset($request['type'])) {
        return array("returnCode" => '0', "message" => "ERROR: unsupported message type");
    }
    switch ($request['type']) {
        case "register":
            return doRegister($request['username'], $request['password']);
        case "login":
            return doLogin($request['username'], $request['password']);
        case "validate_session":
            return doValidate($request['sessionId']);
    }
    return array("returnCode" => '0', "message" => "Unknown request type");
}

$server = new rabbitMQServer("authRabbitMQ.ini", "authServer");

echo "authListener BEGIN" . PHP_EOL;
$server->process_requests('requestProcessor');
echo "authListener END" . PHP_EOL;
exit();
?>
