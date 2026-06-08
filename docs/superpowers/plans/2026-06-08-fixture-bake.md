# Fixture-Bake — Implementation Plan (Plan C)

> **Quelle:** [`docs/superpowers/specs/2026-06-08-fixture-bake-design.md`](../specs/2026-06-08-fixture-bake-design.md) (Spec, Shift-Oberfläche am echten Schema verifiziert).
> **Voraussetzung:** Fix-Paket 1 + 2 sind umgesetzt & gemergt (erledigt). Verifikation per Ausführen (HANDOFF §8), kein Pytest.

**Goal:** Schnell-Install via vorgebackener, datums-verschobener Fixture (fixture-only): Historie + WC-Bestellungen einmal backen, beim Install restaurieren + um `heute−BASE` shiften + neu archivieren → ~2–3 Min statt 15–30.

**Architecture:** Neue `tools/` (bake.conf, bake-fixture.sh, shift-dates.sh) + neue `matomo/fixture/`-Artefakte. Install restauriert immer (kein Generate-Pfad mehr); Offset wird im Host berechnet und an `shift-dates.sh` im db-Container übergeben. Generator bleibt als Back-Maschine.

**Risiken (verifikations-gated):** (R1) Matomo-FK-Konsistenz idsite/idgoal/idaction zwischen gebackenen Logs und frischem Install. (R2) Teil-Dump geteilter WP-Tabellen (wp_posts/postmeta/users) → User-ID-Kollision/Orphans. (R3) gemischte postmeta-Typen. Jede über ein Gate geprüft.

---

## Reihenfolge der Tasks
B1 Tools (bake.conf, shift-dates.sh) → B2 bake-fixture.sh → **B3 Bake fahren (destruktiv, committet Dumps)** → B4 Install-Integration (restore+shift, fixture-only) → B5 .env-Cleanup → B6 E2E-Verifikation (Kopplung nach Shift) → B7 Holistik-Review.

---

## Task B1: `tools/bake.conf` + `tools/shift-dates.sh`

**Files:** Create `tools/bake.conf`, `tools/shift-dates.sh`.

- [ ] **`tools/bake.conf`** (versionierte Bake-Parameter):
```sh
# Parameter, mit denen die ausgelieferte Fixture gebacken wurde. Ändern ⇒ neu backen
# (tools/bake-fixture.sh). Der Generator bleibt Source of Truth; der Dump ist abgeleitet.
HISTORY_DAYS=180
AVG_MONTHLY_REVENUE=1500
CONVERSION_RATE=0.014
RETURNING_RATE=0.08
OFFSET_ROUNDING=exact     # exact | week  (week: Wochentags-Realismus, bis 6 Tage Lücke am Ende)
```

