-- MySQL 8.0 schema converted from the existing Knex migrations for PHP移行.
-- All DATETIME columns are stored in UTC; use DATETIME(3) to keep millisecond precision when available.
SET NAMES utf8mb4;

CREATE TABLE tenants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_uid VARCHAR(64) NOT NULL,
  name VARCHAR(255),
  contact_email VARCHAR(255),
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  deactivated_at DATETIME(3),
  require_employee_email_verification TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY tenants_tenant_uid_unique (tenant_uid),
  KEY tenants_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED,
  username VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(32) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME(3),
  first_name VARCHAR(255),
  last_name VARCHAR(255),
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  deactivated_at DATETIME(3),
  phone_number VARCHAR(32),
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email),
  KEY users_role_idx (role),
  KEY users_email_idx (email),
  KEY users_tenant_role_idx (tenant_id, role),
  KEY users_status_idx (status),
  CONSTRAINT users_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  expires_at DATETIME(3),
  max_uses INT,
  usage_count INT NOT NULL DEFAULT 0,
  is_disabled TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED,
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY role_codes_code_unique (code),
  KEY role_codes_tenant_idx (tenant_id),
  KEY role_codes_code_idx (code),
  CONSTRAINT role_codes_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT role_codes_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token VARCHAR(128) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY password_resets_token_unique (token),
  KEY password_resets_token_idx (token),
  CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  start_time DATETIME(3) NOT NULL,
  end_time DATETIME(3),
  archived_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  KEY work_sessions_user_start_idx (user_id, start_time),
  KEY work_sessions_archived_idx (archived_at),
  CONSTRAINT work_sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED,
  original_file_name VARCHAR(255) NOT NULL,
  stored_file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(255),
  file_size BIGINT UNSIGNED,
  sent_on DATE NOT NULL,
  sent_at DATETIME(3) NOT NULL,
  archived_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  KEY payroll_records_tenant_idx (tenant_id),
  KEY payroll_records_employee_idx (employee_id),
  KEY payroll_records_sent_on_idx (sent_on),
  KEY payroll_records_archived_idx (archived_at),
  CONSTRAINT payroll_records_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT payroll_records_employee_fk FOREIGN KEY (employee_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT payroll_records_uploaded_by_fk FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_mfa_methods (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  secret TEXT,
  config_json TEXT,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  verified_at DATETIME(3),
  last_used_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY user_mfa_methods_user_type_unique (user_id, type),
  KEY user_mfa_methods_user_verified_idx (user_id, is_verified),
  CONSTRAINT user_mfa_methods_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_mfa_recovery_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(128) NOT NULL,
  used_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_mfa_recovery_codes_unique_code (user_id, code_hash),
  KEY user_mfa_recovery_codes_user_idx (user_id),
  CONSTRAINT user_mfa_recovery_codes_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_mfa_trusted_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash VARCHAR(128) NOT NULL,
  device_info VARCHAR(255),
  expires_at DATETIME(3) NOT NULL,
  last_used_at DATETIME(3),
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_mfa_trusted_devices_token_unique (token_hash),
  CONSTRAINT user_mfa_trusted_devices_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_active_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  -- session_hash stores sha256(session_key) for single-session enforcement (never store raw tokens).
  session_hash VARCHAR(64) NOT NULL,
  last_login_at DATETIME(3) NOT NULL,
  last_login_ip VARCHAR(64),
  last_login_ua VARCHAR(512),
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_active_sessions_user_unique (user_id),
  KEY user_active_sessions_user_updated_idx (user_id, updated_at),
  CONSTRAINT user_active_sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_admin_mfa_reset_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_user_id INT UNSIGNED NOT NULL,
  performed_by_user_id INT UNSIGNED,
  reason TEXT NOT NULL,
  previous_method_json TEXT,
  previous_recovery_codes_json TEXT,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  rolled_back_at DATETIME(3),
  rolled_back_by_user_id INT UNSIGNED,
  rollback_reason TEXT,
  PRIMARY KEY (id),
  KEY tenant_admin_mfa_reset_logs_user_created_idx (target_user_id, created_at),
  CONSTRAINT tenant_admin_mfa_reset_logs_target_fk FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT tenant_admin_mfa_reset_logs_performed_by_fk FOREIGN KEY (performed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT tenant_admin_mfa_reset_logs_rollback_by_fk FOREIGN KEY (rolled_back_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_otp_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED,
  role_code_id INT UNSIGNED,
  purpose VARCHAR(64) NOT NULL,
  target_email VARCHAR(320) NOT NULL,
  code_hash VARCHAR(128) NOT NULL,
  metadata_json TEXT,
  expires_at DATETIME(3) NOT NULL,
  consumed_at DATETIME(3),
  failed_attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 5,
  lock_until DATETIME(3),
  last_sent_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY email_otp_requests_user_purpose_unique (user_id, purpose),
  KEY email_otp_requests_purpose_email_idx (purpose, target_email),
  CONSTRAINT email_otp_requests_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT email_otp_requests_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL,
  CONSTRAINT email_otp_requests_role_code_fk FOREIGN KEY (role_code_id) REFERENCES role_codes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: dedicated session table for PHP session_set_save_handler() if DB-backed sessions are preferred.
-- CREATE TABLE php_sessions (
--   id VARBINARY(128) NOT NULL,
--   data LONGBLOB NOT NULL,
--   expires_at DATETIME(3) NOT NULL,
--   PRIMARY KEY (id),
--   KEY php_sessions_expires_idx (expires_at)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
