# M392 Matomo Lab

> Docker-Lehrumgebung für das Modul **392 – Nutzer-Daten mittels Analysetools auswerten**.
> Ein einziger Befehl startet einen vollständigen Online-Shop, ein vorkonfiguriertes
> Web-Analyse-Tool (Matomo) und ein Werkzeug, das realistischen Besucher- und Kauf-Traffic erzeugt.

Die Lernenden erhalten damit eine realitätsnahe Umgebung, in der sie **Web-Analytics von A bis Z**
üben: Tracking verstehen, Besucher- und E-Commerce-Daten auswerten, Conversions analysieren und
eigene Hypothesen mit selbst erzeugten Daten überprüfen — alles lokal, ohne echte Nutzerdaten.

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

Alles läuft in Docker-Containern und ist **turnkey**: `docker compose up` genügt, der Rest wird
automatisch eingerichtet (Shop installiert, Matomo konfiguriert, Demo-Daten befüllt).

## Lernziele

Mit dieser Umgebung lassen sich u. a. folgende Kompetenzen aus Modul 392 abdecken:

- **Tracking verstehen:** Wie gelangt ein Seitenaufruf bzw. eine Bestellung in das Analyse-Tool?
- **Analyse-Tool bedienen:** Matomo-Berichte lesen (Besucher, Verhalten, Akquise, E-Commerce).
- **Kennzahlen interpretieren:** Besuche, Absprungrate, Conversion-Rate, Umsatz, Warenkorb-Wert.
- **Hypothesen testen:** Parameter ändern (z. B. Conversion-Rate) und die Auswirkung beobachten.
- **Datenqualität & Datenschutz reflektieren:** Was wird getrackt, was nicht, und warum.

## Architektur

> 📐 **Ausführliche Architektur-Doku** (Tracking-Wege, Datenfluss, Diagramme): siehe
> [`ARCHITECTURE.md`](ARCHITECTURE.md).

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

## Schnellstart

```bash
cp .env.example .env      # einmalig – Passwörter bei Bedarf anpassen (siehe Hinweis unten)
docker compose up -d
```

Beim **ersten** Start werden Images gezogen und alles automatisch eingerichtet
(Shop, Matomo, Demo-Daten). Das dauert je nach Internet einige Minuten. Den Fortschritt verfolgen:

```bash
docker compose logs -f wp-init matomo-init
```

Sind `wp-init` und `matomo-init` mit `Exited (0)` beendet, ist alles bereit.

> ⚠️ **Passwörter:** Die Datenbank-Benutzer werden **einmalig** beim ersten Start angelegt (aus den
> Werten in `.env`). Wer Passwörter **nachträglich** ändert, muss einmal zurücksetzen:
> `docker compose down -v && docker compose up -d`.

## Zugänge

| Dienst | URL | Login |
|---|---|---|
| **Shop** (Frontend) | http://localhost:8090 | – |
| Shop-Admin | http://localhost:8090/wp-admin | `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` aus `.env` |
| **Matomo** | http://localhost:8091 | `MATOMO_ADMIN_USER` / `MATOMO_ADMIN_PASSWORD` aus `.env` |
| **Datengenerierungstool** | http://localhost:8092 | – |

> Die Standard-Logins stehen in `.env.example` (z. B. `admin` / `admin123` bzw. `admin` / `matomo123`).
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
  Produktbeschreibungen** (Nutzen, Anwendung, Hauttyp – kein Lorem Ipsum).
- **Produktbewertungen** mit Sternen: realistisch gestreut um einen **Durchschnitt von ~4,6**
  (viele zufriedene, einige kritische, wenige sehr unzufriedene Kund:innen) – ideal, um in Matomo
  bzw. im Shop über Kundenzufriedenheit und Conversion zu sprechen.
- **Deutschsprachige Blog-Beiträge** (Kategorie/Ratgeber) mit thematisch passenden Beitragsbildern.
- Saubere Seitenstruktur und Menü (Startseite, Blog, **Shop**, Kontakt).

