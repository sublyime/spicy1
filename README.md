# Is It Too Spicy?

A responsive community catalog for books, movies, TV, podcasts, games, and other media. People can search by title, creator, ISBN, or barcode; create accounts; add missing titles; and leave ratings, spice levels, content warnings, and spoiler-aware notes. Data is stored in MySQL.

Book details show the synopsis, author and catalog metadata, community ratings, and editable shelf status. A related-books rail ranks titles in the same series or by the same author, then titles sharing genres and tropes. Add series, genre, and trope metadata to improve these recommendations. Signed-in readers can track each title as Not Started, In Progress, Completed, or Dropped; find readers, manage friend requests, and message friends; and change their password after confirming the current password. Password reset uses single-use links that expire after one hour. Administrators can create accounts, assign roles, deactivate/reactivate accounts, send password reset links, and moderate community posts and comments.

## IONOS Web Hosting deployment

The production application uses PHP and MySQL; it does not need a Node.js process on IONOS. Node.js is only used on your computer to create the static frontend files.

1. In IONOS, create a MySQL database and note its host, database name, username, and password. Use the host shown in the IONOS database details; it may not be `localhost`.
2. Make sure the domain is configured to use PHP 8.1 or newer with the PDO MySQL extension enabled.
3. Import `server/schema.sql` into that database using phpMyAdmin. If the tables already exist, the `CREATE TABLE IF NOT EXISTS` statements preserve their data. The starter catalog rows are inserted only when their IDs are not already present.
4. Create `config.php` in `/home/www/` (the web root, alongside the `api/` directory) using `api/config.example.php` as a template. Enter the database details and a long, random `jwt_secret`. Set `app_url` to the site's HTTPS URL and `mail_from` to an address allowed by your hosting provider. The host must have PHP `mail()` configured to deliver email. Keep `config.php` private; the API checks the web root first, then `api/config.php`, then the directory above the web root.
5. On your computer, run `npm install` and `npm run build`.
6. Upload the **contents** of the generated `dist/` directory to your IONOS webspace document root. Also upload the `api/` directory (including your private `config.php`) alongside the built `index.html` and `assets/` directory.
7. Visit your domain over HTTPS and check `/api/index.php?route=%2Fhealth`; a working app responds with `{"status":"ok"}`.

The frontend and PHP API use the same domain, so no separate API host or cross-origin configuration is required. Do not upload `node_modules/`, `.env`, or database credentials in any publicly downloadable file.

On first use, the API adds the user activation and community post-lock fields and creates the password reset token table if needed. User deactivation preserves the account's reviews, shelf, and forum history but prevents further sign-in. Locking a post prevents replies; it does not remove existing content. Admin deletion of a forum post also deletes its comments.

## Run locally

Requirements: Node.js 20+ for the frontend build, PHP 8.1+ with PDO MySQL enabled, and MySQL 8+.

1. Create the local MySQL database, import `server/schema.sql`, and configure `api/config.php` as described above.
2. Install frontend packages with `npm install`.
3. Start the PHP API with `php -S 127.0.0.1:8000 -t .`.
4. In a second terminal, start the frontend with `npm run dev` and visit `http://localhost:5173`.

The Vite development server proxies `/api` requests to the local PHP server.

## MySQL tables

- `users`: account name, unique email, and bcrypt password hash.
- `titles`: catalog metadata, ISBN, barcode, optional cover URL, creator, and optional series, genre, and trope fields.
- `reviews`: per-account rating, 0–5 spice level, freeform note, JSON content warnings, and spoiler flag. Each account can keep one updatable review per title.
- `password_reset_tokens`: SHA-256 token hashes with one-hour expiry and single-use redemption.

The SQL file can be imported again safely to create missing tables and add missing starter catalog entries; it preserves existing account and catalog data.
