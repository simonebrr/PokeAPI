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

## Struttura
- `app/src/gestionale/db.php` – connessione e tabelle (`g_*`, create in automatico).
- `app/src/gestionale/api.php` – API JSON `/api/...` con sessione PHP.
- `app/public/app/` – frontend (HTML/CSS/JS, nessuna build).

## Configurazione (env opzionali)
- `GESTIONALE_DSN`, `GESTIONALE_DB_USER`, `GESTIONALE_DB_PASSWORD` (default: MySQL del compose; supporta anche `sqlite:/path/db.sqlite`).
- `CARDTRADER_TOKEN` o `CARDTRADER_TOKEN_FILE` (default: `src/api/token.txt`).
