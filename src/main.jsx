import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

const types = [
  { id: 'all', label: 'Everything', icon: '✳' },
  { id: 'book', label: 'Books', icon: '▤' },
  { id: 'movie', label: 'Movies', icon: '▣' },
  { id: 'tv', label: 'TV & streaming', icon: '▤' },
  { id: 'podcast', label: 'Podcasts', icon: '◖' },
  { id: 'game', label: 'Games', icon: '⌘' },
];

const spiceLabels = ['Mild as milk', 'A little heat', 'Getting warm', 'Pretty spicy', 'Extra spicy', 'Five-alarm'];

function api(path, options = {}) {
  const token = localStorage.getItem('spicy-token');
  const queryIndex = path.indexOf('?');
  const route = queryIndex === -1 ? path : path.slice(0, queryIndex);
  const query = queryIndex === -1 ? '' : path.slice(queryIndex + 1);
  const params = new URLSearchParams(query);
  params.set('route', route);
  return fetch(`/api/index.php?${params.toString()}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  }).then(async (response) => {
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || 'Could not complete that request.');
    return payload;
  });
}

function Icon({ name, size = 18 }) {
  const common = { width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true };
  const paths = {
    search: <><circle cx="10.8" cy="10.8" r="6.8" /><path d="m16 16 4.5 4.5" /></>,
    plus: <><path d="M12 5v14M5 12h14" /></>,
    star: <path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z" />,
    heart: <path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z" />,
    arrow: <><path d="M5 12h14M13 6l6 6-6 6" /></>,
    close: <><path d="m6 6 12 12M18 6 6 18" /></>,
    book: <><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" /><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" /></>,
    flame: <path d="M12 22a7 7 0 0 0 7-7c0-4-3-7-5-8 0 3-2 4-2 4 0-4-3-8-5-9 1 5-3 7-3 13a8 8 0 0 0 8 7Z" />,
    user: <><circle cx="12" cy="8" r="4" /><path d="M5 21a7 7 0 0 1 14 0" /></>,
    barcode: <><path d="M4 5v14M7 5v14m4-14v14m3-14v14m3-14v14m3-14v14" /><path d="M2 3h20M2 21h20" /></>,
  };
  return <svg {...common}>{paths[name]}</svg>;
}

function Cover({ item, small = false }) {
  const coverClasses = `cover cover-${item.kind || 'other'} ${small ? 'cover-small' : ''}`;
  if (item.coverUrl) return <div className={coverClasses}><img src={item.coverUrl} alt="" /></div>;
  const initial = (item.title || '?').split(' ').map((word) => word[0]).slice(0, 2).join('');
  return (
    <div className={coverClasses}>
      <span className="cover-orbit" />
      <span className="cover-initials">{initial}</span>
      <span className="cover-kind">{item.kind === 'tv' ? 'SERIES' : (item.kind || 'STORY').toUpperCase()}</span>
    </div>
  );
}

function App() {
  const [titles, setTitles] = useState([]);
  const [query, setQuery] = useState('');
  const [activeType, setActiveType] = useState('all');
  const [user, setUser] = useState(null);
  const [modal, setModal] = useState(null);
  const [selected, setSelected] = useState(null);
  const [toast, setToast] = useState('');
  const [apiOnline, setApiOnline] = useState(true);
  const [catalogError, setCatalogError] = useState('');
  const [loading, setLoading] = useState(true);
  const [favorites, setFavorites] = useState([]);
  const [authMode, setAuthMode] = useState('login');

  const loadTitles = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (query.trim()) params.set('q', query.trim());
      if (activeType !== 'all') params.set('kind', activeType);
      const data = await api(`/titles?${params}`);
      setTitles(data.titles);
      setApiOnline(true);
    } catch (error) {
      setApiOnline(false);
      setCatalogError(error.message);
      setTitles([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(loadTitles, 220);
    return () => clearTimeout(timer);
  }, [query, activeType]);

  useEffect(() => {
    const token = localStorage.getItem('spicy-token');
    if (token) api('/auth/me').then(({ user: current }) => setUser(current)).catch(() => localStorage.removeItem('spicy-token'));
  }, []);

  const featured = useMemo(() => titles.slice(0, 4), [titles]);
  const toggleFavorite = (id) => setFavorites((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id]);

  const showToast = (message) => {
    setToast(message);
    window.setTimeout(() => setToast(''), 3500);
  };

  const signOut = () => {
    localStorage.removeItem('spicy-token');
    setUser(null);
    showToast('You’re signed out.');
  };

  return (
    <div className="app-shell">
      <div className="announcement"><span className="announcement-spark">✳</span> Your cozy corner for content notes <span className="announcement-divider">·</span> Thoughtful, spoiler-free, always kind</div>
      <header className="site-header">
        <a href="#" className="brand" aria-label="Is It Too Spicy home">
          <span className="brand-mark"><span>it?</span><i>✳</i></span>
          <span className="brand-name">is it too<br /><b>spicy?</b></span>
        </a>
        <nav className="top-nav" aria-label="Main navigation">
          <a className="nav-active" href="#explore">Explore</a>
          <a href="#how-it-works">How it works</a>
          <a href="#community">Community</a>
        </nav>
        <div className="header-actions">
          {user ? <><span className="welcome-user">Hi, {user.name.split(' ')[0]}</span><button className="button button-outline button-small" onClick={signOut}>Sign out</button></> :
            <button className="button button-quiet" onClick={() => { setAuthMode('login'); setModal('auth'); }}>Log in</button>}
          <button className="button button-primary button-small" onClick={() => user ? setModal('add') : (setAuthMode('register'), setModal('auth'))}>
            <Icon name="plus" size={16} /> Add a review
          </button>
        </div>
      </header>

      <main>
        <section className="hero" id="explore">
          <div className="hero-main">
            <div className="eyebrow"><span className="eyebrow-dot" /> A LITTLE MORE CONTEXT. A LOT MORE COMFORT.</div>
            <h1>Know before<br />you <span>get cozy.</span></h1>
            <p className="hero-copy">Honest, thoughtful content notes for the books you read, the shows you stream, and everything in between.</p>
            <div className="hero-search-wrap">
              <Icon name="search" size={21} />
              <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search a title, ISBN, barcode, creator..." aria-label="Search titles by title, ISBN, barcode, or creator" />
              {query && <button className="clear-search" onClick={() => setQuery('')} aria-label="Clear search"><Icon name="close" size={17} /></button>}
              <span className="search-shortcut"><Icon name="barcode" size={14} /> ISBN / barcode</span>
            </div>
            <div className="search-hint"><span>Try searching</span> <button onClick={() => setQuery('Heartstopper')}>Heartstopper</button><span>·</span><button onClick={() => setQuery('978')}>an ISBN</button><span>·</span><button onClick={() => setQuery('Jane Austen')}>Jane Austen</button></div>
          </div>
          <div className="hero-art" aria-hidden="true">
            <div className="sun-shape" />
            <div className="hero-squiggle">✳</div>
            <div className="hero-note note-one"><span>✳</span> chosen family</div>
            <div className="hero-book book-back"><i /><b /></div>
            <div className="hero-book book-front"><span className="book-star">✳</span><b>good<br />things<br />inside</b><small>A STORY FOR YOU</small></div>
            <div className="hero-note note-two"><span>♡</span> gentle on the heart</div>
            <div className="hero-sticker"><span>READ<br />WITH<br />CARE</span><i>✳</i></div>
            <div className="hero-dot dot-one" /><div className="hero-dot dot-two" /><div className="hero-dot dot-three" />
          </div>
          <div className="hero-foot"><span><Icon name="heart" size={15} /> Made for the way you feel</span><span>Stories are personal. Your choices should be, too.</span></div>
        </section>

        <section className="browse-section">
          <div className="section-top">
            <div><div className="eyebrow eyebrow-dark">YOUR NEXT READ, WATCH, OR LISTEN</div><h2>Find your kind of <em>story.</em></h2></div>
            <button className="text-link" onClick={() => { setActiveType('all'); setQuery(''); }}>View everything <Icon name="arrow" size={16} /></button>
          </div>
          <div className="category-row" role="tablist" aria-label="Filter by media type">
            {types.map((type) => <button key={type.id} className={`category-chip ${activeType === type.id ? 'selected' : ''}`} onClick={() => setActiveType(type.id)} role="tab" aria-selected={activeType === type.id}><span className="category-icon">{type.icon}</span>{type.label}</button>)}
          </div>
          <div className="content-grid">
            <div className="discover-column">
              <div className="list-heading"><h3>{query ? 'Search results' : activeType === 'all' ? 'A few community favorites' : `${types.find((item) => item.id === activeType)?.label} to explore`}</h3><span>{loading ? 'Finding stories…' : `${titles.length} ${titles.length === 1 ? 'story' : 'stories'}`}</span></div>
              {loading ? <div className="loading-state">A moment while we find your next favorite…</div> :
                featured.length ? <div className="title-grid">{featured.map((item) => <TitleCard key={item.id} item={item} favorite={favorites.includes(item.id)} onFavorite={() => toggleFavorite(item.id)} onOpen={() => { setSelected(item); setModal('detail'); }} />)}</div> :
                  <div className="empty-state"><span className="empty-spark">✳</span><h3>{apiOnline ? 'No stories just yet.' : 'We can’t reach the catalog.'}</h3><p>{apiOnline ? 'Try a different search, or be the first to add this one.' : catalogError || 'Check your MySQL setup and API connection, then refresh to try again.'}</p><button className="button button-primary" onClick={() => user ? setModal('add') : (setAuthMode('register'), setModal('auth'))}><Icon name="plus" size={16} /> Add to the catalog</button></div>}
              {titles.length > 4 && <button className="button button-outline see-more" onClick={() => document.getElementById('all-results')?.scrollIntoView({ behavior: 'smooth' })}>See all {titles.length} results <Icon name="arrow" size={16} /></button>}
              {titles.length > 4 && <div id="all-results" className="extra-results">{titles.slice(4).map((item) => <button key={item.id} className="mini-result" onClick={() => { setSelected(item); setModal('detail'); }}><Cover item={item} small /><span><b>{item.title}</b><small>{item.creator || item.kind}</small></span><span className="mini-result-arrow">↗</span></button>)}</div>}
            </div>
            <aside className="side-column">
              <div className="note-card">
                <div className="note-card-top"><span className="note-icon"><Icon name="flame" size={17} /></span><span>THE SPICE SCALE</span></div>
                <h3>Set your own<br />comfort level.</h3>
                <p>Everyone’s “too spicy” is different. Our community notes help you decide what feels right for you.</p>
                <div className="scale-row" aria-label="Spice scale from mild to extra spicy"><span className="scale-pepper">✳</span><span className="scale-pepper">✳</span><span className="scale-pepper">✳</span><span className="scale-pepper">✳</span><span className="scale-pepper">✳</span><span className="scale-pepper">✳</span></div>
                <div className="scale-labels"><span>Gentle</span><span>Five-alarm</span></div>
                <a className="note-link" href="#how-it-works">Get to know the scale <Icon name="arrow" size={15} /></a>
              </div>
              <div className="community-card" id="community">
                <span className="community-spark">✳</span>
                <div><b>A kinder kind of content note.</b><p>No judgment, no spoilers. Just a little heads-up from one human to another.</p></div>
              </div>
            </aside>
          </div>
        </section>

        <section className="how-section" id="how-it-works">
          <div className="how-intro"><div className="eyebrow">THOUGHTFUL BY DESIGN</div><h2>Because what’s<br />inside <em>matters.</em></h2><p>We believe everyone deserves to enjoy a story on their own terms. Here’s how we make that a little easier.</p></div>
          <div className="how-steps">
            <div className="how-step"><span className="step-number">01</span><div><b>Find your story</b><p>Search by title, creator, ISBN, or barcode. Add it if it’s not here yet.</p></div><Icon name="search" size={22} /></div>
            <div className="how-step"><span className="step-number">02</span><div><b>Hear from real people</b><p>Get kind, specific notes from readers and watchers like you.</p></div><Icon name="heart" size={22} /></div>
            <div className="how-step"><span className="step-number">03</span><div><b>Choose what feels right</b><p>Your boundaries are yours. We’re just here to help you navigate.</p></div><Icon name="arrow" size={22} /></div>
          </div>
        </section>
      </main>

      <footer className="site-footer"><a className="footer-brand" href="#">is it too <b>spicy?</b> <span>✳</span></a><span>Made with care, for stories worth feeling.</span><span>© {new Date().getFullYear()} Is It Too Spicy?</span></footer>
      {toast && <div className="toast" role="status">{toast}</div>}
      {modal === 'auth' && <AuthModal mode={authMode} setMode={setAuthMode} onClose={() => setModal(null)} onSuccess={(newUser) => { setUser(newUser); setModal(null); showToast(`Welcome${newUser.name ? `, ${newUser.name.split(' ')[0]}` : ''}! You're in.`); }} />}
      {modal === 'add' && <AddModal onClose={() => setModal(null)} onCreated={(item) => { setTitles((current) => [item, ...current]); setModal(null); showToast('Added to the community catalog.'); setSelected(item); setModal('review'); }} />}
      {modal === 'detail' && selected && <DetailModal item={selected} onClose={() => setModal(null)} onReview={() => { if (!user) { setAuthMode('register'); setModal('auth'); return; } setModal('review'); }} />}
      {modal === 'review' && selected && <ReviewModal item={selected} onClose={() => setModal(null)} onSaved={() => { setModal(null); showToast('Your note is saved. Thanks for looking out for each other.'); loadTitles(); }} />}
    </div>
  );
}

