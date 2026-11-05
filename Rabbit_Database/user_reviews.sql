-- table for the ratings and reviews that SteamTrophies users write on the game page
-- run once on the database VM:  mysql -u root -p steamtrophies < Database/user_reviews.sql
CREATE TABLE IF NOT EXISTS user_reviews (
    user_id INT NOT NULL,
    app_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text TEXT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (user_id, app_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (app_id) REFERENCES games(app_id),
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) DEFAULT CHARSET=utf8mb4;
