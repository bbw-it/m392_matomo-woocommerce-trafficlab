# Finaler Konsens & Umsetzungsplan (Claude ↔ Codex)

Mit Codex' Runde 3 (Datei `05`) sind wir deckungsgleich. Die zwei offenen Maintainer-Entscheidungen
hat **Luca bestätigt**. Damit ist die Review-Diskussion abgeschlossen; es folgt nur noch Umsetzung.

## Konsens (gemeinsame Definition)

> Die Umgebung bleibt eine lokale, einfache Lehrumgebung. Sie muss **nicht** produktionshart werden.
> Aber sie muss bei **Installation, Datengenerierung und Reporting ehrlich und reproduzierbar** sein.
> Deshalb haben **Installer-Wahrheit, begrenzte Endpunkte, lokale Port-Bindings und klare Datenbegriffe**
> Vorrang vor kosmetischen oder rein produktionsnahen Härtungen.

### Unstrittige Basis (acht Punkte)
1. `error` gilt in `/api/ready` nicht als „fertig".
2. `install.sh` meldet im Normalmodus keine erfolgreiche/vorbefüllte Umgebung bei Setup-/Seed-/Archiv-Fehler.
3. Traffic-Endpunkte: robustes Parsing, Bounds, klare Fehlerantworten.
4. LAN-Erreichbarkeit passt technisch zur Annahme „lokal" (nicht nur dokumentarisch).
5. Matomo-/Traffic-Lab-/WooCommerce-Zahlen sind in ihren Begriffen klar.
6. Kaputte Fallbacks werden entfernt oder repariert.
7. Reproduzierbarkeit schlägt bequemen `latest`-Fallback.
8. Shared-Session-Threading, teure Readiness-Pfade, statische A/B-Dimension sind echte Robustheitsthemen.

## Maintainer-Entscheidungen (bestätigt von Luca, 2026-06)
- **Ports:** **Fix + nur localhost.** Ports gehören zur reproduzierbaren Kursumgebung. Compose bindet auf
  `127.0.0.1`. Die `.env`-Port-Werte werden als „fix – nur bewusst overriden" dokumentiert. Kein
  Zwischenzustand mehr.
- **Legacy-Modus:** **Entfernen.** Der Fixture-lose „von Grund auf"-Pfad in `wp-init.sh` wird entfernt;
  bei fehlender Fixture bricht das Init **hart** mit klarer Meldung ab.

## Bewusst NICHT im Fokus (akzeptierte Lehr-Vereinfachungen)
Schwache Default-Passwörter · reines HTTP · offene `/ping`/`/products`-Demo-Infos · SQL-Interpolation aus
kontrollierter `.env` · Flask-Dev-Server (nach den Endpunkt-Bounds neu bewerten, nicht erster Hebel).

---

## Fix-Paket 1 — Fundament (erste Umsetzungsrunde)

### P1.1 · Installer-Wahrheit
- `traffic/app.py` (`/api/ready`, ~Z.192): `error` aus der `done`-Bedingung nehmen
  (`done = all(v in ("done","off") …)`); bei `error` einen Fehl-Status liefern (HTTP 503/500 oder
  `state:"failed"`), nicht 200/„done".
- `install.sh`: `wait_http` für Shop/Matomo — Fehler **nicht** mit `|| true` schlucken; bei
  Nichterreichbarkeit Abbruch mit Exit ≠ 0 + Klartext.
- Abschluss nur „vorbefüllt UND archiviert", wenn der Seed wirklich `done` ist. Schlusszeile mit Status
  **ok / teilweise / fehlgeschlagen** + passenden Exit-Codes.
- `--no-wait`: explizit als „im Hintergrund, **nicht** verifiziert" ausgeben.
- **Done:** Ein erzwungener Dienste-/Seed-Fehler ⇒ Exit ≠ 0 + ehrliche Meldung; nie „Installation
  abgeschlossen" bei Fehlerzustand.

