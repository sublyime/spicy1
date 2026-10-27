CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS titles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  kind ENUM('book', 'movie', 'tv', 'podcast', 'game', 'other') NOT NULL,
  creator VARCHAR(200) NULL,
  isbn VARCHAR(20) NULL,
  barcode VARCHAR(64) NULL,
  release_year SMALLINT UNSIGNED NULL,
  cover_url VARCHAR(2048) NULL,
  description TEXT NULL,
  catalog_rating DECIMAL(3,1) NULL,
  genres TEXT NULL,
  tropes TEXT NULL,
  tags TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_titles_isbn (isbn),
  UNIQUE KEY uq_titles_barcode (barcode),
  KEY idx_titles_title (title),
  KEY idx_titles_kind (kind),
  CONSTRAINT fk_titles_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  spice_level TINYINT UNSIGNED NOT NULL,
  notes TEXT NOT NULL,
  warnings JSON NOT NULL,
  contains_spoilers BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reviews_title_user (title_id, user_id),
  KEY idx_reviews_created (created_at),
  CONSTRAINT fk_reviews_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT chk_reviews_spice CHECK (spice_level BETWEEN 0 AND 5)
);

INSERT IGNORE INTO titles (id, title, kind, creator, release_year, description) VALUES
  (1, 'The House in the Cerulean Sea', 'book', 'TJ Klune', 2020, 'A warm-hearted fantasy about found family and making room for a little magic.'),
  (2, 'Pride & Prejudice', 'book', 'Jane Austen', 1813, 'A sparkling classic about first impressions, family, and finding your person.'),
  (3, 'Everything Everywhere All at Once', 'movie', 'Daniel Kwan & Daniel Scheinert', 2022, 'An imaginative multiverse adventure with a big heart.'),
  (4, 'Heartstopper', 'tv', 'Alice Oseman', 2022, 'A tender coming-of-age series about friendship, first love, and being yourself.');
