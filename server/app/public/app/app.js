'use strict';
// CardLedger – gestionale compravendita carte Pokémon (vanilla JS, nessuna build).

const state = { me: null, lots: [] };
const app = document.getElementById('app');
const modalRoot = document.getElementById('modal-root');

const LANGS = ['it', 'en', 'jp', 'fr', 'de', 'es', 'pt', 'kr', 'zh'];
const CONDITIONS = ['Mint', 'Near Mint', 'Slightly Played', 'Moderately Played', 'Played', 'Poor'];
const CHANNELS = ['Vinted', 'Cardmarket', 'CardTrader', 'eBay', 'Subito', 'Di persona', 'Fiera', 'Altro'];
const STATUS_LABEL = { in_stock: 'In magazzino', listed: 'In vendita', sold: 'Venduta' };

// ---------- utility ----------
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const eur = (v) => v == null ? '—' : new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(v);
const signed = (v) => `<span class="${v >= 0 ? 'pos' : 'neg'}">${v > 0 ? '+' : ''}${eur(v)}</span>`;
const today = () => new Date().toISOString().slice(0, 10);
const fmtDate = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
const roiBadge = (r) => r == null ? '' : `<span class="badge roi">x${r.toLocaleString('it-IT')}</span>`;

async function api(path, { method = 'GET', body } = {}) {
  const res = await fetch('/api/' + path, {
    method,
    credentials: 'same-origin',
    headers: body !== undefined ? { 'Content-Type': 'application/json' } : {},
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && !path.startsWith('auth/')) {
    state.me = null;
    renderAuth();
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
    ${billingBanner()}
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
  ['👥', 'Soci', () => 'Più persone nello stesso negozio'],
  ['📈', 'Andamento mensile', () => 'Grafico di incassi e spese mese per mese'],
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
function barChart(rows, { value, tip, fmt = (v) => v }) {
  const max = Math.max(...rows.map(value), 0);
  const bars = rows.map((r, k) => {
    const v = value(r);
    const h = max > 0 ? Math.max(v > 0 ? 2 : 0, (v / max) * 100) : 0;
    return `<div class="bar-col" data-k="${k}" tabindex="0"><div class="bar" style="height:${h}%"></div><div class="bar-x">${monthLabel(r.month)}</div></div>`;
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
      ${i.image_url ? `<img class="thumb" loading="lazy" src="${esc(i.image_url)}" alt="">` : '<div class="thumb"></div>'}
      <div class="body">
        <div class="title">${esc(i.name)}${qty}</div>
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
        ${mode === 'register' ? `<div class="field"><label>Codice invito (opzionale, per unirti al socio)</label><input name="invite_code" autocapitalize="characters"></div>` : ''}
        <button class="btn block">${mode === 'login' ? 'Accedi' : 'Crea account'}</button>
      </form>
    </div>`;
  app.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => renderAuth(b.dataset.mode)));
  const form = document.getElementById('auth-form');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    guarded(form.querySelector('button'), async () => {
      state.me = await api(mode === 'login' ? 'auth/login' : 'auth/register', { method: 'POST', body: formData(form) });
      if (!location.hash) location.hash = '#dashboard';
      router();
    });
  });
}

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
  bindItemRows(main.querySelector('#top'), d.top_items, viewDashboard);
  bindItemRows(main.querySelector('#recent'), d.recent_sales, viewDashboard);
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
    ? `<div class="list">${state.lots.map(lotRow).join('')}</div>`
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
    <div class="actions"><button class="btn secondary sm" id="edit-lot">Modifica lotto</button><button class="btn secondary sm" id="bulk-lot">↻ Aggiorna valori</button></div>
    <h2>Carte (${l.cards})</h2>
    <div class="list" id="items">${l.items.length ? l.items.map((i) => itemRow(i)).join('') : '<div class="empty">Aggiungi le carte trovate nel lotto con “+ Carta”.<br>Basta segnare quelle che valgono qualcosa.</div>'}</div>`;
  main.querySelector('#edit-lot').onclick = () => lotForm(l);
  main.querySelector('#bulk-lot').onclick = () => bulkRefresh(l.items, reload);
  bindItemRows(main.querySelector('#items'), l.items, reload);
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
    <div class="actions" style="margin:0 0 10px"><button class="btn secondary sm" id="bulk">↻ Aggiorna valori da CardTrader</button></div>
    <div id="items-list" class="list"><div class="empty">Caricamento…</div></div>`, { fab: '+ Carta' });
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

function itemDetail(i, reload) {
  const rows = [
    ['Set', i.set_name], ['Numero', i.card_number], ['Lingua', i.language?.toUpperCase()], ['Condizione', i.card_condition],
    ['Quantità', i.quantity], ['Valore stimato', i.est_value != null ? eur(i.est_value) + (i.quantity > 1 ? ' cad.' : '') + (i.est_value_updated_at ? ` <span class="small muted">(CardTrader ${esc(fmtDate(i.est_value_updated_at.slice(0, 10)))})</span>` : '') : null],
    ['Costo acquisto', i.purchase_cost != null ? eur(i.purchase_cost) : null], ['Stato', STATUS_LABEL[i.status]],
    ['Venduta a', i.sale_price != null ? eur(i.sale_price) : null], ['Commissioni/spedizione', i.sale_fees != null ? eur(i.sale_fees) : null],
    ['Data vendita', fmtDate(i.sale_date)], ['Canale', i.sale_channel], ['Note', i.notes],
  ].filter(([, v]) => v != null && v !== '');
  openModal(i.name, `
    ${i.image_url ? `<img class="hero-img" src="${esc(i.image_url)}" alt="">` : ''}
    <div class="card">${rows.map(([k, v]) => `<div class="grid2" style="padding:4px 0"><span class="muted">${k}</span><span>${k.startsWith('Valore') || k.startsWith('Costo') || k.startsWith('Venduta') || k.startsWith('Commissioni') ? v : esc(v)}</span></div>`).join('')}</div>
    <div class="actions">
      ${i.status !== 'sold' ? '<button class="btn" id="sell">💶 Segna venduta</button>' : ''}
      ${i.status === 'in_stock' ? '<button class="btn secondary" id="list">Metti in vendita</button>' : ''}
      <button class="btn secondary" id="edit">Modifica</button>
    </div>`, (m) => {
    m.querySelector('#sell')?.addEventListener('click', () => sellForm(i, reload));
    m.querySelector('#edit').addEventListener('click', () => itemForm(i, {}, reload));
    m.querySelector('#list')?.addEventListener('click', (e) => guarded(e.target, async () => {
      await api(`items/${i.id}`, { method: 'PUT', body: { status: 'listed' } });
      closeModal(); toast('Messa in vendita'); reload();
    }));
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
      <div class="field"><label>Cerca nel catalogo (es. “ditto 63/113”) o incolla il link CardTrader</label>
        <input id="cat-q" type="search" placeholder="Cerca…" autocomplete="off"><div id="cat-res"></div></div>
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
    };
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
    <div class="kpis">
      <div class="kpi"><div class="label">Incassato lordo</div><div class="value">${eur(gross)}</div></div>
      <div class="kpi"><div class="label">Netto (− commissioni)</div><div class="value pos">${eur(gross - fees)}</div></div>
    </div>
    ${Object.keys(byChannel).length ? `<div class="chips">${Object.entries(byChannel).sort((a, b) => b[1] - a[1]).map(([c, v]) => `<span class="chip">${esc(c)}: ${eur(v)}</span>`).join('')}</div>` : ''}
    <div class="list" id="sold">${items.length ? items.map((i) => itemRow(i, { showLot: true })).join('') : '<div class="empty">Nessuna vendita registrata.</div>'}</div>`;
  bindItemRows(main.querySelector('#sold'), items, viewSales);
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
          <tr><td>Andamento mensile</td><td>—</td><td>✓</td></tr>
          <tr><td>Prezzo</td><td>Gratis</td><td>${eur(b.price)}/mese</td></tr>
        </table>
        <div class="actions">
          ${b.stripe_enabled && (b.state !== 'pro' || b.canceled) ? `<button class="btn" id="checkout">Passa a Premium · ${eur(b.price)}/mese</button>` : ''}
          ${b.stripe_enabled && b.has_stripe_customer ? '<button class="btn secondary" id="portal">Gestisci pagamento</button>' : ''}
          ${!b.stripe_enabled && b.state !== 'pro' ? '<p class="small muted">Per attivare Premium contatta il gestore del servizio.</p>' : ''}
        </div>` : ''}
    </div>
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
    <p class="muted">${esc(user.name)} · ${esc(user.email)}</p>
    <button class="btn danger" id="logout">Esci</button>`);
  const goTo = (path) => async (e) => guarded(e.target, async () => { location.href = (await api(path, { method: 'POST', body: {} })).url; });
  document.getElementById('checkout')?.addEventListener('click', goTo('billing/checkout'));
  document.getElementById('portal')?.addEventListener('click', goTo('billing/portal'));
  const ws = document.getElementById('ws');
  ws.addEventListener('submit', (e) => { e.preventDefault(); guarded(ws.querySelector('button'), async () => { state.me = await api('workspace', { method: 'PUT', body: formData(ws) }); toast('Salvato'); viewSettings(); }); });
  const join = document.getElementById('join');
  join.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!confirm('Passerai al negozio del codice inserito. Continuare?')) return;
    guarded(join.querySelector('button'), async () => { state.me = await api('workspace/join', { method: 'POST', body: formData(join) }); state.lots = []; toast('Ti sei unito a ' + state.me.workspace.name); viewSettings(); });
  });
  document.getElementById('logout').onclick = (e) => guarded(e.target, async () => { await api('auth/logout', { method: 'POST', body: {} }); state.me = null; state.lots = []; renderAuth(); });
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
  if (!state.me) return renderAuth();
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

window.addEventListener('hashchange', router);
(async () => {
  try { state.me = await api('me'); } catch { state.me = null; }
  router();
})();
