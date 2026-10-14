-- Apply only as part of an approved MCP OAuth rollout. No grants or tokens are seeded.
CREATE TABLE mcp_oauth_grants (
  user_id INT UNSIGNED NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  resource VARCHAR(255) NOT NULL,
  scopes VARCHAR(80) NOT NULL,
  generation CHAR(32) NOT NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (user_id,client_id,resource),
  CONSTRAINT fk_mcp_grant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mcp_oauth_codes (
  code_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  redirect_uri VARCHAR(512) NOT NULL,
  resource VARCHAR(512) NOT NULL,
  scopes VARCHAR(80) NOT NULL,
  code_challenge CHAR(43) NOT NULL,
  generation CHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mcp_codes_expiry (expires_at),
  CONSTRAINT fk_mcp_code_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mcp_oauth_access_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  resource VARCHAR(512) NOT NULL,
  issuer VARCHAR(512) NOT NULL,
  scopes VARCHAR(80) NOT NULL,
  generation CHAR(32) NOT NULL,
  family_id CHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mcp_access_family (family_id),
  INDEX idx_mcp_access_user (user_id),
  INDEX idx_mcp_access_expiry (expires_at),
  CONSTRAINT fk_mcp_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mcp_oauth_refresh_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  resource VARCHAR(512) NOT NULL,
  issuer VARCHAR(512) NOT NULL,
  scopes VARCHAR(80) NOT NULL,
  generation CHAR(32) NOT NULL,
  family_id CHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mcp_refresh_family (family_id),
  INDEX idx_mcp_refresh_user (user_id),
  INDEX idx_mcp_refresh_expiry (expires_at),
  CONSTRAINT fk_mcp_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
