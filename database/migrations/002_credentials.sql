CREATE TABLE IF NOT EXISTS credentials (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    encrypted_api_key TEXT,
    encrypted_access_token TEXT,
    encrypted_refresh_token TEXT,
    oauth_expires_at INTEGER,
    oauth_scope TEXT,
    oauth_created_at INTEGER,
    oauth_connected_at TEXT,
    updated_at TEXT NOT NULL
);
