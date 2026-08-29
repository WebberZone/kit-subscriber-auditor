CREATE TABLE IF NOT EXISTS schema_migrations (
    version TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS subscribers (
    id INTEGER PRIMARY KEY,
    email_address TEXT NOT NULL,
    first_name TEXT,
    state TEXT NOT NULL,
    created_at TEXT NOT NULL,
    canceled_at TEXT,
    sent INTEGER,
    opened INTEGER,
    clicked INTEGER,
    bounced INTEGER,
    last_sent TEXT,
    last_opened TEXT,
    last_clicked TEXT,
    sends_since_last_open INTEGER,
    sends_since_last_click INTEGER,
    open_rate REAL,
    click_rate REAL,
    stats_updated_at TEXT,
    last_seen_run_id INTEGER,
    last_sync_error TEXT,
    raw_subscriber_json TEXT,
    raw_stats_json TEXT,
    created_local_at TEXT NOT NULL,
    updated_local_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_subscribers_state ON subscribers(state);
CREATE INDEX IF NOT EXISTS idx_subscribers_created_at ON subscribers(created_at);
CREATE INDEX IF NOT EXISTS idx_subscribers_last_opened ON subscribers(last_opened);
CREATE INDEX IF NOT EXISTS idx_subscribers_last_clicked ON subscribers(last_clicked);
CREATE INDEX IF NOT EXISTS idx_subscribers_sent ON subscribers(sent);

CREATE TABLE IF NOT EXISTS sync_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status TEXT NOT NULL,
    phase TEXT NOT NULL,
    page_cursor TEXT,
    page_number INTEGER NOT NULL DEFAULT 0,
    total_subscribers INTEGER,
    processed_subscribers INTEGER NOT NULL DEFAULT 0,
    failed_subscribers INTEGER NOT NULL DEFAULT 0,
    last_message TEXT,
    error_message TEXT,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sync_runs_status ON sync_runs(status);

CREATE TABLE IF NOT EXISTS sync_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    subscriber_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    processed_at TEXT,
    UNIQUE(run_id, subscriber_id),
    FOREIGN KEY(run_id) REFERENCES sync_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sync_queue_run_status ON sync_queue(run_id, status);

CREATE TABLE IF NOT EXISTS cleanup_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status TEXT NOT NULL,
    dry_run INTEGER NOT NULL DEFAULT 1,
    total_items INTEGER NOT NULL DEFAULT 0,
    processed_items INTEGER NOT NULL DEFAULT 0,
    successful_items INTEGER NOT NULL DEFAULT 0,
    failed_items INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    started_at TEXT,
    finished_at TEXT,
    updated_at TEXT NOT NULL,
    error_message TEXT
);

CREATE TABLE IF NOT EXISTS cleanup_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    subscriber_id INTEGER NOT NULL,
    email_address TEXT NOT NULL,
    first_name TEXT,
    state_before TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_opened TEXT,
    last_clicked TEXT,
    sent INTEGER,
    reason TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    error_message TEXT,
    processed_at TEXT,
    FOREIGN KEY(job_id) REFERENCES cleanup_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY(subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cleanup_items_job_status ON cleanup_items(job_id, status);

