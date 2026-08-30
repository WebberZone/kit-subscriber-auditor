CREATE TABLE IF NOT EXISTS reengagement_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id INTEGER NOT NULL,
    tag_name TEXT NOT NULL,
    status TEXT NOT NULL,
    phase TEXT NOT NULL DEFAULT 'tagging',
    tag_page_cursor TEXT,
    tag_page_number INTEGER NOT NULL DEFAULT 0,
    broadcast_id INTEGER,
    broadcast_subject TEXT,
    broadcast_sent_at TEXT,
    total_items INTEGER NOT NULL DEFAULT 0,
    processed_items INTEGER NOT NULL DEFAULT 0,
    successful_items INTEGER NOT NULL DEFAULT 0,
    failed_items INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    tagged_at TEXT,
    resync_started_at TEXT,
    finished_at TEXT,
    updated_at TEXT NOT NULL,
    error_message TEXT
);

CREATE INDEX IF NOT EXISTS idx_reengagement_campaigns_status ON reengagement_campaigns(status);

CREATE TABLE IF NOT EXISTS reengagement_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    subscriber_id INTEGER NOT NULL,
    email_address TEXT NOT NULL,
    first_name TEXT,
    state_before TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_clicked_before_tag TEXT,
    tag_status TEXT NOT NULL DEFAULT 'pending',
    resync_status TEXT NOT NULL DEFAULT 'pending',
    click_status TEXT NOT NULL DEFAULT 'unknown',
    last_clicked_since_broadcast TEXT,
    stats_synced_at TEXT,
    error_message TEXT,
    processed_at TEXT,
    evaluated_at TEXT,
    FOREIGN KEY(campaign_id) REFERENCES reengagement_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY(subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_reengagement_items_campaign_subscriber ON reengagement_items(campaign_id, subscriber_id);
CREATE INDEX IF NOT EXISTS idx_reengagement_items_campaign_tag_status ON reengagement_items(campaign_id, tag_status);
CREATE INDEX IF NOT EXISTS idx_reengagement_items_campaign_resync_status ON reengagement_items(campaign_id, resync_status);
