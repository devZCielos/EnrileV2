-- Migration 008: Add brute-force protection and must_change_password columns to user_accounts
ALTER TABLE user_accounts
    ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER remember_token,
    ADD COLUMN locked_until          DATETIME         NULL     DEFAULT NULL AFTER failed_login_attempts,
    ADD COLUMN must_change_password  TINYINT(1)       NOT NULL DEFAULT 0   AFTER locked_until;
