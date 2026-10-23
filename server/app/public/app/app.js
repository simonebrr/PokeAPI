'use strict';
// CardLedger – gestionale compravendita carte Pokémon (vanilla JS, nessuna build).

const state = { me: null, lots: [] };
const app = document.getElementById('app');
const modalRoot = document.getElementById('modal-root');

const LANGS = ['it', 'en', 'jp', 'fr', 'de', 'es', 'pt', 'kr', 'zh'];
const CONDITIONS = ['Mint', 'Near Mint', 'Slightly Played', 'Moderately Played', 'Played', 'Poor'];
const CHANNELS = ['Vinted', 'Cardmarket', 'CardTrader', 'eBay', 'Subito', 'Di persona', 'Fiera', 'Altro'];
const STATUS_LABEL = { in_stock: 'In magazzino', listed: 'In vendita', sold: 'Venduta' };
const GRADERS = ['PSA', 'BGS', 'CGC', 'ACE', 'GetGraded', 'SGC', 'Altro'];
const photoUrl = (i, name) => `/api/items/${i.id}/photos/${encodeURIComponent(name)}`;
const thumbOf = (i) => i.photos?.length ? photoUrl(i, i.photos[0]) : i.image_url;
const gradeBadge = (i) => i.grading_company ? `<span class="badge grade">${esc(i.grading_company)} ${esc(i.grade || '')}</span>` : '';
const shortDate = (d) => new Date(d.slice(0, 10) + 'T00:00:00').toLocaleDateString('it-IT', { day: '2-digit', month: 'short' });

// ---------- utility ----------
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const eur = (v) => v == null ? '—' : new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(v);
const signed = (v) => `<span class="${v >= 0 ? 'pos' : 'neg'}">${v > 0 ? '+' : ''}${eur(v)}</span>`;
const today = () => new Date().toISOString().slice(0, 10);
const fmtDate = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
const roiBadge = (r) => r == null ? '' : `<span class="badge roi">x${r.toLocaleString('it-IT')}</span>`;

const QUEUE_KEY = 'cl_offline_items';
const readQueue = () => { try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); } catch { return []; } };
const writeQueue = (q) => { try { localStorage.setItem(QUEUE_KEY, JSON.stringify(q)); } catch { /* storage pieno o bloccato */ } };

async function api(path, { method = 'GET', body, quiet = false } = {}) {
  // Senza rete le nuove carte vanno in coda e si salvano al ritorno della connessione.
  if (!navigator.onLine && method === 'POST' && path === 'items') {
    writeQueue([...readQueue(), { body, at: Date.now() }]);
    refreshOfflineBanner();
    return { ...body, id: 0, offline: true, photos: [], quantity: +body.quantity || 1 };
  }
  const res = await fetch('/api/' + path, {
    method,
    credentials: 'same-origin',
    headers: body !== undefined ? { 'Content-Type': 'application/json' } : {},
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && !path.startsWith('auth/')) {
    state.me = null;
    if (!quiet) renderAuth();
    throw new Error('Sessione scaduta');
  }
  if (res.status === 402 && state.me) {
    state.me.billing.premium = false;
    premiumModal();
    throw new Error(data.error);
  }
  if (!res.ok) throw new Error(data.error || 'Errore ' + res.status);
  return data;
}

let toastTimer;
function toast(msg, error = false) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (error ? ' error' : '');
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.hidden = true), 2600);
}

function formData(form) {
  const o = {};
  new FormData(form).forEach((v, k) => (o[k] = v));
  return o;
}

async function guarded(btn, fn) {
  btn && (btn.disabled = true);
  try { await fn(); } catch (e) { toast(e.message, true); } finally { btn && (btn.disabled = false); }
}

// ---------- layout ----------
function shell(title, content, { back, fab } = {}) {
  const route = location.hash.split(/[/?]/)[0] || '#dashboard';
  const tab = (h, icon, label) => `<a href="${h}" class="${route === h ? 'active' : ''}"><span>${icon}</span>${label}</a>`;
  app.innerHTML = `
    <header class="topbar">
      ${back ? `<a class="back" href="${back}">‹</a>` : '<div class="logo-dot"></div>'}
      <h1>${esc(title)}</h1>
    </header>
    ${offlineBanner()}
    ${billingBanner()}
    ${verifyBanner()}
    <main>${content}</main>
    ${fab ? `<button class="btn fab" id="fab">${fab}</button>` : ''}
    <nav class="tabbar">
      ${tab('#dashboard', '📊', 'Riepilogo')}
      ${tab('#lots', '📦', 'Lotti')}
      ${tab('#items', '🃏', 'Carte')}
      ${tab('#sales', '💶', 'Vendite')}
      ${tab('#settings', '⚙️', 'Altro')}
      ${state.me.user.is_admin ? tab('#admin', '👑', 'Admin') : ''}
    </nav>`;
}

function billingBanner() {
  const b = state.me?.billing;
  if (!b) return '';
  if (b.state === 'trial') return `<a class="banner" href="#settings">⭐ Premium in prova: ${b.days_left} ${b.days_left === 1 ? 'giorno' : 'giorni'} rimasti</a>`;
  if (b.state === 'free' && b.items_in_stock >= b.free_item_limit * 0.9) {
    return `<a class="banner" href="#settings">Piano Free: ${b.items_in_stock}/${b.free_item_limit} carte · Passa a Premium →</a>`;
  }
  return '';
}

const isPremium = () => !!state.me?.billing?.premium;
const PREMIUM_FEATURES = [
  ['🃏', 'Carte illimitate', () => `Free: fino a ${state.me.billing.free_item_limit} carte in magazzino`],
  ['↻', 'Aggiornamento valori in blocco', () => 'Tutto il magazzino aggiornato da CardTrader in un tocco'],
  ['👥', 'Soci e divisione profitti', () => 'Più persone nello stesso negozio, quote per ogni lotto'],
  ['🌙', 'Valori aggiornati ogni notte', () => 'Il magazzino si aggiorna da solo con i prezzi CardTrader'],
  ['🔔', 'Avvisi di prezzo', () => 'Email quando una carta sale o scende oltre la soglia'],
  ['📈', 'Storico e grafici', () => 'Andamento di ogni carta, del magazzino e degli incassi mensili'],
  ['🎁', 'Sbusto o rivendo?', () => 'Valore atteso di bustine, ETB e box, pull rate e prezzo consigliato'],
  ['🧾', 'Report annuale', () => 'Acquisti e vendite dell\'anno, esportabili per il commercialista'],
];

// Finestra che spiega Premium quando si tocca una funzione bloccata.
function premiumModal(reason) {
  const b = state.me.billing;
  openModal('⭐ Premium', `
    ${reason ? `<p><strong>${esc(reason)}</strong> è incluso in Premium.</p>` : ''}
    <div class="card">${PREMIUM_FEATURES.map(([i, t, d]) => `<div style="padding:6px 0"><strong>${i} ${t}</strong><div class="small muted">${esc(d())}</div></div>`).join('')}</div>
    <p class="small muted">Il piano Free resta gratis per sempre: lotti, vendite, riepilogo, prezzi CardTrader ed export.</p>
    <a class="btn block" href="#settings">Scopri Premium · ${eur(b.price)}/mese</a>`);
  modalRoot.querySelector('a.btn').addEventListener('click', closeModal);
}

const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const monthLabel = (ym) => MONTHS[+ym.slice(5, 7) - 1];

// Grafico a barre (una serie) con tooltip al passaggio/tocco.
function barChart(rows, { value, tip, fmt = (v) => v, label = (r) => monthLabel(r.month) }) {
  const max = Math.max(...rows.map(value), 0);
  const bars = rows.map((r, k) => {
    const v = value(r);
    const h = max > 0 ? Math.max(v > 0 ? 2 : 0, (v / max) * 100) : 0;
    return `<div class="bar-col" data-k="${k}" tabindex="0"><div class="bar" style="height:${h}%"></div><div class="bar-x">${esc(label(r, k))}</div></div>`;
  }).join('');
  const id = 'c' + Math.random().toString(36).slice(2, 8);
  setTimeout(() => {
    const el = document.getElementById(id);
    if (!el) return;
    const tt = el.querySelector('.chart-tip');
    const show = (col) => {
      const r = rows[+col.dataset.k];
      el.querySelectorAll('.bar-col').forEach((c) => c.classList.toggle('on', c === col));
      tt.innerHTML = tip(r);
      tt.hidden = false;
      const cr = col.getBoundingClientRect(), er = el.getBoundingClientRect();
      const x = Math.min(Math.max(cr.left - er.left + cr.width / 2 - tt.offsetWidth / 2, 0), er.width - tt.offsetWidth);
      tt.style.left = x + 'px';
    };
    el.querySelectorAll('.bar-col').forEach((c) => {
      c.addEventListener('mouseenter', () => show(c));
      c.addEventListener('click', () => show(c));
      c.addEventListener('focus', () => show(c));
    });
    el.addEventListener('mouseleave', () => { tt.hidden = true; el.querySelectorAll('.bar-col').forEach((c) => c.classList.remove('on')); });
  });
  return `<div class="chart card" id="${id}"><div class="chart-max small muted">${fmt(max)}</div><div class="bars">${bars}</div><div class="chart-tip" hidden></div></div>`;
}

// Aggiorna il valore stimato di più carte da CardTrader, con avanzamento.
function bulkRefresh(items, reload) {
  if (!isPremium()) return premiumModal("L'aggiornamento valori in blocco");
  const todo = items.filter((i) => i.status !== 'sold');
  const withCatalog = todo.filter((i) => i.blueprint_id);
  openModal('Aggiorna valori da CardTrader', `
    <p>${withCatalog.length} carte collegate al catalogo su ${todo.length} non vendute.
    ${todo.length - withCatalog.length ? `<br><span class="small muted">Le altre ${todo.length - withCatalog.length} non hanno un collegamento CardTrader: aprile e selezionale dal catalogo.</span>` : ''}</p>
    <div class="field"><label>Valore da usare</label>
      <select id="strategy"><option value="min">Prezzo minimo (più prudente)</option><option value="median">Prezzo mediano (più realistico)</option></select></div>
    <p class="small muted">Usa lingua e condizione di ogni carta. Se non ci sono offerte, il valore resta invariato.</p>
    <button class="btn block" id="go" ${withCatalog.length ? '' : 'disabled'}>Aggiorna ${withCatalog.length} carte</button>
    <div id="progress" hidden>
      <div class="progress"><div></div></div>
      <p class="small muted" id="prog-text"></p>
    </div>
    <div id="result"></div>`, (m) => {
    m.querySelector('#go').addEventListener('click', async (e) => {
      e.target.disabled = true;
      m.querySelector('#strategy').disabled = true;
      m.querySelector('#progress').hidden = false;
      const strategy = m.querySelector('#strategy').value;
      const results = [];
      let done = 0, stop = false;
      const queue = [...withCatalog];
      const bar = m.querySelector('.progress div'), txt = m.querySelector('#prog-text');
      const worker = async () => {
        while (queue.length && !stop) {
          const it = queue.shift();
          try { results.push({ item: it, ...(await api(`items/${it.id}/refresh-price`, { method: 'POST', body: { strategy } })) }); }
          catch (err) {
            results.push({ item: it, error: err.message });
            if (/token|premium|raggiungibile/i.test(err.message)) stop = true; // inutile continuare
          }
          done++;
          bar.style.width = (done / withCatalog.length) * 100 + '%';
          txt.textContent = `${done} / ${withCatalog.length}`;
        }
      };
      await Promise.all([worker(), worker()]);
      const upd = results.filter((r) => r.new != null && !r.skipped && !r.error);
      const before = upd.reduce((s, r) => s + (r.old || 0) * r.item.quantity, 0);
      const after = upd.reduce((s, r) => s + r.new * r.item.quantity, 0);
      const noOffers = results.filter((r) => r.skipped === 'no_offers').length;
      const errors = results.filter((r) => r.error);
      const moves = upd.map((r) => ({ ...r, diff: (r.new - (r.old || 0)) * r.item.quantity })).filter((r) => Math.abs(r.diff) >= 0.01)
        .sort((a, b) => Math.abs(b.diff) - Math.abs(a.diff)).slice(0, 8);
      m.querySelector('#result').innerHTML = `
        <div class="kpis" style="margin-top:12px">
          <div class="kpi"><div class="label">Aggiornate</div><div class="value">${upd.length}</div><div class="small muted">${noOffers} senza offerte${errors.length ? ` · ${errors.length} errori` : ''}</div></div>
          <div class="kpi"><div class="label">Variazione valore</div><div class="value">${signed(after - before)}</div><div class="small muted">${eur(before)} → ${eur(after)}</div></div>
        </div>
        ${errors.length ? `<p class="small neg">${esc(errors[0].error)}</p>` : ''}
        ${moves.length ? `<h2>Variazioni maggiori</h2><div class="card">${moves.map((r) => `<div class="grid2" style="padding:4px 0"><span>${esc(r.item.name)}</span><span>${eur(r.old)} → ${eur(r.new)} ${signed(r.diff)}</span></div>`).join('')}</div>` : ''}
        <button class="btn block secondary" data-close style="margin-top:12px">Chiudi</button>`;
      reload();
    });
  });
}

