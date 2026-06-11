# M392 Matomo Lab

> Docker-Lehrumgebung für das Modul **392 – Nutzer-Daten mittels Analysetools auswerten**.
> Ein einziger Befehl startet einen vollständigen Online-Shop, ein vorkonfiguriertes
> Web-Analyse-Tool (Matomo) und ein Werkzeug, das realistischen Besucher- und Kauf-Traffic erzeugt.

Die Lernenden erhalten damit eine realitätsnahe Umgebung, in der sie **Web-Analytics von A bis Z**
üben: Tracking verstehen, Besucher- und E-Commerce-Daten auswerten, Conversions analysieren und
eigene Hypothesen mit selbst erzeugten Daten überprüfen — alles lokal, ohne echte Nutzerdaten.

---

## Schnellstart

**In zwei Schritten startklar.** Du brauchst nur **Docker Desktop** (installiert **und gestartet**)
und dieses Repository.

```bash
cp .env.example .env      # 1) Pflicht: Konfig-Vorlage kopieren (sonst bricht install.sh ab)
./install.sh              # 2) Baut alles + spielt ~6 Monate Demo-Daten ein (~2–3 Min)
```

Sobald `install.sh` **„Der Stack laeuft"** meldet, im Browser öffnen:

| Dienst | URL | Login |
|---|---|---|
| 🛒 **Shop** | <http://localhost:8090> | – |
| 📊 **Matomo** | <http://localhost:8091> | `admin` / `matomo123` |
| 🤖 **Traffic Lab** | <http://localhost:8092> | – |

> 🪟 **Windows:** `install.sh` ist ein Bash-Skript und läuft **nicht** in cmd/PowerShell – nutze
> **WSL2** (Docker Desktop mit WSL2-Backend, das Repo **im WSL-Dateisystem** klonen, dann `./install.sh`
> im Ubuntu-Terminal). Komplette Schritt-für-Schritt-Anleitung: [`docs/WINDOWS.md`](docs/WINDOWS.md).

