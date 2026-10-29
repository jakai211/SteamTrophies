<?php

// Executes a prepared statement and reports database failures.
function catalogSQL($db, $sql, $types = "", $values = array())
{
    $stmt = $db->prepare($sql);
    if (!$stmt)
    {
        throw new Exception("Catalog SQL prepare failed");
    }

    if ($types !== "")
    {
        if (!$stmt->bind_param($types, ...$values))
        {
            throw new Exception("Catalog SQL binding failed");
        }
    }

    if (!$stmt->execute())
    {
        throw new Exception("Catalog SQL execution failed");
    }
    return $stmt;
}

function catalogAppId($value)
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0 || $id > 4294967295)
    {
        throw new Exception("Invalid appId");
    }
    return $id;
}

// Records attempts and preserves the previous successful timestamp on failure.
function catalogState($db, $type, $scope, $status, $error = null,
                      $lastAppId = null, $hasMore = null)
{
    $now = time();
    $successAt = ($status === "complete") ? $now : null;
    catalogSQL($db,
        "INSERT INTO steam_sync_state
         (request_type, scope_key, status, last_attempt_at,
          last_success_at, last_error, last_app_id, has_more)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         status = VALUES(status),
         last_attempt_at = VALUES(last_attempt_at),
         last_success_at = COALESCE(VALUES(last_success_at), last_success_at),
         last_error = VALUES(last_error),
         last_app_id = VALUES(last_app_id),
         has_more = VALUES(has_more)",
        "sssiisii",
        array($type, $scope, $status, $now, $successAt,
              $error, $lastAppId, $hasMore)
    )->close();
}

