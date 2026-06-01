# M392 Matomo Lab — Design / Spezifikation

**Modul:** 392 – Nutzer-Daten mittels Analysetools auswerten
**Datum:** 2026-06-01
**Ziel:** Eine mit einem einzigen `docker compose up` startbare Lehrumgebung, in der Lernende einen
funktionierenden deutschsprachigen WooCommerce-Shop, ein vorkonfiguriertes Matomo und ein
Datengenerierungstool mit Web-Oberfläche vorfinden.

---

## 1. Überblick & Zielbild

Nach `docker compose up` stehen drei im Browser erreichbare Dienste bereit:

| Dienst | URL | Zweck |
|---|---|---|
| WordPress + WooCommerce | http://localhost:8090 | Deutschsprachiger Demo-Shop, sofort kauffähig |
| Matomo | http://localhost:8091 | Analyse-Tool, vollständig vorinstalliert |
| Datengenerierungstool | http://localhost:8092 | Web-Steuerpult zum Erzeugen von Traffic, Conversions & Käufen |

**Pädagogisches Modell:** „Vollständig vorkonfiguriert". Lernende loggen sich in Matomo ein und
werten vorhandene Daten aus. Sie können zusätzlich live durch den Shop klicken (eigene Klicks
erscheinen in Matomo) und über das Datengenerierungstool gezielt neue Daten erzeugen und beobachten,
wie diese in Matomo sichtbar werden.

**Leitprinzipien:**
- Turnkey & robust: keine manuellen Setup-Schritte nötig, läuft auf Lernenden-Laptops.
- Idempotent: wiederholtes `up` bricht nichts; `down -v` setzt sauber zurück.
- Realistisch & konsistent: E-Commerce-Daten in Matomo passen exakt zu den echten Shop-Produkten.
- Konfigurierbar über eine einzige `.env`.

---

## 2. Container-Architektur

Alle Services laufen in einem gemeinsamen Docker-Netzwerk. Eine einzige MariaDB-Instanz hält zwei
Datenbanken.

| Service | Image | Host-Port | Typ | Aufgabe |
|---|---|---|---|---|
| `db` | `mariadb:11` | – (intern) | dauerhaft | DBs `wordpress` + `matomo` |
| `wordpress` | `wordpress:php8.3-apache` | 8090 | dauerhaft | Shop-Frontend/Backend |
| `matomo` | `matomo:latest` (offiziell) | 8091 | dauerhaft | Analyse-Tool |
| `wp-init` | `wordpress:cli` | – | einmalig | WooCommerce + Theme + Demo-Produkte + Tracking-Snippet |
| `matomo-init` | `curlimages/curl` o. ä. | – | einmalig | Headless-Provisionierung von Matomo |
| `traffic` | `python:3.12-slim` (Flask) | 8092 | dauerhaft | Web-UI + Tracking-API-Generator |

**Begründung Einzel-DB:** geringerer Ressourcenbedarf auf Lernenden-Laptops; zwei getrennte
Datenbanken (`wordpress`, `matomo`) via Init-SQL angelegt, damit logisch sauber getrennt.

### Persistenz (Named Volumes)
- `db_data` → `/var/lib/mysql`
- `wordpress_data` → `/var/www/html`
- `matomo_data` → `/var/www/html` (Matomo)

`docker compose down -v` löscht die Volumes → vollständiger Reset.

---

## 3. Gemeinsamer Produktkatalog (`catalog.json`)

Zentrale Datei, die **sowohl** vom Shop-Seeder **als auch** vom Traffic-Generator gelesen wird.
Dadurch sind die E-Commerce-Ereignisse in Matomo (SKU, Name, Preis, Kategorie) identisch mit den
real im Shop vorhandenen Produkten — die Grundlage für realistische, konsistente Auswertungen.

Beispielstruktur (deutschsprachig):
```json
{
  "currency": "CHF",
  "categories": ["Bekleidung", "Accessoires", "Haushalt"],
  "products": [
    { "sku": "TS-001", "name": "Bio-T-Shirt", "price": 29.90, "category": "Bekleidung" },
    { "sku": "MUG-002", "name": "Keramik-Tasse", "price": 14.50, "category": "Haushalt" }
  ]
}
```
(Finaler Katalog: ca. 8–15 Produkte über mehrere Kategorien.)

