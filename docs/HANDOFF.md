# HANDOFF – Projektübergabe für ein weiterführendes LLM / einen Agenten

Dieses Dokument fasst **Zustand, Architektur, Konventionen, Verifikations-Methodik und offene Arbeit**
zusammen, damit ein neuer Agent ohne Vorwissen produktiv weiterarbeiten kann. Lies zuerst dieses MD,
dann bei Bedarf `../README.md` und `ARCHITECTURE.md`. (`review/` = abgeschlossene Code-Review-Historie.)

---

## 1. Was ist das Projekt

Reproduzierbare, **lokale Lehrumgebung für das Modul 392** „Nutzer-Daten mittels Analysetools auswerten".
Ein realistischer Naturkosmetik-**Online-Shop** (WordPress + WooCommerce) wird mit **Matomo** getrackt;
ein selbstgebautes **Traffic Lab** (Flask) erzeugt realistische Besucher-/Kauf-Daten, damit Lernende
sofort aussagekräftige Berichte haben. Läuft **nur auf localhost** via Docker. **Nicht** produktionshart
(bewusst schwache Passwörter, HTTP, Fake-Zahlungen – siehe Konsens).

## 2. Stack, Ports, Zugänge

| Dienst | Port | Zweck | Login |
|---|---|---|---|
| `wordpress` | **8090** | Shop (WooCommerce) | Admin `admin` / `wp123` |
| `matomo` | **8091** | Web-Analyse | `admin` / `matomo123` |
| `traffic` | **8092** | Traffic Lab (Flask-Dashboard) | – |
| `db` (MariaDB), `wp-init`, `matomo-init` | – | DB + einmalige Init-Container | – |

Alles in `docker-compose.yml`. Zugangsdaten/Stellschrauben in `.env` (lokal, gitignored) bzw.
`.env.example` (Vorlage).

## 3. Schnellstart & Entwicklungs-Workflow

```bash
cp .env.example .env
./install.sh            # Reset → Fixture-Restore → Datums-Shift → Archivierung (~2-3 Min)
# oder ohne Skript:
docker compose up -d
```

**Wo „leben" Edits / was braucht Rebuild?**
- **mu-plugins** (`wordpress/init/mu-plugins/*.php`), **Matomo-Plugins** (`matomo/M392*/plugin/`) und
  **Matomo-/WP-Init-Skripte** sind **Bind-Mounts** → Änderungen sind sofort im Container. Bei
  Matomo-Plugin-Änderungen danach **Cache leeren**:
  `docker compose exec -T -u www-data matomo ./console core:clear-caches`.
- **Traffic Lab** (`traffic/app.py`, `generator.py`, `orders.py`, `templates/index.html`) wird ins
  Image **gebacken** → nach Änderung `docker compose up -d --build traffic`.
- `docker-compose.yml`-Änderungen → `docker compose up -d` (recreate).

## 4. Repo-Struktur (Kern)

```
docker-compose.yml          # Orchestrierung (+ docker-compose.bake.yml = Override nur fürs Backen)
.env(.example)              # Konfiguration (Ports, Versionen, Passwörter, Live-Tropf-Werte)
.gitattributes              # erzwingt LF (Windows-Klon-Sicherheit) – NICHT entfernen
install.sh                  # Reset → Fixture-Restore → Datums-Shift → Matomo-Archivierung
tools/                      # bake.conf, bake-fixture.sh (Fixture backen), shift-dates.sh (Datums-Shift)
seed/catalog.json           # Produktkatalog des Traffic Lab (Spiegel des echten Shops)
db/init/01-init-databases.sh
wordpress/init/
  wp-init.sh                # Fixture-Restore (shop.sql.gz); fehlende Fixture → harter Abbruch
  fixture/                  # shop.sql.gz (Shop OHNE Bestellungen) + uploads.tar.gz = eingefrorener Shop
  mu-plugins/               # matomo-tracking, m392-order-api, m392-ab-test, m392-shop-filters,
                            #   m392-german-shop, m392-test-payments
matomo/
  matomo-init.sh            # headless Install, Site+Ziele+Custom-Dimension, Token
  fixture/                  # matomo-history.sql.gz + wc-orders.sql.gz + BASE + BAKE-INFO (gebackene Historie)
  M392ABTesting/ M392Funnels/   # die zwei nachgebauten Report-Plugins (plugin/ + setup.sh + README)
traffic/                    # Flask Traffic Lab (app.py, generator.py, orders.py, index.html); läuft unter waitress
docs/                       # HANDOFF, ARCHITECTURE, CHANGELOG, LEARNING, WINDOWS + review/
```

## 5. Architektur-Kern (Kurzfassung — Details in `docs/ARCHITECTURE.md`)