// Background operations only; no website session is used here.
function handleGlobalCatalog($request)
{
    $db = connectDB();
    if (!$db->set_charset("utf8mb4"))
    {
        throw new Exception("Could not set character encoding");
    }

    $transaction = false;
    try
    {
        switch ($request['type'])
        {
            case "save_game_catalog":
                $games = $request['games'] ?? null;
                $last = filter_var($request['lastAppId'] ?? null, FILTER_VALIDATE_INT);
                $more = $request['hasMore'] ?? null;

                if (!is_array($games) || count($games) > 1000 ||
                    $last === false || $last === null || $last < 0 ||
                    !is_bool($more))
                {
                    throw new Exception("Invalid catalog batch");
                }

                if (!$db->begin_transaction())
                {
                    throw new Exception("Could not start transaction");
                }
                $transaction = true;
                $now = time();

                foreach ($games as $game)
                {
                    if (!is_array($game) || !isset($game['name']) ||
                        !is_string($game['name']))
                    {
                        throw new Exception("Invalid game data");
                    }
                    $appId = catalogAppId($game['appId'] ?? null);

                    // Existing icons and achievement information stay unchanged.
                    catalogSQL($db,
                        "INSERT INTO games (app_id, name, catalog_updated_at)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                         name = VALUES(name),
                         catalog_updated_at = VALUES(catalog_updated_at)",
                        "isi", array($appId, $game['name'], $now)
                    )->close();
                }

                catalogState($db, "global_catalog", "all",
                    $more ? "running" : "complete",
                    null, $last, $more ? 1 : 0);
                break;

            case "get_global_games_due":
                // Retry incomplete games after a day.
                // Successful collections become due again after seven days.
                $dayAgo = time() - 86400;
                $weekAgo = time() - 7 * 86400;

                $stmt = catalogSQL($db,
                    "SELECT g.app_id
                     FROM games g
                     LEFT JOIN steam_sync_state s
                       ON s.request_type = 'global_achievements'
                       AND s.scope_key = CAST(g.app_id AS CHAR)
                     WHERE g.catalog_updated_at IS NOT NULL
                       AND (s.last_attempt_at IS NULL OR s.last_attempt_at <= ?)
                       AND (s.last_success_at IS NULL OR s.last_success_at <= ?
                            OR s.status <> 'complete')
                     ORDER BY COALESCE(s.last_attempt_at, 0), g.app_id
                     LIMIT 500",
                    "ii", array($dayAgo, $weekAgo)
                );
                $rows = $stmt->get_result();
                $games = array();
                while ($row = $rows->fetch_assoc())
                {
                    $games[] = array("appId" => (int)$row['app_id']);
                }
                $stmt->close();
                return array("success" => true, "games" => $games);

            case "mark_global_game_started":
                $appId = catalogAppId($request['appId'] ?? null);
                catalogState($db, "global_achievements",
                    (string)$appId, "running");
                break;

            case "mark_global_game_failed":
                $appId = catalogAppId($request['appId'] ?? null);
                $message = $request['message'] ?? "Collection failed";
                if (!is_string($message))
                {
                    $message = "Collection failed";
                }
                catalogState($db, "global_achievements",
                    (string)$appId, "failed", substr($message, 0, 500));
                break;

            case "save_global_game_data":
                $data = $request['data'] ?? null;
                if (!is_array($data) ||
                    !isset($data['achievements'], $data['rarityAvailable']) ||
                    !is_array($data['achievements']) ||
                    !is_bool($data['rarityAvailable']))
                {
                    throw new Exception("Invalid achievement batch");
                }
                $appId = catalogAppId($data['appId'] ?? null);

                if (!$db->begin_transaction())
                {
                    throw new Exception("Could not start transaction");
                }
                $transaction = true;
                $now = time();

                // Catalog import must create the game before its achievements.
                $stmt = catalogSQL($db,
                    "SELECT app_id FROM games WHERE app_id = ?",
                    "i", array($appId)
                );
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exists)
                {
                    throw new Exception("Game is missing from catalog");
                }

                foreach ($data['achievements'] as $item)
                {
                    if (!is_array($item) ||
                        !isset($item['id'], $item['name']) ||
                        !is_string($item['id']) || $item['id'] === "" ||
                        !is_string($item['name']))
                    {
                        throw new Exception("Invalid achievement");
                    }
                    $description = $item['description'] ?? "";
                    $icon = $item['icon'] ?? "";
                    $lockedIcon = $item['iconLocked'] ?? "";
                    if (!is_string($description) || !is_string($icon) ||
                        !is_string($lockedIcon))
                    {
                        throw new Exception("Invalid achievement text");
                    }
                    $hidden = !empty($item['hidden']) ? 1 : 0;

                    catalogSQL($db,
                        "INSERT INTO achievement_definitions
                         (app_id, achievement_id, name, description,
                          icon_url, icon_locked_url, hidden, definitions_updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                         name = VALUES(name),
                         description = VALUES(description),
                         icon_url = VALUES(icon_url),
                         icon_locked_url = VALUES(icon_locked_url),
                         hidden = VALUES(hidden),
                         definitions_updated_at = VALUES(definitions_updated_at)",
                        "isssssii",
                        array($appId, $item['id'], $item['name'], $description,
                              $icon, $lockedIcon, $hidden, $now)
                    )->close();

                    // Missing rarity never overwrites an existing percentage.
                    if (isset($item['globalPercent']))
                    {
                        $percent = $item['globalPercent'];
                        if (!is_numeric($percent) || $percent < 0 || $percent > 100)
                        {
                            throw new Exception("Invalid global percentage");
                        }
                        catalogSQL($db,
                            "UPDATE achievement_definitions
                             SET global_percent = ?, global_percent_updated_at = ?
                             WHERE app_id = ? AND achievement_id = ?",
                            "diis",
                            array((float)$percent, $now, $appId, $item['id'])
                        )->close();
                    }
                }

                // No deletion: an empty response must not erase saved definitions.
                catalogSQL($db,
                    "UPDATE games SET achievement_definitions_updated_at = ?
                     WHERE app_id = ?",
                    "ii", array($now, $appId)
                )->close();

                catalogState($db, "global_achievements", (string)$appId,
                    $data['rarityAvailable'] ? "complete" : "partial",
                    $data['rarityAvailable'] ? null : "Some rarity data unavailable");
                break;

            default:
                throw new Exception("Unknown catalog operation");
        }

        if ($transaction)
        {
            if (!$db->commit())
            {
                throw new Exception("Could not commit catalog save");
            }
            $transaction = false;
        }
        return array("success" => true, "message" => "Catalog operation completed");
    }
    catch (Throwable $error)
    {
        if ($transaction) $db->rollback();
        throw $error;
    }
    finally
    {
        $db->close();
    }
}