> 💡 **Nur den Shop ohne Demo-Historie?** `docker compose up -d` genügt (schneller, aber die
> Matomo-Berichte bleiben leer). Hintergründe weiter unten unter
> [Zurücksetzen & Reproduzierbarkeit](#zurücksetzen--reproduzierbarkeit).

---

## Inhalt

- [Ziel & Idee](#ziel--idee)
- [Lernziele](#lernziele)
- [Architektur](#architektur)
- [Voraussetzungen](#voraussetzungen)
- [Schnellstart](#schnellstart)
- [Zugänge](#zugänge)
- [Die drei Komponenten](#die-drei-komponenten)
  - [1. Online-Shop (WordPress + WooCommerce)](#1-online-shop-wordpress--woocommerce)
  - [2. Matomo (Web-Analyse)](#2-matomo-web-analyse)
  - [3. Datengenerierungstool](#3-datengenerierungstool)
- [Bezahlung im Test-Shop](#bezahlung-im-test-shop)
- [Wie alles zusammenhängt (Datenfluss)](#wie-alles-zusammenhängt-datenfluss)
- [Ideen für den Unterricht](#ideen-für-den-unterricht)
- [Konfiguration (`.env`)](#konfiguration-env)
- [Zurücksetzen & Reproduzierbarkeit](#zurücksetzen--reproduzierbarkeit)
- [Projektstruktur](#projektstruktur)
- [Troubleshooting](#troubleshooting)
- [Technische Hinweise & Sicherheit](#technische-hinweise--sicherheit)
- [Lizenz & Credits](#lizenz--credits)

---

## Ziel & Idee

Web-Analyse lernt man am besten an echten Daten — die aber sind aus Datenschutz- und
Praxisgründen im Unterricht schwer verfügbar. Diese Umgebung löst das Problem: Sie liefert einen
**funktionierenden Demo-Shop**, ein **echtes Analyse-Tool** und einen **Traffic-Generator**, der
auf Knopfdruck nachvollziehbare, realistische Daten produziert.

So nehmen die Lernenden Matomo selbst in Betrieb bzw. arbeiten damit, sehen wie Tracking-Daten
entstehen, und werten Besuche, Käufe und Conversions aus — reproduzierbar und gefahrlos.

Alles läuft in Docker-Containern und ist **turnkey**: Ein einziges **`./install.sh`** richtet alles
ein (Shop installiert, Matomo konfiguriert, vorgebackene ~6-Monats-Demo-Historie eingespielt und auf
„heute" verschoben). Wer nur den nackten Shop ohne historische Daten will, kommt mit `docker compose
up -d` aus.

## Lernziele

Mit dieser Umgebung lassen sich u. a. folgende Kompetenzen aus Modul 392 abdecken:

- **Tracking verstehen:** Wie gelangt ein Seitenaufruf bzw. eine Bestellung in das Analyse-Tool?
- **Analyse-Tool bedienen:** Matomo-Berichte lesen (Besucher, Verhalten, Akquise, E-Commerce).
- **Kennzahlen interpretieren:** Besuche, Absprungrate, Conversion-Rate, Umsatz, Warenkorb-Wert.
- **Hypothesen testen:** Parameter ändern (z. B. Conversion-Rate) und die Auswirkung beobachten.
- **Datenqualität & Datenschutz reflektieren:** Was wird getrackt, was nicht, und warum.

## Architektur

> 📐 **Ausführliche Architektur-Doku** (Tracking-Wege, Datenfluss, Diagramme): siehe
> [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
> 📝 **Änderungsverlauf:** siehe [`docs/CHANGELOG.md`](docs/CHANGELOG.md).
> 🤝 **Mitwirkende / Agenten** (Zustand, Architektur, offene Arbeit kompakt): siehe [`docs/HANDOFF.md`](docs/HANDOFF.md).
> 🎓 **Lernpfad Modul 392 → Matomo:** siehe [`docs/LEARNING.md`](docs/LEARNING.md).

Ein `docker compose` startet sechs Container (drei dauerhafte Web-Dienste, zwei einmalige
Einrichtungs-Container und eine Datenbank):

```
                          ┌─────────────────────────────────────────────┐
   Browser  ── :8090 ───► │  wordpress  (WordPress + WooCommerce, Botiga)│
                          └───────────────┬─────────────────────────────┘
                                          │  Matomo-Tracking (JS im <head>)
   Browser  ── :8091 ───► ┌───────────────▼─────────────────────────────┐
                          │  matomo     (Web-Analyse)                    │
                          └───────────────▲─────────────────────────────┘
                                          │  HTTP Tracking-API
   Browser  ── :8092 ───► ┌───────────────┴─────────────────────────────┐
                          │  traffic    (Datengenerierungstool, Flask)   │
                          └─────────────────────────────────────────────┘

   db (MariaDB, zwei Datenbanken: wordpress + matomo)
   wp-init / matomo-init  (laufen einmal, richten Shop bzw. Matomo ein)
```

| Container | Image | Host-Port | Aufgabe |
|---|---|---|---|
| `db` | `mariadb:11.4` | – (intern) | Eine Instanz mit zwei Datenbanken (`wordpress`, `matomo`) |
| `wordpress` | `wordpress:6.7-php8.3-apache` | **8090** | Online-Shop (WooCommerce, Theme Botiga) |
| `matomo` | `matomo:5.3.0` | **8091** | Web-Analyse-Tool |
| `traffic` | selbst gebaut (Python/Flask) | **8092** | Datengenerierungstool mit Dashboard |
| `wp-init` | `wordpress:cli` | – (einmalig) | Richtet Shop ein / spielt Demo-Fixture ein |
| `matomo-init` | `curlimages/curl` | – (einmalig) | Installiert & konfiguriert Matomo headless |

## Voraussetzungen

- **Docker** und **Docker Compose v2** (z. B. Docker Desktop). Getestet mit Docker 29.x.
- **Internetzugang beim ersten Start** – Images, Theme/Plugins und Demo-Bilder werden geladen.
- ~3 GB freier Speicher, die Ports **8090–8092** frei (anpassbar in `.env`).

> 🪟 **Windows:** `docker compose up -d` läuft auch in PowerShell. Das Komfort-Skript
> **`./install.sh`** ist jedoch ein Bash-Skript und läuft **nicht** in cmd/PowerShell. Empfohlen:
> **WSL2 + Docker Desktop** (WSL2-Backend) – Repo am besten **im WSL-Dateisystem** klonen und
> `./install.sh` im WSL-Terminal ausführen. (Git Bash geht meist auch, kann aber beim internen
> `docker run -v …`-Mount zicken.) Wichtig für Windows: Eine `.gitattributes` erzwingt **LF-Zeilenenden**,
> damit die Container-Init-Skripte auch nach einem Windows-Klon funktionieren – nicht entfernen.
> **Schritt-für-Schritt-Anleitung:** [`docs/WINDOWS.md`](docs/WINDOWS.md).

## Zugänge

| Dienst | URL | Login |
|---|---|---|
| **Shop** (Frontend) | http://localhost:8090 | – |
| Shop-Admin | http://localhost:8090/wp-admin | `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` aus `.env` |
| **Matomo** | http://localhost:8091 | `MATOMO_ADMIN_USER` / `MATOMO_ADMIN_PASSWORD` aus `.env` |
| **Datengenerierungstool** | http://localhost:8092 | – |

> **Ports sind fix und an `127.0.0.1` gebunden** (nur localhost, kein LAN-Zugriff). Sie gehören zur reproduzierbaren Kursumgebung – nur bewusst in `.env` überschreiben.

> Die Standard-Logins stehen in `.env.example` (z. B. `admin` / `wp123` bzw. `admin` / `matomo123`).
> Für den Unterricht bewusst einfach gehalten – **nicht für produktiven Einsatz**.

## Die drei Komponenten

### 1. Online-Shop (WordPress + WooCommerce)

Ein voll funktionsfähiger Demo-Shop auf Basis von WordPress + WooCommerce mit dem Theme **Botiga**
(inkl. importiertem Demo-Inhalt „Cocolo" mit Produktbildern). Der Shop ist sofort kauffähig:
Produkte ansehen, in den Warenkorb legen, zur Kasse gehen, Bestellung abschließen.

Der Demo-Shop ist ein fiktiver **Kosmetik-Shop mit Sitz in Berlin**, durchgängig auf **Deutsch**
lokalisiert und in **Euro (EUR)** ausgezeichnet (deutsches Preisformat, z. B. `18,00 €`). Er bringt
fertig mit:

- **Produkte** mit echten Bildern, deutschen Bezeichnungen und **ausführlichen, sinnvollen
  Produktbeschreibungen** (Nutzen, Anwendung, Hauttyp – kein Lorem Ipsum), eingeordnet in die
  **Produktkategorien** *Gesichtsreinigung*, *Gesichtspflege* und *Make-up*.
- **Rabattgutschein `NATUR10`** (10 %), der im Demo-Betrieb ab und zu eingelöst wird.
- **Produktbewertungen** mit Sternen: realistisch gestreut um einen **Durchschnitt von ~4,6**
  (viele zufriedene, einige kritische, wenige sehr unzufriedene Kund:innen) – ideal, um in Matomo
  bzw. im Shop über Kundenzufriedenheit und Conversion zu sprechen.
- **Deutschsprachige Blog-Beiträge** (Kategorie/Ratgeber) mit thematisch passenden Beitragsbildern.
- Saubere Seitenstruktur und Hauptmenü (Blog, **Shop**, Kontakt).

Auf den Shop-/Kategorieseiten gibt es eine **moderne Filter- & Sortierleiste** (Kategorie, Preis,
Bewertung, nur Angebote sowie Sortierung nach Empfehlung/Beliebtheit/Bewertung/Preis/Neuheiten/Name).
Der **Kategorienfilter** liest die echten Produktkategorien dynamisch aus. Die Leiste wirkt **sofort
im Browser** (ohne Neuladen) und liest die echten Produktdaten serverseitig aus.

In jede Shop-Seite ist der **Matomo-Tracking-Code** eingebaut (über ein Must-Use-Plugin), sodass
jeder Klick und jede Bestellung in Matomo erscheint.

> **WordPress-Dateien von Hand bearbeiten:** Das komplette WordPress-Verzeichnis ist als
> **Bind-Mount** auf den Host gelegt (`./wordpress/www/`). Dort lassen sich Plugins, Themes,
> Uploads oder `wp-config.php` direkt importieren/bearbeiten – die Änderungen sind sofort im
> Container sichtbar. Der Ordner wird beim ersten Start automatisch befüllt.

### 2. Matomo (Web-Analyse)

Matomo ist **vorinstalliert und vorkonfiguriert**: Superuser-Login bereit, Website „Demo-Shop M392"
angelegt (Website-ID 1), E-Commerce aktiviert, Währung EUR. Die Lernenden loggen sich ein und
arbeiten direkt mit den Berichten – ohne Setup-Hürden.

Damit von der ersten Minute an aussagekräftige Berichte sichtbar sind, liefert die Umgebung eine
vorgebackene Historie von **rund 6 Monaten** (≈ 180 Tage Besuche, Käufe, Umsatz) – mit leichtem
Wachstums-Trend und Wochen-Saisonalität, damit Zeitvergleiche (Monat/Jahr) etwas hergeben. Diese
Fixture wird beim Start **restauriert, auf „heute" verschoben und einmal archiviert** – die Berichte
stimmen also sofort, ohne Wartezeit (kein Live-Generieren mehr beim Install).

Getrackt werden u. a.:

- **Seitenaufrufe** und Besucherverhalten
- **E-Commerce** – Produktansichten und Kategorien (*Ecommerce → Produkte*), Warenkorb-Updates
  inkl. abgebrochener Warenkörbe sowie abgeschlossene **Bestellungen** (Conversions, Umsatz)
- **Suche auf der Website** (*Verhalten → Suche auf der Website*) – nach welchen Begriffen gesucht
  wurde und wie viele Treffer es gab
- **Ereignisse** (*Verhalten → Ereignisse*) – Interaktionen wie Newsletter-Anmeldung, „Auf
  Wunschliste", Social-Share, Video-Play oder Sortierung ändern
- **Inhalte** (*Verhalten → Inhalte*) – Impressionen und Klicks auf Hero-/Promo-Banner
- **Leistung** (*Verhalten → Leistung*) – Seitenladezeiten (Netzwerk, Server, DOM …)
- **Orte / Geografie** (*Besucher → Orte*) – Besuche aus **DE/CH/AT** plus ~5 % aus dem übrigen
  Europa, die Produkte ansehen und in den Warenkorb legen, aber **nicht bestellen können** (der Shop
  akzeptiert nur DE/CH/AT) – ein schönes Beispiel für „Traffic ohne Conversion"
- **Datei-Downloads als Ziel** – der „INCI-Leitfaden" (PDF) auf der Blog-Seite ist als **Matomo-Ziel**
  hinterlegt (*Ziele*); jeder Download zählt als Conversion (auch das Traffic Lab löst ihn aus)
- **Kontaktanfragen als Ziel** – das Kontaktformular leitet nach dem Absenden auf die Seite
  `/danke/` weiter; dieser Seitenaufruf ist als **Matomo-Ziel** „Kontaktanfrage (Danke-Seite)"
  hinterlegt (URL enthält `/danke`) und zählt als Conversion (auch das Traffic Lab löst ihn aus)

Das gilt sowohl für echte Browser-Aktionen der Lernenden als auch für die synthetischen Daten des
Datengenerierungstools.

### 3. Datengenerierungstool

Ein modernes Dashboard auf **http://localhost:8092** erzeugt realistischen Traffic und sendet ihn
über die Matomo-Tracking-API. So wird sichtbar, **wie** Tracking-Daten entstehen.

- **Live-KPIs & Aktivitäts-Chart:** Besuche, Käufe, Umsatz, Conversion in Echtzeit. Der
  Aktivitäts-Chart beschriftet die Zeitachse **relativ** („jetzt", „−1:00" …); ein **Hover-Tooltip**
  zeigt die Anzahl Besucher:innen pro Balken.
- **Live-Tropf** (standardmäßig aktiv, per Schalter pausierbar; bei Pause sind die Regler ausgegraut)
  mit Reglern:
  - **Besucher / Stunde** – wie viele Besuche im Schnitt eintropfen. Der Tropf läuft **organisch**:
    Besuche kommen in kleinen Schüben (mal mehrere Gäste gleichzeitig) mit zufälligen Pausen dazwischen
    (Poisson-Ankünfte) – kein starres Intervall, aber im Mittel die eingestellte Rate.
  - **Conversion-Rate (%)** – die erwarteten Käufe pro Stunde werden live berechnet und angezeigt.
  - **Wiederkehrende Kunden (%)** – wie oft eine Bestellung einer **bestehenden** WooCommerce-Kund:in
    zugeordnet wird (aus der DB gelesen) statt einer neuen. So entstehen wiederkehrende Käufer:innen.
- **Manuell erzeugen:** sofort X Besuche oder Y Käufe auslösen. (Die historische Tiefe der Charts
  kommt aus der vorgebackenen Fixture, nicht aus einem Live-Backfill beim Install.)
- **Echte WooCommerce-Bestellungen:** Die Fixture enthält echte Bestellungen (sichtbar unter
  *WooCommerce → Bestellungen*) – mit realistischen Daten (Kund:innen + Adressen, nach Bestseller
  gewichtete Artikel, Test-Zahlarten, realistischer Status-Mix). Die Kund:innen sind passend zu Berlin
  **divers** (~70 % deutsche, ~30 % aus weiteren Communities) und werden als **echte
  WooCommerce-Kund:innen** angelegt (Rolle *customer*) und der Bestellung zugeordnet – so erscheinen
  sie unter *WooCommerce → Kunden* bzw. *Analytics → Kunden* (inkl. einiger **wiederkehrender**
  Kund:innen mit mehreren Bestellungen). Die Bestellungen sind über **denselben ~6-Monats-Zeitraum
  wie die Matomo-Historie** verteilt (gleicher Wachstums-Trend/Wochenrhythmus); im laufenden Betrieb
  ergänzt der Live-Tropf weitere echte Bestellungen (steuerbar über `TRAFFIC_CREATE_WC_ORDERS` /
  `TRAFFIC_RETURNING_RATE` in `.env`).
  Menge, Historienlänge und Umsatzniveau der Fixture sind **zur Bake-Zeit** in `tools/bake.conf`
  festgelegt (`HISTORY_DAYS`, `AVG_MONTHLY_REVENUE`, `CONVERSION_RATE`, `RETURNING_RATE`) – zum Ändern
  dort anpassen und mit `./tools/bake-fixture.sh` neu backen (Details in [`docs/HANDOFF.md`](docs/HANDOFF.md)).
  Jede Bestellung ist **zusätzlich in Matomo gespiegelt** (gleiches Datum/Artikel, **Produktumsatz
  ohne Versand**), sodass *Matomo → E-Commerce* „Gesamteinnahmen" und *WooCommerce → Statistiken*
  „Bruttoumsatz" **dieselben Zahlen** zeigen. Versand/Gutscheine/Retouren bleiben bewusst
  WooCommerce-exklusiv (Lerneffekt: Tools messen Unterschiedliches).
  Jede Bestellung ist in **alle WooCommerce-Analytics-Tabellen** synchronisiert (Bestell-Statistik,
  Produkte, Gutscheine, Kund:innen) und mit `date_paid` versehen – dadurch stimmen *WooCommerce →
  Statistiken/Berichte* und die **Gesamtausgaben pro Kund:in** sofort. **Ab und zu** (~18 %) lösen
  Kund:innen den Rabattgutschein **`NATUR10`** (10 %) ein (sichtbar unter *Marketing → Gutscheine*).

Die generierten Daten spiegeln **live den echten Shop**: Das Traffic Lab liest die Produkte über einen
Sync-Endpunkt direkt aus WooCommerce (`/wp-json/m392/v1/products`) – **echte Namen, Preise, Kategorie
und Slugs**, identifiziert über dieselbe E-Commerce-ID `wc_<id>`, die auch ein echter Browser-Kauf
meldet. Dadurch stimmen die Matomo-Produktberichte exakt mit dem Shop überein, **neu angelegte
Produkte werden automatisch berücksichtigt**, und Preisänderungen wirken sich sofort aus. Fällt der
Shop kurzzeitig aus, greift `catalog.json` als Fallback (und liefert weiterhin die Bestseller-
Gewichtung `popularity` je SKU). So zeigt Matomo für synthetischen und realen Traffic **eine
konsistente Shop-Struktur** (Produkte, Kategorien, On-Site-Suche).

Die Grunddaten sind bewusst **realistisch und auswertbar** angelegt:

- **Klare Bestseller statt Gleichverteilung:** Produkte verkaufen sich nach gewichteter Beliebtheit –
  einige laufen sehr gut, andere bilden den „langen Schwanz" (Top-3 ≈ ¾ des Umsatzes). Sichtbar in
  *Matomo → E-Commerce → Produkte* sowie im Shop-Filter „Beliebtheit".
- **Akquise-Kanäle mit Aussage:** Jeder Besuch kommt über einen Kanal (Social, Suche, Direkt,
  Newsletter, Verweis). **Social Media** (Instagram, Facebook, Pinterest, TikTok) ist als
  **stärkster Verkaufskanal** modelliert – hoher Besuchsanteil *und* überdurchschnittliche
  Conversion. In *Matomo → Akquise* bzw. den E-Commerce-Berichten je Kanal wird damit ablesbar,
  dass Social für diesen Shop am meisten Umsatz bringt. Die Kanal-Multiplikatoren sind normiert, die
  im Dashboard eingestellte Conversion-Rate bleibt im Mittel erhalten.
- **Diverse, realistische Verweisquellen:** Die **Verweis-Websites** (*Akquise → Websites*) sind
  mehrere fiktive, thematisch passende Domains (Magazine, Blogs, Marktplätze), wobei **eine Quelle
  klar dominiert** – realistisch statt gleichverteilt. Der **Newsletter** wird als echte
  **Kampagne** getrackt (`pk_campaign`) und erscheint daher unter *Akquise → Kampagnen*.

## Bezahlung im Test-Shop

Im Checkout stehen drei **Test-Zahlungsmethoden** bereit (komplett offline, ohne externe Konten),
mit denen echte Browser-Käufe durchgespielt werden können:

| Methode | Verhalten |
|---|---|
| **Kauf auf Rechnung** | Bestellung geht als „wartet auf Zahlung" (on-hold) durch. |
| **Kreditkarte** | Akzeptiert **nur** die Testkarte `4242 4242 4242 4242` (beliebiges zukünftiges Ablaufdatum, beliebige CVC). Andere Nummern werden **abgelehnt** – ideal, um Conversion vs. Fehlversuch zu vergleichen. |
| **TWINT** | Simuliert eine TWINT-Zahlung und wird automatisch bestätigt. |

Jeder erfolgreiche Browser-Kauf wird auf der Bestellbestätigungsseite als **E-Commerce-Conversion**
an Matomo gemeldet. Die Lernenden finden ihre eigenen Bestellungen unter *Matomo → E-Commerce*.

## Wie alles zusammenhängt (Datenfluss)

1. **Eigene Klicks:** Browser → Shop (`:8090`) → Matomo-JS lädt → Matomo (`:8091`) zählt den Besuch.
2. **Eigener Kauf:** Checkout abschließen → Danke-Seite meldet die Bestellung an Matomo (E-Commerce).
3. **Generierter Traffic:** Datengenerierungstool (`:8092`) → Matomo-Tracking-API → Besuche,
   Conversions, Umsatz im laufenden Betrieb. (Die historische Tiefe der Charts kommt aus der
   vorgebackenen Fixture, die beim Install restauriert und auf „heute" verschoben wird.)

## Ideen für den Unterricht

- **Einstieg:** In Matomo die vorbefüllten Berichte erkunden – Besucher, Verhalten, Akquise, E-Commerce.
- **Tracking nachvollziehen:** Im Shop klicken / einkaufen und die eigenen Aktionen unter
  *Besucher → in Echtzeit* bzw. *E-Commerce* wiederfinden.
- **Kennzahlen steuern:** Im Datengenerierungstool die Conversion-Rate verändern und beobachten,
  wie sich Käufe/Umsatz in Matomo entwickeln.
- **Last simulieren:** „Besucher/Stunde" erhöhen und einen Traffic-Anstieg im Zeitverlauf analysieren.
- **Kampagnen-Analyse:** Käufe vs. abgelehnte Kreditkarten gegenüberstellen (Conversion-Trichter).
- **Datenschutz-Diskussion:** Welche Daten werden erfasst, was sieht der Shop-Betreiber, was nicht?

## Konfiguration (`.env`)

Die gesamte Konfiguration läuft über **eine** Datei: `.env` (Kopie von `.env.example`). Die echte
`.env` ist absichtlich **nicht** im Repo – deshalb **zuerst** `cp .env.example .env` ausführen (sonst
bricht `./install.sh` ab). **Jede Variable ist in `.env.example` direkt kommentiert**; für den normalen
Kursbetrieb funktionieren die Standardwerte ohne Anpassung. Die wichtigsten Variablen:

| Variable | Standard | Bedeutung |
|---|---|---|
| `WORDPRESS_PORT` / `MATOMO_PORT` / `TRAFFIC_PORT` | `8090` / `8091` / `8092` | Host-Ports der drei Dienste |
| `MARIADB_VERSION`, `WORDPRESS_VERSION`, `WORDPRESS_CLI_VERSION`, `MATOMO_VERSION` | gepinnt | Image-Versionen (für reproduzierbare Kurse) |
| `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` / `WP_ADMIN_EMAIL` | `admin` / `wp123` / … | Shop-Admin |
| `MATOMO_ADMIN_USER` / `MATOMO_ADMIN_PASSWORD` / `MATOMO_ADMIN_EMAIL` | `admin` / `matomo123` / … | Matomo-Superuser |
| `*_DB_*` / `MYSQL_ROOT_PASSWORD` | siehe Datei | Datenbank-Namen, -Benutzer, -Passwörter |
| `TRAFFIC_LIVE_DRIP` | `true` | Live-Tropf beim Start aktiv (in der UI abschaltbar) |
| `TRAFFIC_DRIP_VISITS_PER_HOUR` | `120` | Startwert: Besucher/Stunde des Live-Tropfs |
| `TRAFFIC_CONVERSION_RATE` | `0.014` | Startwert: Anteil Besuche mit Kauf (0–1) |
| `TRAFFIC_RETURNING_RATE` | `0.08` | Startwert: Anteil Bestellungen bestehender Kund:innen (wiederkehrende Käufer:innen) |
| `TRAFFIC_CREATE_WC_ORDERS` | `true` | Echte WooCommerce-Bestellungen im Live-Betrieb anlegen |
| `M392_ORDER_API_KEY` | `m392_lab_…` | Gemeinsames Secret für den Bestell-Endpunkt (WP ⇄ Traffic) |
| `M392_AB_TEST_ENABLED` | `true` | Shop-A/B-Test aktiv (Variante A/B, getrackt als Matomo-Custom-Dimension „AB-Variante") |
| `M392_AB_SPLIT_B` | `50` | Prozent der Besucher:innen in Variante B |
| `M392_AB_CONV_FACTOR_B` | `1.25` | Faktor, um den Variante B besser konvertiert (Lerneffekt) |

> **Historie/Umsatz der Fixture:** Historienlänge, Umsatzniveau und Conversion-/Returning-Rate der
> **vorgebackenen Historie** stehen **nicht** in `.env`, sondern in `tools/bake.conf` (`HISTORY_DAYS`,
> `AVG_MONTHLY_REVENUE`, `CONVERSION_RATE`, `RETURNING_RATE`). Die `TRAFFIC_*`-Werte oben steuern nur
> den **laufenden Live-Tropf**. Fixture ändern ⇒ `tools/bake.conf` anpassen und neu backen
> (`./tools/bake-fixture.sh`).

> **Versionen anpassen:** Alle Versionen sind gepinnt. Vor jedem Semester eine Version testen und
> festschreiben, damit der Kurs über die Zeit reproduzierbar bleibt.
>
> **Sprache/Währung/Standort:** Shop-Sprache (de_CH, Schweizer Deutsch ohne ß), Währung (EUR) und
> Land (DE) sind **in der Fixture eingebacken** (`shop.sql.gz` / `uploads.tar.gz`) und werden **nicht**
> mehr über `.env` gesteuert. Zum Ändern die Werte in `tools/bake.conf` anpassen und neu backen
> (`./tools/bake-fixture.sh`); Details in [`docs/HANDOFF.md`](docs/HANDOFF.md).
>
> ⚠️ **Passwörter nachträglich ändern:** Die Datenbank-Benutzer werden **einmalig** beim ersten Start
> aus den `.env`-Werten angelegt. Wer DB-Passwörter **danach** ändert, muss einmal komplett
> zurücksetzen: `docker compose down -v && docker compose up -d` (bzw. `./install.sh`).

## Zurücksetzen & Reproduzierbarkeit

```bash
# Sauberer Neustart ohne Datenverlust:
docker compose up -d

# Frische Installation / vollständiger Neuaufbau (alle Daten gelöscht, alles neu eingerichtet):
./install.sh
```

> **`install.sh` – komplette Einrichtung auf Knopfdruck.** Stoppt einen evtl. laufenden Stack, löscht
> alle Volumes **und** leert `wordpress/www`, baut/startet neu und **restauriert dann die vorgebackene
> ~6-Monats-Fixture (Matomo-Historie + Bestellungen), verschiebt sie per `tools/shift-dates.sh` auf
> „heute" und archiviert Matomo einmal** – und kehrt erst dann zurück, sodass die Berichte **sofort**
> stimmen (kein Nachladen/„Reintröpfeln"). Eine animierte Fortschrittsanzeige (Spinner + Uhr) zeigt
> die Schritte **[1/5]…[5/5]**. Mit `--no-wait` kehrt es schon vor der Archivierung zurück (Matomo holt
> sie beim ersten Bericht-Aufruf nach), `-y` überspringt die Sicherheitsabfrage, `--help` zeigt die
> Hilfe. Hinweis: Eine bestehende Installation wird dabei zurückgesetzt.
>
> ⚠️ **`install.sh` ist der einzige Weg zur vollständigen Demo-Historie.** Ein reines
> `docker compose down -v && docker compose up -d` baut Shop + Matomo-Grundinstallation auf, spielt aber
> die **Matomo-Historie und die Bestell-Fixture nicht** ein (kein Datums-Shift, keine Archivierung,
> keine Aktivierung der M392-Report-Plugins) – die Matomo-Berichte bleiben dann praktisch leer.

> **Hinweis Bind-Mount:** `down -v` entfernt die Docker-Volumes (Datenbank, Matomo), **nicht** aber
> die WordPress-Dateien auf dem Host (`./wordpress/www/`). Beim nächsten Start spielt `wp-init` die
> Fixture sauber darüber ein. Für ein komplett jungfräuliches Docroot zusätzlich den Ordner leeren:
> `rm -rf ./wordpress/www/* ./wordpress/www/.htaccess` (vor `up -d`).

Der **Demo-Shop ist reproduzierbar**: Sein vollständiger Stand – Theme, Demo-Produkte mit deutschen
Beschreibungen, **Bewertungen/Sterne**, Blog-Beiträge, Seiten, Bilder sowie alle Einstellungen
(Sprache, Währung EUR, Standort Berlin) – ist als **Fixture** eingefroren (`wordpress/init/fixture/`:
DB-Dump + Uploads). Beim frischen Start stellt `wp-init` ihn automatisch wieder her – inklusive der
benötigten Plugins/Theme, die in gepinnten Versionen aus dem WordPress-Repository nachinstalliert
werden. Ein `down -v && up -d` liefert also wieder **exakt denselben Shop**.

## Projektstruktur

```
.
├─ docker-compose.yml            # Orchestrierung aller Container
├─ .env.example                  # Vorlage für die Konfiguration
├─ install.sh                    # Neuaufbau: Reset → Fixture-Restore → Datums-Shift → Archivierung
│
├─ tools/                        # Fixture backen + Datums-Shift (Maintainer)
│  ├─ bake.conf                  # Bake-Parameter (Historienlänge, Umsatz, CR, Offset-Rundung)
│  ├─ bake-fixture.sh            # erzeugt die Fixture einmalig (generieren → dumpen)
│  └─ shift-dates.sh             # verschiebt beim Install alle Fixture-Daten auf „heute"
│
├─ seed/
│  └─ catalog.json               # Gemeinsamer Produktkatalog (Shop + Traffic-Generator)
│
├─ db/
│  └─ init/01-init-databases.sh  # Legt beide Datenbanken + Benutzer an (Passwörter aus .env)
│
├─ wordpress/
│  ├─ init/                      # Einrichtung & versioniertes Material (Bind-Mounts in Container)
│  │  ├─ wp-init.sh              # Richtet Shop ein bzw. spielt die Demo-Fixture wieder ein
│  │  ├─ fixture/                # Eingefrorener Demo-Shop (DB-Dump + Uploads)
│  │  │  ├─ shop.sql.gz
│  │  │  └─ uploads.tar.gz
│  │  └─ mu-plugins/             # Immer aktive WordPress-Plugins (per Volume eingebunden)
│  │     ├─ matomo-tracking.php  # Baut den Matomo-Tracking-Code ein (inkl. E-Commerce + Suche)
│  │     ├─ m392-test-payments.php  # Test-Zahlungsmethoden (Rechnung, Kreditkarte, TWINT)
│  │     ├─ m392-german-shop.php # Deutsche Übersetzungen/Labels (z. B. „Angebot!", Trust-Badge)
│  │     ├─ m392-shop-filters.php   # Moderne Produktfilter & Sortierung (Preis/Bewertung/Angebote)
│  │     ├─ m392-ab-test.php     # Shop-A/B-Test (Variante A/B, Tracking via Matomo-Custom-Dimension)
│  │     └─ m392-order-api.php   # REST-Endpunkt: Traffic Lab legt echte Bestellungen an
│  └─ www/                       # WordPress-Docroot als Bind-Mount (Host) – wird generiert,
│                                #   nicht versioniert; hier von Hand Dateien importieren
│
├─ matomo/
│  ├─ matomo-init.sh             # Installiert & konfiguriert Matomo headless, erzeugt API-Token
│  ├─ fixture/                   # Vorgebackene Historie (Install: restaurieren + auf heute shiften)
│  │  ├─ matomo-history.sql.gz   #   Matomo-Roh-Logs
│  │  ├─ wc-orders.sql.gz        #   WooCommerce-Bestellungen + Kund:innen
│  │  ├─ BASE                    #   Anker-Datum für den Offset-Shift
│  │  └─ BAKE-INFO               #   womit gebacken wurde
│  └─ M392ABTesting/ M392Funnels/   # native Matomo-5-Report-Plugins (A/B, Funnels)
│
├─ traffic/                      # Datengenerierungstool (Python/Flask)
│  ├─ Dockerfile
│  ├─ app.py                     # Web-Server + Dashboard-API + Live-Tropf
│  ├─ generator.py               # Logik: Besuche/Käufe/Downloads an die Matomo-Tracking-API senden
│  ├─ orders.py                  # legt echte WooCommerce-Bestellungen an (Order-API)
│  ├─ requirements.txt
│  └─ templates/index.html       # Dashboard-Oberfläche
│
└─ docs/                         # Doku (README bleibt im Root, alles Weitere hier)
   ├─ HANDOFF.md                 # Projektübergabe (Zustand, Architektur, Gotchas)
   ├─ ARCHITECTURE.md            # Datenfluss, Tracking-Wege, Fixture/Shift
   ├─ CHANGELOG.md               # Änderungsverlauf
   ├─ LEARNING.md                # Modul-392-Lernpfad
   ├─ WINDOWS.md                 # Windows/WSL2-Setup
   └─ review/                    # Code-Review-Konversation (Historie)
```

## Troubleshooting

- **„Error establishing a database connection" im Shop**
  Vermutlich wurden Passwörter in `.env` **nach** dem ersten Start geändert. Die DB-Benutzer haben
  dann noch die alten Passwörter. Lösung: `docker compose down -v && docker compose up -d`.
- **Matomo zeigt noch den Installer**
  `docker compose up matomo-init` erneut ausführen und `docker compose logs matomo-init` prüfen.
- **Shop ohne Inhalte / Produkte**
  `docker compose up wp-init` erneut ausführen und Logs prüfen.
- **404 auf Produkt-/Checkout-Seiten**
  Tritt auf, wenn der `.htaccess`-Rewrite-Block fehlt. `docker compose up wp-init` setzt ihn neu.
- **Keine historischen Daten in Matomo**
  Die ~6-Monats-Historie kommt aus der Fixture und wird **nur von `./install.sh`** eingespielt (nicht
  von `docker compose up -d`). Fehlt sie, `./install.sh` ausführen. Schlägt die Restaurierung fehl,
  zeigt der Schritt **[5/5]** ein `✗` samt Fehlerausgabe; meist fehlt der API-Token – `docker compose
  logs matomo-init` prüfen (Token wird dort erzeugt und im Volume `matomo_token` abgelegt).
- **Ports belegt**
  Ports in `.env` ändern (`WORDPRESS_PORT`, `MATOMO_PORT`, `TRAFFIC_PORT`). Der im Shop eingebettete
  Tracking-Code übernimmt `MATOMO_PORT` automatisch aus `.env` – danach die Container einmal neu
  erstellen (`docker compose up -d`).
- **Erster Start hängt / lädt lange**
  Beim ersten Start werden Images, Theme/Plugins und Bilder geladen – Internet nötig, etwas Geduld.

## Technische Hinweise & Sicherheit

> Diese Umgebung ist **ausschließlich für Schulung/Demo auf dem lokalen Rechner** gedacht – **nicht**
> für den Produktivbetrieb.

Bewusste Vereinfachungen für den Lehreinsatz:

- **Schwache Standard-Passwörter** in `.env` (gut lesbar, leicht zu merken).
- **Matomos `enable_trusted_host_check` ist deaktiviert** (Zugriff über `localhost` und intern `matomo`).
- **Init-Container laufen teils als root**, um Volumes einzurichten.
- **Fake-Zahlungsabwicklung** – es findet keine echte Zahlung statt; die „Kreditkarte" prüft nur
  die Testnummer.

## Lizenz & Credits

Diese Umgebung kombiniert quelloffene Software:

- [WordPress](https://wordpress.org/) & [WooCommerce](https://woocommerce.com/) (GPL)
- [Botiga](https://athemes.com/theme/botiga/)-Theme und zugehörige Plugins (GPL)
- [Matomo](https://matomo.org/) (GPL v3)
- [MariaDB](https://mariadb.org/), [Flask](https://flask.palletsprojects.com/), [Docker](https://www.docker.com/)

Die mitgelieferten Skripte und das Datengenerierungstool dieses Repositories dürfen frei für
Unterrichtszwecke verwendet und angepasst werden.