- **Zwei Datenwege, eine Matomo-Instanz:** (A) echte Browser-Besuche über den Tracking-Code im Shop;
  (B) das Traffic Lab sendet serverseitig direkt an die Matomo-Tracking-API (`/matomo.php`), datierbar
  (`cdt` + `token_auth`).
- **Fixture-only (Shop UND Historie eingefroren):** Der **Shop** (`shop.sql.gz` + `uploads.tar.gz`)
  und die **Historie** (Matomo-Besuche + WC-Bestellungen, ~180 Tage; `matomo/fixture/*.sql.gz`) sind
  beide vorgebacken. `install.sh` **restauriert** sie und **verschiebt alle Datumswerte per
  `tools/shift-dates.sh` auf „heute"** (Anker `matomo/fixture/BASE`; `offset = heute − BASE`, identisch
  für Matomo + WooCommerce → die Umsatz-Kopplung bleibt erhalten). Danach **einmal** `core:archive`.
  → Install ~2-3 Min statt 15-30 (kein Live-Generieren mehr beim Install).
- **Archiv-Stolperstein:** Matomo archiviert **nichts vor dem Site-Erstelldatum**. `install.sh` setzt
  darum `matomo_site.ts_created` auf den (geshifteten) Datenanfang + invalidiert, sonst bleiben die
  Alt-Monate leer.
- **Backen (Maintainer, selten):** `tools/bake-fixture.sh` fährt den Stack mit den Parametern aus
  `tools/bake.conf` (Historienlänge 180, Umsatz, CR) frisch hoch, lässt den **Generator** (`traffic/`)
  die Historie EINMAL erzeugen und dumpt sie. Der Generator bleibt „Source of Truth"; der Dump ist ein
  abgeleitetes Artefakt. Parameter ändern ⇒ neu backen. (Seed-Formel im gekoppelten Modus:
  `Bestellungen/Tag = (Monatsumsatz/30)/28`, `Besuche/Tag = Bestellungen/Tag × (1/CR − 1)`.)
- **Live-Tropf:** `TRAFFIC_LIVE_DRIP` erzeugt zusätzlich die laufende „jetzt"-Schicht (in der UI abschaltbar).
- **3-Schichten-Verankerung** (überlebt `install.sh`): (a) versionierter Code, (b) Fixtures (Shop +
  Historie). Was nicht dort liegt, ist transiente Demodaten (Live-Tropf).

## 6. Die zwei Matomo-Plugins (wichtig & nicht offensichtlich)

`matomo/M392ABTesting/plugin/` und `matomo/M392Funnels/plugin/` sind **native Matomo-5-Plugins**.

**Registrierung/Anzeige (Matomo 5):** Ein Sidebar-Eintrag entsteht **nur** über
`Categories/*Subcategory.php` (+ Widget), **nicht** über das alte `configureReportingMenu`. Die
Subcategories hängen bewusst unter die **bestehenden** Promo-Kategorien des Core-Plugins
*ProfessionalServices*: `ProfessionalServices_PromoFunnels` (Icon `icon-funnel`) und
`ProfessionalServices_PromoAbTesting` (Icon `icon-lab`). Das Widget (`Widgets/Get*.php`,
`configure()` setzt `setCategoryId/setSubcategoryId`) rendert die Twig-Seite (`templates/index.twig`).

