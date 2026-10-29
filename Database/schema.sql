DROP DATABASE IF EXISTS steamtrophies;
DROP USER IF EXISTS 'appuser'@'localhost';

CREATE DATABASE steamtrophies;
USE steamtrophies;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at BIGINT NOT NULL DEFAULT (UNIX_TIMESTAMP())
);

CREATE TABLE sessions (
    token VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE steam_profiles (
    steam_id VARCHAR(20) PRIMARY KEY,
    display_name VARCHAR(255),
    avatar_url TEXT,
    profile_url TEXT,
    profile_updated_at BIGINT NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_steam_links (
    user_id INT PRIMARY KEY,
    steam_id VARCHAR(20) NOT NULL UNIQUE,
    verified_at BIGINT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (steam_id) REFERENCES steam_profiles(steam_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE games (
    app_id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(512) NOT NULL,
    icon_hash VARCHAR(64) NULL,
    catalog_updated_at BIGINT NULL,
    achievement_definitions_updated_at BIGINT NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_games (
    steam_id VARCHAR(20) NOT NULL,
    app_id INT UNSIGNED NOT NULL,
    in_owned_library BOOLEAN NULL,
    minutes_played INT UNSIGNED NULL,
    minutes_last_two_weeks INT UNSIGNED NULL,
    library_updated_at BIGINT NULL,
    playtime_updated_at BIGINT NULL,
    recent_playtime_updated_at BIGINT NULL,
    PRIMARY KEY (steam_id, app_id),
    FOREIGN KEY (steam_id) REFERENCES steam_profiles(steam_id),
    FOREIGN KEY (app_id) REFERENCES games(app_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE steam_sync_state (
    request_type VARCHAR(64) NOT NULL,
    scope_key VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL,
    last_attempt_at BIGINT NULL,
    last_success_at BIGINT NULL,
    last_error TEXT NULL,
    last_app_id INT UNSIGNED NULL,
    has_more BOOLEAN NULL,
    PRIMARY KEY (request_type, scope_key)
) DEFAULT CHARSET=utf8mb4;

<<<<<<< HEAD
CREATE TABLE achievement_definitions (
    app_id INT UNSIGNED NOT NULL,
    achievement_id VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    name VARCHAR(512) NOT NULL,
    description TEXT NULL,
    icon_url TEXT NULL,
    icon_locked_url TEXT NULL,
    hidden BOOLEAN NOT NULL DEFAULT 0,
    global_percent DECIMAL(5,2) NULL,
    definitions_updated_at BIGINT NULL,
    global_percent_updated_at BIGINT NULL,
    PRIMARY KEY (app_id, achievement_id),
    FOREIGN KEY (app_id) REFERENCES games(app_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_achievements (
    steam_id VARCHAR(20) NOT NULL,
    app_id INT UNSIGNED NOT NULL,
    achievement_id VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    unlocked BOOLEAN NOT NULL DEFAULT 0,
    unlocked_at BIGINT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (steam_id, app_id, achievement_id),
    FOREIGN KEY (steam_id) REFERENCES steam_profiles(steam_id),
    FOREIGN KEY (app_id, achievement_id) REFERENCES achievement_definitions(app_id, achievement_id)
) DEFAULT CHARSET=utf8mb4;

=======
CREATE TABLE wishlist_items (
    user_id INT NOT NULL,
    app_id INT UNSIGNED NOT NULL,
    added_at BIGINT NOT NULL DEFAULT (UNIX_TIMESTAMP()),
    notify BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (user_id, app_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES games(app_id)
) DEFAULT CHARSET=utf8mb4;

>>>>>>> origin/wishlist
CREATE USER 'appuser'@'localhost' IDENTIFIED BY 'Steam_App_2026';
-- This password is a test and will be changed later when assigning VM roles
GRANT SELECT, INSERT, UPDATE, DELETE ON steamtrophies.* TO 'appuser'@'localhost';