document.addEventListener('click', (e) => {
  if (e.target.id !== 'resend-verify') return;
  e.preventDefault();
  guarded(null, async () => { await api('auth/resend-verification', { method: 'POST', body: {} }); toast('Link inviato, controlla la posta'); });
});

function openModal(title, html, onMount) {
  modalRoot.innerHTML = `
    <div class="modal-backdrop">
      <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-head"><h3>${esc(title)}</h3><button type="button" data-close aria-label="Chiudi">×</button></div>
        ${html}
      </div>
    </div>`;
  const bd = modalRoot.querySelector('.modal-backdrop');
  bd.addEventListener('click', (e) => { if (e.target === bd || e.target.closest('[data-close]')) closeModal(); });
  onMount && onMount(modalRoot.querySelector('.modal'));
}
const closeModal = () => (modalRoot.innerHTML = '');

function itemRow(i, { showLot = false } = {}) {
  const sub = [i.set_name, i.card_number, i.language?.toUpperCase(), i.card_condition].filter(Boolean).join(' · ');
  const qty = i.quantity > 1 ? ` <span class="muted">×${i.quantity}</span>` : '';
  const right = i.status === 'sold'
    ? `<div class="big pos">${eur(i.sale_price)}</div><div class="small muted">${fmtDate(i.sale_date)}</div>`
    : `<div class="big">${eur(i.est_value)}</div>${i.status === 'listed' ? '<span class="badge listed">In vendita</span>' : ''}`;
  return `
    <div class="row" data-item="${i.id}">
      ${thumbOf(i) ? `<img class="thumb" loading="lazy" src="${esc(thumbOf(i))}" alt="">` : '<div class="thumb"></div>'}
      <div class="body">
        <div class="title">${esc(i.name)}${qty} ${gradeBadge(i)}</div>
        <div class="sub">${esc(sub || '—')}</div>
        ${showLot && i.lot_title ? `<div class="sub">📦 ${esc(i.lot_title)}</div>` : ''}
      </div>
      <div class="right">${right}</div>
    </div>`;
}

function bindItemRows(root, items, reload) {
  root.querySelectorAll('[data-item]').forEach((el) => {
    el.addEventListener('click', () => {
      const item = items.find((x) => x.id === +el.dataset.item);
      itemDetail(item, reload);
    });
  });
}

// ---------- auth ----------
function renderAuth(mode = 'login') {
  modalRoot.innerHTML = '';
  app.innerHTML = `
    <div class="auth">
      <div class="brand"><div class="logo-dot"></div><h1>CardLedger</h1>
        <p class="muted">Lotti, carte, vendite e profitti in un posto solo.</p>
        <p class="small muted">Gratis per sempre · Premium facoltativo, 14 giorni di prova inclusi.</p></div>
      <div class="tabs">
        <button data-mode="login" class="${mode === 'login' ? 'active' : ''}">Accedi</button>
        <button data-mode="register" class="${mode === 'register' ? 'active' : ''}">Registrati</button>
      </div>
      <form id="auth-form" class="card">
        ${mode === 'register' ? `<div class="field"><label>Nome</label><input name="name" required autocomplete="name"></div>` : ''}
        <div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email"></div>
        <div class="field"><label>Password</label><input name="password" type="password" required minlength="${mode === 'register' ? 8 : 1}" autocomplete="${mode === 'register' ? 'new-password' : 'current-password'}"></div>
        ${mode === 'register' && refCode() ? `<p class="small" style="margin-top:0">🎁 Sei stato invitato: <strong>30 giorni di Premium</strong> gratis.</p><input type="hidden" name="ref" value="${esc(refCode())}">` : ''}
        ${mode === 'register' ? `<div class="field"><label>Codice negozio del socio (opzionale)</label><input name="invite_code" autocapitalize="characters"></div>
        <label class="check"><input type="checkbox" name="accept_terms" value="1" required> Accetto i <a href="/termini.html" target="_blank">termini d'uso</a> e l'<a href="/privacy.html" target="_blank">informativa privacy</a></label>` : ''}
        <button class="btn block">${mode === 'login' ? 'Accedi' : 'Crea account'}</button>
        ${mode === 'login' ? '<p style="text-align:center;margin:14px 0 0"><a href="#" id="forgot">Password dimenticata?</a></p>' : ''}
      </form>
      <p class="small muted" style="text-align:center"><a href="/">Cos'è ${'CardLedger'}?</a> · <a href="/privacy.html">Privacy</a> · <a href="/termini.html">Termini</a></p>
    </div>`;
  app.querySelector('#forgot')?.addEventListener('click', (e) => { e.preventDefault(); renderForgot(); });
  app.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => renderAuth(b.dataset.mode)));
  const form = document.getElementById('auth-form');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    guarded(form.querySelector('button'), async () => {
      state.me = await api(mode === 'login' ? 'auth/login' : 'auth/register', { method: 'POST', body: formData(form) });
      if (!location.hash || location.hash.startsWith('#reset') || location.hash.startsWith('#verify')) location.hash = '#dashboard';
      router();
    });
  });
}

function refCode() {
  const r = new URLSearchParams(location.search).get('ref');
  return r && /^[A-Za-z0-9]{4,16}$/.test(r) ? r.toUpperCase() : '';
}

function authShell(title, inner) {
  modalRoot.innerHTML = '';
  app.innerHTML = `<div class="auth"><div class="brand"><div class="logo-dot"></div><h1>${esc(title)}</h1></div>${inner}
    <p style="text-align:center"><a href="#" id="to-login">← Torna all'accesso</a></p></div>`;
  app.querySelector('#to-login').addEventListener('click', (e) => { e.preventDefault(); history.replaceState(null, '', '#'); state.me ? router() : renderAuth(); });
}

function renderForgot() {
  authShell('Password dimenticata', `
    <form class="card" id="f">
      <p class="small muted" style="margin-top:0">Ti mandiamo un link per scegliere una nuova password.</p>
      <div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email"></div>
      <button class="btn block">Invia link</button>
    </form>`);
  const f = app.querySelector('#f');
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    guarded(f.querySelector('button'), async () => {
      await api('auth/forgot', { method: 'POST', body: formData(f) });
      f.innerHTML = '<p style="margin:0">Se l\'email è registrata, riceverai a breve il link (controlla anche lo spam). Il link vale 1 ora.</p>';
    });
  });
}

function renderReset(token) {
  authShell('Nuova password', `
    <form class="card" id="f">
      <div class="field"><label>Nuova password (min. 8 caratteri)</label><input name="password" type="password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn block">Salva e accedi</button>
    </form>`);
  const f = app.querySelector('#f');
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    guarded(f.querySelector('button'), async () => {
      state.me = await api('auth/reset', { method: 'POST', body: { ...formData(f), token } });
      toast('Password aggiornata');
      location.hash = '#dashboard';
    });
  });
}

async function renderVerify(token) {
  authShell('Conferma email', '<div class="card" id="v">Verifica in corso…</div>');
  const box = app.querySelector('#v');
  try {
    await api('auth/verify', { method: 'POST', body: { token } });
    box.innerHTML = '✅ Email confermata, grazie!';
    if (state.me) { state.me.user.email_verified_at = new Date().toISOString(); setTimeout(() => (location.hash = '#dashboard'), 1200); }
  } catch (e) {
    box.textContent = e.message;
  }
}

function verifyBanner() {
  const u = state.me?.user;
  if (!u || u.email_verified_at) return '';
  return `<div class="banner info">📧 Conferma la tua email (${esc(u.email)}). <a href="#" id="resend-verify">Rinvia link</a></div>`;
}

function offlineBanner() {
  const n = readQueue().length;
  if (navigator.onLine && !n) return '';
  return `<div class="banner ${navigator.onLine ? 'info' : 'bad'}" id="offline-banner">${navigator.onLine ? `⏳ Salvataggio di ${n} carte create offline…` : `📴 Sei offline: vedi gli ultimi dati salvati e puoi aggiungere carte${n ? ` (${n} in attesa)` : ''}.`}</div>`;
}
function refreshOfflineBanner() {
  const old = document.getElementById('offline-banner');
  const html = offlineBanner();
  if (old) old.outerHTML = html || '';
  else if (html) document.querySelector('.topbar')?.insertAdjacentHTML('afterend', html);
}
async function flushQueue() {
  const q = readQueue();
  if (!q.length || !navigator.onLine || !state.me) return;
  let ok = 0;
  const rest = [];
  for (const job of q) {
    try { await api('items', { method: 'POST', body: job.body }); ok++; } catch { rest.push(job); }
  }
  writeQueue(rest);
  refreshOfflineBanner();
  if (ok) { toast(`${ok} carte create offline salvate ✓`); api('events', { method: 'POST', body: { event: 'offline_sync' } }).catch(() => {}); router(); }
}
window.addEventListener('online', () => { refreshOfflineBanner(); flushQueue(); });
window.addEventListener('offline', refreshOfflineBanner);

// ---------- dashboard ----------
async function viewDashboard() {
  state.me = await api('me').catch(() => state.me);
  shell(state.me.workspace.name, '<div class="empty">Caricamento…</div>', { fab: '+ Lotto' });
  document.getElementById('fab').onclick = () => lotForm();
  const d = await api('dashboard');
  const main = app.querySelector('main');
  main.innerHTML = `
    <div class="kpis">
      <div class="kpi wide"><div class="label">Profitto potenziale (incassato + magazzino − speso)</div><div class="value">${signed(d.potential)}</div></div>
      <div class="kpi"><div class="label">Speso</div><div class="value">${eur(d.spent)}</div></div>
      <div class="kpi"><div class="label">Incassato netto</div><div class="value">${eur(d.revenue)}</div></div>
      <div class="kpi"><div class="label">Valore magazzino</div><div class="value">${eur(d.stock_value)}</div><div class="small muted">${d.cards_in_stock} carte · ${d.cards_listed} in vendita</div></div>
      <div class="kpi"><div class="label">Saldo cassa</div><div class="value">${signed(d.balance)}</div><div class="small muted">${d.cards_sold} vendute</div></div>
    </div>
    <div id="notif"></div>
    <h2>Valore del magazzino</h2>
    <div id="value-hist">${isPremium() ? '<div class="small muted">Caricamento…</div>' : '<div class="card locked" id="lock-value">📊 Andamento giornaliero del valore del magazzino<br><span class="small muted">Funzione Premium · tocca per scoprire</span></div>'}</div>
    <h2>Incassi netti per mese</h2>
    ${!d.months ? '<div class="card locked" id="lock-chart">📈 Andamento mensile di incassi e spese<br><span class="small muted">Funzione Premium · tocca per scoprire</span></div>' : barChart(d.months, { value: (r) => r.revenue, fmt: eur,
      tip: (r) => `<strong>${monthLabel(r.month)} ${r.month.slice(0, 4)}</strong><br>Incassato ${eur(r.revenue)}<br>Speso ${eur(r.spent)}<br>Saldo ${signed(r.profit)}` })}
    <h2>Lotti migliori</h2>
    <div class="list">${d.best_lots.length ? d.best_lots.map(lotRow).join('') : '<div class="empty">Nessun lotto. Crea il primo con “+ Lotto”.</div>'}</div>
    <h2>Carte più preziose in magazzino</h2>
    <div class="list" id="top">${d.top_items.length ? d.top_items.map((i) => itemRow(i)).join('') : '<div class="empty">Ancora nessuna carta.</div>'}</div>
    <h2>Ultime vendite</h2>
    <div class="list" id="recent">${d.recent_sales.length ? d.recent_sales.map((i) => itemRow(i)).join('') : '<div class="empty">Ancora nessuna vendita.</div>'}</div>`;
  main.querySelector('#lock-chart')?.addEventListener('click', () => premiumModal("L'andamento mensile"));
  main.querySelector('#lock-value')?.addEventListener('click', () => premiumModal("L'andamento del valore"));
  bindItemRows(main.querySelector('#top'), d.top_items, viewDashboard);
  bindItemRows(main.querySelector('#recent'), d.recent_sales, viewDashboard);
  api('notifications').then((n) => {
    if (!n.length || !main.isConnected) return;
    main.querySelector('#notif').innerHTML = `<h2>🔔 Avvisi di prezzo</h2><div class="card">${n.slice(0, 5).map((x) =>
      `<div class="notif"><span>${esc(x.message)}</span><span class="small muted">${shortDate(x.created_at)}</span></div>`).join('')}</div>`;
  }).catch(() => {});
  if (isPremium()) {
    api('stats/value-history?days=60').then((h) => {
      const box = main.querySelector('#value-hist');
      if (!box) return;
      if (h.length < 2) { box.innerHTML = '<p class="small muted card">Il grafico si riempie giorno dopo giorno: il valore del magazzino viene registrato ogni notte.</p>'; return; }
      const step = Math.ceil(h.length / 6);
      box.innerHTML = barChart(h, { value: (r) => r.stock_value, fmt: eur, label: (r, k) => (k % step === 0 ? shortDate(r.day) : ''),
        tip: (r) => `<strong>${fmtDate(r.day)}</strong><br>Magazzino ${eur(r.stock_value)} (${r.cards} carte)<br>Incassato totale ${eur(r.revenue_total)}<br>Speso totale ${eur(r.spent_total)}` });
    }).catch(() => {});
  }
}

