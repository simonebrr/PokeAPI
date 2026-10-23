# CardLedger – gestionale compravendita carte

Web app (mobile-first, installabile) per tracciare lotti, carte, vendite e profitti.
Modello freemium: **Free** per sempre + **Premium** facoltativo.

## Avvio in locale
```bash
cd server
cp .env.example .env      # compila almeno CARDTRADER_TOKEN
docker compose up --build
```
- Sito: http://localhost:8080 (presentazione) → app su `/app/`
- phpMyAdmin: http://localhost:8081
- Senza SMTP le email (conferma, reset password…) compaiono in `docker compose logs php`

## Funzioni
| Area | Cosa fa | Piano |
|---|---|---|
| Lotti | prezzo pagato, valore trovato, moltiplicatore (es. x21) | Free |
| Carte | catalogo CardTrader, link CardTrader, scansione con fotocamera, foto proprie, gradate, giochi diversi | Free |
| Prezzi | prezzo CardTrader per lingua e condizione | Free |
| Vendite | prezzo, commissioni, canale, testo annuncio pronto | Free |
| Import/Export | CSV/Excel in entrambe le direzioni | Free |
| Carte in magazzino | limite `FREE_ITEM_LIMIT` (200) | Free / illimitate Premium |
| Aggiornamento valori | in blocco e automatico ogni notte | Premium |
| Storico e avvisi | storico prezzo per carta, valore magazzino giornaliero, avvisi email | Premium |
| Soci | più utenti nello stesso negozio, quote per lotto, resoconto per socio | Premium |
| Report | grafici mensili, report annuale esportabile | Premium |
| Sbusto o rivendo? | per bustine, ETB, box: valore atteso per bustina, pull rate per rarità, simulazione di 3000 sbustamenti (probabilità di rientrare), verdetto e prezzo consigliato di rivendita | Premium |
| Inviti | 30 giorni di Premium per ogni amico che conferma l'email (max 12) | tutti |
| Offline | consultazione ultimi dati, carte create offline salvate al ritorno della rete | tutti |

Nuovi negozi: `TRIAL_DAYS` (14) giorni di Premium in prova (30 se invitati), poi Free senza perdere dati.

## Pannello proprietario (tab 👑 Admin)
Visibile agli utenti in `ADMIN_EMAILS` (o al primo registrato). Mostra entrate mensili, negozi per piano,
conversione, nuovi utenti, imbuto di attivazione, uso delle funzioni; permette di attivare Premium a mano.

## Pull rate (Sbusto o rivendo?)
Valori di default indicativi della community (ere Scarlatto e Violetto, Spada e Scudo), in `src/gestionale/sealed.php`.
Il proprietario li può correggere set per set direttamente dalla pagina (tabella "Pull rate per rarità").
Le rarità senza pull rate non vengono conteggiate e sono elencate sotto la tabella. Dati CardTrader in cache 6 ore.

## Pagamenti
### PayPal (consigliato per iniziare)
1. Su [developer.paypal.com](https://developer.paypal.com) → *Apps & Credentials* → crea un'app (modalità **Sandbox**) → copia *Client ID* e *Secret* nel `.env`.
2. Crea il piano mensile (prezzo = `PLAN_PRICE`):
   ```bash
   docker compose exec php php /var/www/html/src/gestionale/paypal-setup.php
   ```
   Copia `PAYPAL_PLAN_ID=...` stampato nel `.env` e riavvia (`docker compose up -d`).
3. Prova con un account "personal" sandbox (developer.paypal.com → *Sandbox accounts*).
4. Per incassare davvero: credenziali **Live**, `PAYPAL_MODE=live`, rilancia lo script per creare il piano live.

Come funziona: l'utente approva su PayPal → al ritorno Premium si attiva subito. Rinnovi, disdette e
pagamenti falliti arrivano via webhook (registrato in automatico dallo script se `APP_URL` è in https)
e comunque vengono ricontrollati ogni notte. Lo stato è sempre riletto dalle API PayPal.

### Stripe (alternativa)
Prodotto con prezzo mensile → `STRIPE_PRICE_ID`, chiave `STRIPE_SECRET_KEY`, webhook verso
`/api/billing/webhook` con eventi `checkout.session.completed`, `invoice.paid`, `customer.subscription.deleted`
→ `STRIPE_WEBHOOK_SECRET`.

Senza pagamenti online configurati, Premium si attiva a mano dal pannello Admin.

## Email
Configura `SMTP_*` nel `.env` (es. Brevo, gratis fino a 300/giorno). Email inviate: conferma indirizzo,
benvenuto, recupero password, promemoria fine prova, avvisi di prezzo, premio inviti.

## Messa online
```bash
# sul server, con DOMAIN e APP_URL nel .env
./scripts/deploy.sh
```
- `docker-compose.prod.yml`: Caddy con HTTPS automatico, DB e phpMyAdmin non esposti
- `scripts/backup.sh` (da mettere nel crontab del server ogni notte), `scripts/restore.sh`
- Su un server nuovo imposta `DB_PASSWORD` e `DB_ROOT_PASSWORD` **prima** del primo avvio

## Attività automatiche (cron nel container)
Ogni notte alle 3:30: riallineamento PayPal, aggiornamento prezzi Premium + email avvisi,
fotografia del valore del magazzino, promemoria fine prova. Ogni minuto: import catalogo CardTrader.
Esecuzione manuale: `docker compose exec php php /var/www/html/src/gestionale/cron.php [paypal|prices|snapshots|trial]`

## Struttura
- `app/src/gestionale/` – backend: `api.php` (rotte), `db.php` (schema e migrazioni automatiche),
  `billing.php` (piani, Stripe, Admin), `paypal.php`, `account.php` (email, reset, GDPR),
  `premium.php` (storico, avvisi, soci, import, foto, report), `growth.php` (inviti, statistiche), `cron.php`
- `app/public/app/` – frontend (HTML/CSS/JS senza build, service worker `sw.js`)
- `app/public/home.html`, `privacy.html`, `termini.html` – presentazione e **bozze** legali da completare

## Da fare a mano
- Completare e far verificare privacy e termini (parti evidenziate)
- Aspetti fiscali prima di incassare (commercialista)
- Rigenerare i token CardTrader/Telegram se non già fatto