---

## 4. WordPress / WooCommerce-Provisionierung (`wp-init`)

Einmalig laufender `wordpress:cli`-Container, der nach Verfügbarkeit von `db` und `wordpress`:

1. WordPress-Kern installiert (deutsche Sprache `de_CH`/`de_DE`, Titel, Admin-User).
2. `WP_HOME`/`WP_SITEURL` auf `http://localhost:8090` setzt.
3. WooCommerce-Plugin installiert + aktiviert; Shop-Grunddaten (Land **Schweiz**, Währung **CHF**)
   per WooCommerce-Onboarding-Optionen setzt.
4. Ein WooCommerce-fähiges Theme (z. B. Storefront) installiert + aktiviert.
5. Demo-Produkte aus `catalog.json` als WooCommerce-Produkte anlegt (Name, SKU, Preis, Kategorie,
   ggf. Platzhalterbild).
6. Den **Matomo-JS-Tracking-Snippet** per Must-Use-Plugin (`mu-plugins/`) in `wp_head` injiziert
   (Site-ID 1, Matomo-URL `http://localhost:8091`). → garantiert, dass auch Live-Klicks getrackt
   werden, unabhängig von Plugin-Einstellungen.

**Idempotenz:** Vor jedem Schritt Prüfung via `wp core is-installed` / Options-Marker; bei „bereits
eingerichtet" überspringen.

---

## 5. Matomo-Provisionierung (`matomo-init`)

Matomos Installer ist normalerweise ein interaktiver Web-Wizard. Gewählter Ansatz: **Installer
headless per HTTP durchlaufen** (Variante A aus dem Brainstorming).

Ablauf nach Verfügbarkeit von `db` und `matomo`:
1. Warten bis Matomo-HTTP erreichbar.
2. Installations-Schritte sequenziell aufrufen: System-Check → Datenbank-Setup
   (Host `db`, DB `matomo`) → Tabellen anlegen → Superuser anlegen (aus `.env`) →
   erste Website anlegen (Name „Demo-Shop", URL `http://localhost:8090`, Zeitzone Europe/Zurich,
   **E-Commerce aktiviert**) → Abschluss.
3. `config.ini.php` härten: `enable_trusted_host_check=0` (Zugriff via `localhost:8091` **und**
   intern `http://matomo` für den Traffic-Container), Brute-Force-/Setup-Sperren für die Lehrumgebung
   entschärfen.
4. API-Token (`token_auth`) für den Traffic-Generator bereitstellen (generiert/ausgelesen und dem
   `traffic`-Service via gemeinsamem Volume oder Env zugänglich gemacht).

**Idempotenz:** Existiert bereits eine `config.ini.php` mit abgeschlossener Installation → nur
Token sicherstellen, Rest überspringen.

**Fallback (dokumentiert, nicht Default):** vorgefertigter Matomo-SQL-Seed + mitgelieferte
`config.ini.php`, falls sich die Installer-Endpunkte einer Matomo-Version ändern.

---

## 6. Datengenerierungstool (`traffic`)

Kleiner Python/Flask-Dienst auf Port 8092. Zwei Funktionen:

### 6.1 Web-Steuerpult (UI)
Einfache deutschsprachige Oberfläche mit Buttons/Feldern:
- „X Besuche generieren" (mit Geräte-, Referrer-, Standort-Variation)
- „Y Käufe / Conversions auslösen" (Warenkorb → Bestellung)
- Conversion-Rate einstellen (%)
- Historie backfillen (Zeitraum in Tagen, z. B. letzte 28 Tage)
- Status-/Log-Anzeige der zuletzt gesendeten Ereignisse

So wird für Lernende sichtbar, **wie** Tracking-Daten entstehen und nahezu in Echtzeit in Matomo
erscheinen.

### 6.2 Generierungs-Logik (Matomo Tracking-API)
Sendet Requests an `http://matomo/matomo.php`:
- **Besuche & Seitenaufrufe:** Navigation über Shop-Seiten (Startseite, Kategorien, Produktseiten),
  variierende `_id` (Besucher), `urlref` (Referrer), `ua` (Gerät/Browser), `lang`/Standort.