// ---------- lotti ----------
function lotRow(l) {
  return `
    <a class="row" href="#lot/${l.id}">
      <div class="body">
        <div class="title">${esc(l.title)}</div>
        <div class="sub">${[fmtDate(l.purchase_date), l.source, `${l.cards} carte`].filter(Boolean).map(esc).join(' · ')}</div>
        <div class="sub">Pagato ${eur(l.cost)} → trovato <strong>${eur(l.found_value)}</strong></div>
      </div>
      <div class="right">${roiBadge(l.roi)}<div class="small" style="margin-top:4px">${signed(l.profit)}</div><div class="small muted">incassato</div></div>
    </a>`;
}

async function viewLots() {
  shell('Lotti', '<div class="empty">Caricamento…</div>', { fab: '+ Lotto' });
  document.getElementById('fab').onclick = () => lotForm();
  state.lots = await api('lots');
  app.querySelector('main').innerHTML = state.lots.length
    ? `<div class="actions" style="margin:0 0 10px"><a class="btn secondary sm" href="#sealed">🎁 Bustine/ETB: sbusto o rivendo?</a></div><div class="list">${state.lots.map(lotRow).join('')}</div>`
    : '<div class="empty">Nessun lotto ancora.<br>Un lotto è un acquisto in blocco (es. 100 carte a 10 €).</div>';
}

function lotForm(lot = null) {
  const l = lot || { purchase_date: today() };
  openModal(lot ? 'Modifica lotto' : 'Nuovo lotto', `
    <form id="lot-form">
      <div class="field"><label>Nome lotto *</label><input name="title" required value="${esc(l.title)}" placeholder="Es. Lotto 100 carte mercatino"></div>
      <div class="grid2">
        <div class="field"><label>Prezzo pagato (€)</label><input name="cost" inputmode="decimal" value="${esc(l.cost ?? '')}" placeholder="10"></div>
        <div class="field"><label>Data acquisto</label><input name="purchase_date" type="date" value="${esc(l.purchase_date ?? '')}"></div>
      </div>
      <div class="field"><label>Dove / da chi</label><input name="source" value="${esc(l.source)}" placeholder="Vinted, mercatino, privato…"></div>
      <div class="field"><label>Note</label><textarea name="notes">${esc(l.notes)}</textarea></div>
      <button class="btn block">${lot ? 'Salva' : 'Crea lotto'}</button>
      ${lot ? '<div class="actions"><button type="button" class="btn danger block" id="del-lot">Elimina lotto e le sue carte</button></div>' : ''}
    </form>`, (m) => {
    const form = m.querySelector('form');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      guarded(form.querySelector('button'), async () => {
        const saved = await api(lot ? `lots/${lot.id}` : 'lots', { method: lot ? 'PUT' : 'POST', body: formData(form) });
        closeModal();
        toast('Lotto salvato');
        if (lot) router(); else location.hash = `#lot/${saved.id}`;
      });
    });
    m.querySelector('#del-lot')?.addEventListener('click', (e) => guarded(e.target, async () => {
      if (!confirm('Eliminare il lotto e tutte le sue carte?')) return;
      await api(`lots/${lot.id}`, { method: 'DELETE' });
      closeModal();
      location.hash = '#lots';
    }));
  });
}

async function viewLot(id) {
  shell('Lotto', '<div class="empty">Caricamento…</div>', { back: '#lots', fab: '+ Carta' });
  const l = await api(`lots/${id}`);
  const reload = () => viewLot(id);
  document.getElementById('fab').onclick = () => itemForm(null, { lot_id: l.id }, reload);
  app.querySelector('.topbar h1').textContent = l.title;
  const main = app.querySelector('main');
  main.innerHTML = `
    <div class="kpis">
      <div class="kpi"><div class="label">Pagato</div><div class="value">${eur(l.cost)}</div></div>
      <div class="kpi"><div class="label">Valore trovato</div><div class="value">${eur(l.found_value)}</div><div class="small">${roiBadge(l.roi)}</div></div>
      <div class="kpi"><div class="label">Incassato</div><div class="value">${eur(l.revenue)}</div><div class="small muted">${l.cards_sold}/${l.cards} vendute</div></div>
      <div class="kpi"><div class="label">Profitto realizzato</div><div class="value">${signed(l.profit)}</div></div>
    </div>
    <p class="muted small">${[fmtDate(l.purchase_date), l.source].filter(Boolean).map(esc).join(' · ')}${l.notes ? '<br>' + esc(l.notes) : ''}</p>
    <div class="actions"><button class="btn secondary sm" id="edit-lot">Modifica lotto</button><button class="btn secondary sm" id="bulk-lot">↻ Aggiorna valori</button>${state.me.members.length > 1 ? '<button class="btn secondary sm" id="shares">👥 Quote soci</button>' : ''}</div>
    <h2>Carte (${l.cards})</h2>
    <div class="list" id="items">${l.items.length ? l.items.map((i) => itemRow(i)).join('') : '<div class="empty">Aggiungi le carte trovate nel lotto con “+ Carta”.<br>Basta segnare quelle che valgono qualcosa.</div>'}</div>`;
  main.querySelector('#edit-lot').onclick = () => lotForm(l);
  main.querySelector('#bulk-lot').onclick = () => bulkRefresh(l.items, reload);
  main.querySelector('#shares')?.addEventListener('click', () => sharesForm(l));
  bindItemRows(main.querySelector('#items'), l.items, reload);
}

function sharesForm(l) {
  if (!isPremium()) return premiumModal('La divisione profitti tra soci');
  guarded(null, async () => {
    const d = await api(`lots/${l.id}/shares`);
    openModal('Quote soci: ' + l.title, `
      <form>
        <p class="small muted" style="margin-top:0">Quanto ha messo ciascuno in questo lotto. Costi, incassi e valore del lotto vengono divisi con queste percentuali.${d.custom ? '' : ' Ora: parti uguali.'}</p>
        ${d.members.map((m) => `<div class="field"><label>${esc(m.name)} (%)</label><input name="${m.id}" inputmode="decimal" value="${Math.round((d.shares[m.id] || 0) * 100) / 100}"></div>`).join('')}
        <p class="small" id="sum"></p>
        <button class="btn block">Salva quote</button>
        <button type="button" class="btn secondary block" id="equal" style="margin-top:8px">Parti uguali</button>
      </form>`, (m) => {
      const f = m.querySelector('form');
      const sum = () => { const t = [...f.querySelectorAll('input')].reduce((s, x) => s + (parseFloat(x.value.replace(',', '.')) || 0), 0); m.querySelector('#sum').innerHTML = `Totale: <strong class="${Math.abs(t - 100) < 0.6 ? 'pos' : 'neg'}">${Math.round(t * 10) / 10}%</strong>`; };
      f.addEventListener('input', sum); sum();
      f.addEventListener('submit', (e) => { e.preventDefault(); guarded(f.querySelector('button'), async () => { await api(`lots/${l.id}/shares`, { method: 'PUT', body: { shares: formData(f) } }); closeModal(); toast('Quote salvate'); }); });
      m.querySelector('#equal').addEventListener('click', (e) => guarded(e.target, async () => { await api(`lots/${l.id}/shares`, { method: 'PUT', body: { shares: {} } }); closeModal(); toast('Parti uguali'); }));
    });
  });
}

// ---------- carte ----------
let itemsFilter = { status: 'unsold', q: '' };

async function viewItems() {
  shell('Carte', `
    <input class="search" type="search" id="q" placeholder="Cerca per nome, set o numero" value="${esc(itemsFilter.q)}">
    <div class="chips">
      ${[['unsold', 'Disponibili'], ['in_stock', 'In magazzino'], ['listed', 'In vendita'], ['sold', 'Vendute'], ['', 'Tutte']]
        .map(([v, t]) => `<button class="chip ${itemsFilter.status === v ? 'active' : ''}" data-status="${v}">${t}</button>`).join('')}
    </div>
    <div class="actions" style="margin:0 0 10px"><button class="btn secondary sm" id="bulk">↻ Aggiorna valori da CardTrader</button><button class="btn secondary sm" id="import">⬆️ Importa CSV</button><a class="btn secondary sm" href="#sealed">🎁 Sbusto o rivendo?</a></div>
    <div id="items-list" class="list"><div class="empty">Caricamento…</div></div>`, { fab: '+ Carta' });
  document.getElementById('import').onclick = () => importForm(loadItems);
  document.getElementById('bulk').onclick = async () => isPremium() ? bulkRefresh(await api('items?status=unsold'), loadItems) : premiumModal("L'aggiornamento valori in blocco");
  document.getElementById('fab').onclick = () => itemForm(null, {}, loadItems);
  app.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', () => { itemsFilter.status = b.dataset.status; viewItems(); }));
  let t;
  document.getElementById('q').addEventListener('input', (e) => {
    clearTimeout(t);
    t = setTimeout(() => { itemsFilter.q = e.target.value; loadItems(); }, 250);
  });
  await loadItems();
}

async function loadItems() {
  const qs = new URLSearchParams({ status: itemsFilter.status, q: itemsFilter.q });
  const items = await api('items?' + qs);
  const list = document.getElementById('items-list');
  if (!list) return;
  const total = items.reduce((s, i) => s + (i.status === 'sold' ? (i.sale_price || 0) : (i.est_value || 0) * i.quantity), 0);
  const count = items.reduce((s, i) => s + i.quantity, 0);
  list.innerHTML = items.length
    ? `<div class="small muted">${count} carte · ${eur(total)}</div>` + items.map((i) => itemRow(i, { showLot: true })).join('')
    : '<div class="empty">Nessuna carta trovata.</div>';
  bindItemRows(list, items, loadItems);
}

async function importForm(reload) {
  if (!state.lots.length) state.lots = await api('lots').catch(() => []);
  openModal('Importa carte da CSV/Excel', `
    <p class="small muted" style="margin-top:0">Salva il foglio Excel come <strong>CSV</strong>. Prima riga = intestazioni. Colonne riconosciute:
      <em>nome</em> (obbligatoria), set, numero, lingua, condizione, quantità, valore, costo, stato, prezzo vendita, data vendita, canale, lotto, note.
      Va bene anche il file esportato da qui.</p>
    <div class="field"><label>File CSV</label><input type="file" accept=".csv,text/csv,text/plain" id="csv"></div>
    <div class="field"><label>Metti tutte le carte nel lotto</label><select id="lot"><option value="">— Usa la colonna “lotto” o carte singole —</option>${state.lots.map((l) => `<option value="${l.id}">${esc(l.title)}</option>`).join('')}</select></div>
    <button class="btn block" id="go">Importa</button>
    <div id="res"></div>`, (m) => {
    m.querySelector('#go').addEventListener('click', (e) => guarded(e.target, async () => {
      const file = m.querySelector('#csv').files[0];
      if (!file) throw new Error('Scegli un file');
      if (file.size > 2e6) throw new Error('File troppo grande (max 2 MB)');
      let text = await file.text();
      if (text.includes('�')) text = new TextDecoder('windows-1252').decode(await file.arrayBuffer()); // CSV di Excel italiano
      const r = await api('items/import', { method: 'POST', body: { csv: text, lot_id: m.querySelector('#lot').value || null } });
      state.lots = [];
      m.querySelector('#res').innerHTML = `<div class="card" style="margin-top:12px"><strong>${r.imported} carte importate</strong>${r.lots_created ? `, ${r.lots_created} lotti creati` : ''}.
        ${r.error_count ? `<p class="small neg">${r.error_count} righe saltate:</p><ul class="small">${r.errors.map((x) => `<li>Riga ${x.line}: ${esc(x.error)}</li>`).join('')}</ul>` : ''}</div>`;
      reload();
    }));
  });
}

