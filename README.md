# Is It Too Spicy?

A responsive community catalog for books, movies, TV, podcasts, games, and other media. People can search by title, creator, ISBN, or barcode; create accounts; add missing titles; and leave ratings, spice levels, content warnings, and spoiler-aware notes. Data is stored in MySQL.

## IONOS Web Hosting deployment

The production application uses PHP and MySQL; it does not need a Node.js process on IONOS. Node.js is only used on your computer to create the static frontend files.

1. In IONOS, create a MySQL database and note its host, database name, username, and password. Use the host shown in the IONOS database details; it may not be `localhost`.
2. Make sure the domain is configured to use PHP 8.1 or newer with the PDO MySQL extension enabled.
3. Import `server/schema.sql` into that database using phpMyAdmin. If the tables already exist, the `CREATE TABLE IF NOT EXISTS` statements preserve their data. The starter catalog rows are inserted only when their IDs are not already present.
4. Copy `api/config.example.php` to `api/config.php` and enter the database details plus a long, random `JWT_SECRET`. Do not publish or commit `api/config.php`.
5. On your computer, run `npm install` and `npm run build`.
6. Upload the **contents** of the generated `dist/` directory to your IONOS webspace document root. Also upload the `api/` directory (including your private `config.php`) alongside the built `index.html` and `assets/` directory.
7. Visit your domain over HTTPS and check `/api/index.php?route=%2Fhealth`; a working app responds with `{"status":"ok"}`.

The frontend and PHP API use the same domain, so no separate API host or cross-origin configuration is required. Do not upload `node_modules/`, `.env`, or database credentials in any publicly downloadable file.

## Run locally

Requirements: Node.js 20+ for the frontend build, PHP 8.1+ with PDO MySQL enabled, and MySQL 8+.

1. Create the local MySQL database, import `server/schema.sql`, and configure `api/config.php` as described above.
2. Install frontend packages with `npm install`.
3. Start the PHP API with `php -S 127.0.0.1:8000 -t .`.
4. In a second terminal, start the frontend with `npm run dev` and visit `http://localhost:5173`.

The Vite development server proxies `/api` requests to the local PHP server.

## MySQL tables

- `users`: account name, unique email, and bcrypt password hash.
- `titles`: catalog metadata, ISBN, barcode, optional cover URL, and creator.
- `reviews`: per-account rating, 0–5 spice level, freeform note, JSON content warnings, and spoiler flag. Each account can keep one updatable review per title.

The SQL file can be imported again safely to create missing tables and add missing starter catalog entries; it preserves existing account and catalog data.
