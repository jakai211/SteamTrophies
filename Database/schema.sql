DROP DATABASE IF EXISTS steamtrophies;
DROP USER IF EXISTS 'appuser'@'localhost';

CREATE DATABASE steamtrophies;
USE steamtrophies;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sessions (
    token VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE USER 'appuser'@'localhost' IDENTIFIED BY 'password'; 
-- This password is a test and will be changed later on when assigning VM roles
GRANT SELECT, INSERT, UPDATE, DELETE ON steamtrophies.* TO 'appuser'@'localhost';