- [ ] **`tools/shift-dates.sh`** — verschiebt ALLE Zeitstempel um `$1` Tage (vom Host berechnet), identischer Offset für Matomo + WC. Nutzt `mariadb` im db-Container über STDIN.
```sh
#!/usr/bin/env bash
# Verschiebt alle Fixture-Zeitstempel um OFFSET Tage (Argument $1; vom Host berechnet).
# Identischer Offset für Matomo UND WooCommerce – sonst bricht die Umsatz-Kopplung.
# Aufruf (aus install.sh):  tools/shift-dates.sh <offset_days>
set -euo pipefail
OFF="${1:?offset_days fehlt}"
case "$OFF" in (''|*[!0-9-]*) echo "shift-dates: offset muss ganzzahlig sein: '$OFF'" >&2; exit 1;; esac
[ "$OFF" -eq 0 ] && { echo "[shift] offset=0 – nichts zu verschieben."; exit 0; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."
if docker compose version >/dev/null 2>&1; then DC=(docker compose); else DC=(docker-compose); fi
read_env(){ grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' "; }
RP="$(read_env MYSQL_ROOT_PASSWORD)"; MDB="$(read_env MATOMO_DB_NAME)"; WDB="$(read_env WP_DB_NAME)"
MDB="${MDB:-matomo}"; WDB="${WDB:-wordpress}"

echo "[shift] verschiebe alle Fixture-Zeitstempel um ${OFF} Tage ..."
"${DC[@]}" exec -T db mariadb -u root -p"$RP" <<SQL
SET @off = ${OFF};
-- Matomo (verifizierte datetime-Spalten)
UPDATE \`${MDB}\`.matomo_log_visit SET visit_first_action_time = visit_first_action_time + INTERVAL @off DAY, visit_last_action_time = visit_last_action_time + INTERVAL @off DAY;
UPDATE \`${MDB}\`.matomo_log_link_visit_action SET server_time = server_time + INTERVAL @off DAY;
UPDATE \`${MDB}\`.matomo_log_conversion SET server_time = server_time + INTERVAL @off DAY;
UPDATE \`${MDB}\`.matomo_log_conversion_item SET server_time = server_time + INTERVAL @off DAY;
-- WooCommerce / WP (verifizierte Tabellen/Spalten)
UPDATE \`${WDB}\`.wp_wc_orders SET date_created_gmt = date_created_gmt + INTERVAL @off DAY, date_updated_gmt = date_updated_gmt + INTERVAL @off DAY WHERE date_created_gmt IS NOT NULL OR date_updated_gmt IS NOT NULL;
UPDATE \`${WDB}\`.wp_wc_order_operational_data SET date_paid_gmt = date_paid_gmt + INTERVAL @off DAY, date_completed_gmt = date_completed_gmt + INTERVAL @off DAY WHERE date_paid_gmt IS NOT NULL OR date_completed_gmt IS NOT NULL;
UPDATE \`${WDB}\`.wp_wc_order_stats SET date_created = date_created + INTERVAL @off DAY, date_created_gmt = date_created_gmt + INTERVAL @off DAY, date_paid = date_paid + INTERVAL @off DAY, date_completed = date_completed + INTERVAL @off DAY;
UPDATE \`${WDB}\`.wp_wc_order_product_lookup SET date_created = date_created + INTERVAL @off DAY WHERE date_created IS NOT NULL;
UPDATE \`${WDB}\`.wp_wc_customer_lookup SET date_registered = date_registered + INTERVAL @off DAY, date_last_active = date_last_active + INTERVAL @off DAY WHERE date_registered IS NOT NULL OR date_last_active IS NOT NULL;
UPDATE \`${WDB}\`.wp_posts SET post_date = post_date + INTERVAL @off DAY, post_date_gmt = post_date_gmt + INTERVAL @off DAY, post_modified = post_modified + INTERVAL @off DAY, post_modified_gmt = post_modified_gmt + INTERVAL @off DAY WHERE post_type LIKE 'shop_order%';
-- postmeta: datetime-Strings
UPDATE \`${WDB}\`.wp_postmeta SET meta_value = DATE_ADD(meta_value, INTERVAL @off DAY) WHERE meta_key IN ('_paid_date','_completed_date') AND meta_value <> '' AND meta_value REGEXP '^[0-9]{4}-';
-- postmeta: UNIX-Sekunden
UPDATE \`${WDB}\`.wp_postmeta SET meta_value = CAST(meta_value AS UNSIGNED) + (@off * 86400) WHERE meta_key IN ('_date_paid','_date_completed') AND meta_value REGEXP '^[0-9]+\$';
-- Kund:innen-Registrierung (admin harmlos mitverschoben)
UPDATE \`${WDB}\`.wp_users SET user_registered = user_registered + INTERVAL @off DAY WHERE user_registered IS NOT NULL;
SQL
echo "[shift] fertig."
```
- [ ] **Verify:** `bash -n tools/shift-dates.sh` → exit 0. (Echte Wirkung in B6.)
- [ ] **Commit:** `git add tools/bake.conf tools/shift-dates.sh && git commit` (feat(fixture): bake.conf + shift-dates.sh).

---

## Task B2: `tools/bake-fixture.sh`

**Files:** Create `tools/bake-fixture.sh`.

- [ ] Ablauf: (1) `bake.conf` lesen. (2) Stack mit den Seed-Parametern als Env frisch hochfahren (`AUTO_SEED=true`, `BACKFILL_DAYS=$HISTORY_DAYS`, `SEED_ORDERS_DAYS=$HISTORY_DAYS`, `AVG_MONTHLY_REVENUE`, `CONVERSION_RATE`, `RETURNING_RATE`) via `./install.sh -y` (nutzt die in FP1 reparierte Wahrheit; bricht bei Seed-Fehler ab). (3) Auf `Status: OK` warten (install.sh-Exit 0). (4) Dumpen (s. u.). (5) `BASE` (= `date +%F`) + `BAKE-INFO` schreiben. (6) Mengen-Check.
- [ ] **Matomo-Dump** (`matomo/fixture/matomo-history.sql.gz`) — Daten-only, OHNE `matomo_archive_*`, MIT `matomo_log_action` (Dictionary, von link_visit_action referenziert):
  Tabellen: `matomo_log_action matomo_log_visit matomo_log_link_visit_action matomo_log_conversion matomo_log_conversion_item`.
  `mariadb-dump --no-tablespaces --single-transaction <MDB> <tabellen> | gzip`.
