# M392 Matomo Lab

Lehrumgebung für Modul **392 – Nutzer-Daten mittels Analysetools auswerten**.
Ein Befehl startet einen deutschsprachigen WooCommerce-Shop, ein vorkonfiguriertes Matomo und ein
Datengenerierungstool.

## Schnellstart

```bash
cp .env.example .env      # einmalig; Passwörter bei Bedarf anpassen
docker compose up -d
```

Beim ersten Start werden Images gezogen und alles automatisch eingerichtet (kann einige Minuten
dauern). Danach:

| Dienst | URL | Login |
|---|---|---|
| Shop (WordPress/WooCommerce) | http://localhost:8090 | Admin: `/wp-admin`, siehe `.env` |
| Matomo | http://localhost:8091 | siehe `MATOMO_ADMIN_*` in `.env` |
| Datengenerierungstool | http://localhost:8092 | – |

## Was die Lernenden tun

1. In Matomo einloggen und die vorhandenen Besucher-/E-Commerce-Daten auswerten.
2. Durch den Shop klicken (eigene Besuche erscheinen unter *Besucher → in Echtzeit*).
3. Im Datengenerierungstool gezielt Besuche/Käufe erzeugen und in Matomo beobachten.

## Datengenerierungstool

- **Live-Tropf**: standardmäßig aktiv, sendet laufend wenige Besuche/Minute. In der UI pausierbar.
- **Manuell**: Besuche/Käufe erzeugen, Conversion-Rate setzen, historischen Backfill starten.

## Zurücksetzen

```bash
docker compose down -v && docker compose up -d   # vollständiger Reset (alle Daten weg)
```

## Versionen anpassen

Image-Versionen sind in `.env` gepinnt (`MATOMO_VERSION`, `WORDPRESS_VERSION`,
`WOOCOMMERCE_VERSION`, …). Vor jedem Semester eine Version testen und festschreiben.

## Troubleshooting

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
