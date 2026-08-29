CREATE TABLE IF NOT EXISTS oauth_flows (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    state_hash TEXT NOT NULL,
    encrypted_code_verifier TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
