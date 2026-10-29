#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('databaseConnection.php');  // gives us connectDB() that will be used for most of the functions
require_once('globalCatalog.php');


function doLogin($username,$password)
{ 
    $username = trim($username); //trim to remove white spaces - good practice I found online

    $mydb = connectDB();   // needs to connect to database for what I've changed
    
    // lookup username in database

    $stmt = $mydb->prepare("SELECT id, password_hash FROM users WHERE username = ?");     // starting the SQL command and ? is used to prevent potential SQL injections
    $stmt->bind_param("s", $username);     // using the real $username var into that "?" placeholder as a string ("s").
    $stmt->execute();     // Run it
    $user = $stmt->get_result()->fetch_assoc(); // Grab the result from db and turn the user's data into a list.

    // check password - also included the username just to make sure
    if ($user && password_verify($password, $user['password_hash'])) //both have to be correct
    {
        return true; //return true
    }

    //return false if not valid
    return false;
}

function makeSession($username) 
{
    $username = trim($username); //same trim as doLogin so it actually matches - fix
    $mydb = connectDB(); //connect

    // delete expired sessions first (epoch seconds)
    $now = time();
    $stmt = $mydb->prepare("DELETE FROM sessions WHERE expires_at <= ?");
    $stmt->bind_param("i", $now);
    $stmt->execute();

    // get the user's id
    $stmt = $mydb->prepare("SELECT id FROM users WHERE username = ?"); //similar to before
    $stmt->bind_param("s", $username); //similar to before
    $stmt->execute(); //similar to before
    $user = $stmt->get_result()->fetch_assoc();

    // remove this user's previous sessions so only one token is valid at a time
    $stmt = $mydb->prepare("DELETE FROM sessions WHERE user_id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();

    // make a token that lasts one hour and save it(researched online for how to do this)
    $token = bin2hex(random_bytes(32));
    $expires = time() + 3600;

    $stmt = $mydb->prepare("INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)"); // this will help ready a new SQL command to save the session details into the database.

    $stmt->bind_param("sii", $token, $user['id'], $expires);    
    $stmt->execute();//run and save to database

    return $token;//return the token so the rest of it can be used
}

// need to create a register function because we have one :/
function doRegister($firstName,$lastName,$email,$username,$password) //replaced to add first and last names and email that we did not do for auth
{ //email is needed for what we are working on for email notifications
    $mydb = connectDB(); //connect again

    // need to use trim in case they add white spaces - good practice on validation I found online
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $email = trim($email);
    $username = trim($username);

    // nothing can be blank - good practice
    if ($firstName === '' || $lastName === '' || $username === '' || $password === '')
    {
        return "All fields are required";
    }

    // validation checks to make sure the email is correct format and isn't something random
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    {
        return "Invalid email";
    }

    // basic check to make sure that the username they create isn't already taken
    $stmt = $mydb->prepare("SELECT id FROM users WHERE username = ?"); 
    $stmt->bind_param("s", $username); //binding the username
    $stmt->execute(); //running it
    if ($stmt->get_result()->num_rows > 0) // checks whether the name matches with another user within the database
    {
       return "Username already taken"; //changed false to return something that will help explain error
    }

    // used the same code for username for the email
    $stmt = $mydb->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0)
    {
        return "Email already in use"; // using this instead of false to help prevent errors
    }

    // was created from what I saw online for hashed passwords
    $hash = password_hash($password, PASSWORD_DEFAULT); 
    $stmt = $mydb->prepare("INSERT INTO users (first_name, last_name, email, username, password_hash) VALUES (?, ?, ?, ?, ?)"); //updated
    $stmt->bind_param("sssss", $firstName, $lastName, $email, $username, $hash);
    $stmt->execute();

    return true; //returns true
}

function doValidate($sessionId) // creating the missing validate function from the file
{
    $mydb = connectDB(); //connect

    $now = time();
    $stmt = $mydb->prepare(  //changed to match Jakai's validateToken
      "SELECT users.username, users.first_name, users.last_name
       FROM sessions
       JOIN users ON users.id = sessions.user_id
       WHERE sessions.token = ? AND sessions.expires_at > ?"
    ); // getting the user from the db
    $stmt->bind_param("si", $sessionId, $now);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    if ($row)
    {
        return $row; //reutns username, first, and last name
    }
    return false;
}

