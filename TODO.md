# TODO — Schnell-Install via vorgebackener, datums-verschobener Matomo-Fixture

> **Zweck dieser Datei:** Plan für ein noch **nicht umgesetztes** Feature, damit eine frische
> Session es eigenständig bauen kann. Kein Code ist dafür bisher geändert. Lies zuerst
> [`HANDOFF.md`](HANDOFF.md) (Projekt-/Stack-Überblick, Konventionen, Gotchas) und
> [`review/06 claude konsens-und-plan.md`](review/06%20claude%20konsens-und-plan.md) (Fix-Paket 1).

---

## 1. Problem & Idee (in einem Satz)

Heute generiert das **Traffic Lab** beim Install ~3 Monate Historie per HTTP-Tracking → **~15–30 Min**.
Stattdessen: Historie **einmal** erzeugen, als **SQL-Dump** einfrieren und beim Install nur noch
**einspielen + alle Datumswerte auf „heute" verschieben** → **~2–3 Min**.

Die Idee stammt von Luca: „Daten mit einem speziellen Anker-Datum ablegen und das Init-Skript
aktualisiert dieses Datum." Technisch sauber heißt das: **fester Anker + Offset-Shift**, nicht ein
Sentinel wie `9999-12-31` (das bricht Matomo-Archivierung und die MySQL-DATETIME-Grenze).

---

## 2. Kernmechanik

1. **Backen (einmalig, manuell):** Frischen Seed wie heute fahren, dann die relevanten **Roh-Tabellen**
   (Matomo-Logs + WooCommerce-Bestellungen) dumpen. Den Generierungstag als **`BASE`-Anker** mit-speichern
   (z. B. in einer Markerdatei `fixture/matomo/BASE` mit Inhalt `2026-06-08`).
2. **Restore beim Install:** Dump einspielen.
3. **Shiften:** `offset_days = heute − BASE` berechnen und **alle Zeitstempel** in den betroffenen
   Tabellen um `offset_days` per `UPDATE … = DATE_ADD(col, INTERVAL :offset DAY)` verschieben.
4. **Neu archivieren:** Archive werden **nicht** mitgeliefert (sie sind nach Monat benannt + vor-aggregiert,
   ein Shift macht sie ungültig). Nach dem Shift **einmal** `console core:archive` laufen lassen.

**Idempotenz:** `install.sh` macht ohnehin `docker compose down -v` → bei jedem Lauf wird **frisch aus dem
kanonischen Dump** restauriert und **genau einmal** geshiftet. Kein Doppel-Shift möglich. Niemals einen
bereits geshifteten Live-Stand erneut shiften.

---

## 3. Was genau verschoben werden muss (gleicher `offset` für ALLES)

> Der Offset muss für Matomo **und** WooCommerce **identisch** sein, sonst bricht die Kopplung
> „Matomo E-Commerce-Umsatz == WooCommerce-Bruttoumsatz" (siehe CHANGELOG, Abschnitt Kopplung).

### Matomo (Prefix `matomo_`, DB `matomo`)
| Tabelle | Zeitspalten |
|---|---|
| `matomo_log_visit` | `visit_first_action_time`, `visit_last_action_time` |
| `matomo_log_link_visit_action` | `server_time` |
| `matomo_log_conversion` | `server_time` |
| `matomo_log_conversion_item` | `server_time` |

> **Vor dem Backen prüfen** (Schema kann je Matomo-Version minimal abweichen):
> `SHOW COLUMNS FROM matomo_log_visit LIKE '%time%';` etc. Es gibt KEINE Datums-IDs zu shiften —
> `idvisit`/`idlink_va` bleiben unangetastet, also bleibt die referenzielle Integrität erhalten.

### WooCommerce / WordPress (Prefix `wp_`, DB der WP-Instanz)
| Tabelle | Zeitspalten | Hinweis |
|---|---|---|
| `wp_wc_orders` (HPOS) | `date_created_gmt`, `date_updated_gmt`, `date_paid_gmt` | HPOS ist aktiv |
| `wp_wc_order_stats` | `date_created`, `date_paid` | wc-admin Analytics |
| `wp_posts` (post_type `shop_order` / `shop_order_placehold`) | `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt` | Legacy-Spiegel |
| `wp_postmeta` | Datums-Metas (`_paid_date`, `_completed_date`, …) | nach Backen identifizieren |
| `wp_users` | `user_registered` | rückdatierte Kund:innen (neu vs. wiederkehrend) |

