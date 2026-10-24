import 'dotenv/config';
import express from 'express';
import bcrypt from 'bcryptjs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { pool } from './db.js';
import { createToken, requireAuth } from './auth.js';

const app = express();
const port = Number(process.env.PORT || 3001);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

app.use(express.json({ limit: '32kb' }));

app.get('/api/health', async (_req, res, next) => {
  try {
    await pool.query('SELECT 1');
    res.json({ status: 'ok' });
  } catch (error) {
    next(error);
  }
});

app.post('/api/auth/register', async (req, res, next) => {
  const name = String(req.body.name || '').trim();
  const email = String(req.body.email || '').trim().toLowerCase();
  const password = String(req.body.password || '');
  if (name.length < 2 || name.length > 80) return res.status(400).json({ error: 'Name must be between 2 and 80 characters.' });
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 254) return res.status(400).json({ error: 'Enter a valid email address.' });
  if (password.length < 8 || password.length > 72) return res.status(400).json({ error: 'Password must be between 8 and 72 characters.' });

  try {
    const [existing] = await pool.execute('SELECT id FROM users WHERE email = ?', [email]);
    if (existing.length) return res.status(409).json({ error: 'An account with that email already exists.' });
    const passwordHash = await bcrypt.hash(password, 12);
    const [result] = await pool.execute('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', [name, email, passwordHash]);
    const user = { id: result.insertId, name, email };
    res.status(201).json({ user, token: createToken(user) });
  } catch (error) {
    next(error);
  }
});

app.post('/api/auth/login', async (req, res, next) => {
  const email = String(req.body.email || '').trim().toLowerCase();
  const password = String(req.body.password || '');
  try {
    const [rows] = await pool.execute('SELECT id, name, email, password_hash FROM users WHERE email = ?', [email]);
    if (!rows.length || !(await bcrypt.compare(password, rows[0].password_hash))) {
      return res.status(401).json({ error: 'Email or password is incorrect.' });
    }
    const user = { id: rows[0].id, name: rows[0].name, email: rows[0].email };
    res.json({ user, token: createToken(user) });
  } catch (error) {
    next(error);
  }
});

app.get('/api/auth/me', requireAuth, async (req, res, next) => {
  try {
    const [rows] = await pool.execute('SELECT id, name, email FROM users WHERE id = ?', [req.user.id]);
    if (!rows.length) return res.status(401).json({ error: 'Account not found.' });
    res.json({ user: rows[0] });
  } catch (error) {
    next(error);
  }
});

app.get('/api/titles', async (req, res, next) => {
  const query = String(req.query.q || '').trim().slice(0, 120);
  const kind = String(req.query.kind || '').trim();
  try {
    const conditions = [];
    const values = [];
    if (query) {
      const like = `%${query}%`;
      const identifier = query.replace(/[\s-]/g, '');
      conditions.push("(t.title LIKE ? OR t.creator LIKE ? OR t.isbn LIKE ? OR t.barcode LIKE ? OR REPLACE(t.isbn, '-', '') LIKE ?)");
      values.push(like, like, like, like, `%${identifier}%`);
    }
    if (['book', 'movie', 'tv', 'podcast', 'game', 'other'].includes(kind)) {
      conditions.push('t.kind = ?');
      values.push(kind);
    }
    const where = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';
    const [rows] = await pool.execute(
      `SELECT t.id, t.title, t.kind, t.creator, t.isbn, t.barcode, t.release_year AS releaseYear, t.cover_url AS coverUrl, t.description,
        COUNT(r.id) AS reviewCount, ROUND(AVG(r.spice_level), 1) AS averageSpice, ROUND(AVG(r.rating), 1) AS averageRating
       FROM titles t LEFT JOIN reviews r ON r.title_id = t.id ${where}
       GROUP BY t.id ORDER BY ${query ? 'CASE WHEN t.title = ? THEN 0 WHEN t.title LIKE ? THEN 1 ELSE 2 END, ' : ''}t.created_at DESC LIMIT 50`,
      query ? [...values, query, `${query}%`] : values,
    );
    res.json({ titles: rows });
  } catch (error) {
    next(error);
  }
});

app.get('/api/titles/:id/reviews', async (req, res, next) => {
  try {
    const [rows] = await pool.execute(
      `SELECT r.id, r.rating, r.spice_level AS spiceLevel, r.notes, r.warnings, r.contains_spoilers AS containsSpoilers,
        r.created_at AS createdAt, u.name AS reviewer
       FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.title_id = ? ORDER BY r.created_at DESC LIMIT 50`,
      [req.params.id],
    );
    res.json({ reviews: rows });
  } catch (error) {
    next(error);
  }
});

