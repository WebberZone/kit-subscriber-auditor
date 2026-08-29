UPDATE credentials SET
    encrypted_access_token = NULL,
    encrypted_refresh_token = NULL,
    oauth_expires_at = NULL,
    oauth_scope = NULL,
    oauth_created_at = NULL,
    oauth_connected_at = NULL;

DROP TABLE IF EXISTS oauth_flows;