> **Achtung:** Die exakte WC-Tabellenliste **einmal am echten Dump verifizieren** (HPOS + Legacy-Spiegel),
> bevor das Shift-SQL festgezurrt wird. `wp_wc_customer_lookup` / `wp_wc_order_product_lookup` ggf. prüfen.

---

## 4. Umsetzungsschritte

### Schritt A — `tools/bake-fixture.sh` (neu)
- Nimmt einen **frisch geseedeten** Stack an (oder fährt ihn selbst hoch und wartet auf `/api/ready=done`
  + Archivierung — wie `install.sh`).
- Dumpt die Matomo-Roh-Tabellen (Abschnitt 3) **ohne** `matomo_archive_*` nach
  `matomo/fixture/matomo-history.sql.gz` (neuer Ordner `matomo/fixture/`).
- Dumpt die WC-Order-Tabellen nach `matomo/fixture/wc-orders.sql.gz` **oder** integriert sie in die
  bestehende `wordpress/init/fixture/shop.sql.gz` (Designentscheidung — siehe offene Fragen).
- Schreibt den Anker nach `matomo/fixture/BASE` (Inhalt: `date +%F` zum Backzeitpunkt).
- Mengen-Check loggen (Visits/Orders), damit man sieht, was eingefroren wurde.

### Schritt B — Shift-Logik
- Neues Skript `matomo/fixture/shift-dates.sh` (oder Funktion in `matomo-init.sh`):
  - `offset=$(( ( $(date +%s) - $(date -d "$(cat BASE)" +%s) ) / 86400 ))`
    (POSIX-tauglich halten; im Container ggf. `busybox date` → Offset im Host vorab berechnen und reingeben).
  - Führt die `UPDATE … DATE_ADD(…, INTERVAL $offset DAY)`-Statements für **alle** Tabellen aus Abschnitt 3 aus.
  - **Optional/kosmetisch:** Offset auf Vielfaches von 7 runden, um Wochentags-Rhythmus zu erhalten
    (lässt bis zu 6 Tage Lücke am „heute"-Ende — Trade-off dokumentieren).

### Schritt C — `MATOMO_SEED_MODE`-Schalter
- Neue `.env`-Variable `MATOMO_SEED_MODE=fixture|generate` (Default: `fixture`).
- In `install.sh` / `matomo-init.sh` verzweigen:
  - **`fixture`:** Matomo-Roh-Dump restore → `shift-dates.sh` → `core:archive` → fertig (~2–3 Min).
    WC-Orders analog restore + shiften (oder schon in `shop.sql.gz` enthalten → nur shiften).
  - **`generate`:** heutiger Weg (Traffic-Lab-Backfill + Order-Seed). Bleibt für Parameter-Experimente.
- `.env.example` dokumentieren: `fixture` = schnell/fix, `generate` = anpassbar (Umsatz/CR/Tage).

### Schritt D — Hybrid behalten
- Der **Live-Tropf** des Traffic Lab bleibt in **beiden** Modi aktiv (liefert die „heute/live"-Schicht).
- Im `fixture`-Modus ist der **historische Backfill** abgeschaltet (der kommt aus dem Dump), der
  **Order-Seed** ebenfalls — beides steckt im Dump.

---

## 5. Trade-offs (ehrlich dokumentieren)

- **Eingebackene Parameter:** `TRAFFIC_AVG_MONTHLY_REVENUE`, `TRAFFIC_CONVERSION_RATE`,
  `TRAFFIC_*_DAYS` sind im Dump **fix**. Ändern ⇒ **neu backen** (`tools/bake-fixture.sh`).
  → Der **Generator bleibt Source of Truth**, der Dump ist ein **abgeleitetes Artefakt**.
- **Wartungsartefakt:** Bei Katalog-/Schema-Änderungen muss neu gebacken werden.
- **Repo-Größe:** Roh-Logs für 3–6 Monate = einige MB–zig MB gzip (zum Vergleich: `uploads.tar.gz` ~15 MB).
  Akzeptabel; bei 6–12 Monaten beobachten.
- **Vorteil dafür:** Install nahezu konstant schnell **unabhängig von der Historienlänge** → man kann
  sogar **mehr** Historie (6–12 Monate) günstig ausliefern.

---

## 6. Verifikation (Done-Kriterien)

1. Frischer `install.sh` im `fixture`-Modus < ~5 Min bis „vorbefüllt UND archiviert".
2. In Matomo: Berichte zeigen Daten von `heute − Historienlänge` bis `heute` (nichts in der Zukunft,
   keine Lücke > Offset-Rundung).
3. **Kopplung intakt:** Matomo *E-Commerce* „Gesamteinnahmen" == WooCommerce „Bruttoumsatz" (Produktumsatz
   ohne Versand) für denselben Zeitraum — wie im `generate`-Modus.
4. Kund:innen „neu vs. wiederkehrend" + Registrierungs-Zeitreihe plausibel (user_registered mitgeshiftet).
5. Zweiter `install.sh`-Lauf ⇒ identisches Ergebnis (kein Doppel-Shift, da `down -v` + frischer Restore).
6. `generate`-Modus funktioniert unverändert weiter.

---

## 7. Reihenfolge / Einordnung

- **Orthogonal** zum Konsens/Fix-Paket 1 (reine Speed-/Reproduzierbarkeits-Verbesserung, kein Korrektheits-Fix).
- **Erst Fix-Paket 1 umsetzen, DANN backen** — sonst friert man eine Fixture auf noch-nicht-bereinigtem
  Stand ein (z. B. `generate_orders` ohne Besuche, `/api/ready` error≠done). Siehe `review/06`.
- Reihenfolge gesamt: **Fix-Paket 1 → (Fix-Paket 2) → dieses Fixture-Feature backen**.

---

## 8. Offene Fragen / Designentscheidungen (vor dem Bauen klären)

- [ ] WC-Orders **separat** (`matomo/fixture/wc-orders.sql.gz`) oder **in `shop.sql.gz` integriert**?
      (Integriert = ein Restore-Pfad, aber `shop.sql.gz` wird dann größer und enthält Demo-Bestellungen.)
- [ ] Offset **taggenau** oder **7-Tage-gerundet** (Wochentags-Realismus vs. Lücke am Ende)?
- [ ] Historienlänge der gebackenen Fixture: bei 90 Tagen bleiben oder gleich 180 backen (Install bleibt
      schnell, mehr Lerndaten)?
- [ ] Offset im Host berechnen (zuverlässiges `date -d`) und an den Container übergeben, oder im Container?

---

## 9. Referenz-Dateien (Ist-Zustand)

| Datei | Rolle für dieses Feature |
|---|---|
| `matomo/matomo-init.sh` | Matomo-Provisionierung; hier (oder in `install.sh`) den Restore+Shift einhängen |
| `install.sh` | orchestriert Stack-Start, Plugin-Aktivierung, Seed-Warten, `core:archive` (Z. ~262) |
| `docker-compose.yml` | Services `db`/`matomo`/`traffic`; `TRAFFIC_BACKFILL_DAYS`/`TRAFFIC_SEED_ORDERS_DAYS` (`:-90`) |
| `traffic/{app.py,generator.py,orders.py}` | aktueller `generate`-Pfad (Backfill + Order-Seed + Live-Tropf) |
| `wordpress/init/fixture/shop.sql.gz` | bestehende WP/WC-Fixture (Restore-Vorbild) |
| `wordpress/init/wp-init.sh` | WP/WC-Provisionierung + Order-Seed-Aufruf |
| `seed/catalog.json` | Produktkatalog (für Backen relevant) |
| `.env` / `.env.example` | hier `MATOMO_SEED_MODE` ergänzen + dokumentieren |

---

_Stand: 2026-06-08 · noch nicht umgesetzt · Plan abgestimmt mit Luca._
