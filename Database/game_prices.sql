-- Ahbab: prices for the games in our games table
-- filled by Rabbitside/syncGamePrices.php (prices come from the DMZ, get_game_details)
-- run once on the database VM:  sudo mysql steamtrophies < Database/game_prices.sql
CREATE TABLE IF NOT EXISTS game_prices (
    app_id INT UNSIGNED PRIMARY KEY,
    found BOOLEAN NOT NULL DEFAULT TRUE,              -- false = Steam store has no page for it
    has_price BOOLEAN NOT NULL DEFAULT FALSE,         -- false = free or not sold right now
    currency CHAR(3) NULL,
    initial_cents INT UNSIGNED NULL,                  -- price before the sale, 3999 = $39.99
    final_cents INT UNSIGNED NULL,                    -- price right now
    previous_final_cents INT UNSIGNED NULL,           -- price from the last check, for sale alerts
    discount_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    price_updated_at BIGINT NOT NULL,
    INDEX idx_price_updated (price_updated_at),
    FOREIGN KEY (app_id) REFERENCES games(app_id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;