- [ ] **WC-Dump** (`matomo/fixture/wc-orders.sql.gz`) — Order-relevante Tabellen + Teil-Dumps der geteilten Tabellen:
  Voll: `wp_wc_orders wp_wc_orders_meta wp_wc_order_operational_data wp_wc_order_addresses wp_wc_order_stats wp_wc_order_product_lookup wp_wc_order_coupon_lookup wp_wc_order_tax_lookup wp_wc_customer_lookup`.
  Teil (`--where`): `wp_posts` WHERE `post_type LIKE 'shop_order%'`; `wp_postmeta` WHERE `post_id IN (SELECT ID FROM wp_posts WHERE post_type LIKE 'shop_order%')` (via separater Dump-Query, da `--where` keine Subquery über andere Tabelle kann → stattdessen `mariadb-dump ... wp_postmeta --where="post_id >= <min_order_id>"` ODER per `wp_postmeta`-Filter über eine Hilfsabfrage; **Detail in B3 am echten Stand festzurren**); `wp_users` WHERE `ID > 1` (geseedete Kund:innen, ohne admin).
  > **R2-Hinweis:** Teil-Dumps mit `--no-create-info` (Daten in bestehende, aus shop.sql.gz vorhandene Tabellen einfügen). User-ID-Kollision vermeiden: shop.sql.gz hat nur admin(1) (+ ggf. wenige); Kund:innen-IDs >1 sind frei. In B3 verifizieren.
- [ ] **`matomo/fixture/BASE`** = `date +%F`; **`BAKE-INFO`** = `bake.conf`-Werte + `git rev-parse --short HEAD` + Mengen (Visits/Orders).
- [ ] **Verify:** `bash -n tools/bake-fixture.sh`. **Commit** (feat(fixture): bake-fixture.sh) — noch OHNE Artefakte.

---

## Task B3: Bake fahren (destruktiv — erzeugt & committet die Artefakte)

- [ ] `./tools/bake-fixture.sh` ausführen (~15–30 Min). Erwartung: `matomo/fixture/{matomo-history.sql.gz, wc-orders.sql.gz, BASE, BAKE-INFO}` entstehen.
- [ ] **Mengen-Check** loggen: # log_visit, # log_conversion (Matomo) und # wp_wc_orders, SUM(net_total) (WC) — gegen die im `generate`-Lauf bekannten Größen (~9–11k Visits, ~200 Orders, ~5600 EUR).
- [ ] **`.gitattributes`:** `matomo/fixture/*.sql.gz binary` ergänzen (LF-Schutz greift nicht auf Binär).
- [ ] **Commit** der Artefakte (chore(fixture): gebackene Fixture-Dumps 180 Tage).

---

## Task B4: Install-Integration (restore + shift, fixture-only)

**Files:** Modify `matomo/matomo-init.sh`, `wordpress/init/wp-init.sh`, `install.sh`, `docker-compose.yml` (traffic-Env).

- [ ] **`wp-init.sh`:** nach dem `shop.sql.gz`-Restore zusätzlich `wc-orders.sql.gz` einspielen (falls vorhanden): `gunzip -c /fixture/wc-orders.sql.gz | wp db import -` (oder `mysql`). Marker `m392_wc_orders_restored`.
- [ ] **`matomo-init.sh`:** nach Matomo-Install + Site/Goals/Dimension den `matomo-history.sql.gz`-Restore einspielen (Matomo-Logs), BEVOR archiviert wird. Pfad: `matomo/fixture` nach `/matomo-fixture` mounten (docker-compose) — oder via db-Container restaurieren.
- [ ] **`install.sh`:** nach Restore (Shop+WC+Matomo) den Offset im Host berechnen und `tools/shift-dates.sh` aufrufen, DANN `core:archive`:
```bash
BASE_DATE="$(cat matomo/fixture/BASE 2>/dev/null || true)"
if [ -n "$BASE_DATE" ]; then
  today=$(date +%s); base=$(date -d "$BASE_DATE" +%s 2>/dev/null || date -j -f %Y-%m-%d "$BASE_DATE" +%s)
  OFFSET=$(( (today - base) / 86400 ))
  # OFFSET_ROUNDING aus BAKE-INFO; bei 'week' auf Vielfaches von 7 abrunden:
  [ "$(grep -E '^OFFSET_ROUNDING=' tools/bake.conf | cut -d= -f2)" = "week" ] && OFFSET=$(( (OFFSET / 7) * 7 ))
  ./tools/shift-dates.sh "$OFFSET"
fi
```
  Idempotenz: `docker compose down -v` + frischer Restore ⇒ genau ein Shift pro Lauf.
