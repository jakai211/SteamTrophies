USE steamtrophies;

CREATE TABLE IF NOT EXISTS steam_profiles (
    steam_id VARCHAR(20) PRIMARY KEY,
    display_name VARCHAR(255),
    avatar_url TEXT,
    profile_url TEXT,
    profile_updated_at BIGINT NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_steam_links (
    user_id INT PRIMARY KEY,
    steam_id VARCHAR(20) NOT NULL UNIQUE,
    verified_at BIGINT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (steam_id) REFERENCES steam_profiles(steam_id)
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS games (
    app_id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(512) NOT NULL,
    icon_hash VARCHAR(64) NULL,
    catalog_updated_at BIGINT NULL,
    achievement_definitions_updated_at BIGINT NULL
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_games (
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

CREATE TABLE IF NOT EXISTS steam_sync_state (
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