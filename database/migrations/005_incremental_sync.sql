ALTER TABLE sync_runs ADD COLUMN force_full INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sync_runs ADD COLUMN stats_refresh_hours INTEGER NOT NULL DEFAULT 24;
ALTER TABLE sync_runs ADD COLUMN stats_total_subscribers INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sync_runs ADD COLUMN stats_skipped_subscribers INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sync_runs ADD COLUMN subscribers_seen INTEGER NOT NULL DEFAULT 0;
ALTER TABLE sync_runs ADD COLUMN stats_started_at TEXT;
ALTER TABLE sync_runs ADD COLUMN worker_pid INTEGER;
ALTER TABLE sync_runs ADD COLUMN worker_started_at TEXT;
ALTER TABLE sync_runs ADD COLUMN heartbeat_at TEXT;

CREATE INDEX IF NOT EXISTS idx_sync_queue_run_status_id ON sync_queue(run_id, status, id);
