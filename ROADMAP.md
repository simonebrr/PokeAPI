# CardLedger – Piano di sviluppo

Obiettivo: passare da gestionale personale a prodotto vendibile (Free + Premium).
Ogni fase è utilizzabile da sola. Stime indicative per una persona con l'aiuto di Claude.

Legenda priorità: 🔴 indispensabile · 🟠 importante · 🟢 utile

---

## Fase 0 – Sicurezza (subito, ~1 ora)

| # | Cosa | Perché | Priorità |
|---|------|--------|----------|
| 0.1 | Rigenerare il **token CardTrader** e toglierlo dal repo (`server/app/src/api/token.txt`, `server/app/public/token.txt`) | È pubblico su GitHub: chiunque può usarlo a nome tuo | 🔴 |
| 0.2 | Rigenerare il **token del bot Telegram** (`tBot.py`) e leggerlo da variabile d'ambiente | Stesso motivo | 🔴 |
| 0.3 | Password del database e di phpMyAdmin in `.env` invece che in `docker-compose.yaml` | Le password attuali sono quelle di esempio | 🔴 |
| 0.4 | Rendere il repo **privato** se non deve essere pubblico | Riduce i rischi | 🟠 |

**Fatto quando:** nessun segreto nei file su GitHub, i vecchi token non funzionano più.

---

## Fase 1 – Messa online (~1 giorno)

| # | Cosa | Dettagli | Priorità |
|---|------|----------|----------|
| 1.1 | **Server** | VPS (es. Hetzner, OVH, Contabo) ~5 €/mese, Ubuntu + Docker | 🔴 |
| 1.2 | **Dominio** | es. `cardledger.it` ~10 €/anno | 🔴 |
| 1.3 | **HTTPS** | Caddy o Traefik davanti all'app, certificato automatico Let's Encrypt | 🔴 |
| 1.4 | **docker-compose di produzione** | Niente porte MySQL/phpMyAdmin esposte su internet, riavvio automatico | 🔴 |
| 1.5 | **Backup automatico** | Dump giornaliero del database, copia fuori dal server (es. Backblaze/Drive), conservazione 30 giorni, prova di ripristino | 🔴 |
| 1.6 | **Aggiornamenti semplici** | Script `deploy.sh`: `git pull` + rebuild in un comando | 🟠 |
| 1.7 | **Monitoraggio** | Avviso se il sito va giù (es. UptimeRobot, gratis) | 🟠 |

**Fatto quando:** tu e il tuo socio usate l'app da telefono su `https://tuodominio`, con backup che girano ogni notte.

---

## Fase 2 – Pronto per i clienti (~2-3 giorni)

| # | Cosa | Dettagli | Priorità |
|---|------|----------|----------|
| 2.1 | **Invio email** | Servizio SMTP (es. Brevo, gratis fino a 300/giorno) | 🔴 |
| 2.2 | **Recupero password** | Link via email con scadenza | 🔴 |
| 2.3 | **Verifica email** all'iscrizione | Evita account falsi | 🟠 |
| 2.4 | **Protezione login** | Limite tentativi (anti brute-force) | 🔴 |
| 2.5 | **Pagina di presentazione** | Cos'è, screenshot, tabella Free/Premium, "Inizia gratis" | 🔴 |
| 2.6 | **Privacy policy + Termini d'uso + Cookie** | Obbligatori (GDPR). Generatori tipo iubenda | 🔴 |
| 2.7 | **Eliminazione account e dati** | Diritto GDPR, dalla pagina "Altro" | 🔴 |
| 2.8 | **Stripe attivo** | Account Stripe, prodotto Premium, webhook (vedi `server/GESTIONALE.md`) | 🟠 |
| 2.9 | **Aspetti fiscali** | Partita IVA / regime forfettario o prestazione occasionale: sentire un commercialista prima di incassare | 🔴 |
| 2.10 | **Email automatiche** | Benvenuto, prova Premium in scadenza (3 giorni prima), ricevuta | 🟢 |

**Fatto quando:** uno sconosciuto può trovare il sito, iscriversi, recuperare la password e pagare Premium in regola.

---

## Fase 3 – Funzioni che fanno scegliere Premium (~1-2 settimane)

| # | Funzione | Valore per l'utente | Piano | Priorità |
|---|----------|---------------------|-------|----------|
| 3.1 | **Storico valore carte** | Grafico del prezzo di ogni carta e del magazzino nel tempo | Premium | 🟠 |
| 3.2 | **Aggiornamento automatico notturno** dei valori | Il magazzino è sempre aggiornato senza toccare niente | Premium | 🟠 |
| 3.3 | **Avvisi di prezzo** | "Charizard è salito del 30%: è il momento di vendere" (email/notifica) | Premium | 🟠 |
| 3.4 | **Divisione profitti tra soci** | Chi ha messo quanto nel lotto, quanto spetta a ciascuno | Premium | 🟠 |
| 3.5 | **Import da CSV/Excel** | Caricare collezioni esistenti in un colpo | Free (limite) / Premium | 🟢 |
| 3.6 | **Foto delle carte** | Foto propria (fronte/retro, difetti) per carte reali | Free | 🟢 |
| 3.7 | **Report fiscale annuale** | Totale acquisti/vendite per anno, esportabile | Premium | 🟢 |
| 3.8 | **Gradate (PSA/BGS/CGC)** | Campo voto e ente, prezzo separato dalle raw | Free | 🟢 |
| 3.9 | **Etichette e lista vendita** | Testo pronto da incollare su Vinted/Cardmarket | Premium | 🟢 |

---

## Fase 4 – Crescita (dopo i primi utenti)

| # | Cosa | Note | Priorità |
|---|------|------|----------|
| 4.1 | **Scansione carta con fotocamera** | Riconoscimento automatico nome/numero: la funzione più richiesta, ma la più complessa | 🟠 |
| 4.2 | **App installabile (PWA) e uso offline** | Icona sul telefono, inserimento anche senza rete alle fiere | 🟢 |
| 4.3 | **Altri giochi** | Magic, Yu-Gi-Oh, One Piece (CardTrader li supporta già) | 🟢 |
| 4.4 | **Prezzi da più fonti** | Cardmarket, eBay venduti, oltre a CardTrader | 🟢 |
| 4.5 | **Piano annuale** | es. 49 €/anno (2 mesi gratis) | 🟢 |
| 4.6 | **Programma inviti** | 1 mese Premium gratis per ogni amico che si iscrive | 🟢 |
| 4.7 | **Statistiche d'uso** nel pannello Admin | Funzioni più usate, dove gli utenti abbandonano | 🟢 |

---

## Costi indicativi

| Voce | Costo |
|------|-------|
| Server VPS | ~5 €/mese |
| Dominio | ~10 €/anno |
| Email (Brevo) | 0 € fino a 300/giorno |
| Backup esterno | 0-2 €/mese |
| Stripe | ~1,5% + 0,25 € a transazione |
| Privacy/termini (iubenda) | ~30 €/anno |
| **Totale fisso** | **~7-10 €/mese** → servono 2 utenti Premium per andare in pari |

---

## Da decidere insieme

- [ ] Nome definitivo e dominio (CardLedger è provvisorio)
- [ ] Prezzo Premium (4,99 €/mese?) e limite carte Free (200?)
- [ ] Quali funzioni della Fase 3 fare per prime
- [ ] Forma fiscale per incassare
- [ ] Pagamenti solo Stripe o anche PayPal/bonifico manuale

---

**Ordine consigliato:** Fase 0 → Fase 1 → Fase 2 → 3.1 + 3.2 + 3.4 → resto in base al feedback degli utenti.