function TitleCard({ item, favorite, onFavorite, onOpen }) {
  const spice = Number(item.averageSpice);
  return (
    <article className="title-card">
      <button className="cover-button" onClick={onOpen} aria-label={`View ${item.title}`}><Cover item={item} /><span className="card-kind">{item.kind === 'tv' ? 'TV & STREAMING' : item.kind.toUpperCase()}</span></button>
      <button className={`favorite-button ${favorite ? 'is-favorite' : ''}`} onClick={onFavorite} aria-label={favorite ? 'Remove from favorites' : 'Add to favorites'}><Icon name="heart" size={18} /></button>
      <button className="card-content" onClick={onOpen}>
        <span className="card-title">{item.title}</span><span className="card-creator">{item.creator || 'Community pick'}{item.releaseYear ? ` · ${item.releaseYear}` : ''}</span>
        <span className="card-bottom"><span className="spice-meter" aria-label={`${spice || 0} out of 5 spice`}><span className="meter-flame">✳</span>{spice > 0 ? `${spice} / 5` : 'Be the first'}</span><span className="review-count">{item.reviewCount || 0} notes <span>↗</span></span></span>
      </button>
    </article>
  );
}

function AuthModal({ mode, setMode, onClose, onSuccess }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    const form = new FormData(event.currentTarget);
    try {
      const result = await api(`/auth/${mode === 'register' ? 'register' : 'login'}`, { method: 'POST', body: JSON.stringify(Object.fromEntries(form)) });
      localStorage.setItem('spicy-token', result.token);
      onSuccess(result.user);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  };
  return <ModalFrame onClose={onClose}><div className="modal-heading"><span className="modal-kicker">YOUR PEOPLE ARE HERE</span><h2>{mode === 'register' ? 'Join the cozy corner.' : 'Welcome back.'}</h2><p>{mode === 'register' ? 'Make an account to leave thoughtful notes and help someone find their next favorite.' : 'Sign in to add notes, save stories, and help someone out.'}</p></div>
    <form className="form-stack" onSubmit={submit}>
      {mode === 'register' && <label>Your name<input name="name" minLength="2" maxLength="80" autoComplete="name" required placeholder="What should we call you?" /></label>}
      <label>Email address<input type="email" name="email" autoComplete="email" required placeholder="you@example.com" /></label>
      <label>Password<input type="password" name="password" minLength="8" maxLength="72" autoComplete={mode === 'register' ? 'new-password' : 'current-password'} required placeholder={mode === 'register' ? 'At least 8 characters' : 'Your password'} /></label>
      {error && <p className="form-error" role="alert">{error}</p>}
      <button className="button button-primary button-wide" disabled={busy}>{busy ? 'One moment…' : mode === 'register' ? 'Create my account' : 'Sign in'}</button>
    </form>
    <p className="switch-auth">{mode === 'register' ? 'Already have an account?' : 'New around here?'} <button onClick={() => { setError(''); setMode(mode === 'register' ? 'login' : 'register'); }}>{mode === 'register' ? 'Log in' : 'Create an account'}</button></p>
  </ModalFrame>;
}

