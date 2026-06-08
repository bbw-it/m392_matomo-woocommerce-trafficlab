# Changelog

Notable Änderungen an der M392-Matomo-Lehrumgebung. Neueste zuerst.
Format lose angelehnt an [Keep a Changelog](https://keepachangelog.com/de/).

## [Unreleased] – Stand 2026-06-08

### Geändert
- **Install ist jetzt fixture-only (180-Tage-Fixture):** Die Historie (Matomo-Logs + WC-Bestellungen,
  180 Tage ≈ 6 Monate) wird **gebacken** (`tools/bake-fixture.sh` → `matomo/fixture/*.sql.gz`) und beim
  Install nur noch **restauriert**, per `tools/shift-dates.sh` auf „heute" verschoben und **einmal
  archiviert** (~2–3 Min statt 15–30). Kein Live-Generieren mehr beim Install. Bake-Parameter (Historie,
  Umsatz, CR, Returning) liegen in `tools/bake.conf`; gebackene Mengen: 14921 Matomo-Besuche, 250
  E-Commerce-Conversions, 288 WC-Bestellungen, 7595 EUR netto (`matomo/fixture/BAKE-INFO`).
- **`install.sh` – animierte Fortschrittsanzeige:** Spinner + mitlaufende Uhr für alle langen/stummen
  Schritte (Warten, Fixture-DB-Import, `core:archive`), einheitliche Schritt-Header **[1/5]…[5/5]**,
  Fehlerausgabe nur im Fehlerfall; TTY-Erkennung (Punkte-Fallback bei `-y`/Pipe).

### Entfernt
- **`docs/PRODUKTE-WORKFLOW.md`** entfernt; Repo aufgeräumt – nur `README.md` im Root, alle weiteren
  MD unter `docs/`.
- **Install-Seed-Stellschrauben aus `.env.example`** entfernt (`TRAFFIC_AUTO_SEED`,
  `TRAFFIC_BACKFILL_DAYS`, `TRAFFIC_SEED_ORDERS`, `TRAFFIC_SEED_ORDERS_DAYS`,
  `TRAFFIC_AVG_MONTHLY_REVENUE`); Fixture-Parameter leben jetzt in `tools/bake.conf`. `.env.example`
  steuert nur noch Live-Tropf + A/B-Test.

### Hinzugefügt
- **Matomo-Report-Plugins „A/B Tests" + „Funnels" (nachgebaut, kostenfrei):** Zwei native Matomo-5-
  Plugins (`matomo/M392ABTesting`, `matomo/M392Funnels`) als kostenfreier Ersatz für die bezahlten
  Originale. Sie sind per Bind-Mount eingebunden und werden in `install.sh` **automatisch aktiviert**
  (`console plugin:activate` – schreibt die vollständige Plugin-Liste inkl. Login, statt einer
  config.ini-`[Plugins]`-Sektion, die Matomo lahmlegen würde). Die Report-Seiten hängen via
  **Category + Subcategory + Widget** unter den **bestehenden Menüpunkten** „A/B Tests" bzw. „Funnels"
  (mit deren Icons) und erscheinen in der Pluginverwaltung als Dritt-Anbieter.
- **A/B-Test-Plugin:** mehrere Tests verwaltbar; **Test inline anlegen** im selben Fenster (Demo-Look:
  Feld links, Hilfe rechts). Auswertung **kumuliert über die gesamte Laufzeit seit Teststart** (nicht je
  Monat → Gewinner/P-Wert stabil) + Monats-Verlaufskurve als Kontext. **Bayes (Beta-Binomial,
  Prior Beta(1,1)):** P(besser als Original) sofort als Normal-Näherung, Button „exakt" rechnet
  Monte-Carlo (100 000 Stichproben) inkl. Steigerung + 95 %-Intervall. Datenquelle je Variante =
  Matomo-Segment (Custom Dimension „AB-Variante" bzw. Seiten-URL).
- **Funnel-Plugin:** Conversion-Trichter als **Sankey-Diagramm** (SVG) – „weiter"- und „Abbruch"-Bänder –
  mit konkreter **WordPress-Seiten-Zuordnung** je Schritt (Produkt `/product/` → Warenkorb `/cart/` →
  Kasse `/checkout/` → Bestätigung `/checkout/order-received/`).
- **Traffic-Lab-Dashboard neu (Master-Design, responsive):** Spektrum-Farb-Slider (Besucher/Stunde,
  Conversion-Rate, Wiederkehrende Kunden) + abgeleitete „Erwartete Käufe"-Anzeige; **mehrseriges
  Aktivitätsdiagramm** (Besuche · Käufe · Wiederkehrende) mit eigener Skala für Käufe/Wiederkehrende,
  damit sie neben Besuchs-Spikes sichtbar bleiben. Order-API liefert dafür die Anzahl wiederkehrender
  Käufe (`returning`) zurück.
- **Matomo ↔ WooCommerce gekoppelt (im Richtwert-Modus):** Jede geseedete Bestellung wird zusätzlich
  als Matomo-E-Commerce-Conversion gespiegelt (gleiches Datum/Artikel, **Produktumsatz ohne Versand**).
  Dadurch zeigen Matomo *E-Commerce* „Gesamteinnahmen" und WooCommerce „Bruttoumsatz" **dieselben
  Zahlen**. Versand/Gutscheine/Retouren bleiben bewusst WooCommerce-exklusiv (Lerneffekt). Der
  Richtwert (`TRAFFIC_AVG_MONTHLY_REVENUE`) bezieht sich ebenfalls auf den Produktumsatz ohne Versand. Der Backfill erzeugt
  in diesem Modus keine eigenen Käufe mehr, sondern nur noch nicht-kaufende Besuche – skaliert so, dass
  die Conversion-Rate realistisch bleibt. Besuche und Bestellungen decken denselben Zeitraum ab.
  **Hinweis (aktueller Stand):** Diese Kopplung passiert jetzt **beim Backen** (`tools/bake-fixture.sh`),
  nicht mehr zur Install-Zeit; die Parameter stehen in `tools/bake.conf` (`HISTORY_DAYS=180`,
  `AVG_MONTHLY_REVENUE`, `CONVERSION_RATE`, `RETURNING_RATE`), nicht mehr in `.env`.
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
- **`install.sh`:** Einrichtungs-Skript, das den Reset → Fixture-Restore → Datums-Shift →
  Archivierung kapselt und erst zurückkehrt, wenn die Berichte stimmen. Ersetzt das frühere `reset.sh`.
  (Die animierte Fortschrittsanzeige Spinner + Uhr / Schritte [1/5]…[5/5] siehe „Geändert" oben.)

### Geändert
- ~~**Geringere Startlast:** Standard-Seed-Fenster 180 → 90 Tage~~ – **überholt** durch die
  Fixture-only-Migration (siehe oben): Die Historie ist wieder **180 Tage**, kommt aber nicht mehr aus
  einem Install-Seed, sondern aus der gebackenen Fixture. Die kurze Installation (~2–3 Min) entsteht
  jetzt durch Restore + Datums-Shift statt durch ein verkleinertes Seed-Fenster. Seed-Fenster-/
  Last-Profil-Stellschrauben in `.env.example` sind entfernt.
- **Alte WooCommerce-Berichte funktionieren wieder:** `wp-init.sh` aktiviert den
  **HPOS-Kompatibilitätsmodus** vor dem Bestell-Seed; jede Bestellung wird synchron nach
  `posts`/`postmeta` gespiegelt. HPOS-Analytics **und** Legacy-*Berichte* zeigen dieselben Zahlen.
- **Konsistente Kund:innen-Daten:** Anmelde-/Aktivdatum jeder Kund:in wird auf die Bestellhistorie
  zurückdatiert – „neu vs. wiederkehrend" und die Registrierungs-Zeitreihe sind über das Seed-Fenster
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
  und überleben damit `install.sh`. Details: [`ARCHITECTURE.md`](ARCHITECTURE.md) § 8.

---

## Frühere Entwicklung (Zusammenfassung)

- **Grundgerüst & Stack:** MariaDB + WordPress/WooCommerce + Matomo + Traffic Lab (Flask) in
  Docker Compose; turnkey-Einrichtung über `wp-init` / `matomo-init`.
- **Demo-Shop:** Botiga-Theme, Naturkosmetik-Sortiment mit echten Bildern, deutschen Beschreibungen,
  Kategorien, Blog-Beiträgen, Bewertungen (Ø ~4,6), vollständig auf Deutsch, EUR, Standort Berlin.
- **Reproduzierbarkeit:** kompletter Shop als Fixture (DB-Dump + Uploads) eingefroren.
- **Tracking:** Matomo-Snippet via mu-Plugin (Seitenaufrufe, E-Commerce, On-Site-Suche, Ereignisse,
  Inhalte, Leistung, Geografie DE/CH/AT, Ziele für PDF-Download und Kontaktanfrage).
- **Traffic Lab:** datierter Backfill (gebackene Historie ~6 Monate) + organischer Live-Tropf (Poisson-Schübe), echte
  WooCommerce-Bestellungen + Kund:innen, Bestseller-Gewichtung, Gutschein `NATUR10`,
  Wiederkehrer-Regler, Social Media als stärkster Verkaufskanal.
- **Test-Zahlung:** Kauf auf Rechnung, Test-Kreditkarte `4242…`, simuliertes TWINT.