**Aktivierung (kritischer Stolperstein):** Aktivierung **ausschließlich** über
`console plugin:activate` (schreibt die **vollständige** Plugin-Liste inkl. `Login`/`Auth`). Ein
**manueller** `[Plugins]`-Eintrag in `config.ini.php` **ersetzt** die Default-Plugins und legt Matomo
lahm (Login weg, „Authentication object…"-Fehler). `install.sh` aktiviert beide Plugins korrekt nach
dem Matomo-Start und leert danach den Cache. Plugins sind per Bind-Mount im matomo-Container
(`docker-compose.yml`).

**A/B-Plugin (`M392ABTesting`):**
- `Storage.php` – Tests als Matomo-**Option** (JSON); Standard-Test „ShopVariante" wird geseedet.
- `Stats.php` – Kennzahlen je **Segment** (VisitsSummary.get + Goals.get `idGoal=ecommerceOrder`),
  `testRange()` = **kumuliert seit Teststart** (period=range, date=Start..heute → P-Wert stabil),
  `variantSeriesCR()` = Monats-Verlauf; **Bayes** Beta-Binomial: `probBetterNormal()` (Normal-Näherung)
  + `bayesMonteCarlo()` (Gamma/Beta-Sampling, ~90 ms).
- `Controller.php` – `saveTest`/`deleteTest` (Nonce-geschützt), `bayesExact` (AJAX-JSON, kumuliert).
- `Widgets/GetAB.php` + `templates/index.twig` – Übersicht (Tabelle + Total + Gewinner + Verlauf) und
  **inline** Create-Formular (Demo-Look, JS-Toggle).

**Funnel-Plugin (`M392Funnels`):**
- `Widgets/GetFunnel.php` – liest die vier URL-Ziele „Funnel-1…4" (angelegt in `setup.sh`), berechnet
  Drop-off **und** die **Sankey-Geometrie** (`buildSankey()`); jeder Schritt trägt die konkrete
  WordPress-Seite + Pfad. `templates/index.twig` zeichnet das SVG-Sankey + Schritt-Tabelle.

## 7. Traffic Lab (Flask)

- **API-Vertrag:** `GET /api/status` (totals, history mit `{t,visits,purchases,returning}`, log, …);
  `POST /api/toggle-drip`, `/api/set-drip`, `/api/generate-visits`, `/api/generate-orders`,
  `/api/backfill`, `GET /api/ready`; **Produkte:** `GET /api/products` (Liste + Gewichte),
  `POST /api/product-weight` (sku, weight 0–100; persistiert via WP-Option `m392_product_weights`,
  Order-API-Endpunkte `GET/POST m392/v1/weights`).
- **UI** (`templates/index.html`): **drei Tabs** (Dashboard / Produkte / Protokoll), ruhige
  Akzent-Fill-Slider, „Erwartete Käufe"-Anzeige, **mehrseriges Aktivitätsdiagramm**
  (Besuche/Käufe/Wiederkehrende; Achse skaliert auf sichtbare Serien).
- **Defer-Flow (Live/Manuell):** `generate_visits/orders(defer_purchases=True)` liefert je Kauf ein
  `pending` (Warenkorb + vorbereitete Conversion-Parameter); `app._finish_purchases` legt die echten
  WC-Bestellungen mit **exakt diesen Warenkörben** an (`carts`-Parameter der Order-API) und schließt
  dann mit `generator.complete_purchase` (echter Produktumsatz, selber Besuch) ab → Matomo = WC.
- **Seed** in `app.py · _maybe_auto_seed` (siehe Formel oben) läuft **nur beim Backen** (gegated
  über `TRAFFIC_AUTO_SEED`, in `.env.example` nicht mehr gesetzt → beim normalen Install inaktiv);
  echte Bestellungen via `orders.py` → mu-plugin `m392-order-api.php`; Matomo-Spiegelung via
  `generator.track_ecommerce_order`.
- **Gotcha Bestell-IDs:** Die Fixture enthält `wp_wc_orders` mit hohen IDs, aber keine
  shop_order-Posts; HPOS zieht neue Bestell-IDs aus der `wp_posts`-Sequenz. `install.sh` hebt die
  Sequenz nach dem Restore hinter `MAX(wp_wc_orders.id)` – sonst kollidieren Live-Bestellungen
  irgendwann mit Fixture-IDs (Duplicate Key, stille Fehlschläge).

## 8. Verifikations-Methodik (wichtig – Umgebung hat Eigenheiten)

- **PHP-Lint:** `docker compose exec -T matomo php -l /var/www/html/plugins/M392ABTesting/Stats.php` (Pfad
  ohne `/plugin/` – Bind-Mount mappt `matomo/M392X/plugin` → `/var/www/html/plugins/M392X`).
- **Twig:** Syntax + **Mock-Render** mit Matomos Twig-Engine prüfen (ein kleines PHP-Skript via
  `vendor/autoload.php`, `Twig\Environment`, `parse()`/`render()` mit Dummy-Daten) – fängt Laufzeitfehler,
  ohne die Seite einloggen zu müssen.
- **Daten/Registrierung verifizieren ohne Login:** Matomo-**API** mit dem App-Token nutzen
  (`docker compose exec -T traffic cat /token/token_auth`):
  `API.getReportPagesMetadata` zeigt, ob die M392-Subcategories registriert sind; `VisitsSummary.get`/
  `Goals.get` mit `&segment=` liefern die Variant-Kennzahlen.
- **Einschränkung:** Gerenderte Matomo-**Widget-Seiten** lassen sich **nicht** per `token_auth`
  abrufen („Embedding widgets with super-user token authentication is not allowed") → man kann den
  fertig gerenderten Bericht **nicht** ohne eingeloggte Browser-Session screenshoten. **Sicherheitsregel:
  KEINE Passwörter in Web-Formulare eintippen.** Daher: über API + Mock-Render verifizieren und den
  visuellen Endcheck dem Menschen überlassen.

## 9. Konventionen

- **Commits:** prägnante deutsche Messages, Footer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
  Trunk ist **`main`** (der frühere `feat/m392-matomo-lab` ist gemergt + gelöscht). Für größere
  Features einen Topic-Branch von `main` nehmen statt lange direkt auf `main` zu häufen.
- **Doku auf Deutsch** (Schweiz: DSG neben DSGVO).
- **Bewusst so gelassen (Lehrumgebung):** schwache `.env`-Passwörter, deaktivierter Trusted-Host-Check,
  HTTP – **nicht** „fixen" ohne Auftrag.
- `.gitattributes` (LF) **nicht entfernen** (sonst kaputte Container-Skripte nach Windows-Klon).
- Historie ist eine gebackene **180-Tage-Fixture** (`tools/bake.conf`, `HISTORY_DAYS=180`); kein
  Install-Seed mehr. `.env.example` steuert nur den Live-Tropf + A/B-Test.

## 10. Stand: erledigt

- **Report-Plugins** A/B + Funnel (native Matomo 5): auto-aktiviert, Sankey, Bayes, kumulierte Auswertung.
- **Fix-Paket 1** (gemergt, E2E-verifiziert): Installer-Wahrheit (`/api/ready` `error`≠`done`, ehrliche
  Exit-Codes), robuste Traffic-Endpunkte (Bounds, 400 statt 500, inkl. inf/nan), Ports auf `127.0.0.1`,
  Datenkonsistenz (manuelle Käufe zählen als Besuch, WC-Fehler sichtbar, `STATE.wc` getrennt),
  Legacy-Pfad in `wp-init.sh` entfernt (fehlende Fixture → harter Abbruch).
- **Fix-Paket 2:** thread-lokale HTTP-Sessions; leichtes `/ping` (+ separate `/orders-revenue`-Route);
  `latest`-Fallback → harter Fehler; A/B-Custom-Dimension per Assert (Index==1) abgesichert; keine
  externen Google-Fonts (Systemfonts); `waitress` statt Flask-Dev-Server.
- **Fixture-Bake (fixture-only Install):** Historie (Matomo-Logs + WC-Bestellungen) wird gebacken
  (`tools/bake-fixture.sh` → `matomo/fixture/*.sql.gz`), beim Install restauriert + auf „heute"
  verschoben (`tools/shift-dates.sh`) + archiviert. E2E verifiziert: Install ~2-3 Min; Kopplung
  Matomo == WooCommerce. Gebackene Mengen (`matomo/fixture/BAKE-INFO`, 180 Tage): 14921 Matomo-Besuche,
  250 E-Commerce-Conversions, **288 WC-Bestellungen / ~7.6k EUR netto**; keine Zukunftsdaten; Shop-Filter intakt.
- **Doku/Struktur:** alle MD außer `README.md` unter `docs/`; `TODO.md` + Brainstorm-Specs entfernt
  (realisiert); Code-Review-Historie unter `docs/review/`.

## 11. Stand: offene Ideen (nicht dringend)

- Offset-Rundung `OFFSET_ROUNDING=week` (Wochentags-Realismus, kleine Lücke am Rand) ist in
  `tools/bake.conf` schaltbar.
- Historienlänge/Umsatz/CR der Fixture über `tools/bake.conf` + `./tools/bake-fixture.sh` anpassbar.
- Fix-Paket 1 + 2 sind abgeschlossen; die abgestimmte Konsens-Historie liegt in `docs/review/`.

## 12. Bekannte Stolpersteine

- **Matomo config.ini `[Plugins]` niemals manuell schreiben** → Login/Auth bricht. Immer
  `console plugin:activate`.
- **Token-Render-Sperre** (siehe §8) → Endcheck visuell nur eingeloggt.
- **Fixture-Historie ist datums-verschoben:** `tools/shift-dates.sh` schiebt alle Zeitstempel um
  `heute − BASE`. Matomo archiviert nichts vor `matomo_site.ts_created` → `install.sh` setzt es auf den
  Datenanfang + invalidiert, sonst bleiben Alt-Monate leer.
- **WC-Dump OHNE `shop_order`-Platzhalter-Posts:** HPOS vergibt deren `wp_posts`-IDs aus der
  `wp_wc_orders`-Sequenz → sie kollidieren beim Restore mit `shop.sql.gz`-Posts. Kanonisch sind die
  `wp_wc_*`-Tabellen (die WC-Admin + wc-admin-Analytics lesen).
- **Ports an `127.0.0.1` gebunden** (nur localhost); Standardports sind fix (Teil der Kursumgebung).
- **WSL2 empfohlen für `install.sh`** unter Windows (`WINDOWS.md`).

## 13. Weiterführende Doku

`../README.md` (Überblick/Bedienung) · `ARCHITECTURE.md` (Datenfluss/Fixture/Shift) ·
`LEARNING.md` (Modul-392-Lernpfad) · `WINDOWS.md` (Windows-Setup) ·
`../matomo/M392ABTesting/README.md` & `../matomo/M392Funnels/README.md` (Plugin-Details) ·
`review/01–06` (abgeschlossene Code-Review-Historie).