function itemDetail(i, reload) {
  const rows = [
    ['Set', i.set_name], ['Numero', i.card_number], ['Lingua', i.language?.toUpperCase()], ['Condizione', i.card_condition],
    ['Gradazione', i.grading_company ? `${i.grading_company} ${i.grade || ''}` : null],
    ['Quantità', i.quantity], ['Valore stimato', i.est_value != null ? eur(i.est_value) + (i.quantity > 1 ? ' cad.' : '') + (i.est_value_updated_at ? ` <span class="small muted">(CardTrader ${esc(fmtDate(i.est_value_updated_at.slice(0, 10)))})</span>` : '') : null],
    ['Avvisami', [i.alert_above != null ? `sopra ${eur(i.alert_above)}` : '', i.alert_below != null ? `sotto ${eur(i.alert_below)}` : ''].filter(Boolean).join(' · ') || null],
    ['Costo acquisto', i.purchase_cost != null ? eur(i.purchase_cost) : null], ['Stato', STATUS_LABEL[i.status]],
    ['Venduta a', i.sale_price != null ? eur(i.sale_price) : null], ['Commissioni/spedizione', i.sale_fees != null ? eur(i.sale_fees) : null],
    ['Data vendita', fmtDate(i.sale_date)], ['Canale', i.sale_channel], ['Note', i.notes],
  ].filter(([, v]) => v != null && v !== '');
  const html = new Set(['Valore stimato', 'Costo acquisto', 'Venduta a', 'Commissioni/spedizione', 'Avvisami']);
  openModal(i.name, `
    ${i.image_url ? `<img class="hero-img" src="${esc(i.image_url)}" alt="">` : ''}
    <div class="card">${rows.map(([k, v]) => `<div class="grid2" style="padding:4px 0"><span class="muted">${k}</span><span>${html.has(k) ? v : esc(v)}</span></div>`).join('')}</div>
    <h2>Le tue foto</h2>
    <div class="photos">
      ${i.photos.map((n) => `<div class="photo"><a href="${photoUrl(i, n)}" target="_blank"><img src="${photoUrl(i, n)}" alt=""></a><button type="button" data-del="${esc(n)}" aria-label="Elimina foto">×</button></div>`).join('')}
      ${i.photos.length < 4 ? '<label class="photo add">📷<span>Aggiungi</span><input type="file" accept="image/*" capture="environment" hidden id="photo-in"></label>' : ''}
    </div>
    <h2>Andamento prezzo</h2>
    <div id="hist">${isPremium() ? '<div class="small muted">Caricamento…</div>' : '<div class="card locked" id="lock-hist">📈 Storico del valore di questa carta<br><span class="small muted">Funzione Premium · tocca per scoprire</span></div>'}</div>
    <div class="actions">
      ${i.status !== 'sold' ? '<button class="btn" id="sell">💶 Segna venduta</button>' : ''}
      ${i.status === 'in_stock' ? '<button class="btn secondary" id="list">Metti in vendita</button>' : ''}
      ${i.status !== 'sold' ? '<button class="btn secondary" id="listing">📝 Testo annuncio</button>' : ''}
      ${i.status !== 'sold' && i.blueprint_id && isSealedName(i.name) ? '<button class="btn secondary" id="sealed">🎁 Sbusto o rivendo?</button>' : ''}
      <button class="btn secondary" id="edit">Modifica</button>
    </div>`, async (m) => {
    m.querySelector('#sell')?.addEventListener('click', () => sellForm(i, reload));
    m.querySelector('#edit').addEventListener('click', () => itemForm(i, {}, reload));
    m.querySelector('#listing')?.addEventListener('click', () => listingText(i));
    m.querySelector('#sealed')?.addEventListener('click', () => {
      sealedState.paid = i.purchase_cost != null ? String(i.purchase_cost * i.quantity) : '';
      if (i.language) sealedState.lang = i.language;
      closeModal();
      location.hash = `#sealed/${i.blueprint_id}`;
    });
    m.querySelector('#lock-hist')?.addEventListener('click', () => premiumModal('Lo storico prezzi'));
    m.querySelector('#list')?.addEventListener('click', (e) => guarded(e.target, async () => {
      await api(`items/${i.id}`, { method: 'PUT', body: { status: 'listed' } });
      closeModal(); toast('Messa in vendita'); reload();
    }));
    const reopen = (updated) => { reload(); itemDetail(updated, reload); };
    m.querySelector('#photo-in')?.addEventListener('change', (e) => guarded(null, async () => {
      const file = e.target.files[0];
      if (!file) return;
      toast('Caricamento foto…');
      reopen(await api(`items/${i.id}/photos`, { method: 'POST', body: { data: await resizeImage(file) } }));
    }));
    m.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => guarded(b, async () => {
      if (!confirm('Eliminare questa foto?')) return;
      reopen(await api(`items/${i.id}/photos/${encodeURIComponent(b.dataset.del)}`, { method: 'DELETE', body: {} }));
    })));
    if (isPremium()) {
      const box = m.querySelector('#hist');
      try {
        const h = await api(`items/${i.id}/history`);
        if (h.length < 2) { box.innerHTML = '<p class="small muted">Lo storico si riempie con gli aggiornamenti dei prezzi (anche quello automatico notturno).</p>'; return; }
        const first = h[0].price, last = h[h.length - 1].price;
        box.innerHTML = `<p class="small" style="margin:0 0 6px">${eur(first)} → ${eur(last)} ${signed(last - first)} da ${fmtDate(h[0].at.slice(0, 10))}</p>`
          + barChart(h.slice(-24), { value: (r) => r.price, fmt: eur, label: (r, k) => (k % 4 === 0 ? shortDate(r.at) : ''),
            tip: (r) => `<strong>${fmtDate(r.at.slice(0, 10))}</strong><br>${eur(r.price)}<br><span class="small">${r.source === 'cardtrader' ? 'CardTrader' : 'Inserito a mano'}</span>` });
      } catch (err) { box.innerHTML = `<p class="small muted">${esc(err.message)}</p>`; }
    }
  });
}

// ---------- scansione carta (OCR nel browser, sperimentale) ----------
let tesseractLoading = null;
function loadTesseract() {
  if (window.Tesseract) return Promise.resolve(window.Tesseract);
  tesseractLoading ||= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
    s.onload = () => resolve(window.Tesseract);
    s.onerror = () => { tesseractLoading = null; reject(new Error('Scansione non disponibile: serve la connessione internet')); };
    document.head.appendChild(s);
  });
  return tesseractLoading;
}

// Ritaglia una fascia della foto (valori in % dell'altezza/larghezza).
function cropCanvas(img, x0, y0, x1, y1) {
  const c = document.createElement('canvas');
  const w = img.width * (x1 - x0), h = img.height * (y1 - y0);
  const k = Math.min(2, 1400 / w);
  c.width = Math.round(w * k); c.height = Math.round(h * k);
  const g = c.getContext('2d');
  g.filter = 'grayscale(1) contrast(1.4)';
  g.drawImage(img, img.width * x0, img.height * y0, w, h, 0, 0, c.width, c.height);
  return c;
}

// Legge nome (in alto) e numero (in basso, es. 63/113) da una foto della carta.
async function scanCard(file, progress) {
  api('events', { method: 'POST', body: { event: 'scan' } }).catch(() => {});
  progress('Carico il motore di lettura (solo la prima volta)…');
  const T = await loadTesseract();
  const img = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = () => rej(new Error('Foto non leggibile')); im.src = URL.createObjectURL(file); });
  progress('Leggo la carta…');
  const worker = await T.createWorker('eng');
  try {
    const top = (await worker.recognize(cropCanvas(img, 0.04, 0.02, 0.78, 0.12))).data.text;
    const bottom = (await worker.recognize(cropCanvas(img, 0, 0.84, 1, 0.99))).data.text;
    const r = parseScan(top, bottom);
    if (r.name || r.number) api('events', { method: 'POST', body: { event: 'scan_ok' } }).catch(() => {});
    return r;
  } finally {
    worker.terminate();
    URL.revokeObjectURL(img.src);
  }
}

function parseScan(top, bottom) {
  const num = (bottom.replace(/[Oo]/g, '0').match(/(\d{1,3})\s*\/\s*(\d{1,3})/) || [])[0]?.replace(/\s/g, '') || '';
  const skip = /^(basic|base|stage|stadio|level|livello|hp|ps|v|vmax|vstar|ex|gx|trainer|allenatore|energy|energia|pokemon|pokémon|evolves|evolve)$/i;
  const name = (top.split('\n').map((l) => l.replace(/[^A-Za-zÀ-ÿ'\- ]/g, ' ').trim()).find((l) => l.length >= 3) || '')
    .split(/\s+/).filter((w) => w.length > 1 && !skip.test(w)).slice(0, 3).join(' ');
  return { name, number: num };
}

// Ridimensiona la foto nel browser (max 1600px, JPEG) prima dell'invio.
function resizeImage(file, max = 1600) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * k);
      c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(img.src);
      resolve(c.toDataURL('image/jpeg', 0.85));
    };
    img.onerror = () => reject(new Error('Immagine non leggibile'));
    img.src = URL.createObjectURL(file);
  });
}

// Testo pronto da incollare su Vinted, Cardmarket, Subito…
function listingText(i) {
  api('events', { method: 'POST', body: { event: 'listing_text' } }).catch(() => {});
  const lang = { it: 'ITA', en: 'ENG', jp: 'JAP', fr: 'FRA', de: 'GER', es: 'ESP', pt: 'POR', kr: 'KOR', zh: 'CHI' }[i.language] || '';
  const price = i.est_value != null ? Math.ceil(i.est_value * i.quantity) : null;
  const title = [`Carta Pokémon ${i.name}`, i.card_number, i.set_name, lang, i.grading_company ? `${i.grading_company} ${i.grade || ''}`.trim() : i.card_condition].filter(Boolean).join(' - ');
  const body = [
    `${i.quantity > 1 ? i.quantity + 'x ' : ''}${i.name}${i.card_number ? ' ' + i.card_number : ''}${i.set_name ? ' (' + i.set_name + ')' : ''}`,
    lang ? `Lingua: ${lang}` : '',
    i.grading_company ? `Gradata ${i.grading_company} ${i.grade || ''}`.trim() : (i.card_condition ? `Condizione: ${i.card_condition}` : ''),
    'Carta originale, foto reali.',
    'Spedizione tracciata e protetta (sleeve + toploader + busta imbottita).',
    price ? `Prezzo: ${price} €` : '',
  ].filter(Boolean).join('\n');
  openModal('Testo annuncio', `
    <div class="field"><label>Titolo</label><input id="lt" value="${esc(title)}"></div>
    <div class="field"><label>Descrizione</label><textarea id="lb" rows="8">${esc(body)}</textarea></div>
    <div class="actions"><button class="btn" id="copy">📋 Copia tutto</button></div>`, (m) => {
    m.querySelector('#copy').addEventListener('click', async () => {
      const text = m.querySelector('#lt').value + '\n\n' + m.querySelector('#lb').value;
      try { await navigator.clipboard.writeText(text); toast('Copiato'); }
      catch { m.querySelector('#lb').select(); toast('Seleziona e copia il testo', true); }
    });
  });
}

function sellForm(i, reload) {
  const suggested = i.est_value != null ? (i.est_value * i.quantity).toFixed(2) : '';
  openModal('Vendi: ' + i.name, `
    <form>
      <div class="grid2">
        <div class="field"><label>Prezzo incassato (€) *</label><input name="sale_price" inputmode="decimal" required value="${suggested}"></div>
        <div class="field"><label>Commissioni + spedizione (€)</label><input name="sale_fees" inputmode="decimal" placeholder="0"></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Data</label><input name="sale_date" type="date" value="${today()}"></div>
        <div class="field"><label>Canale</label><select name="sale_channel"><option value=""></option>${CHANNELS.map((c) => `<option>${c}</option>`).join('')}</select></div>
      </div>
      ${i.quantity > 1 ? `<p class="small muted">Il prezzo è il totale per tutte le ${i.quantity} copie.</p>` : ''}
      <button class="btn block">Conferma vendita</button>
    </form>`, (m) => {
    const form = m.querySelector('form');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      guarded(form.querySelector('button'), async () => {
        await api(`items/${i.id}`, { method: 'PUT', body: { ...formData(form), status: 'sold' } });
        closeModal(); toast('Vendita registrata 🎉'); reload();
      });
    });
  });
}

