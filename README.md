# Is It Too Spicy?

A responsive community catalog for books, movies, TV, podcasts, games, and other media. People can search by title, creator, ISBN, or barcode; create accounts; add missing titles; and leave ratings, spice levels, content warnings, and spoiler-aware notes. Data is stored in MySQL.

## Run locally

Requirements: Node.js 20+ and MySQL 8+.

1. Create a local environment file: `cp .env.example .env`, then set the MySQL credentials and a long random `JWT_SECRET`.
2. Install packages: `npm install`.
3. Initialize the MySQL database and starter catalog: `npm run db:init`.
4. Start the frontend and API: `npm run dev`.
5. Visit `http://localhost:5173`.

The development frontend proxies `/api` requests to the Express API on port 3001. For production, run `npm run build`, set `NODE_ENV=production`, and start the server with `npm start`; Express serves the built site and API from the same origin.

## MySQL tables

- `users`: account name, unique email, and bcrypt password hash.
- `titles`: catalog metadata, ISBN, barcode, optional cover URL, and creator.
- `reviews`: per-account rating, 0–5 spice level, freeform note, JSON content warnings, and spoiler flag. Each account can keep one updatable review per title.

Run `npm run db:init` again to safely create any missing tables; it preserves existing catalog and account data.
