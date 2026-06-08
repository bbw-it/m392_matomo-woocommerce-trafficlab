# Spec: Schnell-Install via gebackener, datums-verschobener Fixture

- **Datum:** 2026-06-08
- **Status:** Freigegeben (Design mit Luca abgestimmt) — Umsetzung folgt
- **Ursprung:** [`TODO.md`](../../../TODO.md) (Feature-Plan) · abgestimmter Konsens [`review/06`](../../../review/06%20claude%20konsens-und-plan.md)
- **Nächster Schritt:** Implementierungsplan (writing-plans)

---

## 1. Problem & Ziel

Heute generiert das **Traffic Lab** beim Install ~3 Monate Historie per HTTP-Tracking → **15–30 Min**.
Ziel: Historie **einmal** erzeugen, als **SQL-Dump** einfrieren und beim Install nur noch
**einspielen + alle Datumswerte auf „heute" verschieben** → **~2–3 Min**, **unabhängig** von der
Historienlänge.

Technik: **fester Anker (`BASE`) + Offset-Shift**, kein Sentinel wie `9999-12-31` (bricht
Matomo-Archivierung und die MySQL-DATETIME-Grenze).

## 2. Getroffene Entscheidungen

| # | Entscheidung | Wert |
|---|---|---|
| D1 | Modus | **fixture-only** — kein `MATOMO_SEED_MODE`-Schalter; Install restauriert immer |
| D2 | Bake-Parameter | versionierte **`tools/bake.conf`** |
| D3 | WC-Orders-Dump | **separat** (`matomo/fixture/wc-orders.sql.gz`), nicht in `shop.sql.gz` |
| D4 | Offset-Rundung | **taggenau** als Default, Toggle `OFFSET_ROUNDING=exact\|week` in `bake.conf` |
| D5 | Offset-Berechnung | **im Host** (`install.sh`), an Container übergeben |
| D6 | Historienlänge (Default) | **180 Tage** |
| — | (vorab fix, aus Konsens) | Ports fix auf `127.0.0.1`; Legacy-Modus entfernen |

**Kein LLM:** Die Daten entstehen deterministisch (`traffic/generator.py` mit `random`;
Bestell-Realismus serverseitig in PHP `m392-order-api.php`; realer Katalog `seed/catalog.json`).
Ein LLM widerspräche dem Kernprinzip Reproduzierbarkeit/Offline. Der Generator **bleibt** als
Back-Maschine und „Source of Truth"; der Dump ist ein **abgeleitetes Artefakt**.

## 3. Reihenfolge & Voraussetzung (kritisch)

Das Backen friert den aktuellen Datenstand ein → **erst Fix-Paket 1, dann backen** (sonst frieren
Bugs mit ein). Maßgeblich blockierend:

- **P1.1** — `/api/ready` zählt `error` noch als `done` ([`traffic/app.py:192`](../../../traffic/app.py)).
  Der Bake wartet auf `ready=done`; bei kaputtem `done` würde er unvollständige Daten einfrieren.
- **P1.4** — `generate_orders` bucht keine Besuche (Datenkonsistenz). Genau der vom TODO genannte Grund.
- **P1.5** — Legacy-Pfad in `wp-init.sh` entfällt mit „fixture-only" ohnehin.

**Umsetzungs-Reihenfolge:** Fix-Paket 1 (mind. P1.1 + P1.4) → Tooling bauen → 180-Tage-Fixture
backen → Artefakte committen → Verifikation.

## 4. Artefakte & Repo-Layout (neu)

```
matomo/fixture/
  matomo-history.sql.gz   # Roh-Logs (OHNE matomo_archive_*)
  wc-orders.sql.gz        # WC-Orders + geseedete Kund:innen (OHNE admin)
  BASE                    # Anker-Datum, z. B. 2026-06-08
  BAKE-INFO               # verwendete Bake-Werte + OFFSET_ROUNDING (Doku/Reproduzierbarkeit)
tools/
  bake-fixture.sh         # Back-Werkzeug (einmalig, manuell)
  bake.conf               # versionierte Bake-Parameter
  shift-dates.sh          # Shift-SQL (vom Install im db-Container genutzt)
```