Auf den Shop-/Kategorieseiten gibt es eine **moderne Filter- & Sortierleiste** (Preis, Bewertung,
nur Angebote sowie Sortierung nach Empfehlung/Beliebtheit/Bewertung/Preis/Neuheiten/Name). Sie
wirkt **sofort im Browser** (ohne Neuladen) und liest die echten Produktdaten serverseitig aus.

In jede Shop-Seite ist der **Matomo-Tracking-Code** eingebaut (über ein Must-Use-Plugin), sodass
jeder Klick und jede Bestellung in Matomo erscheint.

> **WordPress-Dateien von Hand bearbeiten:** Das komplette WordPress-Verzeichnis ist als
> **Bind-Mount** auf den Host gelegt (`./wordpress-html/`). Dort lassen sich Plugins, Themes,
> Uploads oder `wp-config.php` direkt importieren/bearbeiten – die Änderungen sind sofort im
> Container sichtbar. Der Ordner wird beim ersten Start automatisch befüllt.

### 2. Matomo (Web-Analyse)

Matomo ist **vorinstalliert und vorkonfiguriert**: Superuser-Login bereit, Website „Demo-Shop M392"
angelegt (Website-ID 1), E-Commerce aktiviert, Währung EUR. Die Lernenden loggen sich ein und
arbeiten direkt mit den Berichten – ohne Setup-Hürden.

Damit von der ersten Minute an aussagekräftige Berichte sichtbar sind, befüllt die Umgebung beim
Start automatisch **rund 24 Monate Verlaufsdaten** (Besuche, Käufe, Umsatz) – mit leichtem
Wachstums-Trend und Wochen-Saisonalität, damit Zeitvergleiche (Monat/Jahr) etwas hergeben. Das
Befüllen läuft im Hintergrund und dauert je nach Rechner einige Minuten; der Fortschritt ist im
Dashboard-Log sichtbar.

Getrackt werden u. a.:

- **Seitenaufrufe** und Besucherverhalten
- **E-Commerce** – Produktansichten und Kategorien (*Ecommerce → Produkte*), Warenkorb-Updates
  inkl. abgebrochener Warenkörbe sowie abgeschlossene **Bestellungen** (Conversions, Umsatz)
- **Suche auf der Website** (*Verhalten → Suche auf der Website*) – nach welchen Begriffen gesucht
  wurde und wie viele Treffer es gab

Das gilt sowohl für echte Browser-Aktionen der Lernenden als auch für die synthetischen Daten des
Datengenerierungstools.

### 3. Datengenerierungstool

Ein modernes Dashboard auf **http://localhost:8092** erzeugt realistischen Traffic und sendet ihn
über die Matomo-Tracking-API. So wird sichtbar, **wie** Tracking-Daten entstehen.

- **Live-KPIs & Aktivitäts-Chart:** Besuche, Käufe, Umsatz, Conversion in Echtzeit.
- **Live-Tropf** (standardmäßig aktiv, per Schalter pausierbar) mit Reglern:
  - **Besucher / Stunde** – wie viele Besuche im Schnitt eintropfen. Der Tropf läuft **organisch**:
    Besuche kommen in kleinen Schüben (mal mehrere Gäste gleichzeitig) mit zufälligen Pausen dazwischen
    (Poisson-Ankünfte) – kein starres Intervall, aber im Mittel die eingestellte Rate.
  - **Conversion-Rate (%)** – die erwarteten Käufe pro Stunde werden live berechnet und angezeigt.
- **Manuell erzeugen:** sofort X Besuche oder Y Käufe auslösen, oder historische Daten (Tage)
  nachfüllen (Backfill).

Die generierten Daten nutzen denselben Produktkatalog (`catalog.json`) wie der echte Shop: **dieselben
Produkte, Preise, die Kategorie „Kosmetik" und die echten Produkt-/Kategorie-URLs** (`/product/…`,
`/product-category/cosmetics/`). Auch die E-Commerce-IDs (`wc_<id>`) entsprechen exakt dem, was ein
echter Browser-Kauf meldet – so zeigt Matomo für synthetischen und realen Traffic **eine konsistente
Shop-Struktur** (Produkte, Kategorien, On-Site-Suche).

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

## Bezahlung im Test-Shop

Im Checkout stehen drei **Test-Zahlungsmethoden** bereit (komplett offline, ohne externe Konten),
mit denen echte Browser-Käufe durchgespielt werden können:

| Methode | Verhalten |
|---|---|
| **Kauf auf Rechnung** | Bestellung geht als „wartet auf Zahlung" (on-hold) durch. |
| **Kreditkarte (Test)** | Akzeptiert **nur** die Testkarte `4242 4242 4242 4242` (beliebiges zukünftiges Ablaufdatum, beliebige CVC). Andere Nummern werden **abgelehnt** – ideal, um Conversion vs. Fehlversuch zu vergleichen. |
| **TWINT (Test)** | Simuliert eine TWINT-Zahlung und wird automatisch bestätigt. |

Jeder erfolgreiche Browser-Kauf wird auf der Bestellbestätigungsseite als **E-Commerce-Conversion**
an Matomo gemeldet. Die Lernenden finden ihre eigenen Bestellungen unter *Matomo → E-Commerce*.

## Wie alles zusammenhängt (Datenfluss)

1. **Eigene Klicks:** Browser → Shop (`:8090`) → Matomo-JS lädt → Matomo (`:8091`) zählt den Besuch.
2. **Eigener Kauf:** Checkout abschließen → Danke-Seite meldet die Bestellung an Matomo (E-Commerce).
3. **Generierter Traffic:** Datengenerierungstool (`:8092`) → Matomo-Tracking-API → Besuche,
   Conversions, Umsatz. Inklusive historischem Backfill für gefüllte Charts.

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

Alles wird zentral über `.env` gesteuert (Kopie von `.env.example`). Wichtigste Variablen:

| Variable | Standard | Bedeutung |
|---|---|---|
| `WORDPRESS_PORT` / `MATOMO_PORT` / `TRAFFIC_PORT` | `8090` / `8091` / `8092` | Host-Ports der drei Dienste |
| `MARIADB_VERSION`, `WORDPRESS_VERSION`, `MATOMO_VERSION`, `WOOCOMMERCE_VERSION` | gepinnt | Image-/Software-Versionen (für reproduzierbare Kurse) |
| `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` / `WP_ADMIN_EMAIL` | `admin` / `admin123` / … | Shop-Admin |
| `MATOMO_ADMIN_USER` / `MATOMO_ADMIN_PASSWORD` / `MATOMO_ADMIN_EMAIL` | `admin` / `matomo123` / … | Matomo-Superuser |
| `SHOP_CURRENCY` / `SHOP_COUNTRY` / `WP_LOCALE` | `EUR` / `DE` / `de_CH` | Shop-Währung, -Land, Sprachpaket |
| `*_DB_*` / `MYSQL_ROOT_PASSWORD` | siehe Datei | Datenbank-Namen, -Benutzer, -Passwörter |
| `TRAFFIC_AUTO_SEED` | `true` | Beim Start automatisch Historie befüllen |
| `TRAFFIC_BACKFILL_DAYS` | `730` | Zeitraum der historischen Befüllung (Tage, ≈ 24 Monate) |
| `TRAFFIC_LIVE_DRIP` | `true` | Live-Tropf beim Start aktiv (in der UI abschaltbar) |
| `TRAFFIC_DRIP_VISITS_PER_HOUR` | `120` | Startwert: Besucher/Stunde des Live-Tropfs |
| `TRAFFIC_CONVERSION_RATE` | `0.04` | Startwert: Anteil Besuche mit Kauf (0–1) |

> **Versionen anpassen:** Alle Versionen sind gepinnt. Vor jedem Semester eine Version testen und
> festschreiben, damit der Kurs über die Zeit reproduzierbar bleibt.
>
> **Sprache:** Der Shop ist durchgängig auf Deutsch lokalisiert. `WP_LOCALE` ist `de_CH` (Schweizer
> Deutsch, identischer Wortschatz, ohne ß). Für reichsdeutsche Schreibweise (mit ß) auf `de_DE`
> stellen und einmal zurücksetzen (`down -v && up -d`). Währung und Standort sind davon unabhängig
> (`SHOP_CURRENCY`, `SHOP_COUNTRY`).

## Zurücksetzen & Reproduzierbarkeit

