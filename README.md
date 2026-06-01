# M392 Matomo Lab

Lehrumgebung für Modul **392 – Nutzer-Daten mittels Analysetools auswerten**.
Ein Befehl startet einen deutschsprachigen WooCommerce-Shop, ein vorkonfiguriertes Matomo und ein
Datengenerierungstool.

## Schnellstart

```bash
cp .env.example .env      # einmalig; Passwörter bei Bedarf anpassen
docker compose up -d
```

> **Wichtig bei Passwort-Änderungen:** Die DB-Benutzer werden beim **ersten** Start angelegt
> (Init-Script auf leerem Daten-Volume). Wenn du Passwörter in `.env` änderst, **bevor** du das
> erste Mal startest, passt alles automatisch. Änderst du sie **nachträglich**, einmal zurücksetzen:
> `docker compose down -v && docker compose up -d`.

Beim ersten Start werden Images gezogen und alles automatisch eingerichtet (kann einige Minuten
dauern). Danach:

| Dienst | URL | Login |
|---|---|---|
| Shop (WordPress/WooCommerce) | http://localhost:8090 | Admin: `/wp-admin`, siehe `.env` |
| Matomo | http://localhost:8091 | siehe `MATOMO_ADMIN_*` in `.env` |
| Datengenerierungstool | http://localhost:8092 | – |

Der Shop nutzt das Theme **Botiga** und zeigt die Produkte mit Produktbildern.

## Was die Lernenden tun

1. In Matomo einloggen und die vorhandenen Besucher-/E-Commerce-Daten auswerten.
2. Durch den Shop klicken (eigene Besuche erscheinen unter *Besucher → in Echtzeit*).
3. Im Datengenerierungstool gezielt Besuche/Käufe erzeugen und in Matomo beobachten.

## Bezahlung (Test)

Im Checkout stehen drei Test-Zahlungsmethoden bereit, mit denen die Lernenden echte
Browser-Käufe durchspielen können:

- **Kauf auf Rechnung** – die Bestellung geht als „wartet auf Zahlung" (on-hold) durch.
- **Kreditkarte (Test)** – akzeptiert nur die Testkarte `4242 4242 4242 4242` (beliebiges
  zukünftiges Ablaufdatum, beliebige CVC). Andere Nummern werden abgelehnt – gut für die
  Analyse von Conversion vs. Fehlversuch.
- **TWINT (Test)** – simuliert eine TWINT-Zahlung und wird automatisch bestätigt.

Echte Browser-Käufe werden auf der Danke-/Bestellbestätigungsseite in Matomo als
E-Commerce-Conversions getrackt. Die Lernenden finden ihre eigenen Bestellungen damit
unter *Matomo → E-Commerce* wieder.

## Datengenerierungstool

Modernes Dashboard auf http://localhost:8092 mit Live-KPIs, Aktivitäts-Chart und Protokoll.

- **Live-Tropf**: standardmäßig aktiv, sendet laufend Besuche. Über Regler steuerbar:
  - **Besucher / Stunde** (`TRAFFIC_DRIP_VISITS_PER_HOUR`, Standard 120)
  - **Conversion-Rate** (`TRAFFIC_CONVERSION_RATE`) – die erwarteten Käufe/Stunde werden live angezeigt.
  - per Schalter pausierbar.
- **Manuell**: Besuche/Käufe sofort erzeugen oder historischen Backfill (Tage) starten.

## Zurücksetzen

```bash
docker compose down -v && docker compose up -d   # vollständiger Reset (alle Daten weg)
```

## Versionen anpassen

Image-Versionen sind in `.env` gepinnt (`MATOMO_VERSION`, `WORDPRESS_VERSION`,
`WOOCOMMERCE_VERSION`, …). Vor jedem Semester eine Version testen und festschreiben.

## Troubleshooting

- **„Error establishing a database connection" im Shop:** Du hast vermutlich Passwörter in `.env`
  nach dem ersten Start geändert. Die DB-Benutzer haben dann noch die alten Passwörter. Lösung:
  `docker compose down -v && docker compose up -d` (legt die DB-Benutzer mit den `.env`-Passwörtern neu an).
- **Matomo zeigt noch den Installer:** `docker compose up matomo-init` erneut ausführen und Logs prüfen.
- **Keine Backfill-Daten (älter als 24 h):** API-Token fehlt — `docker compose logs matomo-init` prüfen;
  ohne Token werden nur aktuelle Besuche akzeptiert.
- **Shop ohne Produkte:** `docker compose up wp-init` erneut ausführen.
- **Ports belegt:** Ports in `.env` ändern (`WORDPRESS_PORT`, `MATOMO_PORT`, `TRAFFIC_PORT`).
  Achtung: Die im Shop ausgelieferte Matomo-URL und die Tracking-URLs nutzen `localhost:8091` —
  bei geändertem `MATOMO_PORT` `wordpress/mu-plugins/matomo-tracking.php` anpassen.

## Fallback Matomo-Installation

Falls der headless Installer bei einer neuen Matomo-Version fehlschlägt, kann ein vorab erzeugter
SQL-Seed (`matomo`-DB-Dump) plus mitgelieferte `config/config.ini.php` eingespielt werden.
Dump erzeugen nach einmaliger manueller Installation:
`docker compose exec db mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" matomo > matomo/seed.sql`.