function AddModal({ onClose, onCreated }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    const form = Object.fromEntries(new FormData(event.currentTarget));
    try {
      const result = await api('/titles', { method: 'POST', body: JSON.stringify(form) });
      onCreated(result.title);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  };
  return <ModalFrame onClose={onClose}><div className="modal-heading"><span className="modal-kicker">GROW THE CATALOG</span><h2>Can’t find it? Add it.</h2><p>A few details help the next person find the right story.</p></div>
    <form className="form-stack" onSubmit={submit}>
      <label>Title<input name="title" required maxLength="200" placeholder="What’s it called?" /></label>
      <div className="form-row"><label>Format<select name="kind" defaultValue="book"><option value="book">Book</option><option value="movie">Movie</option><option value="tv">TV / streaming</option><option value="podcast">Podcast</option><option value="game">Game</option><option value="other">Other</option></select></label><label>Year<input name="releaseYear" type="number" min="1" max="9999" placeholder="2025" /></label></div>
      <label>Creator, author, or director<input name="creator" maxLength="200" placeholder="Who made it?" /></label>
      <div className="form-row"><label>ISBN <span className="optional-label">optional</span><input name="isbn" placeholder="978-..." /></label><label>Barcode <span className="optional-label">optional</span><input name="barcode" placeholder="Scan or type" /></label></div>
      <label>Short description <span className="optional-label">optional</span><textarea name="description" maxLength="1500" rows="2" placeholder="A sentence to help someone recognize it." /></label>
      {error && <p className="form-error" role="alert">{error}</p>}
      <button className="button button-primary button-wide" disabled={busy}>{busy ? 'Adding…' : 'Add this story'}</button>
    </form>
  </ModalFrame>;
}

