# Changelog

Notable Änderungen an der M392-Matomo-Lehrumgebung. Neueste zuerst.
Format lose angelehnt an [Keep a Changelog](https://keepachangelog.com/de/).

## [Unreleased] – Stand 2026-06-05

### Hinzugefügt
- **Matomo ↔ WooCommerce gekoppelt (im Richtwert-Modus):** Jede geseedete Bestellung wird zusätzlich
  als Matomo-E-Commerce-Conversion gespiegelt (gleiches Datum/Artikel, **Produktumsatz ohne Versand**).
  Dadurch zeigen Matomo *E-Commerce* „Gesamteinnahmen" und WooCommerce „Bruttoumsatz" **dieselben
  Zahlen**. Versand/Gutscheine/Retouren bleiben bewusst WooCommerce-exklusiv (Lerneffekt). Der
  Richtwert (`TRAFFIC_AVG_MONTHLY_REVENUE`) bezieht sich ebenfalls auf den Produktumsatz ohne Versand. Der Backfill erzeugt
  in diesem Modus keine eigenen Käufe mehr, sondern nur noch nicht-kaufende Besuche – skaliert so, dass
  die Conversion-Rate realistisch bleibt. Besuche und Bestellungen decken denselben Zeitraum ab
  (`TRAFFIC_BACKFILL_DAYS` = `TRAFFIC_SEED_ORDERS_DAYS`, Standard jetzt 100). Hinweis: mehr Besuche ⇒
  längere Installation (Stellschrauben: Fenster, `TRAFFIC_CONVERSION_RATE`, Richtwert).
- **Umsatz-Richtwert für den Bestell-Seed (`TRAFFIC_AVG_MONTHLY_REVENUE`):** Statt einer festen
  Bestellanzahl kann nun ein durchschnittlicher Monatsumsatz (EUR) vorgegeben werden. Der Startseed
  legt so viele Bestellungen an, dass der Monatsumsatz der generierten Bestellungen etwa dem Richtwert
  entspricht. Er **kalibriert** dazu kurz den realen Ø-Bestellwert (der Order-Endpunkt meldet pro Batch
  den Umsatz zurück) und füllt **idempotent** bis zum Zielumsatz auf. Hat Vorrang vor
  `TRAFFIC_SEED_ORDERS`; `0`/leer = klassischer Anzahl-Modus.
- **Kategorienfilter im Shop:** Die Filterleiste hat einen Kategorienfilter, der die echten
  Produktkategorien dynamisch ausliest (passt sich neuen Kategorien automatisch an).
- **Diverse, realistische Verweisquellen:** Mehrere fiktive Verweis-Domains in *Akquise → Websites*,
  davon eine klar dominant (statt Gleichverteilung).
- **Newsletter als Kampagne:** Newsletter-Traffic wird als Matomo-**Kampagne** (`pk_campaign`)
  getrackt und erscheint unter *Akquise → Kampagnen*.
- **Aktivitäts-Chart im Traffic Lab:** relative Zeitachse („jetzt", „−1:00" …) und ein
  **Hover-Tooltip** mit der Anzahl Besucher:innen pro Balken.
- **`install.sh`:** Einrichtungs-Skript mit Fortschrittsanzeige (Spinner + Uhr); wartet bis Historie,
  Bestellungen und Matomo-Archivierung fertig sind. Ersetzt das frühere `reset.sh`.

### Geändert
- **Alte WooCommerce-Berichte funktionieren wieder:** `wp-init.sh` aktiviert den
  **HPOS-Kompatibilitätsmodus** vor dem Bestell-Seed; jede Bestellung wird synchron nach
  `posts`/`postmeta` gespiegelt. HPOS-Analytics **und** Legacy-*Berichte* zeigen dieselben Zahlen.
- **Konsistente Kund:innen-Daten:** Anmelde-/Aktivdatum jeder Kund:in wird auf die Bestellhistorie
  zurückdatiert – „neu vs. wiederkehrend" und die Registrierungs-Zeitreihe sind über ~6 Monate
  plausibel.
- **Zahlart-Namen** ohne „(Test)"-Zusatz (Kauf auf Rechnung, Kreditkarte, TWINT).
- **Checkout** der Test-Kreditkarte sauber ausgerichtet (Kartennummer / Ablauf / CVC).
- **Filterleiste** als aufgeräumtes 2-Spalten-Raster; alle Bedienelemente bündig ausgerichtet.
- **Live-Tropf-Regler** werden ausgegraut, wenn der Tropf pausiert ist.
- **Historien-Dauer** in den `install.sh`-Ausgaben wird dynamisch aus `TRAFFIC_BACKFILL_DAYS`
  berechnet (statt fix „6 Monate").
- **Standard-Konfiguration:** Header-Schriftgrößen, Kontakt-Titel als **h1** und das um „Startseite"
  bereinigte Hauptmenü sind in die Fixture übernommen (überleben `install.sh`).
- `catalog.json` nach `seed/catalog.json` verschoben.

### Entfernt
- TWINT-Telefonnummernfeld im Checkout (nicht benötigt).
- Menüeintrag „Startseite" aus dem Hauptmenü.
- Tote Datei `wordpress/init/make-placeholder.php` und weitere Altlasten.

### Verankerung
- Alle Anpassungen liegen in genau einer von drei dauerhaften Schichten – **(a)** versionierter Code
  (mu-Plugins, `wp-init.sh`, Traffic Lab), **(b)** Fixture `shop.sql.gz`, **(c)** Laufzeit-Seed –
  und überleben damit `install.sh`. Details: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) § 8.

---

## Frühere Entwicklung (Zusammenfassung)

- **Grundgerüst & Stack:** MariaDB + WordPress/WooCommerce + Matomo + Traffic Lab (Flask) in
  Docker Compose; turnkey-Einrichtung über `wp-init` / `matomo-init`.
- **Demo-Shop:** Botiga-Theme, Naturkosmetik-Sortiment mit echten Bildern, deutschen Beschreibungen,
  Kategorien, Blog-Beiträgen, Bewertungen (Ø ~4,6), vollständig auf Deutsch, EUR, Standort Berlin.
- **Reproduzierbarkeit:** kompletter Shop als Fixture (DB-Dump + Uploads) eingefroren.
- **Tracking:** Matomo-Snippet via mu-Plugin (Seitenaufrufe, E-Commerce, On-Site-Suche, Ereignisse,
  Inhalte, Leistung, Geografie DE/CH/AT, Ziele für PDF-Download und Kontaktanfrage).
- **Traffic Lab:** datierter 6-Monats-Backfill + organischer Live-Tropf (Poisson-Schübe), echte
  WooCommerce-Bestellungen + Kund:innen, Bestseller-Gewichtung, Gutschein `NATUR10`,
  Wiederkehrer-Regler, Social Media als stärkster Verkaufskanal.
- **Test-Zahlung:** Kauf auf Rechnung, Test-Kreditkarte `4242…`, simuliertes TWINT.