function doLogout($sessionId)
{
    $mydb = connectDB(); //connect

    $stmt = $mydb->prepare("DELETE FROM sessions WHERE token = ?"); //removes then from database
    $stmt->bind_param("s", $sessionId); //bind
    $stmt->execute(); //execute

    return true;
}

// finds which user a token belongs to (false if the token is fake or expired)
// the user id always comes from the token, never from the request
function getUserIdFromSession($sessionId)
{
    $mydb = connectDB(); //connect

    $now = time();
    $stmt = $mydb->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > ?");
    $stmt->bind_param("si", $sessionId, $now);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    if ($row)
    {
        return $row['user_id'];
    }
    return false;
}

// ties a steam account to the logged in user
// returns true if it worked, or a message (string) saying why it didn't
function doLinkSteam($sessionId,$steamId)
{
    $mydb = connectDB(); //connect

    // steam ids are 17 digits 
    if (!preg_match('/^[0-9]{17}$/', $steamId))
    {
        return "Invalid Steam ID";
    }

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    // make sure another user doesn't already have this steam account
    $stmt = $mydb->prepare("SELECT user_id FROM user_steam_links WHERE steam_id = ? AND user_id <> ?");
    $stmt->bind_param("si", $steamId, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0)
    {
        return "Steam account already linked to another user";
    }

    // the profile row has to exist first (foreign key), the name and avatar get filled in later
    $stmt = $mydb->prepare("INSERT IGNORE INTO steam_profiles (steam_id) VALUES (?)");
    $stmt->bind_param("s", $steamId);
    $stmt->execute();

    // save the link, if they already had one it gets replaced (epoch time)
    $now = time();
    $stmt = $mydb->prepare("INSERT INTO user_steam_links (user_id, steam_id, verified_at) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE steam_id = VALUES(steam_id), verified_at = VALUES(verified_at)");
    $stmt->bind_param("isi", $userId, $steamId, $now);
    $stmt->execute();

    return true;
}

// saves a steam profile reply from the DMZ (steamId, name, avatar, profileUrl)
// returns true if it worked, or a message (string) saying why it didn't
function saveSteamProfile($sessionId,$profile)
{
    $mydb = connectDB(); //connect

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    if (!is_array($profile))
    {
        return "Invalid profile data";
    }

    $steamId = $profile['steamId'] ?? '';
    if (!preg_match('/^[0-9]{17}$/', $steamId))
    {
        return "Invalid Steam ID";
    }

    // only save for the steam account linked to this user, never trust the id in the request
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link || $link['steam_id'] !== $steamId)
    {
        return "Steam account not linked to this user";
    }

    $name = $profile['name'] ?? '';
    $avatar = $profile['avatar'] ?? '';
    $profileUrl = $profile['profileUrl'] ?? '';
    $now = time(); //epoch

    // insert, or update the row if it already exists (link_steam made a stub row)
    $stmt = $mydb->prepare("INSERT INTO steam_profiles (steam_id, display_name, avatar_url, profile_url, profile_updated_at)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), avatar_url = VALUES(avatar_url),
                            profile_url = VALUES(profile_url), profile_updated_at = VALUES(profile_updated_at)");
    $stmt->bind_param("ssssi", $steamId, $name, $avatar, $profileUrl, $now);
    $stmt->execute();

    return true;
}

