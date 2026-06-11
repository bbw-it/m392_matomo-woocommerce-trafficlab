# Changelog

Notable Änderungen an der M392-Matomo-Lehrumgebung. Neueste zuerst.
Format lose angelehnt an [Keep a Changelog](https://keepachangelog.com/de/).

## [Unreleased] – Stand 2026-06-11

### Behoben (Traffic Lab ↔ WooCommerce)
- **„Wiederkehrende Kunden" funktionieren jetzt wirklich:** Der Bestandskunden-Pool der Order-API
  wird rollenunabhängig über die Bestellhistorie (`wp_wc_orders.customer_id`) ermittelt statt per
  `get_users(role=customer)`. Der Fixture-Restore bringt `wp_users` ohne `usermeta` (= ohne Rolle)
  mit – vorher bestand der Pool nur aus wenigen live angelegten Kund:innen, und eine einzige Kund:in
  bekam fast alle Folgebestellungen. Name/Adresse von Fixture-Kund:innen kommen als Fallback aus
  `wc_customer_lookup`; `bake-fixture.sh` dumpt künftig die `wp_usermeta` der Kund:innen mit.
- **Wiederkehrer streuen über viele Kund:innen (gewichtete Auswahl):** Beim Ziehen einer
  Bestandskund:in werden Kund:innen mit **wenigen** bisherigen Bestellungen bevorzugt (Lose im Topf
  ~ 1/Bestellanzahl: 1 Bestellung → 6 Lose … ab 6 → 1 Los). Damit verteilen sich Folgebestellungen
  realistisch auf den ganzen Stamm statt sich auf einzelne „Vielbesteller" zu konzentrieren – und
  eine bereits bestehende Konzentration verdünnt sich mit der Zeit von selbst. Verifiziert: 60
  Wiederkehrer-Bestellungen → 60 verschiedene Kund:innen.
- **Matomo- und WooCommerce-Umsatz sind im Live-Betrieb identisch (Defer-Flow):** Bisher trackte der
  Live-Tropf bei einem Kauf einen zufälligen Warenkorb nach Matomo und legte unabhängig davon eine
  WC-Bestellung mit anderem Warenkorb an (Drift, z. B. €2338 vs. €893 an einem Tag). Jetzt wird die
  WC-Bestellung mit **exakt dem Warenkorb des getrackten Besuchs** angelegt (`carts`-Parameter der
  Order-API) und die Matomo-Conversion danach – im selben Besuch – mit dem echten Produktumsatz
  gesendet. Umsatz-Konvention wie bei der Fixture: Produktumsatz ohne Versand. Live verifiziert:
  15 Käufe → beide Systeme exakt EUR 698.00.
- **ID-Kollision zwischen Live-Bestellungen und Fixture entschärft:** Die Fixture bringt
  `wp_wc_orders` mit hohen IDs mit, dumpt aber bewusst keine shop_order-Platzhalter-Posts. HPOS
  zieht die ID neuer Bestellungen aus der `wp_posts`-Sequenz – die wäre irgendwann in die
  Fixture-IDs gelaufen (Duplicate Key, Bestellungen schlagen still fehl). `install.sh` hebt die
  Sequenz nach dem Restore jetzt hinter die höchste Bestell-ID; das Traffic Lab loggt
  unvollständige Bestell-Batches statt sie zu verschlucken.

### Hinzugefügt (Traffic Lab)
- **Produkte-Tab – Beliebtheit steuern:** Produktliste live aus WooCommerce (inkl. bisheriger
  Verkäufe), pro Produkt ein Gewicht 0–100 (Regler + Schnellwahl Bestseller/Normal/Ladenhüter).
  Wirkt auf Produktansichten UND Warenkörbe, also auf Matomo-Traffic und echte Bestellungen.
  Persistiert als WP-Option `m392_product_weights` (neue Endpunkte `GET/POST m392/v1/weights`,
  Schreibzugriff mit API-Key); überlebt Container-Neustarts, `./install.sh` setzt zurück.
- **Protokoll-Tab:** Das Aktivitätslog liegt in einem eigenen Tab (mit Eintrags-Badge), statt das
  Dashboard zu verlängern.
- **Sync-Button im Produkte-Tab + Verkaufszahlen live:** `/api/products` liest Produkte und
  „verkauft bisher" jetzt immer frisch aus WooCommerce (kein 5-Minuten-Cache mehr für die Anzeige).
  Der Button „Synchronisieren" erneuert zusätzlich den Produkt-Cache des Generators und
  protokolliert den Abgleich – **neue, im WP-Backend angelegte Produkte erscheinen sofort** im Tab
  (mit Median-Default-Gewicht) und im erzeugten Traffic. End-to-end verifiziert (Produkt angelegt →
  nach Sync sichtbar → entfernt → nach Sync weg).
- **Nunito lokal gebündelt:** Die Skydash-Schrift „Nunito" (Variable Font, SIL OFL, ~270 KB) liegt
  unter `traffic/static/fonts/` und wird vom Container offline ausgeliefert – kein CDN. Fallback
  bleiben System-Fonts. Dazu grosszügigere Abstände (Header, Karten, Listen).

### Geändert (Traffic Lab)
- **UI verfeinert („Papier & Tinte"):** Tab-Navigation, reife Farbpalette (warme Neutraltöne,
  Tinten-Navy als Akzent, gedeckte Datenfarben Stahlblau/Salbeigrün/Bernstein), ruhige
  Akzent-Fill-Slider statt Regenbogen-Gradient, Buttons in Tinte, tabellarische Ziffern,
  Fokus-Stile und `prefers-reduced-motion`. Weiterhin offline-tauglich (nur System-Fonts).
- KPI „Umsatz heute" weist jetzt konsequent den **Produktumsatz ohne Versand** aus (gleiche
  Konvention wie Fixture und WooCommerce-„Bruttoumsatz").

### Geändert
- **Matomo-Tracking-URL folgt jetzt `.env`:** Das mu-plugin `matomo-tracking.php` liest den
  Matomo-Host-Port aus `MATOMO_PORT` (via Compose als `M392_MATOMO_PORT` in den WordPress-Container
  gereicht) statt fest `8091`. Port in `.env` ändern + `docker compose up -d` genügt; kein
  Datei-Editieren mehr nötig.
- **`tools/bake-fixture.sh` fragt vor dem destruktiven Neuaufbau nach** (Bestätigung `bake`,
  überspringbar mit `-y`/`--yes`), analog `install.sh` – schützt vor versehentlichem `down -v`.
- **Shop-Filter robuster gegen Theme-Markup:** Die Produkt-Zuordnung läuft über einen serverseitigen
  Marker (`.m392-pid` mit `data-product-id`) statt über das Parsen der `post-<ID>`-CSS-Klasse
  (bleibt als Fallback erhalten).
- **`matomo-init.sh`:** Token-Erzeugung entflochten (curl-Fehler bricht jetzt hart mit klarer
  Meldung ab, statt still mit leerem Token weiterzulaufen).
- **Backfill zählt übersprungene Treffer:** Transiente Netzwerkfehler beim Backfill werden weiterhin
  übersprungen, aber gezählt (`skipped`) und im Traffic-Lab-Log ausgewiesen – systematische
  Probleme (Matomo down, Token fehlt) bleiben nicht mehr unsichtbar.
- **Order-API: Gutschein-Fehlschläge sichtbar:** Schlägt `apply_coupon()` fehl (abgelaufen,
  Nutzungslimit), wird das als Bestellnotiz + PHP-Log festgehalten statt still ignoriert.
- **`tools/shift-dates.sh` warnt bei unplausiblem Offset** (> ±365 Tage → Hinweis auf
  falsches/veraltetes BASE-Datum).
- **`install.sh`:** Spinner-Temp-Logs werden auch bei Abbruch (Ctrl+C) aufgeräumt (EXIT-Trap).

### Hinzugefügt
- **Healthchecks** für `wordpress`, `matomo` und `traffic` in `docker-compose.yml` – `docker compose
  ps` zeigt jetzt auch ohne `install.sh` an, ob die Dienste wirklich antworten.
- **`.editorconfig`** für einheitliche Einrückung/Zeilenenden im Repo.

## [1.0.0] – 2026-06-08

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
