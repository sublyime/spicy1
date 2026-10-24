import jwt from 'jsonwebtoken';

export function createToken(user) {
  if (!process.env.JWT_SECRET) {
    throw new Error('JWT_SECRET is required. Set it in your environment before starting the API.');
  }

  return jwt.sign({ id: user.id, email: user.email }, process.env.JWT_SECRET, { expiresIn: '7d' });
}

export function requireAuth(req, res, next) {
  const token = req.get('authorization')?.replace(/^Bearer\s+/i, '');
  if (!token) return res.status(401).json({ error: 'Sign in to continue.' });

  try {
    req.user = jwt.verify(token, process.env.JWT_SECRET);
    next();
  } catch {
    return res.status(401).json({ error: 'Your session has expired. Please sign in again.' });
  }
}