- [ ] **Generate-Install-Pfad entfernen:** im `traffic`-Service (docker-compose) `TRAFFIC_AUTO_SEED` effektiv aus (kein historischer Seed beim Normal-Install; Live-Tropf bleibt). `_maybe_auto_seed`/`_maybe_seed_orders` laufen nur noch im Bake (bake-fixture.sh setzt die Env temporär).
- [ ] **Verify:** `bash -n` aller geänderten Skripte.

---

## Task B5: `.env` / `.env.example`-Cleanup

- [ ] **Entfernen** (jetzt eingebacken, leben in `tools/bake.conf`): `TRAFFIC_AUTO_SEED`, `TRAFFIC_BACKFILL_DAYS`, `TRAFFIC_AVG_MONTHLY_REVENUE`, `TRAFFIC_SEED_ORDERS`, `TRAFFIC_SEED_ORDERS_DAYS` + „Startlast"-Kommentarblock. **Behalten:** Live-Tropf (`TRAFFIC_LIVE_DRIP`, `TRAFFIC_DRIP_VISITS_PER_HOUR`, `TRAFFIC_CONVERSION_RATE`, `TRAFFIC_RETURNING_RATE`), `M392_ORDER_API_KEY`, `TRAFFIC_CREATE_WC_ORDERS`, `M392_AB_*`.
- [ ] Compose: entfernte Vars aus `traffic`-Service nehmen; README/`.env.example` dokumentieren: Historie via Fixture, Tuning ⇒ `tools/bake.conf` + neu backen.

---

## Task B6: E2E-Verifikation (Done-Kriterien)

- [ ] Frischer `./install.sh -y` (fixture-only) **< ~5 Min** bis „Status: OK" (Exit 0).
- [ ] **R1-Gate:** Matomo-Berichte rendern; `VisitsSummary.get`/`Goals.get` liefern Daten im Zeitraum `heute−180d … heute`, **nichts in der Zukunft**.
- [ ] **Kopplung (Done #3):** Matomo-E-Commerce-Umsatz == WC `order_stats` net_total (wie im generate-Modus exakt 1:1).
- [ ] **R2-Gate:** WC-Bestellungen sichtbar (Anzahl == Matomo-Conversions), Kund:innen „neu vs. wiederkehrend" plausibel, keine Orphan-/Kollisions-Artefakte.
- [ ] Zweiter `install.sh`-Lauf ⇒ identisch (kein Doppel-Shift).
- [ ] Falls ein Gate bricht (FK/Orphan): iterieren (Dump-Tabellenset / Restore-Reihenfolge / Goal-id-Determinismus anpassen) und neu backen.

---

## Task B7: Holistik-Review + Commit

- [ ] Code-Quality-Review über das gesamte Fixture-Bake-Diff. Findings fixen.
- [ ] Abschluss-Commit; HANDOFF/README auf fixture-only aktualisieren.

---

## Self-Review (Plan vs. Spec)
- Shift-SQL deckt alle in der Spec §6 (verifiziert) gelisteten Tabellen/Spalten ab, inkl. mixed-type postmeta. ✓
- Offset im Host (D5), `OFFSET_ROUNDING` aus bake.conf (D4), 180 Tage (D6), WC separat (D3), bake.conf (D2), fixture-only (D1). ✓
- Offene, am echten Stand zu fixierende Details bewusst in B2/B3 markiert (postmeta-Teil-Dump-Filter, User-ID-Kollision) — mit Verifikations-Gate in B6.