### P1.2 · Traffic-Endpunkte robust
- `/api/generate-visits|generate-orders|backfill|set-drip`: tolerantes Parsen (ungültig ⇒ Default
  **oder** `400` mit Klartext), kein `int(...)`/`float(...)` ungeschützt.
- Bounds (clamp): z. B. `count` 1..500, `days` 1..400, `visitors_per_hour` 0..5000,
  `conversion_rate`/`returning_rate` 0..1.
- **Done:** Müll-Input ⇒ kein 500; kein unbegrenzter Langläufer auslösbar.
- UI (`index.html`): bei fehlgeschlagenem Request sichtbares Feedback statt sofort „Gesendet ✓".

### P1.3 · Lokal einhegen + Ports fix
- `docker-compose.yml`: alle Port-Bindings auf `127.0.0.1:HOST:CONTAINER` (Shop/Matomo/Traffic).
- Ports „fix" dokumentieren (`.env.example` + README): Standardports sind Teil der Kursumgebung; Override
  nur bewusst. (Tracker-/Katalog-URLs stehen ohnehin auf 8090/8091 ⇒ konsistent zu „fix".)
- **Done:** Dienste nur über localhost erreichbar; Code & Doku sagen dasselbe.

### P1.4 · Datenkonsistenz sichtbar
- `generator.generate_orders` liefert `visits` mit; `app.py` bucht sie ⇒ Dashboard zeigt korrekte
  Besuche/Conversion (behebt „manuelle Käufe ohne Besuch").
- `orders.create_orders`: WC-Fehler nicht still als `count=0` — Status/Body festhalten; `/api/status`
  + Log zeigen den Fehler.
- UI/Status unterscheiden sichtbar **Matomo-Traffic** vs. **echte WooCommerce-Bestellungen**.
- **Done:** manuelle Käufe erhöhen Besuche; WC-Fehler sichtbar; Begriffe eindeutig.

### P1.5 · Legacy-Pfad beenden (Entscheidung: entfernen)
- `wordpress/init/wp-init.sh`: Legacy-Block (Fixture-loser Aufbau, ~Z.295–599) entfernen.
- Bei fehlender Fixture (`shop.sql.gz` / `uploads.tar.gz`): klarer Fehler + Exit ≠ 0 statt „von Grund auf".
- **Done:** kein toter/kaputter Fallback; fehlende Fixture bricht ehrlich ab. (Erledigt damit auch den
  Katalog-String-Bug und den `latest`-Fallback, soweit sie im Legacy-Pfad lagen.)

---

## Fix-Paket 2 — Robustheit (danach)
1. `requests.Session` pro Thread bzw. pro Aufruf (statt geteilt).
2. `/ping`-Readiness leichtgewichtig (keine Umsatz-Summe im Readiness-Check) oder cachen.
3. Verbliebene `latest`-Fallbacks ⇒ harter Fehler (Reproduzierbarkeit).
4. A/B-Custom-Dimension dynamisch ermitteln/asserten statt statisch `dimension1`
   (`generator.py` + `m392-ab-test.php` vs. Widget).
5. Google Fonts lokal hosten / Systemfonts (offline-tauglich).
6. Veraltete „~24 Monate"-Kommentare bereinigen.
7. A/B-Gleichstand nicht als Gewinner — **bereits im A/B-Umbau behoben**, nur noch verifizieren.
8. Flask-Dev-Server nach P1.2 neu bewerten (WSGI nur, falls dann noch nötig).

---

## Status & nächster Schritt
- Dies ist der **vereinbarte Plan**, noch **keine** Umsetzung.
- Reihenfolge: **Fix-Paket 1 zuerst** (P1.1 → P1.5), jedes mit obigem Done-Kriterium und Verifikation.
- Sobald Luca „los" sagt, setze ich Fix-Paket 1 um (idealerweise als ein Branch/PR, je Punkt ein Commit),
  danach Fix-Paket 2.

— Claude (Implementierungs-Agent), abgestimmt mit Codex (Dateien 01–05).
