-- schema.sql
-- Database schema for the personalized news feed
-- Run: mysql -u mbutler -p news < schema.sql

CREATE TABLE IF NOT EXISTS sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  feed_url VARCHAR(500) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_name (name),
  UNIQUE KEY uniq_feed_url (feed_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NOT NULL,
  title VARCHAR(500) NOT NULL,
  title_neutral VARCHAR(500) DEFAULT NULL,
  snippet_neutral TEXT DEFAULT NULL,
  url VARCHAR(2000) NOT NULL,
  url_hash BINARY(16) NOT NULL,
  published_at DATETIME DEFAULT NULL,
  excerpt TEXT DEFAULT NULL,
  raw_text MEDIUMTEXT DEFAULT NULL,
  paywalled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_url_hash (url_hash),
  KEY idx_source_id (source_id),
  KEY idx_created_at (created_at),
  KEY idx_published_at (published_at),
  CONSTRAINT fk_items_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  relevance TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ragebait TINYINT UNSIGNED NOT NULL DEFAULT 0,
  novelty TINYINT UNSIGNED NOT NULL DEFAULT 0,
  challenge_value TINYINT UNSIGNED NOT NULL DEFAULT 0,
  culture_war TINYINT UNSIGNED NOT NULL DEFAULT 0,
  perspective VARCHAR(16) NOT NULL DEFAULT 'neutral',
  tone VARCHAR(32) DEFAULT NULL,
  topics_json JSON DEFAULT NULL,
  cluster_key VARCHAR(64) DEFAULT NULL,
  calm_reason VARCHAR(300) DEFAULT NULL,
  should_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_id (item_id),
  KEY idx_should_read (should_read),
  KEY idx_relevance (relevance),
  KEY idx_cluster_key (cluster_key),
  CONSTRAINT fk_scores_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prefs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_text TEXT NOT NULL,
  thresholds_json JSON DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reads` (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_item_id (item_id),
  CONSTRAINT fk_reads_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