function DetailModal({ item, onClose, onReview }) {
  const [reviews, setReviews] = useState([]);
  const [error, setError] = useState('');
  useEffect(() => { api(`/titles/${item.id}/reviews`).then(({ reviews: data }) => setReviews(data)).catch((reason) => setError(reason.message)); }, [item.id]);
  return <ModalFrame onClose={onClose} wide><div className="detail-layout"><Cover item={item} /><div className="detail-copy"><span className="modal-kicker">{item.kind.toUpperCase()}{item.releaseYear ? ` · ${item.releaseYear}` : ''}</span><h2>{item.title}</h2><p className="detail-creator">{item.creator || 'Community catalog'}</p><div className="detail-stats"><span><Icon name="flame" size={15} /> {item.averageSpice || '—'} / 5 spice</span><span><Icon name="star" size={15} /> {item.averageRating || '—'} / 5 rating</span></div><p className="detail-description">{item.description || 'A story waiting to be discovered. Community members can leave helpful, spoiler-conscious notes below.'}</p><button className="button button-primary" onClick={onReview}><Icon name="plus" size={16} /> Leave a note</button></div></div>
    <div className="reviews-heading"><h3>Community notes</h3><span>{reviews.length} {reviews.length === 1 ? 'note' : 'notes'}</span></div>
    {error ? <p className="form-error">{error}</p> : reviews.length ? <div className="review-list">{reviews.map((review) => <div className="review-item" key={review.id}><div className="review-meta"><b>{review.reviewer}</b><span>{spiceLabels[review.spiceLevel]} · {review.rating}/5 stars</span></div><p>{review.notes}</p>{Array.isArray(review.warnings) && review.warnings.length > 0 && <div className="warning-tags">{review.warnings.map((warning) => <span key={warning}>{warning}</span>)}</div>}{review.containsSpoilers && <span className="spoiler-flag">Contains spoilers</span>}</div>)}</div> : <p className="no-reviews">No notes yet. You could be the first to look out for someone.</p>}
  </ModalFrame>;
}