async function itemForm(item, defaults, reload) {
  const i = item || { quantity: 1, status: 'in_stock', language: 'it', card_condition: 'Near Mint', ...defaults };
  if (!state.lots.length) state.lots = await api('lots').catch(() => []);
  const opt = (list, cur, labels) => list.map((v) => `<option value="${esc(v)}" ${String(cur ?? '') === String(v) ? 'selected' : ''}>${esc(labels ? labels[v] : v)}</option>`).join('');
  openModal(item ? 'Modifica carta' : 'Aggiungi carta', `
    <form>
      <div class="field"><label>Gioco</label><select name="game">${Object.entries(state.me.games || { pokemon: 'Pokémon' }).map(([k, v]) => `<option value="${k}" ${(i.game || 'pokemon') === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
      <div class="field"><label>Cerca nel catalogo (es. “ditto 63/113”) o incolla il link CardTrader</label>
        <div class="search-row"><input id="cat-q" type="search" placeholder="Cerca…" autocomplete="off"><label class="btn secondary scan-btn" title="Scansiona la carta">📷<input type="file" accept="image/*" capture="environment" hidden id="scan-in"></label></div>
        <div id="scan-out" class="small muted"></div><div id="cat-res"></div></div>
      <input type="hidden" name="blueprint_id" value="${esc(i.blueprint_id ?? '')}">
      <input type="hidden" name="image_url" value="${esc(i.image_url ?? '')}">
      <img id="img-preview" class="hero-img" alt="" ${i.image_url ? `src="${esc(i.image_url)}"` : 'hidden'}>
      <div class="field"><label>Nome carta *</label><input name="name" required value="${esc(i.name)}"></div>
      <div class="grid2">
        <div class="field"><label>Set / espansione</label><input name="set_name" value="${esc(i.set_name)}"></div>
        <div class="field"><label>Numero</label><input name="card_number" value="${esc(i.card_number)}" placeholder="25/102"></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Lingua</label><select name="language"><option value=""></option>${opt(LANGS, i.language, Object.fromEntries(LANGS.map((l) => [l, l.toUpperCase()])))}</select></div>
        <div class="field"><label>Condizione</label><select name="card_condition"><option value=""></option>${opt(CONDITIONS, i.card_condition)}</select></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Valore stimato cad. (€)</label><input name="est_value" inputmode="decimal" value="${esc(i.est_value ?? '')}"></div>
        <div class="field"><label>Quantità</label><input name="quantity" type="number" min="1" value="${esc(i.quantity)}"></div>
      </div>
      <div class="actions" style="margin-top:-4px;margin-bottom:12px"><button type="button" class="btn secondary sm" id="price">🔎 Prezzo CardTrader</button><span id="price-out" class="small muted" style="align-self:center"></span></div>
      <div class="field"><label>Lotto</label><select name="lot_id"><option value="">— Carta singola (nessun lotto) —</option>${state.lots.map((l) => `<option value="${l.id}" ${i.lot_id === l.id ? 'selected' : ''}>${esc(l.title)}</option>`).join('')}</select></div>
      <div class="grid2">
        <div class="field"><label>Gradata da</label><select name="grading_company"><option value="">— Non gradata —</option>${opt(GRADERS, i.grading_company)}</select></div>
        <div class="field" id="grade-field"><label>Voto</label><input name="grade" value="${esc(i.grade ?? '')}" placeholder="10"></div>
      </div>
      <details class="field" ${i.alert_above != null || i.alert_below != null ? 'open' : ''}><summary class="small">🔔 Avvisami quando il valore… ${isPremium() ? '' : '(Premium)'}</summary>
        <div class="grid2" style="margin-top:8px">
          <div class="field"><label>sale sopra (€)</label><input name="alert_above" inputmode="decimal" value="${esc(i.alert_above ?? '')}"></div>
          <div class="field"><label>scende sotto (€)</label><input name="alert_below" inputmode="decimal" value="${esc(i.alert_below ?? '')}"></div>
        </div>
      </details>
      <div class="field" id="cost-field"><label>Costo d'acquisto cad. (€) – solo carte singole</label><input name="purchase_cost" inputmode="decimal" value="${esc(i.purchase_cost ?? '')}"></div>
      <div class="field"><label>Stato</label><select name="status">${opt(Object.keys(STATUS_LABEL), i.status, STATUS_LABEL)}</select></div>
      <div id="sale-fields">
        <div class="grid2">
          <div class="field"><label>Prezzo incassato (€)</label><input name="sale_price" inputmode="decimal" value="${esc(i.sale_price ?? '')}"></div>
          <div class="field"><label>Commissioni (€)</label><input name="sale_fees" inputmode="decimal" value="${esc(i.sale_fees ?? '')}"></div>
        </div>
        <div class="grid2">
          <div class="field"><label>Data vendita</label><input name="sale_date" type="date" value="${esc(i.sale_date ?? today())}"></div>
          <div class="field"><label>Canale</label><select name="sale_channel"><option value=""></option>${opt(CHANNELS, i.sale_channel)}</select></div>
        </div>
      </div>
      <div class="field"><label>Note</label><textarea name="notes">${esc(i.notes)}</textarea></div>
      <button class="btn block">${item ? 'Salva' : 'Aggiungi'}</button>
      ${item ? '<div class="actions"><button type="button" class="btn danger block" id="del">Elimina carta</button></div>' : ''}
      ${!item ? '<label style="margin-top:12px;display:flex;gap:8px;align-items:center"><input type="checkbox" id="again" checked style="width:auto"> Dopo il salvataggio aggiungine un\'altra</label>' : ''}
    </form>`, (m) => {
    const form = m.querySelector('form');
    const f = (n) => form.elements[n];
    const setImage = (url) => {
      f('image_url').value = url || '';
      const img = m.querySelector('#img-preview');
      img.hidden = !url;
      if (url) img.src = url; else img.removeAttribute('src');
    };
    const sync = () => {
      m.querySelector('#sale-fields').hidden = f('status').value !== 'sold';
      m.querySelector('#cost-field').hidden = !!f('lot_id').value;
      m.querySelector('#grade-field').style.visibility = f('grading_company').value ? 'visible' : 'hidden';
    };
    f('grading_company').addEventListener('change', sync);
    f('status').addEventListener('change', sync);
    f('lot_id').addEventListener('change', sync);
    sync();

    // Ricerca catalogo con debounce
    let t;
    const res = m.querySelector('#cat-res');
    m.querySelector('#cat-q').addEventListener('input', (e) => {
      clearTimeout(t);
      t = setTimeout(async () => {
        const q = e.target.value.trim();
        // Link CardTrader incollato: es. cardtrader.com/en-EU/cards/115023-ditto-...
        const link = q.match(/cardtrader\.com\/.*?cards\/(\d+)/i);
        if (link) {
          f('blueprint_id').value = link[1];
          setImage('');
          res.innerHTML = '<div class="small muted" style="padding:6px 2px">Link CardTrader riconosciuto ✓</div>';
          fetchPrice();
          return;
        }
        if (q.length < 2) { res.innerHTML = ''; return; }
        const list = await api('catalog/search?q=' + encodeURIComponent(q)).catch(() => []);
        res.innerHTML = list.length
          ? `<div class="suggest">${list.map((c, k) => `<div data-k="${k}">${c.image_url ? `<img src="${esc(c.image_url)}" alt="">` : ''}<span><strong>${esc(c.name)}</strong><br><span class="small muted">${esc(c.set_name)} · ${esc(c.card_number)}</span></span></div>`).join('')}</div>`
          : '<div class="small muted" style="padding:6px 2px">Nessun risultato nel catalogo: compila i campi a mano.</div>';
        res.querySelectorAll('[data-k]').forEach((el) => el.addEventListener('click', () => {
          const c = list[+el.dataset.k];
          f('name').value = c.name.replace(/\b\w/g, (s) => s.toUpperCase());
          f('set_name').value = c.set_name || '';
          f('card_number').value = c.card_number || '';
          f('blueprint_id').value = c.blueprint_id;
          setImage(c.image_url);
          res.innerHTML = '';
          m.querySelector('#cat-q').value = '';
        }));
      }, 300);
    });

    const fetchPrice = async () => {
      const out = m.querySelector('#price-out');
      if (!f('blueprint_id').value) { out.textContent = 'Cerca la carta nel catalogo o incolla il link CardTrader'; return; }
      out.textContent = 'Cerco…';
      const qs = new URLSearchParams({ blueprint_id: f('blueprint_id').value });
      if (f('language').value) qs.set('language', f('language').value);
      if (f('card_condition').value) qs.set('condition', f('card_condition').value);
      if (!f('image_url').value) qs.set('with_image', '1');
      let p;
      try { p = await api('catalog/price?' + qs); } catch (err) { out.textContent = err.message; return; }
      if (!f('name').value && p.name) f('name').value = p.name;
      if (!f('set_name').value && p.set_name) f('set_name').value = p.set_name;
      if (!f('card_number').value && p.card_number) f('card_number').value = p.card_number;
      if (!f('image_url').value && p.image_url) setImage(p.image_url);
      if (!p.count) { out.textContent = 'Nessuna offerta in vendita ora su CardTrader'; return; }
      const notes = [];
      if (f('language').value && !p.language_used) notes.push(`nessuna in ${f('language').value.toUpperCase()}: tutte le lingue`);
      if (f('card_condition').value && !p.condition_used) notes.push('tutte le condizioni');
      out.innerHTML = `min ${eur(p.min)} · mediana ${eur(p.median)} (${p.count} offerte)` + (notes.length ? `<br>${esc(notes.join(', '))}` : '');
      f('est_value').value = p.min.toFixed(2);
    };
    m.querySelector('#price').addEventListener('click', (e) => guarded(e.target, fetchPrice));
    m.querySelector('#scan-in').addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const out = m.querySelector('#scan-out');
      const q = m.querySelector('#cat-q');
      try {
        const r = await scanCard(file, (msg) => (out.textContent = msg));
        if (!r.name && !r.number) { out.textContent = 'Non sono riuscito a leggere la carta: prova con più luce, carta dritta e inquadrata tutta.'; return; }
        out.textContent = `Letto: ${[r.name, r.number].filter(Boolean).join(' · ')}`;
        if (!f('name').value && r.name) f('name').value = r.name;
        if (!f('card_number').value && r.number) f('card_number').value = r.number;
        q.value = [r.name, r.number].filter(Boolean).join(' ');
        q.dispatchEvent(new Event('input'));
      } catch (err) { out.textContent = err.message; }
      e.target.value = '';
    });

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      guarded(form.querySelector('button:not([type])'), async () => {
        const body = formData(form);
        if (body.status !== 'sold') { delete body.sale_price; delete body.sale_fees; delete body.sale_date; delete body.sale_channel; }
        if (body.lot_id) body.purchase_cost = '';
        await api(item ? `items/${item.id}` : 'items', { method: item ? 'PUT' : 'POST', body });
        toast(item ? 'Carta salvata' : 'Carta aggiunta');
        const again = !item && m.querySelector('#again')?.checked;
        reload();
        if (again) itemForm(null, { lot_id: body.lot_id ? +body.lot_id : null, language: body.language, card_condition: body.card_condition }, reload);
        else closeModal();
      });
    });
    m.querySelector('#del')?.addEventListener('click', (e) => guarded(e.target, async () => {
      if (!confirm('Eliminare questa carta?')) return;
      await api(`items/${item.id}`, { method: 'DELETE' });
      closeModal(); toast('Carta eliminata'); reload();
    }));
  });
}

// ---------- vendite ----------
async function viewSales() {
  shell('Vendite', '<div class="empty">Caricamento…</div>');
  const items = await api('items?status=sold');
  const gross = items.reduce((s, i) => s + (i.sale_price || 0), 0);
  const fees = items.reduce((s, i) => s + (i.sale_fees || 0), 0);
  const byChannel = {};
  items.forEach((i) => { const c = i.sale_channel || 'Non indicato'; byChannel[c] = (byChannel[c] || 0) + (i.sale_price || 0) - (i.sale_fees || 0); });
  const main = app.querySelector('main');
  main.innerHTML = `
    <div class="actions" style="margin:0 0 12px"><a class="btn secondary sm" href="#report">🧾 Report annuale</a>${state.me.members.length > 1 ? '<a class="btn secondary sm" href="#partners">👥 Divisione soci</a>' : ''}</div>
    <div class="kpis">
      <div class="kpi"><div class="label">Incassato lordo</div><div class="value">${eur(gross)}</div></div>
      <div class="kpi"><div class="label">Netto (− commissioni)</div><div class="value pos">${eur(gross - fees)}</div></div>
    </div>
    ${Object.keys(byChannel).length ? `<div class="chips">${Object.entries(byChannel).sort((a, b) => b[1] - a[1]).map(([c, v]) => `<span class="chip">${esc(c)}: ${eur(v)}</span>`).join('')}</div>` : ''}
    <div class="list" id="sold">${items.length ? items.map((i) => itemRow(i, { showLot: true })).join('') : '<div class="empty">Nessuna vendita registrata.</div>'}</div>`;
  bindItemRows(main.querySelector('#sold'), items, viewSales);
}

// ---------- sigillati: sbustare o rivendere? (Premium) ----------
const sealedState = { lang: 'it', fee: 10, paid: '', data: null, highlight: null, packs: {}, manual: { packs: '', price: '' } };
const isSealedName = (n) => /booster|box|bundle|elite trainer|\betb\b|blister|pack|tin|collection|display|bustina/i.test(n || '');
const oneIn = (p) => p > 0 ? `1 su ${p >= 1 ? '1' : Math.round(1 / p)}` : '—';

async function viewSealed(bp) {
  shell('Sbusto o rivendo?', '', { back: '#items' });
  const main = app.querySelector('main');
  if (!isPremium()) {
    main.innerHTML = `<div class="card locked" id="lk">🎁 Bustine, ETB e box: conviene sbustare o rivendere?<br>
      <span class="small muted">Valore atteso, pull rate, probabilità di rientrare e prezzo consigliato · Funzione Premium</span></div>`;
    main.querySelector('#lk').onclick = () => premiumModal("L'analisi sbustare o rivendere");
    return;
  }
  main.innerHTML = `
    <div class="card">
      <div class="field"><label>Set (es. “Evoluzioni Prismatiche”, “151”) o link CardTrader del prodotto</label>
        <input id="set-q" type="search" autocomplete="off" placeholder="Cerca il set…" value="${esc(sealedState.data?.set_name || '')}"><div id="set-res"></div></div>
      <div class="grid2">
        <div class="field"><label>Lingua</label><select id="s-lang">${LANGS.map((l) => `<option value="${l}" ${sealedState.lang === l ? 'selected' : ''}>${l.toUpperCase()}</option>`).join('')}</select></div>
        <div class="field"><label>Commissioni vendita (%)</label><input id="s-fee" type="number" min="0" max="50" value="${sealedState.fee}"></div>
      </div>
      <div class="field"><label>Quanto l'hai pagato? (€, facoltativo)</label><input id="s-paid" inputmode="decimal" value="${esc(sealedState.paid)}"></div>
    </div>
    <div id="sealed-out">${sealedState.data ? '' : '<p class="small muted">Scegli un set: carico i prezzi di tutte le carte e dei prodotti sigillati da CardTrader.</p>'}</div>`;
  const out = main.querySelector('#sealed-out');
  const load = (expId) => guarded(null, async () => {
    out.innerHTML = '<div class="empty">Carico prezzi e carte del set… (può richiedere qualche secondo)</div>';
    try {
      sealedState.data = await api(`sealed/analyze?expansion_id=${expId}&language=${sealedState.lang}`);
      main.querySelector('#set-q').value = sealedState.data.set_name || '';
      renderSealed(out);
    } catch (e) { out.innerHTML = `<p class="neg">${esc(e.message)}</p>`; }
  });
  main.querySelector('#s-lang').onchange = (e) => { sealedState.lang = e.target.value; if (sealedState.data) load(sealedState.data.expansion_id); };
  main.querySelector('#s-fee').oninput = (e) => { sealedState.fee = Math.max(0, Math.min(50, +e.target.value || 0)); if (sealedState.data) renderSealed(out); };
  main.querySelector('#s-paid').oninput = (e) => { sealedState.paid = e.target.value; if (sealedState.data) renderSealed(out); };
  let t;
  main.querySelector('#set-q').addEventListener('input', (e) => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const q = e.target.value.trim();
      const res = main.querySelector('#set-res');
      const link = q.match(/cardtrader\.com\/.*?cards\/(\d+)/i);
      if (link) { res.innerHTML = ''; location.hash = `#sealed/${link[1]}`; return; }
      if (q.length < 2) { res.innerHTML = ''; return; }
      const sets = await api('sealed/sets?q=' + encodeURIComponent(q)).catch(() => []);
      res.innerHTML = sets.length ? `<div class="suggest">${sets.map((x) => `<div data-exp="${x.id}"><span><strong>${esc(x.name)}</strong> <span class="small muted">${esc(x.code || '')}</span></span></div>`).join('')}</div>`
        : '<div class="small muted" style="padding:6px 2px">Nessun set trovato.</div>';
      res.querySelectorAll('[data-exp]').forEach((el) => el.addEventListener('click', () => { res.innerHTML = ''; sealedState.highlight = null; load(+el.dataset.exp); }));
    }, 300);
  });
  if (bp) {
    // Arrivo da un prodotto del magazzino o da un link: trovo il suo set.
    out.innerHTML = '<div class="empty">Cerco il prodotto…</div>';
    try {
      const p = await api(`catalog/price?blueprint_id=${bp}`);
      if (!p.expansion_id) throw new Error('Nessuna offerta su CardTrader per questo prodotto: non riesco a capire il set');
      sealedState.highlight = bp;
      load(p.expansion_id);
    } catch (e) { out.innerHTML = `<p class="neg">${esc(e.message)}</p>`; }
  } else if (sealedState.data) renderSealed(out);
}

