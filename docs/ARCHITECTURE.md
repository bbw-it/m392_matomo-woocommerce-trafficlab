# Architektur — M392 Matomo Lab

Diese Datei erklärt, **wie die Lehrumgebung aufgebaut ist und warum sie so aufgebaut ist**:
wie WordPress/WooCommerce, Matomo und der „Traffic Lab Generator" zusammenspielen, wie das
Tracking technisch funktioniert und welchen Weg die Daten nehmen. Für Bedienung/Installation
siehe `README.md`, für Entwickler-Gotchas `docs/HANDOFF.md`.

---

## Inhalt

- [1. Überblick in einem Bild](#1-überblick-in-einem-bild)
- [2. Die fünf Leitideen](#2-die-fünf-leitideen)
- [3. Container & Ports](#3-container--ports)
- [4. Die Datenbank (MariaDB, zwei Schemas)](#4-die-datenbank-mariadb-zwei-schemas)
- [5. Weg A: Wie echte Besuche in Matomo landen](#5-weg-a-wie-echte-besuche-in-matomo-landen)
- [6. Weg B: Der Traffic Lab Generator](#6-weg-b-der-traffic-lab-generator)
- [7. Der A/B-Test (Original vs. Shop-Variante)](#7-der-ab-test-original-vs-shop-variante)
- [8. Boot-/Initialisierungs-Reihenfolge](#8-boot--initialisierungs-reihenfolge)
- [9. Reproduzierbarkeit (Fixture & Schichten-Modell)](#9-reproduzierbarkeit-fixture--schichten-modell)
- [10. Wichtige Dateien](#10-wichtige-dateien)

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
   │  Shop+Botiga  │         │   ┌─────────────────┐   │  Weg B  │  „Traffic Lab      │
   │  + Tracking-  │         │   │   matomo.php    │   │ HTTP GET│   Generator"       │
   │    Snippet    │         │   │  TRACKING-API   │◄──┼─────────┤  app.py +          │
   │       ▲       │         │   │ (einziger       │   │ /matomo │  generator.py      │
   │       │ REST  │         │   │  Dateneingang)  │   │ .php?…  │        │           │
   │       └───────┼─────────┼───┼─────────────────┼───┼─────────┼────────┘           │
   │  m392/v1/*    │         │   └────────┬────────┘   │         │  orders.py         │
   └──────┬────────┘         │            ▼            │         └─────────┬──────────┘
          │ DB: wordpress    │       DB: matomo        │                   ╎ liest Token
          ▼                  └─────────────────────────┘                   ╎ (nur Historie)
        (DB: wordpress und DB: matomo liegen in EINER MariaDB „db".) ┌─────▼──────┐
                                                                      │  Volume    │
   Einrichtung (einmalig, dann Exit 0):                               │matomo_token│
     • wp-init     – spielt die Fixture ein, installiert Theme/Plugins└─────▲──────┘
     • matomo-init – installiert Matomo, erzeugt den API-Token  ────────────┘ (schreibt)

   ════════════════════════════════════════════════════════════════════════════════════
   Weg A = echte Besucher: der Browser lädt matomo.js von :8091 und sendet die Treffer selbst.
   Weg B = Traffic Lab:    generator.py ruft dieselbe Tracking-API serverseitig auf (kein Browser).
   →  BEIDE Wege landen auf demselben Endpunkt  /matomo.php ; Matomo unterscheidet sie nicht.
      Zusätzlich legt das Traffic Lab über die REST-API m392/v1/* ECHTE Bestellungen im Shop an.
```

Alle Dienste liegen im selben Docker-Netzwerk und erreichen sich **intern** über ihren
Service-Namen (`db`, `matomo`, `wordpress`). Der **Browser** erreicht sie über die
veröffentlichten Host-Ports `8090/8091/8092` (nur `localhost`, kein LAN).

---

## 2. Die fünf Leitideen

Wer eine Stelle der Umgebung ändern will, sollte diese fünf Entscheidungen kennen — sie
erklären, warum das Setup so gebaut ist, wie es ist.

**1. Es gibt genau EINEN Dateneingang in Matomo: die Tracking-API `/matomo.php`.**
Niemand schreibt direkt in Matomos Datenbank — weder der Shop noch das Traffic Lab.
Echte Browser (Weg A) und der Generator (Weg B) rufen denselben HTTP-Endpunkt auf;
Matomo kann sie nicht unterscheiden. Das ist didaktisch gewollt: Die Lernenden sehen,
dass *alle* Web-Analyse-Daten aus solchen Treffern entstehen — und dass man ihnen
deshalb nicht blind trauen kann (Datenqualität ist Lernziel von Modul 392).

**2. Synthetische Daten müssen vom echten Shop ununterscheidbar sein.**
Der Generator liest die Produkte **live aus WooCommerce** (gleiche SKUs `wc_<id>`, Namen,
Preise, Kategorien, URLs) und legt für jeden simulierten Kauf eine **echte Bestellung mit
exakt demselben Warenkorb** an (Defer-Flow, Kapitel 6). Ergebnis: Matomo-Berichte und
WooCommerce-Statistiken zeigen **dieselben Zahlen** (Produktumsatz ohne Versand) — und ein
echter Browser-Kauf einer Lernenden reiht sich nahtlos ein. Bewusste Ausnahmen als
Lerneffekt: Versand, Gutschein-Rabatte und Stornos erscheinen nur in WooCommerce
(„Tools messen Unterschiedliches").

**3. Reproduzierbarkeit durch Fixtures statt Live-Generieren.**
Die ~6-Monats-Historie (180 Tage Besuche, Käufe, Kund:innen) wird **nicht** bei jeder
Installation neu erzeugt (das dauerte 15–30 Minuten und ergab jedes Mal andere Zahlen),
sondern wurde einmal **gebacken** (`tools/bake-fixture.sh`) und liegt als SQL-Dump im Repo.
`install.sh` restauriert sie in ~2–3 Minuten und verschiebt alle Zeitstempel auf „heute"
(`tools/shift-dates.sh`). Jede Kursdurchführung startet so mit **identischen** Daten.

**4. Jede Anpassung lebt in genau einer dauerhaften Schicht.**
Versionierter Code (mu-Plugins, Skripte, Traffic Lab), eingefrorene Fixtures (Shop-DB,
Historie) oder Laufzeit (Live-Tropf, Matomo-Ziele). Was in keiner Schicht verankert ist,
überlebt `install.sh` nicht — das Schichten-Modell steht in Kapitel 9.

**5. Bewusste Vereinfachungen, weil es eine lokale Lehrumgebung ist.**
Schwache, lesbare Passwörter, HTTP statt HTTPS, deaktivierter Trusted-Host-Check,
Fake-Zahlarten, keine E-Mails. Das ist kein Nachlässigkeits-, sondern ein Lehr-Setup —
und selbst Gegenstand des Unterrichts („was wäre für Produktion zusätzlich nötig?").

---

## 3. Container & Ports

| Container | Image | Host-Port → intern | Rolle |
|---|---|---|---|
| `db` | `mariadb:11.4` | – (nur intern `3306`) | Eine Instanz, **zwei** Datenbanken |
| `wordpress` | `wordpress:6.7-php8.3-apache` | **8090** → 80 | Shop (WooCommerce, Theme Botiga) |
| `matomo` | `matomo:5.3.0` | **8091** → 80 | Web-Analyse |
| `traffic` | selbst gebaut (Python/Flask) | **8092** → 8092 | Traffic Lab Generator (Dashboard) |
| `wp-init` | `wordpress:cli-2.11` | – (einmalig) | Shop einrichten / Fixture einspielen |
| `matomo-init` | `curlimages/curl:8.11.1` | – (einmalig) | Matomo headless installieren + Token |

Die drei Web-Dienste haben **Healthchecks** (`docker compose ps` zeigt `healthy`),
alle Image-Versionen sind **gepinnt** (reproduzierbare Kurse, `.env`).

**Wichtige Volumes / Bind-Mounts**

```
./wordpress/www              ⇄  wordpress:/var/www/html        (Bind-Mount: WP-Docroot auf dem Host)
./wordpress/init/mu-plugins  ⇄  …/wp-content/mu-plugins (ro)   (immer aktive Plugins – Änderungen
                                                                sofort wirksam, kein Rebuild)
./seed/catalog.json          ⇄  traffic:/seed/catalog.json (ro)(Produktkatalog: Gewichtung + Fallback)
matomo_token (Volume)        ⇄  matomo-init schreibt, traffic liest  (API-Token-Austausch)
db_data / matomo_data        (Volumes; persistente DB- bzw. Matomo-Dateien)
```

---

## 4. Die Datenbank (MariaDB, zwei Schemas)

Es gibt **eine** MariaDB-Instanz mit **zwei voneinander getrennten** Datenbanken. WordPress
und Matomo teilen sich **keine** Tabellen — die einzige Verbindung zwischen ihnen ist der
Tracking-Datenstrom (Leitidee 1), nicht die Datenbank.

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

Beide Datenbanken + Benutzer legt `db/init/01-init-databases.sh` beim allerersten Start an —
die Zugangsdaten kommen aus `.env` (kein Hardcoding, kein Passwort-Drift).

---

## 5. Weg A: Wie echte Besuche in Matomo landen

**Kurz: WordPress und Matomo reden nie direkt miteinander — der Browser ist der Bote.**
WordPress bettet nur den Matomo-JavaScript-Schnipsel in jede Seite ein (Must-Use-Plugin
`matomo-tracking.php`, Hook `wp_head`). Der **Browser** lädt dann `matomo.js` von `:8091`
und schickt die Tracking-Treffer selbst dorthin.

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
| jede Seite | `trackPageView` (+ Custom-Dimension „AB-Variante", Kap. 7) | Besucher / Verhalten |
| Produktseite | `setEcommerceView(sku,name,kat,preis)` | E-Commerce → Produktansichten |
| Kategorieseite | `setEcommerceView(false,false,kategorie)` | E-Commerce → Kategorien |
| Suchergebnis (`/?s=…`) | `trackSiteSearch(begriff, …, treffer)` (statt PageView) | Verhalten → Suche |
| Warenkorb | `addEcommerceItem(…)` + `trackEcommerceCartUpdate(total)` | E-Commerce → Warenkörbe |
| Bestellbestätigung (`order-received`) | `addEcommerceItem(…)` + `trackEcommerceOrder(id,total,…)` | E-Commerce → Bestellungen (Conversion) |
| Klick auf PDF-Link (Blog) | automatisch via `enableLinkTracking` → Datei-Download | Verhalten → Downloads + **Ziel** „PDF-Download: INCI" |
| Kontaktformular abgesendet → `/danke/` | normaler Seitenaufruf der Danke-Seite (WPForms-Weiterleitung) | **Ziel** „Kontaktanfrage (Danke-Seite)" |

Der eingebettete Tracker zeigt auf `http://localhost:<MATOMO_PORT>/` (Standard `8091`), weil
der Code im **Browser** der Lernenden läuft — nicht im Docker-Netz. Der Port kommt aus `.env`
(via Compose als `M392_MATOMO_PORT` in den WordPress-Container gereicht).

> **Ziele (Goals):** Beide von `matomo-init.sh` reproduzierbar angelegt:
> **„PDF-Download: INCI-Leitfaden"** (Datei-Download, URL enthält `inci`; das Traffic Lab löst
> ihn bei ~3,5 % der Besuche aus) und **„Kontaktanfrage (Danke-Seite)"** (Seitenaufruf `/danke`;
> ~2 % der synthetischen Besuche).

---

## 6. Weg B: Der Traffic Lab Generator

Der `traffic`-Container (Flask, `:8092`) erzeugt **synthetischen** Traffic. Anders als ein
Browser lädt er kein WordPress und kein `matomo.js`, sondern ruft die **Matomo-Tracking-API
direkt** auf (Server-zu-Server, intern `http://matomo/matomo.php`). Das Dashboard hat drei
Tabs: **Dashboard** (KPIs, Chart, Live-Tropf, manuell senden), **Produkte**
(Beliebtheits-Steuerung) und **Protokoll**.

### 6.1 Ein simulierter Besuch

`generator.py · simulate_visit` spielt einen kompletten Besucherpfad — mit fester
Besucher-ID über alle Aktionen:

```
   [Kanal wählen] → Einstiegs-Referrer (urlref) nur auf 1. Aktion
        │             (Social dominiert; Newsletter als Kampagne pk_campaign)
        ├─ ~30 %  On-Site-Suche       → search=…&search_count=…
        ├─ 1–2×   Kategorie-Ansicht   → _pkc=Gesichtsreinigung
        ├─ 1–4×   Produkt-Ansicht     → _pks/_pkn/_pkc/_pkp   (gewichtet nach Beliebtheit)
        ├─ Funnel: /cart/ → /checkout/ → /order-received/      (Nicht-Käufer brechen ab)
        └─ Kauf?  (kanalabhängige Conversion-Rate)
               ├─ ja  → echte WC-Bestellung + Matomo-Conversion  (Defer-Flow, 6.2)
               └─ nein, aber Warenkorb → ec_items ohne ec_id     (abgebrochener Warenkorb)
   dazu gelegentlich: PDF-Download, Kontaktanfrage, Events, Content-Impressionen,
   Performance-Timings, Geografie (DE/CH/AT + ~5 % übriges Europa, das nicht kaufen kann)
```

Es wird **weder** in die Webseite **noch** direkt in eine Datenbank geschrieben — nur
HTTP-Treffer an `/matomo.php`, exakt wie ein Browser sie senden würde. Beispiel
(Produktansicht):

```
GET http://matomo/matomo.php
      ?idsite=1 &rec=1                  ← „nimm einen Treffer für Website 1 auf"
      &_id=8f3a91c2d4e5f6a7             ← Besucher-ID (konstant pro Besuch)
      &url=http://localhost:8090/product/vinopure-…/
      &_pks=wc_96 &_pkn=Vinopure… &_pkc=Gesichtsreinigung &_pkp=14
      &urlref=https://www.instagram.com/          ← Einstiegskanal → „Social"
   →  Matomo antwortet 204 und legt Besuch + Produktansicht an.
```

**Warum die Daten zum echten Shop passen:** Der Generator **synchronisiert die Produkte live
aus WooCommerce** (`GET /wp-json/m392/v1/products`, gecacht ~5 min; der Sync-Button im
Produkte-Tab erneuert sofort): echte SKU `wc_<id>`, Name, Preis, Kategorie, Slug — exakt das,
was auch der Browser-Tracker meldet. Neue Produkte sind automatisch dabei, Preisänderungen
wirken sofort. `catalog.json` liefert die Grund-Gewichtung und dient als Offline-Fallback.

### 6.2 Käufe: ein Warenkorb, zwei Systeme (Defer-Flow)

Damit Matomo und WooCommerce **dieselbe Bestellung** zeigen, läuft jeder simulierte Kauf
zweistufig:

```
   simulate_visit                       m392-order-api.php (WordPress)
   ──────────────                       ──────────────────────────────
   Kauf entschieden, Warenkorb steht
   Conversion wird NICHT sofort gesendet
        │
        ├─ 1) POST /wp-json/m392/v1/orders  ───►  wc_create_order() mit EXAKT
        │       carts=[[sku,menge],…]              diesem Warenkorb; antwortet mit
        │       Header X-M392-Key                  echtem Produktumsatz je Bestellung
        │                                          (orders_detail)
        └─ 2) complete_purchase(…)  ───►  Matomo-Conversion im SELBEN Besuch,
                                          mit dem ECHTEN Produktumsatz (ohne Versand)
```

- **Umsatz-Konvention:** Matomo-„Gesamteinnahmen" = WooCommerce-„Bruttoumsatz" =
  Produktumsatz **ohne Versand** (`get_subtotal()`). Versand, Gutschein-Rabatte und Stornos
  bleiben WooCommerce-exklusiv (Lerneffekt).
- **Fallback:** Ist die Bestell-Erzeugung deaktiviert (`TRAFFIC_CREATE_WC_ORDERS=false`)
  oder schlägt sie fehl, wird die Conversion mit den simulierten Warenkorb-Werten gesendet —
  Matomo-Daten entstehen immer; Teil-Fehlschläge werden im Protokoll geloggt.

Die Bestellungen selbst sind realistisch (alles serverseitig in PHP, voller
WooCommerce-Zugriff):

- **Kund:innen:** echte WooCommerce-Konten (divers, ~70 % deutsche Namen + Communities),
  mit Wahrscheinlichkeit `returning_rate` (Regler) eine **Bestandskund:in**. Der Pool wird
  **rollenunabhängig aus der Bestellhistorie** ermittelt und **gewichtet**: Kund:innen mit
  wenigen Bestellungen werden bevorzugt (Lose ≈ 1/Bestellanzahl), sodass Wiederkehrer breit
  streuen statt sich auf „Vielbesteller" zu konzentrieren. Anmelde-/Aktivdatum wird auf die
  Bestellhistorie zurückdatiert (`m392_fix_customer_dates`).
- **Stimmige Berichte:** jede Bestellung wird synchron in **alle** wc-admin-Analytics-Tabellen
  geschrieben (`m392_sync_order_analytics`) — ohne das blieben *Statistiken* und
  Kund:innen-Gesamtausgaben leer (es läuft kein Action-Scheduler). Der von `wp-init.sh`
  aktivierte **HPOS-Kompatibilitätsmodus** hält zusätzlich die Legacy-`postmeta` synchron.
- **Details:** Status-Mix je Zahlart (Rechnung öfter „on-hold"), ~18 % lösen den Gutschein
  `NATUR10` ein, Versand frei ab 50 €, E-Mails unterdrückt.
- **Auth:** gemeinsames Secret `M392_ORDER_API_KEY` (Header `X-M392-Key`), Vergleich mit
  `hash_equals` — falscher Schlüssel → `401`.

### 6.3 Beliebtheit steuern (Produkte-Tab)

Im Produkte-Tab bekommt jedes Produkt ein Gewicht **0–100** (fünfstufig benannt:
Ladenhüter → Wenig gefragt → Normal → Beliebt → Bestseller). Die Gewichte

- überschreiben die `popularity` aus `catalog.json` und steuern damit **Produktansichten
  UND Warenkörbe** — durch den Defer-Flow folgen die echten Bestellungen automatisch;
- werden als WP-Option `m392_product_weights` persistiert (Endpunkte
  `GET/POST m392/v1/weights`) und überleben so Container-Neustarts; `./install.sh` setzt
  sie zurück;
- starten als normierte Abbildung der bisherigen Katalog-Gewichtung (stärkstes Produkt = 100).

### 6.4 Die REST-Endpunkte (Namespace `m392/v1`, mu-plugin `m392-order-api.php`)

| Endpunkt | Zweck | Auth |
|---|---|---|
| `GET /ping` | WooCommerce bereit? Produkt-/Bestellanzahl, `provisioned`-Marker | – |
| `GET /products` | Live-Produktliste (SKU/Name/Preis/Kategorie/Slug/total_sales) | – |
| `POST /orders` | N Bestellungen anlegen; `count`, `dates[]` (Historie) oder `carts[]` (Defer) | `X-M392-Key` |
| `GET /weights` · `POST /weights` | Beliebtheits-Gewichte lesen / setzen (`replace` möglich) | POST: `X-M392-Key` |
| `GET /orders-revenue` | Produktumsatz-Summe (Idempotenz beim Backen) | – |

### 6.5 Historie: gebacken, nicht generiert

Beim normalen Install wird **keine** Historie erzeugt — sie kommt aus der Fixture
(Leitidee 3). Das **Backen** (`tools/bake-fixture.sh`, Parameter in `tools/bake.conf`:
`HISTORY_DAYS=180`, `AVG_MONTHLY_REVENUE`, `CONVERSION_RATE`, `RETURNING_RATE`) läuft selten
und nur beim Maintainer:

1. Stack frisch hochfahren mit Bake-Override (`TRAFFIC_AUTO_SEED=true`).
2. Der **Backfill** erzeugt datierte Besuche (`cdt` + `token_auth`) über 180 Tage mit
   Wachstums-Trend und Wochen-Saisonalität; die **Bestellungen** werden über dasselbe
   Fenster verteilt und 1:1 nach Matomo gespiegelt (Umsatz-Richtwert-Modus: kalibriert den
   Ø-Bestellwert und trifft so den Ziel-Monatsumsatz).
3. Roh-Logs + Bestellungen + Kund:innen (inkl. `usermeta`) werden gedumpt →
   `matomo/fixture/*.sql.gz`, Anker-Datum → `BASE`, Parameter → `BAKE-INFO`.

**Token-Austausch:** Datierte Treffer verlangen `token_auth`. `matomo-init` erzeugt den
Token und legt ihn ins Volume `matomo_token`; `traffic` liest ihn von dort. Live-Treffer
(„jetzt") brauchen keinen Token.

### 6.6 Stellschrauben im Überblick

| Mechanismus | Wirkung in Matomo / WooCommerce | Wo |
|---|---|---|
| **Live-Tropf** (Poisson-Schübe) | laufend Besuche/Käufe in Echtzeit | `app.py · _drip_worker` |
| **Manuell senden** | sofort X Besuche / Y Käufe | `app.py · /api/generate-*` |
| **Conversion-Rate / Besucher/Std / Wiederkehrer** (Regler) | Kaufanteil, Volumen, Kundenmix | `app.py · STATE` |
| **Beliebtheits-Gewichte** (Produkte-Tab) | Bestseller vs. Ladenhüter in Ansichten + Bestellungen | `generator._WEIGHTS` + WP-Option |
| **Akquise-Kanäle** | Social stärkster Verkaufskanal; Newsletter als Kampagne | `generator.py · CHANNELS` |
| **Geografie** | DE/CH/AT kaufen; ~5 % übriges Europa bricht ab | `generator.py · _GEO` |
| **Gutschein `NATUR10`** | ~18 % der Bestellungen (*Marketing → Gutscheine*) | `m392-order-api.php` |
| **Ziele** | PDF-Download (~3,5 %), Kontaktanfrage (~2 %) | `generator.py` + `matomo-init.sh` |
| **Events / Inhalte / Leistung** | Verhalten → Ereignisse / Inhalte / Leistung | `generator.py` |

---

## 7. Der A/B-Test (Original vs. Shop-Variante)

Für Modul 392 gibt es einen vorbereiteten A/B-Vergleich: **Original** = `/shop/` (horizontale
Filterleiste, 3er-Raster) vs. **Shop-Variante** = `/shop-variante/` (Filter-Sidebar,
2er-Raster, Aktionsband) — beide in derselben Botiga-Farbwelt, unterschieden nur durchs
Layout.

**Wichtig: Echte Besucher:innen werden NICHT umgeleitet oder zugewiesen.** Es gibt kein
Cookie-Bucketing und keinen Redirect — `/shop/` bleibt `/shop/`, die Variante ist eine
eigenständig erreichbare Seite. Die Aufteilung entsteht rein **synthetisch**:

- Das **Traffic Lab** weist jedem simulierten Besuch eine Variante zu (`M392_AB_SPLIT_B`,
  Standard 50 %) und steuert den passenden Einstieg (`/shop/` bzw. `/shop-variante/`) an;
  Variante B konvertiert um `M392_AB_CONV_FACTOR_B` (1.25) besser — so zeigt der Bericht
  einen echten, interpretierbaren Unterschied.
- Jeder Treffer trägt die **Matomo-Custom-Dimension 1 „AB-Variante"**. Bei echten Besuchen
  setzt das mu-plugin `m392-ab-test.php` die Dimension **URL-basiert** (Variante-Seite =
  „Shop-Variante", alles andere = „Original").
- Ausgewertet wird im nachgebauten Matomo-Plugin **M392ABTesting** (*A/B Tests → Vergleich
  (M392)*): Conversion-Rate je Variante, Bayes-Wahrscheinlichkeit „besser als Original",
  kumuliert seit Teststart. Das Schwester-Plugin **M392Funnels** zeigt den Kauf-Trichter
  (Produkt → Warenkorb → Kasse → Kauf) als Sankey.

---

## 8. Boot-/Initialisierungs-Reihenfolge

`docker compose up -d` startet alles; `depends_on` erzwingt die Reihenfolge. Die beiden
`*-init`-Container laufen **einmal** und beenden sich.

```
   t │
     │  db  ──────────────► healthy ✓
     │   │
     │   ├─► wordpress  ──► füllt ./wordpress/www mit WP-Core (falls leer)
     │   │       │
     │   │       └─► wp-init  ─ Marker gesetzt? ── nein ─► Fixture importieren
     │   │                      (shop.sql.gz + uploads), Theme/Plugins (gepinnt),
     │   │                      Permalinks, .htaccess, HPOS-Sync, Gutschein → Exit 0
     │   │
     │   └─► matomo  ─────► matomo-init  ─ installiert headless via curl,
     │                       legt Website id=1 an (EUR, E-Commerce + Suche),
     │                       Ziele + A/B-Dimension, token_auth → Volume → Exit 0
     │
     │  traffic  ─ wartet aktiv auf Matomo (installiert + Token) ─► Live-Tropf läuft
     ▼

   install.sh (Komfort-Hülle um das Ganze):
     [1/5] down -v + wordpress/www leeren     [2/5..4/5] bauen, starten, warten
     [5/5] Historie-Fixture restaurieren ─► Bestell-ID-Sequenz anheben (s. u.)
           ─► shift-dates.sh (auf „heute") ─► invalidieren ─► core:archive
           ⇒ Berichte stimmen sofort
```

Zwei Garantien, die leicht übersehen werden:

- `traffic` pollt aktiv (`generator.wait_for_ready`), bis Matomo installiert ist **und** ein
  Token vorliegt — sonst liefen die ersten Treffer ins Leere.
- **Bestell-ID-Sequenz:** Die Fixture bringt `wp_wc_orders` mit hohen IDs mit, aber bewusst
  **keine** shop_order-Platzhalter-Posts. Da HPOS neue Bestell-IDs aus der `wp_posts`-Sequenz
  zieht, hebt `install.sh` die Sequenz nach dem Restore hinter `MAX(wp_wc_orders.id)` —
  sonst kollidierten Live-Bestellungen irgendwann mit Fixture-IDs (stille Fehlschläge).

---

## 9. Reproduzierbarkeit (Fixture & Schichten-Modell)

Es gibt **zwei** Fixture-Verzeichnisse — den eingefrorenen Shop und die gebackene Historie:

```
   wordpress/init/fixture/            (Shop, von wp-init restauriert)
     ├─ shop.sql.gz      ← WordPress-DB-Dump (Produkte, Bewertungen, Seiten, Blog,
     │                      Einstellungen: Sprache/EUR/Standort, total_sales …) – OHNE Bestellungen
     └─ uploads.tar.gz   ← wp-content/uploads (Produkt-/Beitragsbilder)

   matomo/fixture/                    (Historie, von install.sh [5/5] restauriert)
     ├─ matomo-history.sql.gz  ← Matomo-Roh-Logs (180 Tage Besuche/E-Commerce)
     ├─ wc-orders.sql.gz       ← WooCommerce-Bestellungen + Kund:innen (+ usermeta)
     ├─ BASE                   ← Anker-Datum für den Offset-Shift
     └─ BAKE-INFO              ← womit gebacken wurde (Parameter + Mengen)
```

- `docker compose up -d` allein ergibt den **Shop ohne Historie** (Matomo-Berichte leer).
- `./install.sh` ist der einzige Weg zur vollen Demo-Historie: Restore → ID-Sequenz →
  Datums-Shift → Archivierung. Bestätigung nötig (`install` tippen), `-y` überspringt sie.
- Die Fixture **backt** der Maintainer selten mit `./tools/bake-fixture.sh` (eigene
  Bestätigung `bake`, Parameter in `tools/bake.conf`).

### Wo „lebt" welche Anpassung? (Schichten-Modell)

Jede dauerhafte Änderung ist in genau **einer** von drei Schichten verankert — was
nirgends verankert ist, ist transiente Demodaten und nach `install.sh` weg:

| Schicht | Was darin liegt | Greift über |
|---|---|---|
| **(a) Versionierter Code** | mu-Plugins (Tracking, Zahlarten, Filter, A/B, Order-API), `wp-init.sh`/`matomo-init.sh`, Traffic Lab (`app.py`/`generator.py`/`orders.py`/Templates), `install.sh`, `tools/` | Bind-Mount (mu-plugins: sofort) bzw. Image-Rebuild (`--build`) |
| **(b) Fixtures** | (b1) Shop-DB + Uploads (`wordpress/init/fixture/`), (b2) Historie + Bestellungen (`matomo/fixture/`) | `wp-init` bzw. `install.sh` [5/5] |
| **(c) Laufzeit** | Matomo-Ziele/Site (matomo-init, idempotent), Live-Tropf, Beliebtheits-Gewichte (WP-Option) | bei jedem Start/Install neu bzw. zurückgesetzt |

---

## 10. Wichtige Dateien

```
.
├─ docker-compose.yml              # Orchestrierung: Container, Volumes, Ports, Healthchecks
├─ docker-compose.bake.yml         # Override nur fürs Backen der Fixture
├─ .env(.example)                  # Konfiguration (Ports, Versionen, Passwörter, Live-Tropf, A/B, Order-Key)
├─ install.sh                      # Reset → Restore → ID-Sequenz → Datums-Shift → Archivierung
│
├─ tools/                          # Maintainer: Fixture backen + Datums-Shift
│  ├─ bake.conf                    # Bake-Parameter (HISTORY_DAYS=180, Umsatz, CR, Returning)
│  ├─ bake-fixture.sh              # erzeugt die Fixture (destruktiv; Bestätigung 'bake')
│  └─ shift-dates.sh               # verschiebt beim Install alle Fixture-Daten auf „heute"
│
├─ seed/catalog.json               # Produktkatalog: Grund-Gewichtung + Offline-Fallback
│
├─ db/init/01-init-databases.sh    # legt beide DBs + Benutzer an (aus .env)
│
├─ wordpress/
│  ├─ init/
│  │  ├─ wp-init.sh                # Fixture-Restore + Theme/Plugins + HPOS-Sync + Gutschein
│  │  ├─ fixture/                  # eingefrorener Shop (shop.sql.gz + uploads.tar.gz)
│  │  └─ mu-plugins/               # (ro gemountet → Änderungen sofort wirksam)
│  │     ├─ matomo-tracking.php    # ← Weg A: JS-Tracking inkl. E-Commerce + Suche
│  │     ├─ m392-order-api.php     # ← Weg B: REST m392/v1 (orders/products/weights/ping)
│  │     ├─ m392-ab-test.php       # A/B: Custom-Dimension URL-basiert + Shop-Variante-Layout
│  │     ├─ m392-shop-filters.php  # Filterleiste des Original-Shops
│  │     ├─ m392-test-payments.php # Test-Zahlarten (Rechnung, Kreditkarte, TWINT)
│  │     └─ m392-german-shop.php   # deutsche Labels/Übersetzungen
│  └─ www/                         # WordPress-Docroot (Bind-Mount, generiert; nicht versioniert)
│
├─ matomo/
│  ├─ matomo-init.sh               # Matomo headless installieren, Site+Ziele, Token erzeugen
│  ├─ fixture/                     # gebackene Historie (s. Kapitel 9)
│  └─ M392ABTesting/ M392Funnels/  # native Matomo-5-Report-Plugins (aktiviert von install.sh)
│
└─ traffic/                        # „Traffic Lab Generator"
   ├─ app.py                       # Flask: Dashboard-API, Live-Tropf, Defer-Abschluss, Gewichte
   ├─ generator.py                 # ← Weg B: Besuche/Käufe via Matomo-Tracking-API
   ├─ orders.py                    # ← Client für m392/v1 (Bestellungen, Gewichte)
   ├─ templates/index.html         # Dashboard (3 Tabs, Skydash-Stil)
   └─ static/fonts/                # Nunito (lokal gebündelt, offline; SIL OFL)
```

**Die zwei URLs, die das Zusammenspiel definieren:**

- `matomo-tracking.php`: Browser-seitige Matomo-URL `http://localhost:<MATOMO_PORT>/`
  (aus `.env`) — verbindet **echte** Besucher mit Matomo (Weg A).
- `generator.py`: `MATOMO_URL = http://matomo` (Docker-intern), `ID_SITE = 1` —
  verbindet das **Traffic Lab** mit Matomo (Weg B).

> Diese Umgebung ist ausschließlich für Schulung/Demo auf dem lokalen Rechner gedacht —
> nicht für den Produktivbetrieb (bewusst schwache Passwörter, Fake-Zahlungen,
> deaktivierter Trusted-Host-Check). Details siehe `README.md`.