## 5. `tools/bake.conf` + `tools/bake-fixture.sh`

**`bake.conf`** (versioniert, ein Ort für „so wurde die Fixture gebacken"):
```sh
HISTORY_DAYS=180
AVG_MONTHLY_REVENUE=1500
CONVERSION_RATE=0.014
RETURNING_RATE=0.08
OFFSET_ROUNDING=exact        # exact | week  (wird beim Backen in BAKE-INFO/Metadaten geschrieben,
                             #                vom Install beim Shift gelesen)
```

**`bake-fixture.sh`** (Ablauf):
1. Frischen Stack hochfahren; Generator + Order-Seed **mit `bake.conf`-Werten** einmal laufen lassen
   (setzt die historischen Seed-Env-Vars temporär, s. §7).
2. Auf `/api/ready=done` **und** abgeschlossene Archivierung warten (nutzt die in P1.1 reparierte
   Wahrheit).
3. Dumpen:
   - Matomo-Roh-Tabellen (§6) **ohne** `matomo_archive_*` → `matomo/fixture/matomo-history.sql.gz`.
   - WC-Order-Tabellen (§6) + **nur die geseedeten** `wp_users`-Zeilen (ohne `admin`) →
     `matomo/fixture/wc-orders.sql.gz`.
4. `BASE` (= `date +%F` zum Backzeitpunkt) und `BAKE-INFO` (Bake-Werte + `OFFSET_ROUNDING`) schreiben.
5. Mengen-Check loggen (Visits/Orders), damit sichtbar ist, was eingefroren wurde.

## 6. Was geshiftet wird (identischer Offset für ALLES)

> Der Offset muss für Matomo **und** WooCommerce identisch sein, sonst bricht die Kopplung
> „Matomo-E-Commerce-Umsatz == WooCommerce-Bruttoumsatz".

**Matomo (Prefix `matomo_`, DB `matomo`):**
| Tabelle | Zeitspalten |
|---|---|
| `matomo_log_visit` | `visit_first_action_time`, `visit_last_action_time` |
| `matomo_log_link_visit_action` | `server_time` |
| `matomo_log_conversion` | `server_time` |
| `matomo_log_conversion_item` | `server_time` |

**WooCommerce/WordPress (Prefix `wp_`, WP-DB):**
| Tabelle | Zeitspalten | Hinweis |
|---|---|---|
| `wp_wc_orders` (HPOS) | `date_created_gmt`, `date_updated_gmt`, `date_paid_gmt` | HPOS aktiv |
| `wp_wc_order_stats` | `date_created`, `date_paid` | wc-admin Analytics |
| `wp_posts` (`shop_order*`) | `post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt` | Legacy-Spiegel |
| `wp_postmeta` | Datums-Metas (`_paid_date`, `_completed_date`, …) | nach Backen identifizieren |
| `wp_users` | `user_registered` | rückdatierte Kund:innen |

> **Vor dem Festzurren am echten Dump verifizieren** (Schema je Matomo-Version; exakte WC-Tabellenliste
> inkl. HPOS + Legacy-Spiegel; ggf. `wp_wc_customer_lookup` / `wp_wc_order_product_lookup`).
> IDs (`idvisit`, `idlink_va`, …) werden **nicht** angefasst → referenzielle Integrität bleibt.

## 7. `install.sh`-Integration & Shift

- **Restore:** `matomo-history.sql.gz` (in `matomo-init.sh`), `wc-orders.sql.gz` (in `wp-init.sh`,
  nach `shop.sql.gz`).
- **Shift zentral & atomar:** `install.sh` rechnet auf dem **Host**
  `offset = heute − BASE` (taggenau bzw. auf Vielfaches von 7 bei `OFFSET_ROUNDING=week`) und ruft
  **`shift-dates.sh`** im `db`-Container mit **demselben** Offset für **beide** DBs auf
  (`UPDATE … = DATE_ADD(col, INTERVAL :offset DAY)` über die Tabellen aus §6).
- Danach **einmal** `core:archive` ([`install.sh:262`](../../../install.sh)) wie heute.
- **Idempotenz:** `install.sh` macht `docker compose down -v` → jeder Lauf restauriert frisch aus dem
  kanonischen Dump und shiftet **genau einmal**. Kein Doppel-Shift möglich.

## 8. `.env` / `.env.example`-Cleanup

**Entfernen** (nur historischer Seed — verifiziert [`traffic/app.py:294-315`](../../../traffic/app.py)),
Werte leben künftig in `bake.conf`:
`TRAFFIC_AUTO_SEED`, `TRAFFIC_BACKFILL_DAYS`, `TRAFFIC_AVG_MONTHLY_REVENUE`, `TRAFFIC_SEED_ORDERS`,
`TRAFFIC_SEED_ORDERS_DAYS` + der „Startlast/Install-Dauer"-Kommentarblock.

**Bleiben** (treiben den **Live-Tropf** — [`traffic/app.py:56-60`](../../../traffic/app.py)):
`TRAFFIC_LIVE_DRIP`, `TRAFFIC_DRIP_VISITS_PER_HOUR`, `TRAFFIC_CONVERSION_RATE`,
`TRAFFIC_RETURNING_RATE`, `M392_ORDER_API_KEY`, `TRAFFIC_CREATE_WC_ORDERS`, `M392_AB_*`.

**Compose:** Die entfernten Vars aus dem `traffic`-Service nehmen; normaler Betrieb seedet keine
Historie (nur Live-Tropf). Der Bake injiziert die Seed-Vars temporär.

## 9. Trade-offs (ehrlich)

- **Eingebackene Parameter:** Historienlänge/Umsatz/CR sind im Dump fix. Ändern ⇒ **neu backen**
  (`tools/bake-fixture.sh`). Der Generator bleibt Source of Truth.
- **Wartungsartefakt:** Katalog-/Schema-Änderungen ⇒ neu backen.
- **Repo-Größe:** Roh-Logs 180 Tage = einige MB–zig MB gzip (Vergleich: `uploads.tar.gz` ~15 MB).
  Akzeptabel.
- **Vorteil:** Install nahezu konstant schnell unabhängig von der Historienlänge.

## 10. Verifikation (Done-Kriterien)

1. Frischer `install.sh` < ~5 Min bis „vorbefüllt UND archiviert".
2. Matomo-Berichte: `heute−180d … heute`, nichts in der Zukunft, keine Lücke > Offset-Rundung.
3. **Kopplung intakt:** Matomo-E-Commerce-„Gesamteinnahmen" == WooCommerce-Bruttoumsatz (Produktumsatz
   ohne Versand) für denselben Zeitraum.
4. „Neu vs. wiederkehrend" + `user_registered`-Zeitreihe plausibel (mitgeshiftet).
5. Zweiter `install.sh`-Lauf ⇒ identisches Ergebnis (kein Doppel-Shift, da `down -v` + frischer Restore).

## 11. Am echten Dump zu verifizierende Implementierungsdetails (für die Planung)

- Exakte WC-Tabellenliste + Datums-Spalten (HPOS + Legacy-Spiegel) am realen Dump bestätigen.
- `wp_postmeta`-Datums-Metas konkret identifizieren.
- `wp_users`-Dump so filtern, dass nur geseedete Kund:innen enthalten sind (kein `admin`-Clobber).
- Matomo-Zeitspalten je `MATOMO_VERSION` (5.3.0) per `SHOW COLUMNS … LIKE '%time%'` gegenchecken.
- `OFFSET_ROUNDING`-Wert vom Bake in Metadaten schreiben, vom Install lesen.
