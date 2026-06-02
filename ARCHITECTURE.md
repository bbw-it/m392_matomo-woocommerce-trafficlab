# Architektur — M392 Matomo Lab

Diese Datei erklärt, **wie die Lehrumgebung aufgebaut ist**: wie WordPress/WooCommerce,
Matomo und das „Traffic Lab" zusammenspielen, wie das Tracking technisch funktioniert und
welchen Weg die Daten nehmen. Für die Bedienung/Installation siehe `README.md`.

---

## Inhalt

- [1. Überblick in einem Bild](#1-überblick-in-einem-bild)
- [2. Container & Ports](#2-container--ports)
- [3. Die Datenbank (MariaDB, zwei Schemas)](#3-die-datenbank-mariadb-zwei-schemas)
- [4. Wie Matomo mit WordPress verbunden ist](#4-wie-matomo-mit-wordpress-verbunden-ist)
- [5. Wie das Traffic Lab Einfluss nimmt](#5-wie-das-traffic-lab-einfluss-nimmt)
- [6. Der gesamte Datenfluss (zwei Wege)](#6-der-gesamte-datenfluss-zwei-wege)
- [7. Boot-/Initialisierungs-Reihenfolge](#7-boot--initialisierungs-reihenfolge)
- [8. Reproduzierbarkeit (Fixture)](#8-reproduzierbarkeit-fixture)
- [9. Wichtige Dateien](#9-wichtige-dateien)

---

## 1. Überblick in einem Bild

Drei browser-erreichbare Dienste, eine gemeinsame Datenbank und zwei einmalige
Einrichtungs-Container. Alles läuft lokal in Docker.

```
   ┌──────────────────────── Browser (Lehrperson / Lernende) ─────────────────────────┐
   │   Shop  :8090        Matomo-Berichte  :8091        Traffic-Lab-Dashboard  :8092   │
   └────┬──────────────────────────┬───────────────────────────────────┬──────────────┘
        │ Shop-HTML                 │ Berichte ansehen   +   Tracking    │ Dashboard
        │ (+ Matomo-Snippet)        │ senden  (Weg A, via matomo.js)     │ bedienen
        ▼                           ▼                                    ▼
   ┌───────────────┐         ┌─────────────────────────┐         ┌────────────────────┐
   │  wordpress    │         │        matomo  :8091    │         │  traffic  :8092    │
   │  :8090        │         │                         │         │  (Flask)           │
   │  Shop+Botiga  │         │   ┌─────────────────┐   │  Weg B  │  „Traffic Lab"     │
   │  + Tracking-  │         │   │   matomo.php    │   │ HTTP GET│  app.py +          │
   │    Snippet    │         │   │  TRACKING-API   │◄──┼─────────┤  generator.py      │
   │               │         │   │ (einziger       │   │ /matomo │  → sendet          │
   │               │         │   │  Dateneingang)  │   │ .php?…  │    /matomo.php?…    │
   │               │         │   └────────┬────────┘   │         └─────────┬──────────┘
   └──────┬────────┘         │            ▼            │                   ╎ liest Token
          │ DB: wordpress    │       DB: matomo        │                   ╎ (nur Historie)
          ▼                  └─────────────────────────┘             ┌─────▼──────┐
        (DB: wordpress und DB: matomo liegen in EINER MariaDB „db".)  │  Volume    │
                                                                      │matomo_token│
   Einrichtung (einmalig, dann Exit 0):                               └─────▲──────┘
     • wp-init     – spielt die Fixture ein, installiert Theme/Plugins        ╎ schreibt
     • matomo-init – installiert Matomo, erzeugt den API-Token  ──────────────┘ (matomo-init)

   ════════════════════════════════════════════════════════════════════════════════════
   Weg A = echte Besucher: der Browser lädt matomo.js von :8091 und sendet die Treffer selbst.
   Weg B = Traffic Lab:   generator.py ruft dieselbe Tracking-API serverseitig auf (kein Browser).
   →  BEIDE Wege landen auf demselben Endpunkt  /matomo.php ; Matomo unterscheidet sie nicht
      und schreibt daraus die Zeilen in „DB: matomo". (Details: Kapitel 4–6.)
```

Alle Dienste liegen im selben Docker-Netzwerk und erreichen sich **intern** über ihren
Service-Namen (`db`, `matomo`, `wordpress`). Der **Browser** erreicht sie über die
veröffentlichten Host-Ports `8090/8091/8092` (`localhost`). Der entscheidende Punkt:
**Daten gelangen ausschließlich über die HTTP-Tracking-API `/matomo.php` in Matomo** – egal
ob von einem echten Browser (Weg A) oder vom Traffic Lab (Weg B).

---

## 2. Container & Ports

| Container | Image | Host-Port → intern | Rolle |
|---|---|---|---|
| `db` | `mariadb:11.4` | – (nur intern `3306`) | Eine Instanz, **zwei** Datenbanken |
| `wordpress` | `wordpress:6.7-php8.3-apache` | **8090** → 80 | Shop (WooCommerce, Theme Botiga) |
| `matomo` | `matomo:5.3.0` | **8091** → 80 | Web-Analyse |
| `traffic` | selbst gebaut (Python/Flask) | **8092** → 8092 | Datengenerierungs-Dashboard |
| `wp-init` | `wordpress:cli-2.11` | – (einmalig) | Shop einrichten / Fixture einspielen |
| `matomo-init` | `curlimages/curl:8.11.1` | – (einmalig) | Matomo headless installieren + Token |

**Wichtige Volumes / Bind-Mounts**

```
./wordpress/www      ⇄  wordpress:/var/www/html        (Bind-Mount: WP-Docroot auf dem Host)
./wordpress/init/mu-plugins ⇄ …/wp-content/mu-plugins (ro)  (immer aktive Plugins)
./catalog.json        ⇄  traffic:/seed/catalog.json (ro)(gemeinsamer Produktkatalog)
matomo_token (Volume) ⇄  matomo-init schreibt, traffic liest   (API-Token-Austausch)
db_data / matomo_data (Volumes)                          (persistente DB- bzw. Matomo-Dateien)
```

---

## 3. Die Datenbank (MariaDB, zwei Schemas)

Es gibt **eine** MariaDB-Instanz mit **zwei voneinander getrennten** Datenbanken. WordPress
und Matomo teilen sich **keine** Tabellen — die einzige Verbindung zwischen ihnen ist der
Tracking-Datenstrom (Kapitel 4–6), nicht die Datenbank.

```
   ┌──────────────────────── db  (MariaDB 11.4) ────────────────────────┐
   │                                                                     │
   │   DB „wordpress"                      DB „matomo"                    │
   │   ┌───────────────────────────┐      ┌────────────────────────────┐ │
   │   │ wp_posts (Produkte, Seiten│      │ matomo_log_visit           │ │
   │   │ wp_options, wp_users …    │      │ matomo_log_link_visit_action│ │
   │   │ WooCommerce-Bestellungen) │      │ matomo_log_conversion …    │ │
   │   └───────────────────────────┘      │ matomo_archive_* (Berichte)│ │
   │           ▲                          └────────────────────────────┘ │
   │           │ Benutzer „wp"                     ▲ Benutzer „matomo"    │
   └───────────┼───────────────────────────────────┼─────────────────────┘
               │                                    │
         wordpress-Container                  matomo-Container
```

Beide Datenbanken + Benutzer werden beim allerersten Start vom Init-Skript
`db/init/01-init-databases.sh` angelegt — die Zugangsdaten kommen aus `.env` (kein
Hardcoding, damit Passwörter konsistent bleiben).

---

## 4. Wie Matomo mit WordPress verbunden ist

**Kurz: gar nicht direkt — sondern über den Browser.** WordPress bettet lediglich den
Matomo-JavaScript-Schnipsel in jede Seite ein. Der **Browser** lädt dann `matomo.js` von
Matomo (`:8091`) und schickt die Tracking-Treffer selbst dorthin. WordPress „spricht" also
nie selbst mit Matomo.

Das übernimmt das Must-Use-Plugin **`wordpress/init/mu-plugins/matomo-tracking.php`**. Es hängt
sich an `wp_head` und schreibt den Tracking-Code in den `<head>` jeder Shop-Seite:

```
                    ┌───────────────────────────────────────────────┐
   1. GET /shop/    │  wordpress  (:8090)                            │
   ───────────────► │  liefert HTML + <script> Matomo-Snippet        │
                    │  (mu-plugin matomo-tracking.php, Server-seitig) │
                    └───────────────────────────────────────────────┘
                                      │  HTML mit Tracking-Snippet
                                      ▼
                    ┌───────────────────────────────────────────────┐
                    │  Browser                                      │
                    │  2. lädt matomo.js von  http://localhost:8091 │
                    │  3. _paq.push([...]) → sendet Tracking-Treffer │
                    └───────────────────────┬───────────────────────┘
                                            │  GET :8091/matomo.php?idsite=1&…
                                            ▼
                    ┌───────────────────────────────────────────────┐
                    │  matomo  (:8091)  speichert Besuch/Aktion/Kauf │
                    └───────────────────────────────────────────────┘
```

Welche Ereignisse das Plugin meldet (kontextabhängig pro Seitentyp):

| Auf welcher Seite | Was an Matomo gemeldet wird | Matomo-Bericht |
|---|---|---|
| jede Seite | `trackPageView` | Besucher / Verhalten |
| Produktseite | `setEcommerceView(sku,name,kat,preis)` | E-Commerce → Produktansichten |
| Kategorieseite | `setEcommerceView(false,false,kategorie)` | E-Commerce → Kategorien |
| Suchergebnis (`/?s=…`) | `trackSiteSearch(begriff, …, treffer)` (statt PageView) | Verhalten → Suche |
| Warenkorb | `addEcommerceItem(…)` + `trackEcommerceCartUpdate(total)` | E-Commerce → Warenkörbe |
| Bestellbestätigung (`order-received`) | `addEcommerceItem(…)` + `trackEcommerceOrder(id,total,…)` | E-Commerce → Bestellungen (Conversion) |
| Klick auf PDF-Link (Blog) | automatisch via `enableLinkTracking` → Datei-Download | Verhalten → Downloads + **Ziel** „PDF-Download: INCI" |
| Kontaktformular abgesendet → `/danke/` | normaler Seitenaufruf der Danke-Seite (WPForms-Weiterleitung) | **Ziel** „Kontaktanfrage (Danke-Seite)" |

Der im HTML eingebettete Tracker zeigt auf `http://localhost:8091/` (Host-Port), weil der
Code im **Browser** der Lernenden läuft — nicht im Docker-Netz.

> **Ziele (Goals):** Neben E-Commerce-Conversions sind zwei weitere **Ziele** hinterlegt, beide
> von `matomo-init.sh` reproduzierbar angelegt:
> - **„PDF-Download: INCI-Leitfaden"** – matcht einen Datei-Download, dessen URL `inci` enthält
>   (der Leitfaden auf der Blog-Seite). Das Traffic Lab löst ihn bei ~3,5 % der Besuche aus.
> - **„Kontaktanfrage (Danke-Seite)"** – matcht einen Seitenaufruf, dessen URL `/danke` enthält.
>   Das Kontaktformular (WPForms) leitet nach dem Absenden auf `/danke/` weiter; `wp-init.sh` stellt
>   Seite und Weiterleitung reproduzierbar her. Das Traffic Lab löst das Ziel bei ~2 % der Besuche aus.

---

## 5. Wie das Traffic Lab Einfluss nimmt

Das **Traffic Lab** (`traffic`-Container, Port `:8092`) erzeugt **synthetischen** Traffic.
Anders als ein echter Browser lädt es **kein** WordPress und **kein** `matomo.js`, sondern
ruft die **Matomo-HTTP-Tracking-API direkt** auf (Server-zu-Server, intern über
`http://matomo/matomo.php`). Es „spielt Besucher" — inklusive Produktansichten, Suchen,
Warenkörben und Käufen.

```
   ┌──────────────────────────────────────────────────────────────┐
   │  traffic  (Flask, :8092)                                      │
   │                                                               │
   │   app.py  ── Dashboard-UI + Live-Tropf + Manuell + Backfill   │
   │      │                                                        │
   │      ▼  ruft auf                                              │
   │   generator.py  ── baut Besucherpfade aus catalog.json        │
   │      │            (gleiche Produkte/SKUs/URLs wie der Shop)   │
   │      ▼                                                        │
   │   HTTP GET  http://matomo/matomo.php?idsite=1&rec=1&_id=…&…    │
   └───────────────────────────────┬──────────────────────────────┘
                                   │ (internes Docker-Netz, kein Browser)
                                   ▼
                       ┌────────────────────────┐
                       │  matomo  (:8091)        │
                       └────────────────────────┘
```

**Warum die Daten zum echten Shop passen:** Der Generator **synchronisiert die Produkte live aus
WooCommerce** (Name, Preis, Kategorie, echte Slugs und die E-Commerce-IDs `wc_<id>`) – siehe
„Produkt-Sync" weiter unten. Dadurch erscheinen synthetische und echte Käufe in Matomo unter
**denselben** Produkten/Kategorien/URLs, neue Produkte sind automatisch dabei, und Preisänderungen
wirken sofort. `catalog.json` liefert die Bestseller-Gewichtung und dient als Offline-Fallback.

**Was der Generator pro „Besuch" sendet** (`generator.py → simulate_visit`):

```
   ein simulierter Besuch  (fester Besucher-_id über alle Aktionen):
   ─────────────────────────────────────────────────────────────────
   [Kanal wählen] → Einstiegs-Referrer (urlref) nur auf 1. Aktion
        │
        ├─ ~30 %  On-Site-Suche       → search=…&search_count=…
        ├─ 1–2×   Kategorie-Ansicht   → _pkc=Kosmetik
        ├─ 1–4×   Produkt-Ansicht     → _pks/_pkn/_pkc/_pkp
        └─ Kauf?  (kanalabhängige Conversion)
               ├─ ja  → idgoal=0 & ec_id & revenue & ec_items  (Bestellung)
               └─ nein, aber Warenkorb (~20 %) → ec_items ohne ec_id (Abbruch)
```

### Konkret: so „schreibt" das Traffic Lab in Matomo

Der häufigste Denkfehler ist, „Daten schreiben" hieße „in eine Datenbank schreiben". Hier
läuft es anders: Das Traffic Lab schreibt **weder** in die Webseite (WordPress) **noch**
direkt in eine Datenbank. Es ruft denselben HTTP-Endpunkt auf, den auch ein echter Browser
benutzt – **`/matomo.php`**, die Matomo-Tracking-API. Matomo parst die Parameter und legt
selbst die Zeilen in *seiner* Datenbank an. Es kann nicht unterscheiden, ob ein Browser oder
ein Python-Skript den Treffer geschickt hat.

So sieht ein **echter** Aufruf aus `generator.py` aus – eine Produktansicht:

```
GET http://matomo/matomo.php
      ?idsite=1 &rec=1                  ← „nimm einen Treffer für Website 1 auf"
      &_id=8f3a91c2d4e5f6a7             ← Besucher-ID (gleich über alle Aktionen eines Besuchs)
      &url=http://localhost:8090/product/vinopure-…/
      &action_name=Vinopure Pore Purifying Gel Cleanser
      &_pks=wc_96 &_pkn=Vinopure… &_pkc=Kosmetik &_pkp=14   ← E-Commerce-Produktansicht
      &urlref=https://www.instagram.com/                    ← Einstiegskanal → „Social"

   →  Matomo antwortet 204 (No Content) und legt einen Besuch + eine Produktansicht an.
```

Ein **Kauf** im selben Besuch hängt zusätzlich an:

```
      &idgoal=0 &ec_id=4f1c8a &revenue=28
      &ec_items=[["wc_96","Vinopure…","Kosmetik",14,2]]
   →  Matomo verbucht eine E-Commerce-Bestellung (Conversion + Umsatz), zugeordnet
      zum Einstiegskanal „instagram" → Bericht *Akquise → Social*.
```

Wichtig – was auf **diesem** Weg (Matomo-Tracking) **nicht** passiert:

- Es wird **keine** WordPress-Seite geladen und der **Shop-Katalog nicht verändert**
  (Produkte, Seiten, Einstellungen bleiben unangetastet).
- Es wird **nicht** direkt in Matomos DB geschrieben – Matomo *protokolliert* nur, was ihm
  gemeldet wird. Damit es stimmig aussieht, **synchronisiert** der Generator die Produkte live
  aus WooCommerce (`GET /wp-json/m392/v1/products`, gecacht ~5 min): echte SKU `wc_<id>`, voller
  Name, echter Preis, Kategorie und Slug – exakt wie der Matomo-Tracker echte Browser-Käufe meldet.
  So stimmen Matomo-Produktberichte und Shop überein und **neue Produkte sind automatisch dabei**.
  `catalog.json` dient als Fallback und liefert die Bestseller-Gewichtung (`popularity`).

Genau dieselbe Art Request schickt auch der echte Browser (über `matomo.js`) – nur dass dort
der Browser den Treffer baut und das Traffic Lab ihn in Python (`requests.get`) baut.

### Echte WooCommerce-Bestellungen (zweiter Schreib-Weg)

Zusätzlich zum Matomo-Tracking legt das Traffic Lab **echte Bestellungen** an, die im
WP-Admin unter *WooCommerce → Bestellungen* erscheinen. Das ist ein **getrennter** Weg:
nicht über Matomo, sondern über einen geschützten REST-Endpunkt im WordPress-Container.

```
   traffic (app.py / orders.py)                         wordpress :8090
   ────────────────────────────                         ───────────────
     POST http://wordpress/wp-json/m392/v1/orders        mu-plugin
          Header  X-M392-Key: <gemeinsames Secret>  ───►  m392-order-api.php
          Body    { count, days_back }                    │
                                                           ▼  wc_create_order():
                                                      realistische Bestellung
                                                      (diverse Kund:in als echtes
                                                       Kund:innen-Konto + Adresse,
                                                       Bestseller-gewichtete Artikel,
                                                       Test-Zahlart, Status-Mix, datiert)
                                                           │
                                                           ▼
                                                      DB: wordpress  (Bestellung + Kund:in)
```

- **Kund:innen:** Pro Bestellung wird eine **echte WooCommerce-Kund:in** (Rolle *customer*) angelegt
  oder – zu ~35 % – eine bestehende wiederverwendet (wiederkehrende Käufer:innen), der Bestellung
  via `set_customer_id()` zugeordnet und die wc-admin-Kunden-Lookup-Tabelle aktualisiert
  (`DataStore::sync_order_customer`). Dadurch erscheinen sie unter *WooCommerce → Kunden* bzw.
  *Analytics → Kunden* – nicht als anonyme Gäste.

- **Wann:** ein Startseed (`TRAFFIC_SEED_ORDERS`, Standard 120) – über **dasselbe
  ~24-Monats-Fenster wie die Matomo-Historie** verteilt, mit demselben Wachstums-Trend und
  Wochenrhythmus (Python liefert pro Bestellung einen Zeitstempel; PHP datiert die Order exakt
  darauf). So entspricht die Bestell-Historie zeitlich dem Matomo-Verlauf. Dazu laufend bei
  jedem Live-Drip-Kauf + beim manuellen „Käufe erzwingen".
- **Realismus liegt in PHP:** der Endpunkt hat vollen WooCommerce-Zugriff und baut die Order
  serverseitig (echte Produkte/Preise, Versand, Totalsummen, Status). E-Mails werden während
  der Erzeugung unterdrückt (kein SMTP in der Lehrumgebung).
- **Auth:** gemeinsames Secret `M392_ORDER_API_KEY` (Header `X-M392-Key`); falscher/leerer
  Schlüssel → `401`.
- **Nicht in der Fixture:** Bestellungen und Kund:innen sind – wie die Matomo-Daten –
  transiente Demodaten. Die Fixture (`shop.sql.gz`) ist **bestellungs- und kund:innenfrei**;
  der Bestseller-Prior (`total_sales`) wird beim Restore aus `catalog.json` gesetzt (Schritt 8c
  in `wp-init.sh`). Der Startseed ist idempotent (füllt nur auf den Zielwert auf), bei
  `down -v && up -d` entstehen Bestellungen + Kund:innen frisch über ~24 Monate.

> Hinweis: Die Bestellungen sind ein **paralleler, in sich stimmiger** Strom (gleiche
> Produkte/Bestseller, gleiche Test-Zahlarten) – sie sind **nicht** 1:1 dieselben Transaktionen
> wie die 24-Monats-Matomo-Historie (das würde die Bestellliste fluten). Für die Lehre zählt,
> dass Shop **und** Matomo dieselbe Geschichte erzählen.

Die wichtigsten Stellschrauben, mit denen das Traffic Lab die Matomo-Daten formt:

| Mechanismus | Wirkung in Matomo | Wo im Code |
|---|---|---|
| **Live-Tropf** (organisch, Poisson-Schübe) | laufend neue Besuche/Käufe in Echtzeit | `app.py · _drip_worker` |
| **Manuell senden** | sofort X Besuche / Y Käufe | `app.py · /api/generate-*` |
| **Backfill** (Standard 730 Tage) | **datierte Historie** (`cdt`) ⇒ gefüllte Zeitreihen über 24 Monate | `generator.py · backfill` |
| **Produkt-Popularität** (stark gespreizt) | klare **Bestseller** + langer Schwanz | `catalog.json · popularity` |
| **Akquise-Kanäle** (`urlref`) | **Social Media** als stärkster Verkaufskanal (Instagram/Facebook/…) | `generator.py · CHANNELS` |
| **Conversion-Rate** (Regler) | Anteil Käufe; Schnitt bleibt erhalten (Kanal-Mult. normiert) | `app.py · STATE` / `generator.py` |
| **Echte Bestellungen** | sichtbar in *WooCommerce → Bestellungen* (Startseed + Live) | `orders.py` + `init/mu-plugins/m392-order-api.php` |
| **PDF-Downloads** | füllen das Ziel „PDF-Download: INCI" (*Verhalten → Downloads*) | `generator.py` (~3,5 %) + `catalog.json` |
| **Kontaktanfragen** | Aufruf `/danke/` ⇒ füllt das Ziel „Kontaktanfrage" (*Ziele*) | `generator.py` (~2 %) + `catalog.json` |

**Token-Austausch:** Für **datierte** Treffer in der Vergangenheit verlangt Matomo einen
API-Token (`token_auth`) und den Parameter `cdt`. `matomo-init` erzeugt den Token und legt
ihn im Volume `matomo_token` ab; der `traffic`-Container liest ihn von dort
(`/token/token_auth`). Live-Treffer („jetzt") brauchen keinen Token.

```
   matomo-init  ──schreibt──►  Volume matomo_token (/token/token_auth)  ──liest──►  traffic
```

---

## 6. Der gesamte Datenfluss (zwei Wege)

Der Kern der Architektur: **zwei** Wege münden in **dieselbe** Matomo-Instanz und damit in
dieselben Berichte.

```
   WEG A — echte Besucher (clientseitig)
   ─────────────────────────────────────
     Browser ─GET─► wordpress :8090 ─HTML + matomo.js─► Browser ───┐
                                                  (der Browser       │
                                                   sendet den Treffer)│ HTTP GET
   WEG B — Traffic Lab (serverseitig, ohne Browser/WordPress)        │ /matomo.php?…
   ──────────────────────────────────────────────────────────       │
     traffic :8092 · generator.py ─────────────────────────────┐    │
                                                                ▼    ▼
                                              ┌──────────────────────────────────┐
                                              │  matomo  :8091                    │
                                              │    /matomo.php                    │
                                              │    EIN Endpunkt für ALLE          │
                                              │    Tracking-Treffer (A wie B)     │
                                              │          │ schreibt               │
                                              │          ▼                        │
                                              │    DB: matomo  ──►  Berichte      │
                                              └──────────────────────────────────┘

   Gemeinsame Basis:  catalog.json (Traffic Lab)  ≡  echte WooCommerce-Produkte (Shop)
   ⇒ beide Wege erzeugen Daten unter denselben Produkten / Kategorien / URLs.
```

- **Weg A** ist „echt": WordPress liefert nur das Tracking-Snippet; das eigentliche Tracking
  macht der Browser. Ideal, um zu zeigen, **wie** ein Klick/Kauf in Matomo landet.
- **Weg B** ist „synthetisch": ohne Browser, direkt per API — schnell, datierbar (Historie),
  und steuerbar (Volumen, Conversion, Kanäle, Bestseller).

---

## 7. Boot-/Initialisierungs-Reihenfolge

`docker compose up -d` startet alles; die `depends_on`-Bedingungen erzwingen die richtige
Reihenfolge. Die beiden `*-init`-Container laufen **einmal** und beenden sich.

```
   t │
     │  db  ──────────────► healthy ✓
     │   │
     │   ├─► wordpress  ──► füllt ./wordpress/www mit WP-Core (falls leer)
     │   │       │
     │   │       └─► wp-init  ─ Marker gesetzt? ── nein ─► Fixture importieren
     │   │                      (shop.sql.gz + uploads), Theme/Plugins (gepinnt)
     │   │                      installieren, Permalinks, .htaccess → Exit 0
     │   │
     │   └─► matomo  ─────► matomo-init  ─ installiert headless via curl,
     │                       legt Website id=1 an (Währung EUR, E-Commerce + Suche an),
     │                       erzeugt token_auth → Volume matomo_token → Exit 0
     │
     │  traffic  ─ wartet auf Matomo (installiert + Token) ─►
     │              Auto-Seed: Backfill 730 Tage (~24 Monate)  ─►  Live-Tropf läuft
     ▼
```

Reihenfolge-Garantien:
- `wp-init`/`matomo-init` warten via `depends_on` auf `db` (healthy) bzw. den jeweiligen Dienst.
- `traffic` pollt aktiv, bis Matomo **installiert** ist **und** ein gültiger Token vorliegt
  (`generator.wait_for_ready`) — sonst liefen die ersten Treffer ins Leere.

---

## 8. Reproduzierbarkeit (Fixture)

Der Demo-Shop ist als **Fixture** eingefroren, damit ein kompletter Reset wieder denselben
Stand erzeugt:

```
   wordpress/init/fixture/
     ├─ shop.sql.gz      ← kompletter WordPress-DB-Dump (Produkte, Bewertungen,
     │                      Seiten, Blog, Einstellungen: Sprache/EUR/Berlin, total_sales …)
     └─ uploads.tar.gz   ← wp-content/uploads (Produkt-/Beitragsbilder)
```

- Beim frischen Start (`down -v && up -d`) spielt `wp-init` die Fixture ein und installiert
  Theme/Plugins in **gepinnten** Versionen nach → identischer Shop.
- Die **Matomo-Daten** sind bewusst **nicht** Teil der Fixture: sie werden beim Start vom
  Traffic Lab neu erzeugt (24-Monats-Backfill). So ist die Historie immer „frisch datiert".
- Hinweis Bind-Mount: `down -v` löscht die Docker-Volumes (DB, Matomo), **nicht** den
  Host-Ordner `./wordpress/www`. `wp-init` spielt die Fixture sauber darüber.

---

## 9. Wichtige Dateien

```
.
├─ docker-compose.yml              # Orchestrierung aller Container, Volumes, Ports
├─ .env(.example)                  # zentrale Konfiguration (Ports, Versionen, Passwörter, Tropf)
├─ catalog.json                    # Produktkatalog des Traffic Lab (Spiegel des echten Shops)
│
├─ db/init/01-init-databases.sh    # legt beide DBs + Benutzer an (aus .env)
│
├─ wordpress/
│  ├─ init/                        # Einrichtung + versioniertes Material (Bind-Mounts)
│  │  ├─ wp-init.sh                # Fixture-Restore + Theme/Plugins + Permalinks
│  │  ├─ fixture/                  # eingefrorener Shop (DB-Dump + Uploads)
│  │  └─ mu-plugins/
│  │     ├─ matomo-tracking.php    # ← Verbindung WP→Matomo (JS-Tracking, E-Commerce, Suche)
│  │     ├─ m392-test-payments.php # Test-Zahlarten (Rechnung, Kreditkarte, TWINT)
│  │     ├─ m392-german-shop.php   # deutsche Labels/Übersetzungen
│  │     ├─ m392-shop-filters.php  # Produktfilter & Sortierung im Shop
│  │     └─ m392-order-api.php     # ← REST-Endpunkt: Traffic Lab legt echte Bestellungen an
│  └─ www/                         # WordPress-Docroot (Bind-Mount, generiert; nicht versioniert)
│
├─ matomo/matomo-init.sh           # Matomo headless installieren, Site+Ziele, Token erzeugen
│
└─ traffic/                        # „Traffic Lab"
   ├─ app.py                       # Flask: Dashboard, Live-Tropf, Manuell, Backfill, Status
   ├─ generator.py                 # ← Tracking: baut Besuche/Käufe via Matomo-API
   ├─ orders.py                    # ← ruft den Order-API-Endpunkt auf (echte Bestellungen)
   └─ templates/index.html         # Dashboard-Oberfläche
```

**Zwei Zeilen, die das Zusammenspiel definieren:**

- `wordpress/init/mu-plugins/matomo-tracking.php` → `$matomo_url = 'http://localhost:8091/'`
  (Browser-seitige Matomo-URL; verbindet **echte** Besucher mit Matomo).
- `traffic/generator.py` → `MATOMO_URL = 'http://matomo'`, `ID_SITE = 1`
  (interne Tracking-API; verbindet das **Traffic Lab** mit Matomo).

> Diese Umgebung ist ausschließlich für Schulung/Demo auf dem lokalen Rechner gedacht –
> nicht für den Produktivbetrieb (bewusst schwache Passwörter, Fake-Zahlungen,
> deaktivierter Trusted-Host-Check). Details siehe `README.md`.
