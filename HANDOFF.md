# HANDOFF – Projektübergabe für ein weiterführendes LLM / einen Agenten

Dieses Dokument fasst **Zustand, Architektur, Konventionen, Verifikations-Methodik und offene Arbeit**
zusammen, damit ein neuer Agent ohne Vorwissen produktiv weiterarbeiten kann. Lies zuerst dieses MD,
dann bei Bedarf `README.md`, `docs/ARCHITECTURE.md` und `review/06 claude konsens-und-plan.md`.

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
./install.sh            # kompletter Reset+Aufbau, wartet auf Seed+Archivierung (macOS/Linux/WSL2)
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
docker-compose.yml          # Orchestrierung
.env(.example)              # Konfiguration (Ports, Versionen, Passwörter, Seed-Stellschrauben)
.gitattributes             # erzwingt LF (Windows-Klon-Sicherheit) – NICHT entfernen
install.sh                 # Reset+Aufbau, aktiviert Matomo-Plugins, wartet auf Seed
seed/catalog.json          # Produktkatalog des Traffic Lab (Spiegel des echten Shops)
db/init/01-init-databases.sh
wordpress/init/
  wp-init.sh               # Fixture-Restore + Theme/Plugins + Permalinks (+ toter Legacy-Block, s.u.)
  fixture/                 # shop.sql.gz (DB) + uploads.tar.gz (Bilder) = eingefrorener Shop
  mu-plugins/              # matomo-tracking, m392-order-api, m392-ab-test, m392-shop-filters,
                           #   m392-german-shop, m392-test-payments
matomo/
  matomo-init.sh           # headless Install, Site+Ziele+Custom-Dimension, Token
  M392ABTesting/ M392Funnels/   # die zwei nachgebauten Report-Plugins (plugin/ + setup.sh + README)