function ReviewModal({ item, onClose, onSaved }) {
  const [spice, setSpice] = useState(1);
  const [rating, setRating] = useState(4);
  const [warnings, setWarnings] = useState([]);
  const [warningInput, setWarningInput] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    const form = new FormData(event.currentTarget);
    try {
      await api(`/titles/${item.id}/reviews`, { method: 'POST', body: JSON.stringify({ notes: form.get('notes'), rating, spiceLevel: spice, warnings, containsSpoilers: form.get('containsSpoilers') === 'on' }) });
      onSaved();
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  };
  const addWarning = () => {
    const clean = warningInput.trim();
    if (clean && !warnings.includes(clean) && warnings.length < 20) setWarnings((current) => [...current, clean]);
    setWarningInput('');
  };
  return <ModalFrame onClose={onClose}><div className="modal-heading"><span className="modal-kicker">LOOKING OUT FOR EACH OTHER</span><h2>Leave a kind note.</h2><p>For <b>{item.title}</b>. Be specific, be gentle, skip the spoilers.</p></div>
    <form className="form-stack" onSubmit={submit}>
      <div className="range-label"><label htmlFor="spice-slider">How spicy does it get?</label><b><Icon name="flame" size={15} /> {spiceLabels[spice]}</b></div>
      <input id="spice-slider" className="spice-slider" type="range" min="0" max="5" value={spice} onChange={(event) => setSpice(Number(event.target.value))} />
      <div className="slider-labels"><span>Very gentle</span><span>Five-alarm</span></div>
      <div className="range-label"><label>Your overall rating</label><div className="rating-control">{[1, 2, 3, 4, 5].map((number) => <button type="button" key={number} aria-label={`${number} stars`} onClick={() => setRating(number)} className={number <= rating ? 'rating-on' : ''}><Icon name="star" size={23} /></button>)}</div></div>
      <label>Your note<textarea name="notes" required maxLength="3000" rows="4" placeholder="What would you want a friend to know? Keep it thoughtful and spoiler-free…" /></label>
      <label>Content warnings <span className="optional-label">optional</span><div className="warning-input"><input value={warningInput} onChange={(event) => setWarningInput(event.target.value)} maxLength="80" onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addWarning(); } }} placeholder="Add a gentle heads-up" /><button type="button" className="button button-outline button-small" onClick={addWarning}>Add</button></div></label>
      {warnings.length > 0 && <div className="warning-tags">{warnings.map((warning) => <button type="button" key={warning} onClick={() => setWarnings((current) => current.filter((entry) => entry !== warning))}>{warning} <Icon name="close" size={12} /></button>)}</div>}
      <label className="checkbox-row"><input type="checkbox" name="containsSpoilers" /> This note contains spoilers</label>
      {error && <p className="form-error" role="alert">{error}</p>}
      <button className="button button-primary button-wide" disabled={busy}>{busy ? 'Saving your note…' : 'Share my note'}</button>
    </form>
  </ModalFrame>;
}

function ModalFrame({ children, onClose, wide = false }) {
  useEffect(() => {
    const onKeyDown = (event) => { if (event.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKeyDown);
    document.body.style.overflow = 'hidden';
    return () => { window.removeEventListener('keydown', onKeyDown); document.body.style.overflow = ''; };
  }, [onClose]);
  return <div className="modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}><section className={`modal-panel ${wide ? 'modal-wide' : ''}`} role="dialog" aria-modal="true"><button className="modal-close" onClick={onClose} aria-label="Close"><Icon name="close" size={20} /></button>{children}</section></div>;
}

createRoot(document.getElementById('root')).render(<App />);
