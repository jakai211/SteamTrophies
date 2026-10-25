<?php
chdir(__DIR__);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

header("Content-Type: application/json");
header("Cache-Control: no-store");

function sendResult($result, $status = 200)
{
    http_response_code($status);
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
function successful($response)
{
    return is_array($response) &&
        ($response['returnCode'] ?? '0') === '1';
}
function failResult($message, $status = 502)
{
    sendResult(array("returnCode" => '0', "message" => $message), $status);
}

if ($_SERVER['REQUEST_METHOD'] !== "POST")
{
    failResult("Submit the Steam test form.", 405);
}
$action = $_POST['action'] ?? "profile";
$user = $_POST['user'] ?? "";
if (!is_string($action) ||
    !in_array($action, array("profile", "recent", "achievements"), true))
{
    failResult("Unsupported action.", 400);
}
if (!is_string($user) || trim($user) === "" || strlen($user) > 100)
{
    failResult("Enter a SteamID or custom profile name.", 400);
}
$user = trim($user);

try
{
    $client = new rabbitMQClient("dmzRabbitMQ.ini", "dmzServer");

    switch ($action)
    {
        case "profile":
            $profile = $client->send_request(array(
                "type" => "get_profile",
                "user" => $user
            ));
            if (!successful($profile) ||
                !isset($profile['steamId'], $profile['name'], $profile['avatar']))
            {
                failResult("Could not load the Steam profile.");
            }

            $library = $client->send_request(array(
                "type" => "get_owned_games",
                "user" => $profile['steamId']
            ));
            $games = array();
            $message = "";
            if (successful($library) && isset($library['games']) &&
                is_array($library['games']))
            {
                $games = $library['games'];
            }
            else
            {
                $message = "Profile loaded, but the library is unavailable. Check Steam privacy.";
            }
            sendResult(array(
                "returnCode" => '1',
                "profile" => $profile,
                "games" => $games,
                "message" => $message
            ));
            break;

        case "recent":
            $recent = $client->send_request(array(
                "type" => "get_recent_playtime",
                "user" => $user
            ));
            if (!successful($recent) || !isset($recent['games']) ||
                !is_array($recent['games']))
            {
                failResult("Recent playtime unavailable. Check Steam privacy.");
            }
            sendResult(array(
                "returnCode" => '1',
                "games" => $recent['games']
            ));
            break;

        case "achievements":
            $appId = filter_var($_POST['appId'] ?? null, FILTER_VALIDATE_INT);
            if ($appId === false || $appId === null || $appId <= 0)
            {
                failResult("Invalid appId.", 400);
            }

            $player = $client->send_request(array(
                "type" => "get_achievements",
                "user" => $user,
                "appId" => $appId
            ));
            if (!successful($player) || !isset($player['achievements']) ||
                !is_array($player['achievements']))
            {
                failResult("Achievements unavailable. The game may have none, or player data may be private.");
            }

            $definitions = $client->send_request(array(
                "type" => "get_achievement_definitions",
                "appId" => $appId
            ));
            $lookup = array();
            $warning = "";
            if (successful($definitions) && isset($definitions['achievements']) &&
                is_array($definitions['achievements']))
            {
                foreach ($definitions['achievements'] as $item)
                {
                    $lookup[$item['id']] = $item;
                }
            }
            else
            {
                $warning = "Achievement icons/definitions unavailable";
            }

            $achievements = array();
            $unlocked = 0;
            foreach ($player['achievements'] as $item)
            {
                $definition = $lookup[$item['id']] ?? array();
                $isUnlocked = !empty($item['unlocked']);
                $hidden = !empty($definition['hidden']) && !$isUnlocked;

                $achievements[] = array(
                    "id" => $item['id'],
                    "name" => $hidden ? "Hidden achievement" :
                        ($definition['name'] ?? $item['name'] ?? $item['id']),
                    "description" => $hidden ? "Unlock to reveal." :
                        ($definition['description'] ?? $item['description'] ?? ""),
                    "icon" => $hidden ? "" : ($definition['icon'] ?? ""),
                    "iconLocked" => $hidden ? "" : ($definition['iconLocked'] ?? ""),
                    "unlocked" => $isUnlocked,
                    "unlockTime" => $item['unlockTime'] ?? 0,
                    "globalPercent" => $item['globalPercent'] ?? null
                );
                if ($isUnlocked) $unlocked++;
            }

            sendResult(array(
                "returnCode" => '1',
                "total" => count($achievements),
                "unlocked" => $unlocked,
                "achievements" => $achievements,
                "warning" => $warning
            ));
            break;
    }
}
catch (Throwable $error)
{
    error_log("Steam test request failed: " . get_class($error));
    failResult("Could not complete the Steam request.");
}
