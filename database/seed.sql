-- Demo data for KaninPHP. Run it with: php kanin db:seed
-- Warning: this drops and re-creates the users table.

DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (name, email) VALUES ('Ada Lovelace', 'ada@example.com');
INSERT INTO users (name, email) VALUES ('Alan Turing', 'alan@example.com');
INSERT INTO users (name, email) VALUES ('Grace Hopper', 'grace@example.com');