function sealedModel(d) {
  const tiers = d.rarities.filter((r) => r.per_pack > 0 && r.cards.length).map((r) => ({ p: r.per_pack, prices: r.cards.map((c) => c.price) }));
  const ev = d.bulk_per_pack + tiers.reduce((s, r) => s + r.p * (r.prices.reduce((a, b) => a + b, 0) / r.prices.length), 0);
  return { tiers, ev };
}

// Simula N sbustamenti: per ogni bustina e rarità, estrazione con il pull rate e carta a caso di quella rarità.
function simulate(model, packs, bulk, sims = 3000) {
  const out = new Float64Array(sims);
  for (let s = 0; s < sims; s++) {
    let tot = 0;
    for (let k = 0; k < packs; k++) {
      tot += bulk;
      for (const t of model.tiers) {
        let hits = Math.floor(t.p) + (Math.random() < t.p % 1 ? 1 : 0);
        while (hits-- > 0) tot += t.prices[(Math.random() * t.prices.length) | 0];
      }
    }
    out[s] = tot;
  }
  return out.sort();
}

function sealedVerdict(d, model, packs, market, median, fee, paid) {
  const k = 1 - fee / 100;
  const sellNet = market * k;
  const sims = simulate(model, packs, d.bulk_per_pack);
  const net = (v) => v * k;
  const pct = (q) => net(sims[Math.min(sims.length - 1, Math.floor(q * sims.length))]);
  const evOpen = net(model.ev * packs);
  const beat = sims.filter((v) => net(v) >= sellNet).length / sims.length;
  const ratio = sellNet > 0 ? evOpen / sellNet : 0;
  // In media conviene sbustare, ma se si supera il sigillato in meno di 1 caso su 3 è una scommessa.
  const verdict = ratio >= 1.1 ? (beat < 0.33 ? ['even', 'Sbusta solo se ami il rischio'] : ['open', 'Conviene sbustare'])
    : ratio <= 0.9 ? ['sell', 'Conviene rivendere'] : ['even', 'Quasi pari'];
  const fast = market >= 5 ? Math.floor(market * 0.97) - 0.01 : Math.round(market * 0.97 * 100) / 100;
  return { sellNet, evOpen, beat, p10: pct(0.1), p50: pct(0.5), p90: pct(0.9), verdict, fast, median,
    paidSell: paid != null ? sellNet - paid : null, paidOpen: paid != null ? evOpen - paid : null,
    beatPaid: paid != null ? sims.filter((v) => net(v) >= paid).length / sims.length : null };
}

