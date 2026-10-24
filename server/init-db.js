import 'dotenv/config';
import fs from 'node:fs/promises';
import mysql from 'mysql2/promise';

const connection = await mysql.createConnection({
  host: process.env.MYSQL_HOST || 'localhost',
  port: Number(process.env.MYSQL_PORT || 3306),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || '',
  multipleStatements: true,
});

try {
  const sql = await fs.readFile(new URL('./schema.sql', import.meta.url), 'utf8');
  await connection.query(sql);
  console.log('Database and starter catalog initialized.');
} finally {
  await connection.end();
}