app.post('/api/titles', requireAuth, async (req, res, next) => {
  const title = String(req.body.title || '').trim();
  const kind = String(req.body.kind || '');
  const creator = String(req.body.creator || '').trim() || null;
  const isbn = String(req.body.isbn || '').replace(/[\s-]/g, '') || null;
  const barcode = String(req.body.barcode || '').trim() || null;
  const releaseYear = req.body.releaseYear ? Number(req.body.releaseYear) : null;
  const coverUrl = String(req.body.coverUrl || '').trim() || null;
  const description = String(req.body.description || '').trim() || null;
  if (!title || title.length > 200) return res.status(400).json({ error: 'Title is required and must be 200 characters or fewer.' });
  if (!['book', 'movie', 'tv', 'podcast', 'game', 'other'].includes(kind)) return res.status(400).json({ error: 'Choose a valid media type.' });
  if (creator && creator.length > 200) return res.status(400).json({ error: 'Creator must be 200 characters or fewer.' });
  if (isbn && !/^(?:\d{9}[\dXx]|\d{13})$/.test(isbn)) return res.status(400).json({ error: 'ISBN must be 10 or 13 characters, excluding spaces and hyphens.' });
  if (barcode && barcode.length > 64) return res.status(400).json({ error: 'Barcode must be 64 characters or fewer.' });
  if (releaseYear !== null && (!Number.isInteger(releaseYear) || releaseYear < 1 || releaseYear > 9999)) return res.status(400).json({ error: 'Enter a valid release year.' });
  if (description && description.length > 1500) return res.status(400).json({ error: 'Description must be 1,500 characters or fewer.' });
  if (coverUrl && (!/^https?:\/\//i.test(coverUrl) || coverUrl.length > 2048)) return res.status(400).json({ error: 'Cover URL must be a valid http(s) URL.' });

  try {
    const [result] = await pool.execute(
      'INSERT INTO titles (title, kind, creator, isbn, barcode, release_year, cover_url, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [title, kind, creator, isbn, barcode, releaseYear, coverUrl, description, req.user.id],
    );
    res.status(201).json({ title: { id: result.insertId, title, kind, creator, isbn, barcode, releaseYear, coverUrl, description, reviewCount: 0 } });
  } catch (error) {
    if (error.code === 'ER_DUP_ENTRY') return res.status(409).json({ error: 'That ISBN or barcode is already in the catalog. Try searching for it first.' });
    next(error);
  }
});

app.post('/api/titles/:id/reviews', requireAuth, async (req, res, next) => {
  const rating = Number(req.body.rating);
  const spiceLevel = Number(req.body.spiceLevel);
  const notes = String(req.body.notes || '').trim();
  const warnings = Array.isArray(req.body.warnings) ? req.body.warnings.map((warning) => String(warning).trim()).filter(Boolean).slice(0, 20) : [];
  const containsSpoilers = Boolean(req.body.containsSpoilers);
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) return res.status(400).json({ error: 'Rating must be between 1 and 5.' });
  if (!Number.isInteger(spiceLevel) || spiceLevel < 0 || spiceLevel > 5) return res.status(400).json({ error: 'Spice level must be between 0 and 5.' });
  if (!notes || notes.length > 3000) return res.status(400).json({ error: 'Add a note of up to 3,000 characters.' });
  if (warnings.some((warning) => warning.length > 80)) return res.status(400).json({ error: 'Each warning must be 80 characters or fewer.' });

  try {
    const [result] = await pool.execute(
      `INSERT INTO reviews (title_id, user_id, rating, spice_level, notes, warnings, contains_spoilers)
       VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE rating = VALUES(rating), spice_level = VALUES(spice_level), notes = VALUES(notes),
         warnings = VALUES(warnings), contains_spoilers = VALUES(contains_spoilers), updated_at = CURRENT_TIMESTAMP`,
      [req.params.id, req.user.id, rating, spiceLevel, notes, JSON.stringify(warnings), containsSpoilers],
    );
    res.status(result.affectedRows === 1 ? 201 : 200).json({ message: 'Your review is saved.' });
  } catch (error) {
    if (error.code === 'ER_NO_REFERENCED_ROW_2') return res.status(404).json({ error: 'That title could not be found.' });
    next(error);
  }
});

if (process.env.NODE_ENV === 'production') {
  app.use(express.static(path.join(root, 'dist')));
  app.get('*', (_req, res) => res.sendFile(path.join(root, 'dist', 'index.html')));
}

app.use((error, _req, res, _next) => {
  console.error(error);
  res.status(500).json({ error: 'Something went wrong. Please try again.' });
});

app.listen(port, () => console.log(`Is It Too Spicy API listening on http://localhost:${port}`));