// saves a get_owned_games reply from the DMZ (steamId + games list) into games and player_games
// returns true if it worked, or a message (string) saying why it didn't
function saveSteamLibrary($sessionId,$library)
{
    $mydb = connectDB(); //connect
    $mydb->set_charset("utf8mb4"); //so names like DARK SOULS™ save correctly, found how to do this online

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    $steamId = $library['steamId'] ?? '';
    $games = $library['games'] ?? array();

    if (!preg_match('/^[0-9]{17}$/', $steamId))
    {
        return "Invalid Steam ID";
    }

    // an empty list would look like "they own nothing", so don't save it
    if (!is_array($games) || count($games) === 0)
    {
        return "No games to save";
    }

    // only save for the steam account linked to this user
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link || $link['steam_id'] !== $steamId)
    {
        return "Steam account not linked to this user";
    }

    $now = time(); //epoch

    foreach ($games as $game)
    {
        $appId = (int)($game['appId'] ?? 0);
        if ($appId <= 0)
        {
            continue; //skip anything without a real app id
        }
        $name = $game['name'] ?? '';
        $icon = $game['icon'] ?? '';
        $minutes = (int)($game['minutesPlayed'] ?? 0);

        // the game has to exist first (foreign key)
        $stmt = $mydb->prepare("INSERT INTO games (app_id, name, icon_hash) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), icon_hash = VALUES(icon_hash)");
        $stmt->bind_param("iss", $appId, $name, $icon);
        $stmt->execute();

        // then this player's row for that game
        $stmt = $mydb->prepare("INSERT INTO player_games (steam_id, app_id, in_owned_library, minutes_played, library_updated_at, playtime_updated_at)
                                VALUES (?, ?, 1, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE in_owned_library = 1, minutes_played = VALUES(minutes_played),
                                library_updated_at = VALUES(library_updated_at), playtime_updated_at = VALUES(playtime_updated_at)");
        $stmt->bind_param("siiii", $steamId, $appId, $minutes, $now, $now);
        $stmt->execute();
    }

    return true;
}

// saves a get_recent_playtime reply from the DMZ (steamId + games list) into player_games
// returns true if it worked, or a message (string) saying why it didn't
function saveSteamRecent($sessionId,$recent)
{
    $mydb = connectDB(); //connect
    $mydb->set_charset("utf8mb4"); //so game names save correctly

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    if (!is_array($recent))
    {
        return "Invalid recent playtime data";
    }

    $steamId = $recent['steamId'] ?? '';
    $games = $recent['games'] ?? array();

    if (!preg_match('/^[0-9]{17}$/', $steamId))
    {
        return "Invalid Steam ID";
    }

    if (!is_array($games))
    {
        return "Invalid games list";
    }

    // only save for the steam account linked to this user
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link || $link['steam_id'] !== $steamId)
    {
        return "Steam account not linked to this user";
    }

    $now = time(); //epoch

    // anything not in this list wasn't played in the last 2 weeks, so set it to 0 first
    // (an empty list is allowed here, it just means they didn't play anything)
    $stmt = $mydb->prepare("UPDATE player_games SET minutes_last_two_weeks = 0, recent_playtime_updated_at = ? WHERE steam_id = ?");
    $stmt->bind_param("is", $now, $steamId);
    $stmt->execute();

    foreach ($games as $game)
    {
        $appId = (int)($game['appId'] ?? 0);
        if ($appId <= 0)
        {
            continue; //skip anything without a real app id
        }
        $name = $game['name'] ?? '';
        $minutes = (int)($game['minutesLastTwoWeeks'] ?? 0);

        // the game has to exist first (foreign key), if it's already there leave it alone
        $stmt = $mydb->prepare("INSERT IGNORE INTO games (app_id, name) VALUES (?, ?)");
        $stmt->bind_param("is", $appId, $name);
        $stmt->execute();

        // then the recent minutes, this does NOT mark the game as owned
        $stmt = $mydb->prepare("INSERT INTO player_games (steam_id, app_id, minutes_last_two_weeks, recent_playtime_updated_at)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE minutes_last_two_weeks = VALUES(minutes_last_two_weeks),
                                recent_playtime_updated_at = VALUES(recent_playtime_updated_at)");
        $stmt->bind_param("siii", $steamId, $appId, $minutes, $now);
        $stmt->execute();
    }

    return true;
}

// saves the achievements for one game from the DMZ: the game's definitions plus this player's progress
// returns true if it worked, or a message (string) saying why it didn't
function saveSteamAchievements($sessionId,$data)
{
    $mydb = connectDB(); //connect
    $mydb->set_charset("utf8mb4"); //so names and descriptions save correctly

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    if (!is_array($data))
    {
        return "Invalid achievement data";
    }

    $steamId = $data['steamId'] ?? '';
    $appId = (int)($data['appId'] ?? 0);
    $definitions = $data['definitions'] ?? array(); //raw list from get_achievement_definitions
    $player = $data['player'] ?? array(); //raw list from get_achievements

    if (!preg_match('/^[0-9]{17}$/', $steamId))
    {
        return "Invalid Steam ID";
    }

    if ($appId <= 0)
    {
        return "Invalid app id";
    }

    if (!is_array($definitions) || !is_array($player) || count($definitions) + count($player) === 0)
    {
        return "No achievements to save";
    }

    // only save for the steam account linked to this user
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link || $link['steam_id'] !== $steamId)
    {
        return "Steam account not linked to this user";
    }

    $now = time(); //epoch

    // the game has to exist first (foreign key), if it's already there leave it alone
    $stmt = $mydb->prepare("INSERT IGNORE INTO games (app_id, name) VALUES (?, '')");
    $stmt->bind_param("i", $appId);
    $stmt->execute();

    // the game's achievements first (the player's rows point at these)
    foreach ($definitions as $item)
    {
        $id = $item['id'] ?? '';
        if ($id === '')
        {
            continue; //skip anything without an id
        }
        $name = $item['name'] ?? $id;
        $description = $item['description'] ?? '';
        $icon = $item['icon'] ?? '';
        $iconLocked = $item['iconLocked'] ?? '';
        $hidden = !empty($item['hidden']) ? 1 : 0;

        $stmt = $mydb->prepare("INSERT INTO achievement_definitions (app_id, achievement_id, name, description, icon_url, icon_locked_url, hidden, definitions_updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), icon_url = VALUES(icon_url),
                                icon_locked_url = VALUES(icon_locked_url), hidden = VALUES(hidden), definitions_updated_at = VALUES(definitions_updated_at)");
        $stmt->bind_param("isssssii", $appId, $id, $name, $description, $icon, $iconLocked, $hidden, $now);
        $stmt->execute();
    }

    // then this player's progress
    foreach ($player as $item)
    {
        $id = $item['id'] ?? '';
        if ($id === '')
        {
            continue; //skip anything without an id
        }
        $name = $item['name'] ?? $id;
        $description = $item['description'] ?? '';
        $unlocked = !empty($item['unlocked']) ? 1 : 0;
        $unlockTime = (int)($item['unlockTime'] ?? 0);
        $unlockedAt = ($unlockTime > 0) ? $unlockTime : null; //steam sends 0 for locked, save NULL instead
        $percent = isset($item['globalPercent']) ? (float)$item['globalPercent'] : null; //missing stays NULL

        // the global percent comes with the player's reply, so it goes on the achievement
        // (this also makes sure the achievement row exists for the foreign key)
        $stmt = $mydb->prepare("INSERT INTO achievement_definitions (app_id, achievement_id, name, description, global_percent, global_percent_updated_at)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE global_percent = VALUES(global_percent), global_percent_updated_at = VALUES(global_percent_updated_at)");
        $stmt->bind_param("isssdi", $appId, $id, $name, $description, $percent, $now);
        $stmt->execute();

        $stmt = $mydb->prepare("INSERT INTO player_achievements (steam_id, app_id, achievement_id, unlocked, unlocked_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE unlocked = VALUES(unlocked), unlocked_at = VALUES(unlocked_at), updated_at = VALUES(updated_at)");
        $stmt->bind_param("sisiii", $steamId, $appId, $id, $unlocked, $unlockedAt, $now);
        $stmt->execute();
    }

    return true;
}

// gets the saved steam profile for the logged in user
// returns an array (steamId, name, avatar, profileUrl, updatedAt) if it worked, or a message (string) saying why it didn't
function getSteamProfile($sessionId)
{
    $mydb = connectDB(); //connect

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    // the steam id comes from the user's link, never from the request
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link)
    {
        return "Steam account not linked to this user";
    }

    $stmt = $mydb->prepare("SELECT steam_id, display_name, avatar_url, profile_url, profile_updated_at FROM steam_profiles WHERE steam_id = ?");
    $stmt->bind_param("s", $link['steam_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row)
    {
        return "No saved profile yet";
    }

    return array(
        "steamId" => $row['steam_id'],
        "name" => $row['display_name'] ?? '',
        "avatar" => $row['avatar_url'] ?? '',
        "profileUrl" => $row['profile_url'] ?? '',
        "updatedAt" => $row['profile_updated_at'] //epoch, NULL if it was never saved
    );
}

// gets the saved games (owned only) for the logged in user
// returns an array of games if it worked, or a message (string) saying why it didn't
function getSteamLibrary($sessionId)
{
    $mydb = connectDB(); //connect
    $mydb->set_charset("utf8mb4"); //so names like DARK SOULS™ come back correctly

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    // the steam id comes from the user's link, never from the request
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link)
    {
        return "Steam account not linked to this user";
    }

    // only games they own, most played first
    $stmt = $mydb->prepare("SELECT g.app_id, g.name, g.icon_hash, pg.minutes_played, pg.minutes_last_two_weeks
                            FROM player_games pg
                            JOIN games g ON g.app_id = pg.app_id
                            WHERE pg.steam_id = ? AND pg.in_owned_library = 1
                            ORDER BY pg.minutes_played DESC");
    $stmt->bind_param("s", $link['steam_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $games = array();
    while ($row = $result->fetch_assoc())
    {
        $minutes = (int)($row['minutes_played'] ?? 0);
        $games[] = array(
            "appId" => (int)$row['app_id'],
            "name" => $row['name'],
            "icon" => $row['icon_hash'] ?? '',
            "minutesPlayed" => $minutes,
            "hoursPlayed" => round($minutes / 60, 1), //steam gives minutes, the page wants hours
            "minutesLastTwoWeeks" => (int)($row['minutes_last_two_weeks'] ?? 0)
        );
    }

    return $games;
}

// gets the saved achievements for one game for the logged in user
// returns an array (total, unlocked, achievements) if it worked, or a message (string) saying why it didn't
function getSteamAchievements($sessionId,$appId)
{
    $mydb = connectDB(); //connect
    $mydb->set_charset("utf8mb4"); //so names and descriptions come back correctly

    // has to be logged in
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    $appId = (int)$appId;
    if ($appId <= 0)
    {
        return "Invalid app id";
    }

    // the steam id comes from the user's link, never from the request
    $stmt = $mydb->prepare("SELECT steam_id FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link)
    {
        return "Steam account not linked to this user";
    }

    // this player's progress joined to the game's achievement info, unlocked ones first
    $stmt = $mydb->prepare("SELECT ad.achievement_id, ad.name, ad.description, ad.icon_url, ad.icon_locked_url, ad.hidden,
                            ad.global_percent, pa.unlocked, pa.unlocked_at
                            FROM player_achievements pa
                            JOIN achievement_definitions ad ON ad.app_id = pa.app_id AND ad.achievement_id = pa.achievement_id
                            WHERE pa.steam_id = ? AND pa.app_id = ?
                            ORDER BY pa.unlocked DESC, ad.name");
    $stmt->bind_param("si", $link['steam_id'], $appId);
    $stmt->execute();
    $result = $stmt->get_result();

    $achievements = array();
    $unlockedCount = 0;
    while ($row = $result->fetch_assoc())
    {
        $isUnlocked = ($row['unlocked'] == 1);
        $hiddenLocked = ($row['hidden'] == 1 && !$isUnlocked); //don't give away hidden achievements that are still locked

        $achievements[] = array(
            "id" => $row['achievement_id'],
            "name" => $hiddenLocked ? "Hidden achievement" : $row['name'],
            "description" => $hiddenLocked ? "Unlock to reveal." : ($row['description'] ?? ''),
            "icon" => $hiddenLocked ? "" : ($row['icon_url'] ?? ''),
            "iconLocked" => $hiddenLocked ? "" : ($row['icon_locked_url'] ?? ''),
            "unlocked" => $isUnlocked,
            "unlockTime" => (int)($row['unlocked_at'] ?? 0), //epoch, 0 if locked
            "globalPercent" => ($row['global_percent'] === null) ? null : (float)$row['global_percent']
        );
        if ($isUnlocked)
        {
            $unlockedCount++;
        }
    }

    if (count($achievements) === 0)
    {
        return "No saved achievements for this game";
    }

    return array(
        "total" => count($achievements),
        "unlocked" => $unlockedCount,
        "achievements" => $achievements
    );
}

// removes the steam link, the website account stays
function doUnlinkSteam($sessionId)
{
    $mydb = connectDB(); //connect

    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return false;
    }

    $stmt = $mydb->prepare("DELETE FROM user_steam_links WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    return true;
}

// adds a game to the user's wishlist
function doAddWishlist($sessionId, $appId)
{
    $mydb = connectDB(); 
    $userId = getUserIdFromSession($sessionId); //finds the user from their session
    if ($userId === false)
    {
        return "session invalid";
    }

    //makes sure the game is in our games table first
    $stmt = $mydb->prepare("SELECT app_id FROM games WHERE app_id = ?");
    $stmt->bind_param("i", $appId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0)
    {
        return "game not found";
    }

    //INSERT IGNORE so adding the same game twice doesn't cause an error
    $stmt = $mydb->prepare("INSERT IGNORE INTO wishlist_items (user_id, app_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $userId, $appId);
    $stmt->execute();

    return true;
}

// removes a game from the user's wishlist
function doRemoveWishlist($sessionId, $appId)
{
    $mydb = connectDB(); 
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    $stmt = $mydb->prepare("DELETE FROM wishlist_items WHERE user_id = ? AND app_id = ?");
    $stmt->bind_param("ii", $userId, $appId);
    $stmt->execute();

    return true;
}

//turns notifications on (1) or off (0) for one game
function doSetWishlistNotify($sessionId, $appId, $notify)
{
    $mydb = connectDB(); 
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return "session invalid";
    }

    $stmt = $mydb->prepare("UPDATE wishlist_items SET notify = ? WHERE user_id = ? AND app_id = ?");
    $stmt->bind_param("iii", $notify, $userId, $appId);
    $stmt->execute();

    return true;
}

// gets all the games on the user's wishlist
function doGetWishlist($sessionId)
{
    $mydb = connectDB(); 
    $mydb->set_charset("utf8mb4"); 
    $userId = getUserIdFromSession($sessionId);
    if ($userId === false)
    {
        return false;
    }

    //join with games so we get the game name too
    $stmt = $mydb->prepare("SELECT wishlist_items.app_id, games.name, wishlist_items.added_at, wishlist_items.notify
                            FROM wishlist_items
                            JOIN games ON games.app_id = wishlist_items.app_id
                            WHERE wishlist_items.user_id = ?
                            ORDER BY wishlist_items.added_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC); 
}

function handleRequest($request)
{
  echo "received request".PHP_EOL;
  //var_dump($request);
  if(!isset($request['type']))
  {
    return array("success" => false, "message" => "ERROR: missing request type"); //updated
  }
  switch ($request['type'])
  {
    case "login":
      if (doLogin($request['username'],$request['password']))
      {
        $token = makeSession($request['username']);
        return array("success" => true, "message" => "login ok", "token" => $token);
      }
      return array("success" => false, "message" => "wrong username or password");
    case "register": //added a case to register
      $result = doRegister( //seperating it instead of putting it all in one if statement now were going to use result to store this
        $request['first_name'] ?? '',
        $request['last_name'] ?? '',
        $request['email'] ?? '',
        $request['username'] ?? '',
        $request['password'] ?? ''
      );
      if ($result === true)
        {
            return array("success" => true, "message" => "account created"); //if true show that the account is create
        }
      return array("success" => false, "message" => $result); //changed since I already handle the error cases above now so it will give back the error
    case "validate_session":
      $row = doValidate($request['sessionId']); //updated to hold new var and matches Jakai's validateToken file
      if ($row !== false)
      {
        return array(
          "success" => true, 
          "message" => "session valid", 
          "username" => $row['username'],
          "first_name" => $row['first_name'],
          "last_name" => $row['last_name']
          ); //updated and w/first and last
      }
      return array("success" => false, "message" => "session invalid");
    case "logout":
      if (doLogout($request['sessionId']))
      {
        return array("success" => true, "message" => "logged out");
      }
      return array("success" => false, "message" => "logout failed");
    case "link_steam": //steamId comes from the web side's verified steam login
      $result = doLinkSteam($request['sessionId'] ?? '', $request['steamId'] ?? '');
      if ($result === true)
      {
        return array("success" => true, "message" => "steam linked");
      }
      return array("success" => false, "message" => $result);
    case "unlink_steam":
      if (doUnlinkSteam($request['sessionId'] ?? ''))
      {
        return array("success" => true, "message" => "steam unlinked");
      }
      return array("success" => false, "message" => "session invalid");
    case "save_steam_profile": //profile is the raw get_profile reply from the DMZ
      $result = saveSteamProfile($request['sessionId'] ?? '', $request['profile'] ?? array());
      if ($result === true)
      {
        return array("success" => true, "message" => "profile saved");
      }
      return array("success" => false, "message" => $result);
    case "save_steam_library": //library is the raw get_owned_games reply from the DMZ
      $result = saveSteamLibrary($request['sessionId'] ?? '', $request['library'] ?? array());
      if ($result === true)
      {
        return array("success" => true, "message" => "library saved");
      }
      return array("success" => false, "message" => $result);

    case "save_steam_recent": //recent is the raw get_recent_playtime reply from the DMZ
      $result = saveSteamRecent($request['sessionId'] ?? '', $request['recent'] ?? array());
      if ($result === true)
      {
        return array("success" => true, "message" => "recent playtime saved");
      }
      return array("success" => false, "message" => $result);
    case "save_steam_achievements": //achievements holds the raw replies from get_achievement_definitions and get_achievements
      $result = saveSteamAchievements($request['sessionId'] ?? '', $request['achievements'] ?? array());
      if ($result === true)
      {
        return array("success" => true, "message" => "achievements saved");
      }
      return array("success" => false, "message" => $result);
    case "get_steam_achievements":
      $result = getSteamAchievements($request['sessionId'] ?? '', $request['appId'] ?? 0);
      if (is_array($result))
      {
        return array(
          "success" => true,
          "message" => "achievements loaded",
          "total" => $result['total'],
          "unlocked" => $result['unlocked'],
          "achievements" => $result['achievements']
        );
      }
      return array("success" => false, "message" => $result);
    case "get_steam_profile": //readd because mege conflicts
      $result = getSteamProfile($request['sessionId'] ?? ''); 
      if (is_array($result))
      {
        return array("success" => true, "message" => "profile loaded", "profile" => $result);
      }
      return array("success" => false, "message" => $result);
    case "get_steam_library":
      $result = getSteamLibrary($request['sessionId'] ?? '');
      if (is_array($result))
      {
        return array("success" => true, "message" => "library loaded", "games" => $result);
      }
      return array("success" => false, "message" => $result);
    case "add_wishlist":
      $result = doAddWishlist($request['sessionId'] ?? '', intval($request['appId'] ?? 0));
      if ($result === true)
      {
        return array("success" => true, "message" => "added to wishlist");
      }
      return array("success" => false, "message" => $result);
    case "remove_wishlist":
      $result = doRemoveWishlist($request['sessionId'] ?? '', intval($request['appId'] ?? 0));
      if ($result === true)
      {
        return array("success" => true, "message" => "removed from wishlist");
      }
      return array("success" => false, "message" => $result);
    case "set_wishlist_notify":
      $result = doSetWishlistNotify($request['sessionId'] ?? '', intval($request['appId'] ?? 0), intval($request['notify'] ?? 0));
      if ($result === true)
      {
        return array("success" => true, "message" => "notify updated");
      }
      return array("success" => false, "message" => $result);
    case "get_wishlist":
      $items = doGetWishlist($request['sessionId'] ?? '');
      if ($items === false)
      {
        return array("success" => false, "message" => "session invalid");
      }
      return array("success" => true, "message" => "wishlist loaded", "items" => $items);

    case "save_game_catalog":
    case "get_global_games_due":
    case "mark_global_game_started":
    case "mark_global_game_failed":
    case "save_global_game_data":
      return handleGlobalCatalog($request);
  }

  return array("success" => false, "message" => "Unknown request type"); //every reply now has success and message
}

function requestProcessor($request)
{
  echo "request: type=".($request['type'] ?? 'NONE')
      ." user=".($request['username'] ?? '-').PHP_EOL;
  try
  {
    $result = handleRequest($request);
  }
  catch (Throwable $e)
  {
    echo "ERROR: ".$e->getMessage().PHP_EOL;
    return array("success" => false, "message" => "server error");
  }
  echo "result: ".json_encode($result).PHP_EOL;
  return $result;
}
$server = new rabbitMQServer("databaseRabbitMQ.ini","databaseServer");   // CHANGED to the new database ini and this databse file

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END".PHP_EOL;
exit();
?>