```bash
# Sauberer Neustart ohne Datenverlust:
docker compose up -d

# Vollständiger Reset (alle Daten gelöscht, alles wird neu eingerichtet):
docker compose down -v && docker compose up -d
```

> **Hinweis Bind-Mount:** `down -v` entfernt die Docker-Volumes (Datenbank, Matomo), **nicht** aber
> die WordPress-Dateien auf dem Host (`./wordpress-html/`). Beim nächsten Start spielt `wp-init` die
> Fixture sauber darüber ein. Für ein komplett jungfräuliches Docroot zusätzlich den Ordner leeren:
> `rm -rf ./wordpress-html/* ./wordpress-html/.htaccess` (vor `up -d`).

Der **Demo-Shop ist reproduzierbar**: Sein vollständiger Stand – Theme, Demo-Produkte mit deutschen
Beschreibungen, **Bewertungen/Sterne**, Blog-Beiträge, Seiten, Bilder sowie alle Einstellungen
(Sprache, Währung EUR, Standort Berlin) – ist als **Fixture** eingefroren (`wordpress/fixture/`:
DB-Dump + Uploads). Beim frischen Start stellt `wp-init` ihn automatisch wieder her – inklusive der
benötigten Plugins/Theme, die in gepinnten Versionen aus dem WordPress-Repository nachinstalliert
werden. Ein `down -v && up -d` liefert also wieder **exakt denselben Shop**.

## Projektstruktur

```
.
├─ docker-compose.yml            # Orchestrierung aller Container
├─ .env.example                  # Vorlage für die Konfiguration
├─ catalog.json                  # Gemeinsamer Produktkatalog (Shop + Traffic-Generator)
│
├─ db/
│  └─ init/01-init-databases.sh  # Legt beide Datenbanken + Benutzer an (Passwörter aus .env)
│
├─ wordpress-html/               # WordPress-Docroot als Bind-Mount (Host) – wird generiert,
│                                #   nicht versioniert; hier von Hand Dateien importieren
├─ wordpress/
│  ├─ wp-init.sh                 # Richtet Shop ein bzw. spielt die Demo-Fixture wieder ein
│  ├─ make-placeholder.php       # Erzeugt Platzhalterbilder (Fallback ohne Internet)
│  ├─ fixture/                   # Eingefrorener Demo-Shop (DB-Dump + Uploads)
│  │  ├─ shop.sql.gz
│  │  └─ uploads.tar.gz
│  └─ mu-plugins/                # Immer aktive WordPress-Plugins (per Volume eingebunden)
│     ├─ matomo-tracking.php     # Baut den Matomo-Tracking-Code ein (inkl. E-Commerce + Suche)
│     ├─ m392-test-payments.php  # Test-Zahlungsmethoden (Rechnung, Kreditkarte, TWINT)
│     ├─ m392-german-shop.php    # Deutsche Übersetzungen/Labels (z. B. „Angebot!", Trust-Badge)
│     └─ m392-shop-filters.php   # Moderne Produktfilter & Sortierung (Preis/Bewertung/Angebote)
│
├─ matomo/
│  └─ matomo-init.sh             # Installiert & konfiguriert Matomo headless, erzeugt API-Token
│
├─ traffic/                      # Datengenerierungstool (Python/Flask)
│  ├─ Dockerfile
│  ├─ app.py                     # Web-Server + Dashboard-API + Live-Tropf
│  ├─ generator.py               # Logik: Besuche/Käufe an die Matomo-Tracking-API senden
│  ├─ requirements.txt
│  └─ templates/index.html       # Dashboard-Oberfläche
│
└─ docs/                         # Interne Design-/Planungsdokumente
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
  Backfill älter als 24 h braucht einen API-Token. `docker compose logs matomo-init` prüfen
  (Token wird dort erzeugt und im Volume `matomo_token` abgelegt).
- **Ports belegt**
  Ports in `.env` ändern (`WORDPRESS_PORT`, `MATOMO_PORT`, `TRAFFIC_PORT`). Hinweis: Der im Shop
  eingebettete Tracking-Code zeigt auf `localhost:8091`; bei geändertem `MATOMO_PORT` die Datei
  `wordpress/mu-plugins/matomo-tracking.php` anpassen.
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