function renderSealed(out) {
  const d = sealedState.data;
  const fee = sealedState.fee;
  const paid = sealedState.paid !== '' && !isNaN(parseFloat(sealedState.paid.replace(',', '.'))) ? parseFloat(sealedState.paid.replace(',', '.')) : null;
  const model = sealedModel(d);
  const unknown = d.rarities.filter((r) => !(r.per_pack > 0) && r.cards.length);
  const products = [...d.sealed.filter((x) => x.price)].sort((a, b) => (b.blueprint_id === sealedState.highlight) - (a.blueprint_id === sealedState.highlight) || (a.packs || 99) - (b.packs || 99));
  const hits = d.rarities.filter((r) => r.per_pack > 0).flatMap((r) => r.cards.map((c) => ({ ...c, rarity: r.rarity, p: r.per_pack / r.cards.length })))
    .sort((a, b) => b.price - a.price).slice(0, 8);

  const card = (title, packs, market, median, bp, img) => {
    if (!packs) {
      return `<div class="card sealed-card"><div class="sealed-head">${img ? `<img src="${esc(img)}" alt="">` : ''}<div><strong>${esc(title)}</strong><div class="small muted">Prezzo ${eur(market)}</div></div></div>
        <div class="field" style="margin:8px 0 0"><label>Quante bustine contiene?</label><input type="number" min="1" data-packs="${bp}" value=""></div></div>`;
    }
    const v = sealedVerdict(d, model, packs, market, median, fee, paid);
    return `<div class="card sealed-card ${bp === sealedState.highlight ? 'hl' : ''}">
      <div class="sealed-head">${img ? `<img src="${esc(img)}" alt="">` : ''}<div style="flex:1"><strong>${esc(title)}</strong>
        <div class="small muted">${packs} bustin${packs === 1 ? 'a' : 'e'} ${bp !== 'manual' ? `· <input class="mini" type="number" min="1" data-packs="${bp}" value="${packs}">` : ''}</div></div>
        <span class="badge verdict ${v.verdict[0]}">${v.verdict[1]}</span></div>
      <div class="grid2 sealed-grid">
        <div><div class="small muted">Rivendendo (netto)</div><strong>${eur(v.sellNet)}</strong><div class="small muted">mercato ${eur(market)}</div></div>
        <div><div class="small muted">Sbustando (atteso, netto)</div><strong>${eur(v.evOpen)}</strong><div class="small muted">8 volte su 10 tra ${eur(v.p10)} e ${eur(v.p90)}</div></div>
      </div>
      <p class="small" style="margin:8px 0 4px">🎲 Sbustando superi il valore del sigillato nel <strong>${Math.round(v.beat * 100)}%</strong> dei casi${paid != null ? ` · rientri della spesa nel <strong>${Math.round(v.beatPaid * 100)}%</strong>` : ''}.</p>
      ${paid != null ? `<p class="small" style="margin:0 0 4px">Con ${eur(paid)} pagati: rivendendo ${signed(v.paidSell)} · sbustando (in media) ${signed(v.paidOpen)}</p>` : ''}
      ${v.verdict[0] !== 'open' ? `<p class="small" style="margin:0">💶 Prezzo consigliato: <strong>${eur(v.fast)}</strong> per vendere in fretta (sotto l'offerta più bassa) · fino a ${eur(Math.max(market, v.median || 0))} se puoi aspettare.</p>` : ''}
    </div>`;
  };

  out.innerHTML = `
    <div class="kpis" style="margin-top:12px">
      <div class="kpi wide"><div class="label">Valore atteso di una bustina · ${esc(d.set_name || '')} (${(d.language || 'tutte').toUpperCase()})</div>
        <div class="value">${eur(model.ev)}</div><div class="small muted">netto commissioni ${eur(model.ev * (1 - fee / 100))} · prezzi CardTrader, aggiornati ${fmtDate(d.updated_at.slice(0, 10))}</div></div>
    </div>
    <h2>Prodotti sigillati</h2>
    <div class="list">${products.length ? products.map((x) => card(x.name, sealedState.packs[x.blueprint_id] || x.packs, x.price.min, x.price.median, x.blueprint_id, x.image_url)).join('')
      : '<p class="small muted">Nessun prodotto sigillato in vendita su CardTrader per questo set: usa il calcolo manuale qui sotto.</p>'}
      <div class="card"><strong>Altro prodotto</strong>
        <div class="grid2" style="margin-top:8px"><div class="field"><label>Bustine</label><input type="number" min="1" id="m-packs" value="${esc(sealedState.manual.packs)}"></div>
        <div class="field"><label>Prezzo di mercato (€)</label><input inputmode="decimal" id="m-price" value="${esc(sealedState.manual.price)}"></div></div>
        <div id="m-out"></div></div>
    </div>
    <h2>Carte che fanno la differenza</h2>
    <div class="card">${hits.map((c) => `<div class="grid2" style="padding:4px 0"><span>${esc(c.name)} <span class="small muted">${esc(c.number || '')} · ${esc(c.rarity)}</span></span><span>${eur(c.price)} <span class="small muted">${oneIn(c.p)} bust.</span></span></div>`).join('') || '<span class="muted">Nessun prezzo disponibile.</span>'}</div>
    <h2>Pull rate per rarità</h2>
    <div class="card">
      <table class="plans rates"><tr><th>Rarità</th><th>Probabilità</th><th>Carte</th><th>Valore medio</th></tr>
      ${d.rarities.map((r) => `<tr><td>${esc(r.rarity)}${r.custom ? ' ✎' : ''}</td>
        <td>${d.can_edit_rates ? `<input class="mini" data-rate="${esc(r.rarity)}" value="${r.per_pack ? Math.round(1 / r.per_pack * 10) / 10 : ''}" placeholder="—" title="1 su N bustine">` : oneIn(r.per_pack)}</td>
        <td>${r.cards.length}</td><td>${eur(r.avg)}</td></tr>`).join('')}</table>
      ${d.can_edit_rates ? '<p class="small muted">Proprietario: scrivi N per “1 su N bustine” (vuoto = non conteggiata).</p><button class="btn sm" id="save-rates">Salva pull rate del set</button>' : ''}
      ${unknown.length ? `<p class="small muted">Senza pull rate (non conteggiate): ${unknown.map((r) => esc(r.rarity)).join(', ')}.</p>` : ''}
    </div>
    <p class="small muted">Stime indicative: pull rate medi della community e prezzi delle offerte più basse. Le comuni/non comuni valgono ${eur(d.bulk_per_pack)} a bustina (bulk). Il risultato reale di uno sbustamento può variare molto.</p>`;

  out.querySelectorAll('[data-packs]').forEach((inp) => inp.addEventListener('change', () => { sealedState.packs[+inp.dataset.packs] = Math.max(1, +inp.value || 1); renderSealed(out); }));
  const manual = () => {
    sealedState.manual = { packs: out.querySelector('#m-packs').value, price: out.querySelector('#m-price').value };
    const n = +sealedState.manual.packs, pr = parseFloat(String(sealedState.manual.price).replace(',', '.'));
    out.querySelector('#m-out').innerHTML = n > 0 && pr > 0 ? card(`${n} bustine a ${eur(pr)}`, n, pr, pr, 'manual', null) : '';
  };
  out.querySelector('#m-packs').oninput = manual;
  out.querySelector('#m-price').oninput = manual;
  manual();
  out.querySelector('#save-rates')?.addEventListener('click', (e) => guarded(e.target, async () => {
    const rates = {};
    out.querySelectorAll('[data-rate]').forEach((i) => { const n = parseFloat(i.value.replace(',', '.')); if (n > 0) rates[i.dataset.rate] = 1 / n; });
    await api(`sealed/rates/${d.expansion_id}`, { method: 'PUT', body: { rates } });
    toast('Pull rate salvati');
    sealedState.data = await api(`sealed/analyze?expansion_id=${d.expansion_id}&language=${sealedState.lang}`);
    renderSealed(out);
  }));
}

// ---------- report ----------
async function viewReport(year) {
  shell('Report annuale', '<div class="empty">Caricamento…</div>', { back: '#sales' });
  if (!isPremium()) { app.querySelector('main').innerHTML = '<div class="card locked" id="lk">🧾 Report annuale di acquisti e vendite<br><span class="small muted">Funzione Premium · tocca per scoprire</span></div>'; app.querySelector('#lk').onclick = () => premiumModal('Il report annuale'); return; }
  const d = await api('reports/annual?year=' + (year || new Date().getFullYear()));
  const t = d.totals;
  app.querySelector('main').innerHTML = `
    <div class="chips">${d.years.map((y) => `<a class="chip ${y === d.year ? 'active' : ''}" href="#report/${y}">${y}</a>`).join('')}</div>
    <div class="kpis">
      <div class="kpi"><div class="label">Acquisti</div><div class="value">${eur(t.purchases)}</div></div>
      <div class="kpi"><div class="label">Vendite nette</div><div class="value">${eur(t.net)}</div><div class="small muted">lordo ${eur(t.gross)} · commissioni ${eur(t.fees)}</div></div>
      <div class="kpi wide"><div class="label">Saldo ${d.year} (vendite nette − acquisti)</div><div class="value">${signed(t.balance)}</div><div class="small muted">${t.sold} carte vendute</div></div>
    </div>
    <h2>Vendite nette per mese</h2>
    ${barChart(d.months, { value: (r) => r.net, fmt: eur, tip: (r) => `<strong>${monthLabel(r.month)} ${d.year}</strong><br>Vendite nette ${eur(r.net)}<br>Acquisti ${eur(r.purchases)}<br>${r.sold} carte vendute` })}
    ${d.channels.length ? `<h2>Per canale</h2><div class="card">${d.channels.map((c) => `<div class="grid2" style="padding:4px 0"><span>${esc(c.channel)}</span><span>${eur(c.net)}</span></div>`).join('')}</div>` : ''}
    <div class="actions"><a class="btn" href="/api/reports/annual.csv?year=${d.year}">⬇️ Scarica report ${d.year} (Excel)</a></div>
    <p class="small muted">Riepilogo indicativo dei movimenti registrati: per gli adempimenti fiscali fatti seguire da un commercialista.</p>`;
}

async function viewPartners() {
  shell('Divisione soci', '<div class="empty">Caricamento…</div>', { back: '#sales' });
  if (!isPremium()) { app.querySelector('main').innerHTML = '<div class="card locked" id="lk">👥 Quanto ha messo e quanto spetta a ogni socio<br><span class="small muted">Funzione Premium · tocca per scoprire</span></div>'; app.querySelector('#lk').onclick = () => premiumModal('La divisione profitti tra soci'); return; }
  const rows = await api('reports/partners');
  app.querySelector('main').innerHTML = `
    <p class="small muted" style="margin-top:0">Lotti divisi secondo le quote (dal lotto → “Quote soci”, altrimenti parti uguali). Carte singole: di chi le ha inserite.</p>
    <div class="list">${rows.map((r) => `
      <div class="card">
        <div class="title" style="font-weight:700;margin-bottom:6px">👤 ${esc(r.name)}</div>
        <div class="grid2"><span class="muted">Ha investito</span><span>${eur(r.invested)}</span></div>
        <div class="grid2"><span class="muted">Incassato (sua parte)</span><span>${eur(r.revenue)}</span></div>
        <div class="grid2"><span class="muted">Magazzino (sua parte)</span><span>${eur(r.stock_value)}</span></div>
        <div class="grid2"><span class="muted">Profitto realizzato</span><span>${signed(r.profit)}</span></div>
        <div class="grid2"><span class="muted">Profitto potenziale</span><span>${signed(r.potential)}</span></div>
      </div>`).join('')}</div>`;
}

// ---------- impostazioni ----------
async function viewSettings() {
  if (location.hash.includes('pagamento=ok')) {
    toast('Pagamento ricevuto, grazie! 🎉');
    history.replaceState(null, '', '#settings');
  }
  state.me = await api('me').catch(() => state.me);
  const { user, workspace, members, billing: b } = state.me;
  const planText = {
    comp: '⭐ Premium incluso (account proprietario).',
    pro: `⭐ Premium attivo fino al <strong>${fmtDate(b.until?.slice(0, 10))}</strong>${b.canceled ? ' (disdetto: poi torni su Free)' : ', rinnovo automatico'}.`,
    trial: `⭐ Premium in prova: <strong>${b.days_left} giorni</strong> rimasti. Poi passi a Free senza perdere nulla.`,
    free: `Piano <strong>Free</strong> · ${b.items_in_stock}/${b.free_item_limit} carte in magazzino.`,
  }[b.state];
  shell('Altro', `
    <h2>Piano</h2>
    <div class="card">
      <p style="margin-top:0">${planText}</p>
      ${b.state !== 'comp' ? `
        <table class="plans">
          <tr><th></th><th>Free</th><th>⭐ Premium</th></tr>
          <tr><td>Lotti, vendite, riepilogo</td><td>✓</td><td>✓</td></tr>
          <tr><td>Prezzo CardTrader per carta</td><td>✓</td><td>✓</td></tr>
          <tr><td>Export Excel</td><td>✓</td><td>✓</td></tr>
          <tr><td>Carte in magazzino</td><td>${b.free_item_limit}</td><td>Illimitate</td></tr>
          <tr><td>Aggiornamento valori in blocco</td><td>—</td><td>✓</td></tr>
          <tr><td>Soci nello stesso negozio</td><td>—</td><td>✓</td></tr>
          <tr><td>Valori aggiornati ogni notte</td><td>—</td><td>✓</td></tr>
          <tr><td>Storico prezzi e avvisi</td><td>—</td><td>✓</td></tr>
          <tr><td>Grafici e report annuale</td><td>—</td><td>✓</td></tr>
          <tr><td>Divisione profitti tra soci</td><td>—</td><td>✓</td></tr>
          <tr><td>Sbusto o rivendo? (bustine, ETB, box)</td><td>—</td><td>✓</td></tr>
          <tr><td>Prezzo</td><td>Gratis</td><td>${eur(b.price)}/mese</td></tr>
        </table>
        <div class="actions">
          ${b.paypal_enabled && (b.state !== 'pro' || b.canceled) ? `<button class="btn paypal" id="paypal">Passa a Premium con <strong>PayPal</strong> · ${eur(b.price)}/mese</button>` : ''}
          ${b.stripe_enabled && (b.state !== 'pro' || b.canceled) ? `<button class="btn" id="checkout">${b.paypal_enabled ? 'Paga con carta' : 'Passa a Premium'} · ${eur(b.price)}/mese</button>` : ''}
          ${b.stripe_enabled && b.has_stripe_customer ? '<button class="btn secondary" id="portal">Gestisci pagamento</button>' : ''}
          ${b.has_paypal && b.state === 'pro' && !b.canceled ? '<button class="btn secondary" id="paypal-cancel">Disdici abbonamento PayPal</button>' : ''}
          ${!b.stripe_enabled && !b.paypal_enabled && b.state !== 'pro' ? '<p class="small muted">Per attivare Premium contatta il gestore del servizio.</p>' : ''}
        </div>` : ''}
    </div>
    <h2>Prezzi e avvisi ${b.premium ? '' : '<span class="badge">Premium</span>'}</h2>
    <form id="prefs" class="card">
      <label class="check"><input type="checkbox" name="auto_refresh" value="1" ${workspace.auto_refresh ? 'checked' : ''}> Aggiorna i valori da CardTrader ogni notte</label>
      <div class="grid2">
        <div class="field"><label>Valore da usare</label><select name="price_strategy"><option value="min" ${workspace.price_strategy === 'min' ? 'selected' : ''}>Prezzo minimo</option><option value="median" ${workspace.price_strategy === 'median' ? 'selected' : ''}>Prezzo mediano</option></select></div>
        <div class="field"><label>Avvisami se cambia più del (%)</label><input name="alert_pct" type="number" min="0" max="100" value="${workspace.alert_pct}"></div>
      </div>
      <p class="small muted" style="margin-top:0">0 = nessun avviso per variazione. Le soglie per singola carta si impostano da “Modifica carta”.</p>
      <button class="btn sm">Salva</button>
    </form>
    <h2>🎁 Invita amici</h2>
    <div class="card">
      <p style="margin-top:0">Per ogni amico che si iscrive con il tuo link e conferma l'email ricevi <strong>${state.me.referral.days} giorni di Premium</strong>. Lui parte con ${state.me.referral.days} giorni di prova.</p>
      <input readonly value="${esc(state.me.referral.link)}" id="ref-link" onclick="this.select()">
      <div class="actions"><button class="btn sm" id="ref-share">Condividi link</button></div>
      <p class="small muted" style="margin-bottom:0">Invitati: ${state.me.referral.invited} · confermati: ${state.me.referral.confirmed}</p>
    </div>
    ${deferredInstall ? '<h2>📱 App</h2><button class="btn secondary" id="install">Installa CardLedger sul telefono</button>' : ''}
    <h2>Il tuo negozio</h2>
    <form id="ws" class="card">
      <div class="field"><label>Nome</label><input name="name" value="${esc(workspace.name)}"></div>
      <button class="btn sm">Salva nome</button>
    </form>
    <h2>Soci</h2>
    <div class="card">
      ${members.map((m) => `<div style="padding:4px 0">👤 ${esc(m.name)} <span class="muted small">${esc(m.email)}</span></div>`).join('')}
      <p class="small muted">Fai registrare il tuo socio con questo codice invito: vedrete gli stessi lotti e le stesse carte.${b.premium ? '' : ' <strong>Funzione Premium.</strong>'}</p>
      <div class="code">${esc(workspace.invite_code)}</div>
    </div>
    <h2>Unisciti a un altro negozio</h2>
    <form id="join" class="card">
      <div class="field"><label>Codice invito</label><input name="code" autocapitalize="characters"></div>
      <button class="btn sm secondary">Unisciti</button>
    </form>
    <h2>Dati</h2>
    <div class="actions"><a class="btn secondary" href="/api/export/items.csv">⬇️ Esporta carte (CSV/Excel)</a></div>
    <h2>Account</h2>
    <p class="muted">${esc(user.name)} · ${esc(user.email)} ${user.email_verified_at ? '✓' : '<span class="small">(non confermata)</span>'}</p>
    <div class="actions">
      <button class="btn secondary" id="chpw">Cambia password</button>
      <button class="btn secondary" id="logout">Esci</button>
    </div>
    <h2>Zona pericolosa</h2>
    <button class="btn danger" id="delete-account">Elimina il mio account</button>
    <p class="small muted"><a href="/privacy.html">Privacy</a> · <a href="/termini.html">Termini</a></p>`);
  document.getElementById('chpw').onclick = () => openModal('Cambia password', `
    <form>
      <div class="field"><label>Password attuale</label><input name="current" type="password" required autocomplete="current-password"></div>
      <div class="field"><label>Nuova password (min. 8)</label><input name="password" type="password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn block">Salva</button>
    </form>`, (m) => {
    const f = m.querySelector('form');
    f.addEventListener('submit', (e) => { e.preventDefault(); guarded(f.querySelector('button'), async () => { await api('account/password', { method: 'PUT', body: formData(f) }); closeModal(); toast('Password cambiata'); }); });
  });
  document.getElementById('delete-account').onclick = () => openModal('Elimina account', `
    <form>
      <p>${members.length > 1 ? 'Il tuo account verrà eliminato. Il negozio e i suoi dati restano ai tuoi soci.' : '<strong>Verranno eliminati per sempre il tuo account, il negozio, tutti i lotti, le carte e le vendite.</strong> Prima puoi esportare i dati da “Esporta carte”.'}</p>
      <div class="field"><label>Conferma con la password</label><input name="password" type="password" required autocomplete="current-password"></div>
      <button class="btn danger block">Elimina definitivamente</button>
    </form>`, (m) => {
    const f = m.querySelector('form');
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!confirm('Sei sicuro? L\'operazione non si può annullare.')) return;
      guarded(f.querySelector('button'), async () => { await api('account', { method: 'DELETE', body: formData(f) }); clearOfflineData(); state.me = null; closeModal(); history.replaceState(null, '', '#'); renderAuth(); toast('Account eliminato'); });
    });
  });
  const goTo = (path) => async (e) => guarded(e.target, async () => { location.href = (await api(path, { method: 'POST', body: {} })).url; });
  document.getElementById('checkout')?.addEventListener('click', goTo('billing/checkout'));
  document.getElementById('portal')?.addEventListener('click', goTo('billing/portal'));
  document.getElementById('paypal')?.addEventListener('click', goTo('billing/paypal/subscribe'));
  document.getElementById('paypal-cancel')?.addEventListener('click', (e) => {
    if (!confirm('Disdire l\'abbonamento? Premium resta attivo fino alla fine del periodo già pagato, poi torni al piano Free.')) return;
    guarded(e.target, async () => { await api('billing/paypal/cancel', { method: 'POST', body: {} }); toast('Abbonamento disdetto'); viewSettings(); });
  });
  document.getElementById('ref-share').onclick = async () => {
    const link = state.me.referral.link;
    const text = 'Uso CardLedger per segnare lotti, carte e profitti: iscriviti con il mio link e hai 30 giorni di Premium gratis';
    if (navigator.share) { try { await navigator.share({ title: 'CardLedger', text, url: link }); } catch { /* annullato */ } return; }
    try { await navigator.clipboard.writeText(link); toast('Link copiato'); } catch { document.getElementById('ref-link').select(); }
  };
  document.getElementById('install')?.addEventListener('click', async () => {
    deferredInstall.prompt();
    await deferredInstall.userChoice;
    deferredInstall = null;
    viewSettings();
  });
  const prefs = document.getElementById('prefs');
  prefs.addEventListener('submit', (e) => { e.preventDefault(); guarded(prefs.querySelector('button'), async () => { state.me = await api('workspace/settings', { method: 'PUT', body: { auto_refresh: false, ...formData(prefs) } }); toast('Salvato'); }); });
  const ws = document.getElementById('ws');
  ws.addEventListener('submit', (e) => { e.preventDefault(); guarded(ws.querySelector('button'), async () => { state.me = await api('workspace', { method: 'PUT', body: formData(ws) }); toast('Salvato'); viewSettings(); }); });
  const join = document.getElementById('join');
  join.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!confirm('Passerai al negozio del codice inserito. Continuare?')) return;
    guarded(join.querySelector('button'), async () => { state.me = await api('workspace/join', { method: 'POST', body: formData(join) }); state.lots = []; toast('Ti sei unito a ' + state.me.workspace.name); viewSettings(); });
  });
  document.getElementById('logout').onclick = (e) => guarded(e.target, async () => { await api('auth/logout', { method: 'POST', body: {} }); clearOfflineData(); state.me = null; state.lots = []; renderAuth(); });
}

// ---------- pannello proprietario ----------
const STATE_LABEL = { comp: 'Premium gratis', pro: 'Premium', trial: 'Prova Premium', free: 'Free' };
let adminFilter = '';

async function viewAdmin() {
  if (!state.me.user.is_admin) { location.hash = '#dashboard'; return; }
  shell('Pannello proprietario', '<div class="empty">Caricamento…</div>');
  renderAdmin(await api('admin/overview'));
}

function renderAdmin(d) {
  const c = d.counts;
  const rows = d.rows.filter((r) => !adminFilter || r.state === adminFilter);
  const main = app.querySelector('main');
  main.innerHTML = `
    <div class="kpis">
      <div class="kpi wide"><div class="label">Entrate mensili ricorrenti (MRR)</div><div class="value">${eur(d.mrr)}</div>
        <div class="small muted">${c.pro} paganti × ${eur(d.price)} · ${eur(d.mrr * 12)}/anno</div></div>
      <div class="kpi"><div class="label">Negozi</div><div class="value">${d.workspaces}</div><div class="small muted">${d.users} utenti</div></div>
      <div class="kpi"><div class="label">Attivi ultimi 7 giorni</div><div class="value">${d.active_7d}</div></div>
      <div class="kpi"><div class="label">Prova Premium</div><div class="value">${c.trial}</div><div class="small muted">${d.trials_expiring} finiscono entro 3 giorni</div></div>
      <div class="kpi"><div class="label">Free</div><div class="value">${c.free}</div><div class="small muted">conversione a Premium ${c.pro + c.free ? Math.round((c.pro / (c.pro + c.free)) * 100) : 0}%</div></div>
    </div>
    ${d.stripe_enabled ? '' : '<p class="small muted">Stripe non configurato: attiva Premium a mano da qui, oppure imposta le chiavi (vedi GESTIONALE.md).</p>'}
    <h2>Nuovi utenti per mese</h2>
    ${barChart(d.signups, { value: (r) => r.value, tip: (r) => `<strong>${monthLabel(r.month)} ${r.month.slice(0, 4)}</strong><br>${r.value} nuovi utenti` })}
    <h2>Negozi</h2>
    <div class="chips">${[['', 'Tutti'], ['pro', 'Premium'], ['trial', 'In prova'], ['free', 'Free'], ['comp', 'Premium gratis']]
      .map(([v, t]) => `<button class="chip ${adminFilter === v ? 'active' : ''}" data-f="${v}">${t}</button>`).join('')}</div>
    <div class="list">${rows.length ? rows.map((r) => `
      <div class="row" data-ws="${r.id}">
        <div class="body">
          <div class="title">${esc(r.name)}</div>
          <div class="sub">${esc(r.members.map((m) => m.email).join(', '))}</div>
          <div class="sub">${r.lots} lotti · ${r.cards} carte · ultima attività ${fmtDate(r.last_activity.slice(0, 10))}</div>
        </div>
        <div class="right"><span class="badge st-${r.state}">${STATE_LABEL[r.state]}</span>
          <div class="small muted" style="margin-top:4px">${r.state === 'pro' || r.state === 'trial' ? `${r.days_left} gg` : ''}</div></div>
      </div>`).join('') : '<div class="empty">Nessun negozio.</div>'}</div>`;
  main.insertAdjacentHTML('beforeend', '<h2>Uso dell\'app (30 giorni)</h2><div id="usage"><div class="small muted">Caricamento…</div></div>');
  api('admin/usage').then((u) => {
    const max = Math.max(...u.funnel.map((f) => f.value), 1);
    const EV = { login: 'Accessi', signup: 'Iscrizioni', signup_referral: 'Iscrizioni da invito', referral_reward: 'Inviti premiati', item_create: 'Carte aggiunte', item_sold: 'Vendite registrate',
      price_refresh: 'Aggiornamenti prezzo', import: 'Import CSV', photo_add: 'Foto caricate', report_view: 'Report aperti', shares_set: 'Quote soci', scan: 'Scansioni', scan_ok: 'Scansioni riuscite',
      listing_text: 'Testi annuncio', app_installed: 'App installate', offline_sync: 'Sincronizzazioni offline' };
    main.querySelector('#usage').innerHTML = `
      <div class="card">${u.funnel.map((f) => `<div class="funnel"><span>${esc(f.step)}</span><div class="fbar"><div style="width:${(f.value / max) * 100}%"></div></div><strong>${f.value}</strong></div>`).join('')}</div>
      <div class="card" style="margin-top:10px">${u.events.length ? u.events.map((e) => `<div class="grid2" style="padding:3px 0"><span>${esc(EV[e.event] || e.event)}</span><span>${e.count} <span class="small muted">(${e.shops} negozi)</span></span></div>`).join('') : '<span class="muted">Nessun evento ancora.</span>'}</div>
      <p class="small muted">Utenti arrivati da invito: ${u.referred_users} · Carte per gioco: ${u.games.map((g) => `${esc(g.game)} ${g.cards}`).join(', ') || '—'}</p>`;
  }).catch(() => {});
  main.querySelectorAll('[data-f]').forEach((b) => b.addEventListener('click', () => { adminFilter = b.dataset.f; renderAdmin(d); }));
  main.querySelectorAll('[data-ws]').forEach((el) => el.addEventListener('click', () => adminWorkspace(d.rows.find((r) => r.id === +el.dataset.ws))));
}

function adminWorkspace(r) {
  openModal(r.name, `
    <div class="card">
      <div class="grid2" style="padding:4px 0"><span class="muted">Stato</span><span><span class="badge st-${r.state}">${STATE_LABEL[r.state]}</span>${r.canceled ? ' <span class="small muted">disdetto</span>' : ''}</span></div>
      ${r.until ? `<div class="grid2" style="padding:4px 0"><span class="muted">Scadenza</span><span>${fmtDate(r.until.slice(0, 10))}</span></div>` : ''}
      <div class="grid2" style="padding:4px 0"><span class="muted">Iscritto il</span><span>${fmtDate(r.created_at.slice(0, 10))}</span></div>
      <div class="grid2" style="padding:4px 0"><span class="muted">Pagamento</span><span>${r.stripe ? 'Stripe' : 'Manuale'}</span></div>
      <div class="grid2" style="padding:4px 0"><span class="muted">Utilizzo</span><span>${r.lots} lotti · ${r.cards} carte</span></div>
      <div class="grid2" style="padding:4px 0"><span class="muted">Magazzino / incassato</span><span>${eur(r.stock_value)} / ${eur(r.revenue)}</span></div>
      <div style="padding:4px 0"><span class="muted">Membri</span>${r.members.map((m) => `<div>👤 ${esc(m.name)} <span class="small muted">${esc(m.email)}</span></div>`).join('')}</div>
    </div>
    <h2>Azioni</h2>
    <p class="small muted">Per Premium pagato a mano (bonifico, PayPal, contanti).</p>
    <div class="actions">
      <button class="btn" data-a="extend" data-d="30">Premium +1 mese</button>
      <button class="btn" data-a="extend" data-d="365">Premium +1 anno</button>
      <button class="btn secondary" data-a="trial" data-d="14">Prova Premium 14 gg</button>
      <button class="btn secondary" data-a="comp">Premium gratis per sempre</button>
      <button class="btn danger" data-a="free">Riporta a Free</button>
    </div>`, (m) => {
    m.querySelectorAll('[data-a]').forEach((b) => b.addEventListener('click', () => guarded(b, async () => {
      if (b.dataset.a === 'free' && !confirm('Riportare questo negozio al piano Free?')) return;
      const d = await api(`admin/workspaces/${r.id}`, { method: 'PUT', body: { action: b.dataset.a, days: +b.dataset.d || 30 } });
      closeModal(); toast('Aggiornato'); renderAdmin(d);
    })));
  });
}

// ---------- router ----------
async function router() {
  const [r0, tok] = location.hash.split('/');
  if (r0 === '#reset' && tok) return renderReset(tok);
  if (r0 === '#verify' && tok) return renderVerify(tok);
  if (!state.me) return renderAuth(location.search.includes('signup') ? 'register' : 'login');
  closeModal();
  const [route, id] = (location.hash || '#dashboard').split('?')[0].split('/');
  try {
    switch (route) {
      case '#lots': return await viewLots();
      case '#lot': return await viewLot(+id);
      case '#items': return await viewItems();
      case '#sales': return await viewSales();
      case '#settings': return await viewSettings();
      case '#admin': return await viewAdmin();
      case '#report': return await viewReport(+id || null);
      case '#partners': return await viewPartners();
      case '#sealed': return await viewSealed(+id || null);
      default: return await viewDashboard();
    }
  } catch (e) {
    if (state.me) toast(e.message, true);
  }
}

// Immagine non caricabile: segnaposto invece dell'icona rotta.
document.addEventListener('error', (e) => {
  const img = e.target;
  if (img.tagName !== 'IMG') return;
  if (img.id === 'img-preview' || img.classList.contains('hero-img')) { img.hidden = true; return; }
  img.removeAttribute('src');
  img.classList.add('broken');
}, true);

let deferredInstall = null;
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferredInstall = e; });
window.addEventListener('appinstalled', () => { api('events', { method: 'POST', body: { event: 'app_installed' } }).catch(() => {}); });
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/app/sw.js', { scope: '/app/' }).catch(() => {});
function clearOfflineData() {
  writeQueue([]);
  navigator.serviceWorker?.controller?.postMessage('clear-data');
}

window.addEventListener('hashchange', router);
(async () => {
  try { state.me = await api('me', { quiet: true }); } catch { state.me = null; }
  const qs = new URLSearchParams(location.search);
  if (qs.get('paypal') && state.me) {
    history.replaceState(null, '', '/app/#settings');
    if (qs.get('paypal') === 'ok' && qs.get('subscription_id')) {
      try {
        const r = await api('billing/paypal/confirm', { method: 'POST', body: { subscription_id: qs.get('subscription_id') } });
        state.me.billing = r.billing;
        toast(r.status === 'ACTIVE' ? 'Premium attivato, grazie! 🎉' : 'Pagamento in elaborazione: Premium si attiverà a breve');
      } catch (e) { toast(e.message, true); }
    } else {
      toast('Pagamento annullato');
    }
  }
  router();
  flushQueue();
})();
