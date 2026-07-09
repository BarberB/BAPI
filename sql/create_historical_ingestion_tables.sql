USE `crypto`;

CREATE TABLE IF NOT EXISTS `symbols` (
  `symbol` VARCHAR(32) NOT NULL,
  `base_asset` VARCHAR(32) NOT NULL,
  `quote_asset` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `base_asset_precision` INT UNSIGNED NULL,
  `quote_asset_precision` INT UNSIGNED NULL,
  `order_types` JSON NULL,
  `permissions` JSON NULL,
  `filters` JSON NULL,
  `raw_response` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`symbol`),
  KEY `idx_symbols_base_quote` (`base_asset`, `quote_asset`),
  KEY `idx_symbols_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_trades` (
  `symbol` VARCHAR(32) NOT NULL,
  `trade_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `order_list_id` BIGINT NULL,
  `price` DECIMAL(38,18) NOT NULL,
  `qty` DECIMAL(38,18) NOT NULL,
  `quote_qty` DECIMAL(38,18) NULL,
  `commission` DECIMAL(38,18) NULL,
  `commission_asset` VARCHAR(32) NULL,
  `trade_time` BIGINT UNSIGNED NOT NULL,
  `is_buyer` TINYINT(1) NOT NULL,
  `is_maker` TINYINT(1) NOT NULL,
  `is_best_match` TINYINT(1) NULL,
  `raw_response` JSON NULL,
  `ingested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`symbol`, `trade_id`),
  KEY `idx_account_trades_order` (`symbol`, `order_id`),
  KEY `idx_account_trades_time` (`symbol`, `trade_time`),
  KEY `idx_account_trades_commission_asset` (`commission_asset`),
  CONSTRAINT `fk_account_trades_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `market_klines` (
  `symbol` VARCHAR(32) NOT NULL,
  `interval_name` VARCHAR(16) NOT NULL,
  `open_time` BIGINT UNSIGNED NOT NULL,
  `open_price` DECIMAL(38,18) NOT NULL,
  `high_price` DECIMAL(38,18) NOT NULL,
  `low_price` DECIMAL(38,18) NOT NULL,
  `close_price` DECIMAL(38,18) NOT NULL,
  `volume` DECIMAL(38,18) NOT NULL,
  `close_time` BIGINT UNSIGNED NOT NULL,
  `quote_asset_volume` DECIMAL(38,18) NULL,
  `trade_count` INT UNSIGNED NULL,
  `taker_buy_base_volume` DECIMAL(38,18) NULL,
  `taker_buy_quote_volume` DECIMAL(38,18) NULL,
  `raw_response` JSON NULL,
  `ingested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`symbol`, `interval_name`, `open_time`),
  KEY `idx_market_klines_close_time` (`symbol`, `interval_name`, `close_time`),
  CONSTRAINT `fk_market_klines_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `market_agg_trades` (
  `symbol` VARCHAR(32) NOT NULL,
  `agg_trade_id` BIGINT UNSIGNED NOT NULL,
  `price` DECIMAL(38,18) NOT NULL,
  `qty` DECIMAL(38,18) NOT NULL,
  `first_trade_id` BIGINT UNSIGNED NULL,
  `last_trade_id` BIGINT UNSIGNED NULL,
  `trade_time` BIGINT UNSIGNED NOT NULL,
  `is_buyer_maker` TINYINT(1) NOT NULL,
  `is_best_match` TINYINT(1) NULL,
  `raw_response` JSON NULL,
  `ingested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`symbol`, `agg_trade_id`),
  KEY `idx_market_agg_trades_time` (`symbol`, `trade_time`),
  KEY `idx_market_agg_trades_trade_range` (`symbol`, `first_trade_id`, `last_trade_id`),
  CONSTRAINT `fk_market_agg_trades_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `balance_snapshots` (
  `snapshot_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_time` BIGINT UNSIGNED NOT NULL,
  `account_type` VARCHAR(32) NOT NULL DEFAULT 'SPOT',
  `asset` VARCHAR(32) NOT NULL,
  `free` DECIMAL(38,18) NOT NULL DEFAULT 0,
  `locked` DECIMAL(38,18) NOT NULL DEFAULT 0,
  `total` DECIMAL(38,18) NOT NULL DEFAULT 0,
  `raw_response` JSON NULL,
  `ingested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`snapshot_id`),
  UNIQUE KEY `uq_balance_snapshots_asset_time` (`account_type`, `asset`, `snapshot_time`),
  KEY `idx_balance_snapshots_time` (`snapshot_time`),
  KEY `idx_balance_snapshots_asset` (`asset`, `snapshot_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingest_runs` (
  `run_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_type` VARCHAR(64) NOT NULL,
  `endpoint` VARCHAR(128) NOT NULL,
  `symbol` VARCHAR(32) NULL,
  `interval_name` VARCHAR(16) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'started',
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` TIMESTAMP NULL,
  `from_time` BIGINT UNSIGNED NULL,
  `to_time` BIGINT UNSIGNED NULL,
  `records_inserted` INT UNSIGNED NOT NULL DEFAULT 0,
  `records_updated` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `request_params` JSON NULL,
  `cursor_before` JSON NULL,
  `cursor_after` JSON NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`run_id`),
  KEY `idx_ingest_runs_status` (`status`, `started_at`),
  KEY `idx_ingest_runs_endpoint_symbol` (`endpoint`, `symbol`, `interval_name`),
  KEY `idx_ingest_runs_time_window` (`from_time`, `to_time`),
  CONSTRAINT `fk_ingest_runs_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingest_cursors` (
  `cursor_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cursor_name` VARCHAR(128) NOT NULL,
  `endpoint` VARCHAR(128) NOT NULL,
  `symbol` VARCHAR(32) NULL,
  `interval_name` VARCHAR(16) NULL,
  `last_id` BIGINT UNSIGNED NULL,
  `last_time` BIGINT UNSIGNED NULL,
  `cursor_value` JSON NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cursor_id`),
  UNIQUE KEY `uq_ingest_cursors_scope` (`cursor_name`, `endpoint`, `symbol`, `interval_name`),
  KEY `idx_ingest_cursors_updated` (`updated_at`),
  CONSTRAINT `fk_ingest_cursors_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_errors` (
  `error_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NULL,
  `endpoint` VARCHAR(128) NOT NULL,
  `symbol` VARCHAR(32) NULL,
  `interval_name` VARCHAR(16) NULL,
  `http_status` INT UNSIGNED NULL,
  `api_code` INT NULL,
  `api_message` TEXT NULL,
  `request_params` JSON NULL,
  `raw_response` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`error_id`),
  KEY `idx_api_errors_run` (`run_id`),
  KEY `idx_api_errors_endpoint_created` (`endpoint`, `created_at`),
  KEY `idx_api_errors_symbol_created` (`symbol`, `created_at`),
  CONSTRAINT `fk_api_errors_run`
    FOREIGN KEY (`run_id`) REFERENCES `ingest_runs` (`run_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_api_errors_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingest_symbol_watchlist` (
  `watchlist_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(32) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `reason` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`watchlist_id`),
  UNIQUE KEY `uq_ingest_symbol_watchlist_symbol` (`symbol`),
  KEY `idx_ingest_symbol_watchlist_enabled_symbol` (`enabled`, `symbol`),
  CONSTRAINT `fk_ingest_symbol_watchlist_symbol`
    FOREIGN KEY (`symbol`) REFERENCES `symbols` (`symbol`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