- **E-Commerce:** `idgoal=0` mit `ec_items` (`addEcommerceItem`), Warenkorb-Updates und
  `trackEcommerceOrder` (Bestell-ID, Umsatz, Steuer, Versand) — Produkte stammen aus `catalog.json`.
- **Conversions:** Anteil gemäß eingestellter Conversion-Rate.
- **Historischer Backfill:** `cdt`-Parameter (Custom-Timestamp) + `token_auth`, um beim ersten Start
  die letzten ~4 Wochen zu füllen → Matomo-Charts sind sofort gefüllt.

### 6.3 Betriebsmodi
- **Auto-Seed beim Start** (einschaltbar): einmaliger Backfill der Historie.
- **Manuell:** über die Web-UI ausgelöst.
- (Optional, abschaltbar) **kontinuierlicher Tropf:** wenige Ereignisse pro Minute für „lebende"
  Echtzeit-Ansicht.

---

## 7. Datenfluss

1. **Live-Klick:** Browser → Shop `localhost:8090` → mu-Plugin lädt Matomo-JS → Matomo `localhost:8091`.
2. **Generiert:** `traffic` (UI/Loop) → Matomo Tracking-API (`http://matomo/matomo.php`) →
   Besuche, Conversions, Umsatz.
3. **Historie:** `traffic` Backfill (`cdt` + `token_auth`) → gefüllte Vergangenheit.

---

## 8. Konfiguration (`.env`)

Eine zentrale Datei steuert u. a.:
- Host-Ports (Default 8090 / 8091 / 8092)
- DB-Root-/User-Passwörter
- WordPress-Admin (User/Passwort/E-Mail), Shop-Sprache & Währung
- Matomo-Superuser (User/Passwort/E-Mail)
- Traffic: Standard-Conversion-Rate, Backfill-Zeitraum, Auto-Seed an/aus, kontinuierlicher Tropf an/aus

Mitgeliefert als `.env.example`; `.env` selbst wird per `.gitignore` ausgeschlossen.

---

## 9. Reset & Wiederholbarkeit

- **Neustart ohne Datenverlust:** `docker compose up`
- **Vollständiger Reset:** `docker compose down -v && docker compose up`
- Alle Init-Container sind idempotent → kein kaputter Zustand bei mehrfachem Start.

---

## 10. Liefergegenstände

```
M392 Matomo/
├─ docker-compose.yml
├─ .env.example
├─ .gitignore
├─ catalog.json
├─ README.md                  # Start, URLs, Logins, Lernaufgaben-Hinweise, Troubleshooting
├─ wordpress/
│  ├─ wp-init.sh              # WooCommerce + Demo-Produkte + Tracking-Snippet
│  └─ mu-plugins/
│     └─ matomo-tracking.php  # JS-Snippet-Injektion
├─ matomo/
│  └─ matomo-init.sh          # Headless-Installer + Härtung + Token
└─ traffic/
   ├─ Dockerfile
   ├─ app.py                  # Flask-Web-UI + Generator
   ├─ generator.py            # Tracking-API-Logik
   ├─ requirements.txt
   └─ templates/index.html    # deutschsprachiges Steuerpult
```

---

## 11. Bekannte Risiken / offene Punkte

- **Matomo-Installer-Endpunkte** können sich zwischen Versionen ändern → Fallback-Seed (§5) als
  Absicherung dokumentieren; `matomo:latest` ggf. auf eine getestete Version pinnen.
- **Trusted-Host-Check deaktiviert** ist bewusst für die Lehrumgebung — **nicht** für Produktion.
- **Reihenfolge/Healthchecks:** `depends_on` + Healthchecks (DB ready, Matomo ready), damit
  init-Container nicht zu früh starten.
- **Platzhalterbilder** für Produkte halten das Image schlank; echte Bilder optional ergänzbar.
- **Pinning:** WordPress-/WooCommerce-/Matomo-Versionen für reproduzierbare Kurse pinnen
  (Empfehlung), statt `latest`.
