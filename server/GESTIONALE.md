# CardLedger – gestionale compravendita carte

Web app (mobile-first) per tracciare lotti, carte, vendite e profitti.

## Avvio
```
cd server && docker compose up --build
```
Apri http://localhost:8080 → reindirizza a `/app/`.

## Funzioni
- **Lotti**: prezzo pagato, data, provenienza. Mostra valore trovato e moltiplicatore (es. 10 € → 214 € = x21,4).
- **Carte**: nel lotto o singole, con lingua, condizione, quantità, valore stimato. Ricerca nel catalogo CardTrader (tabella `single_cards`) e prezzo di mercato live.
- **Vendite**: prezzo, commissioni, canale (Vinted, Cardmarket…), totali per canale.
- **Riepilogo**: speso, incassato netto, valore magazzino, profitto potenziale.
- **Multi-utente**: ogni account ha il suo negozio; il socio si unisce con il codice invito.
- **Export CSV** compatibile Excel.
- **Aggiornamento valori in blocco** da CardTrader (tutto il magazzino o un lotto).
- **Pannello proprietario** (tab 👑 Admin): entrate mensili (MRR), negozi, utenti, prove, conversione, attivazione manuale di Premium.

## Piani (freemium)
| | Free | Premium |
|---|---|---|
| Lotti, vendite, riepilogo, prezzo per carta, export | ✓ | ✓ |
| Carte in magazzino | `FREE_ITEM_LIMIT` (200) | illimitate |
| Aggiornamento valori in blocco | — | ✓ |
| Soci nello stesso negozio | — | ✓ |
| Andamento mensile | — | ✓ |

- Ogni nuovo negozio ha `TRIAL_DAYS` (14) giorni di Premium in prova, poi passa a Free senza perdere dati.
- Il negozio del proprietario (`ADMIN_EMAILS`) ha Premium gratis.

## Pagamenti con Stripe (facoltativo)
1. Crea un prodotto con prezzo mensile ricorrente su Stripe → copia l'ID prezzo (`price_...`) in `STRIPE_PRICE_ID`.
2. Copia la chiave segreta (`sk_...`) in `STRIPE_SECRET_KEY`.
3. Aggiungi un webhook verso `https://TUO-DOMINIO/api/billing/webhook` con gli eventi
   `checkout.session.completed`, `invoice.paid`, `customer.subscription.deleted` → copia il segreto (`whsec_...`) in `STRIPE_WEBHOOK_SECRET`.
4. Attiva il Customer Portal di Stripe (per far gestire/disdire l'abbonamento agli utenti).

Senza Stripe, Premium si attiva a mano dal pannello Admin (es. pagamento con bonifico/PayPal).

## Struttura
- `app/src/gestionale/db.php` – connessione e tabelle (`g_*`, create in automatico).
- `app/src/gestionale/api.php` – API JSON `/api/...` con sessione PHP.
- `app/public/app/` – frontend (HTML/CSS/JS, nessuna build).

## Configurazione
Copia `.env.example` in `.env` e compila. Altre variabili:
- `GESTIONALE_DSN`, `GESTIONALE_DB_USER`, `GESTIONALE_DB_PASSWORD` (default: MySQL del compose; supporta anche `sqlite:/path/db.sqlite`).
- `CARDTRADER_TOKEN` o `CARDTRADER_TOKEN_FILE` (default: `src/api/token.txt`).