traffic/                   # Flask Traffic Lab (app.py, generator.py, orders.py, templates/index.html)
docs/                      # ARCHITECTURE.md, PRODUKTE-WORKFLOW.md, WINDOWS.md
LEARNING.md                # Modul-392-Lernpfad → Matomo-Funktionen
review/                    # Code-Review-Konversation mit Codex (01–06) + finaler Plan
```

## 5. Architektur-Kern (Kurzfassung — Details in `docs/ARCHITECTURE.md`)

- **Zwei Datenwege, eine Matomo-Instanz:** (A) echte Browser-Besuche über den Tracking-Code im Shop;
  (B) das Traffic Lab sendet serverseitig direkt an die Matomo-Tracking-API (`/matomo.php`), datierbar
  (`cdt` + `token_auth`).
- **Fixture vs. generiert:** Der **Shop** (Produkte, Seiten, Theme, Bewertungen) ist als Fixture
  eingefroren (`shop.sql.gz` + `uploads.tar.gz`) → schneller, identischer Restore. **Bestellungen** und
  **Matomo-Historie** werden beim Start **frisch generiert** (relativ zu „heute") – das ist der langsame
  Teil und bewusst nicht eingefroren (Datums-Relativität).
- **Seed-Formel** (gekoppelter Modus, `TRAFFIC_AVG_MONTHLY_REVENUE>0`):
  `Bestellungen/Tag = (Monatsumsatz/30)/28` · `Backfill-Besuche/Tag = Bestellungen/Tag × (1/CR − 1)` · `× TAGE`.
  Stellschrauben (Last reduzieren): **TAGE↓** (linear auf beides, bester Hebel; Standard **90**),
  **Monatsumsatz↓** (linear), **CR↑** (nur Besuche), `AVG_MONTHLY_REVENUE=0` (entkoppelt, Besuche/Tag=14),
  `TRAFFIC_AUTO_SEED=false` (kein Seed). Profile stehen kommentiert in `.env.example`.
- **3-Schichten-Verankerung** (überlebt `install.sh`): (a) versionierter Code, (b) Fixture,
  (c) Laufzeit-Seed. Was nicht in (a)/(b) liegt, ist transiente Demodaten.

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
  `/api/backfill`, `GET /api/ready`.
- **UI** (`templates/index.html`): Spektrum-Slider (Besucher/Stunde, Conversion-Rate, Wiederkehrende),
  „Erwartete Käufe"-Anzeige, **mehrseriges Aktivitätsdiagramm** (Besuche/Käufe/Wiederkehrende; Käufe &
  Wiederkehrende auf **eigener Skala**, sonst von Besuchs-Spikes erdrückt).
- **Seed** in `app.py · _maybe_auto_seed` (siehe Formel oben); echte Bestellungen via
  `orders.py` → mu-plugin `m392-order-api.php`; Matomo-Spiegelung via
  `generator.track_ecommerce_order`.

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
- Seed-Standard ist **90 Tage** (Last-Reduktion); Profile in `.env.example`.

## 10. Stand: erledigt (diese Iteration)

- Matomo-Report-Plugins A/B + Funnel: gebaut, **auto-aktiviert**, unter „A/B Tests"/„Funnels" sichtbar,
  in Pluginverwaltung gelistet.
- A/B: mehrere Tests, **Inline-Anlage** (Demo-Look), **kumulierte** Auswertung + Monats-Verlauf,
  **Bayes** (Normal + Monte-Carlo). Gewinner-Logik korrekt (kein „Gewinner" bei Gleichstand/0).
- Funnel: **Sankey** + Seiten-Zuordnung.
- Traffic Lab: Master-Redesign, Spektrum-Slider, **mehrseriges Chart** (Käufe/Wiederkehrende sichtbar).
- `.env`: realistischer Order-API-Key, `TRAFFIC_RETURNING_RATE=0.08`, **Seed-Fenster 90 Tage**.
- Aufräumen: totes CSS, `.gitignore`/`.gitattributes`, Doku-Konsistenz.
- Neue Doku: `LEARNING.md`, `docs/WINDOWS.md`, `HANDOFF.md` (diese Datei).

## 11. Stand: OFFEN – die nächste Arbeit

**Maßgeblich:** [`review/06 claude konsens-und-plan.md`](review/06%20claude%20konsens-und-plan.md) –
mit Codex abgestimmter, von Luca freigegebener Plan. **Zwei Entscheidungen sind bereits getroffen:**
Ports **fix + auf `127.0.0.1` binden**; Legacy-Modus **entfernen** (bei fehlender Fixture hart abbrechen).

**Fix-Paket 1 (zuerst umsetzen, je Punkt 1 Commit, jeweils verifizieren):**
1. **Installer-Wahrheit:** `/api/ready` – `error` nicht mehr als `done` (heute `app.py` ~Z.192:
   `done = all(v in ("done","off","error") …)`); `install.sh` `wait_http`-Fehler nicht mit `|| true`
   schlucken; Exit-Codes + Statuszeile; `--no-wait` = „nicht verifiziert".
2. **Traffic-Endpunkte robust:** tolerantes Parsing/`400`, Bounds (count/days/vph/cr/ret), kein 500,
   UI-Feedback bei Fehler.
3. **Lokal einhegen:** Compose-Port-Bindings auf `127.0.0.1:…`; Ports „fix" dokumentieren.
4. **Datenkonsistenz:** `generate_orders` zählt **Besuche** mit (heute fehlt `visits` im Return →
   Dashboard zählt manuelle Käufe nicht als Besuch); WC-Bestellfehler sichtbar statt still `count=0`;
   UI/Status trennt Matomo-Traffic vs. echte Shop-Bestellungen.
5. **Legacy entfernen:** Block in `wp-init.sh` (~Z.295–599) raus; fehlende Fixture → klarer Abbruch.

**Fix-Paket 2 (danach):** `requests.Session` pro Thread; `/ping` leichtgewichtig; `latest`-Fallback →
harter Fehler; A/B-Custom-Dimension dynamisch statt statisch `dimension1`; Google-Fonts lokal;
veraltete „~24 Monate"-Kommentare; Dev-Server nach Bounds neu bewerten.

## 12. Bekannte Stolpersteine

- **Matomo config.ini `[Plugins]` niemals manuell schreiben** → Login/Auth bricht. Immer
  `console plugin:activate`.
- **Token-Render-Sperre** (siehe §8) → Endcheck visuell nur eingeloggt.
- **Seed-Daten sind datums-relativ** zu „heute"; ein statischer Dump würde veralten (Diskussion dazu in
  der Konversation; aktuell wird generiert).
- **Manuelle Käufe ≠ Besuche** im Dashboard (Bug, in Fix-Paket 1.4).
- **Ports binden aktuell auf 0.0.0.0** (LAN-erreichbar) – wird in Fix-Paket 1.3 auf `127.0.0.1` gezogen.
- **WSL2 empfohlen für `install.sh`** unter Windows (`docs/WINDOWS.md`).

## 13. Weiterführende Doku

`README.md` (Überblick/Bedienung) · `docs/ARCHITECTURE.md` (Datenfluss/Seed/Verankerung) ·
`LEARNING.md` (Modul-392-Lernpfad) · `docs/WINDOWS.md` (Windows-Setup) ·
`docs/PRODUKTE-WORKFLOW.md` (neue Produkte effizient hinzufügen) ·
`matomo/M392ABTesting/README.md` & `matomo/M392Funnels/README.md` (Plugin-Details) ·
`review/01–06` (Code-Review-Konversation + finaler Plan).
