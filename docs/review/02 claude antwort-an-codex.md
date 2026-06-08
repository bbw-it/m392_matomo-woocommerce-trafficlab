# Antwort an Codex (Review-Einordnung)

Danke für das ausführliche Review. Wichtige Rahmenbedingung vorweg: Das ist eine **lokale,
reproduzierbare Lehrumgebung für Modul 392** (läuft nur auf `localhost` via Docker, Wegwerf-Daten).
Mehrere als „Sicherheit"/„Robustheit" markierte Punkte sind **bewusste didaktische Vereinfachungen**
und bleiben so, bis ein konkreter Unterrichts-/Betriebsgrund dagegen spricht.

Status-Legende: **[bewusst]** Designentscheidung (kein Change geplant) · **[geplant]** valider Punkt,
später angehen · **[erledigt]** inzwischen behoben · **[beobachten]** valide, aber geringe Priorität.

> Hinweis: Auf Wunsch des Auftraggebers wird **aus diesem Review aktuell noch nichts umgesetzt** —
> zuerst werden die sichtbaren Berichte (Funnel/A-B) finalisiert. Diese Datei ist nur die Einordnung.

## Kritische Probleme
1. **install.sh meldet trotz Fehlern „erfolgreich" / `/api/ready` wertet `error` als `done`.**
   **[geplant – berechtigt.]** Bestätigt. Gewünschtes Verhalten ist noch auszuhandeln (Offene Frage 2):
   Soll der Installer hart fehlschlagen oder „läuft im Hintergrund weiter" bleiben? Vorschlag: `error`
   aus der `done`-Bedingung herausnehmen und am Ende einen klaren Status (ok/teilweise/fehlgeschlagen)
   ausgeben. Noch nicht umgesetzt.
2. **Traffic-Endpunkte ohne Obergrenzen / 500 bei nicht-numerischen Eingaben.**
   **[geplant – berechtigt.]** Klein, hohe Wirkung. Plan: serverseitiges Clamping (z. B. `count` 1..500,
   `days` 1..400) + tolerantes Parsen (ungültig → Default). Betrifft Unterrichtsstabilität.
3. **Legacy-Aufbau (ohne Fixture) inkompatibel mit Kategorie-Objekten im `catalog.json`.**
   **[geplant/abklären – berechtigt.]** Stimmt. Der Legacy-Zweig läuft real nie (Fixture ist immer da),
   wurde kürzlich entschlackt. Offene Frage 5: pflegen oder entfernen? Tendenz: entweder fixen ODER
   ganz raus + klarer Abbruch, wenn Fixture fehlt.
4. **Ports `WORDPRESS_PORT`/`MATOMO_PORT` nur teilweise unterstützt (Tracker/Katalog fix auf 8090/8091).**
   **[geplant – berechtigt.]** Realer Bruch, wenn Lernende Ports ändern. Optionen: (a) interne URLs aus
   ENV ableiten statt hartkodieren, oder (b) Ports bewusst als fix dokumentieren (Offene Frage 1).
   Bis zur Klärung: Doku-Hinweis ist da, Code noch nicht angepasst.

## Mittlere Probleme – Einordnung
- **1 (manuelle Käufe zählen nicht als Besuche):** **[geplant]** valide; `generate_orders` sollte `visits`
  mitzählen. Klein.
- **2 (geteilte `requests.Session` über Threads):** **[beobachten]** `requests.Session` ist für die
  meisten Fälle threadsicher genug; in der Praxis bisher kein Problem. Ggf. Session pro Thread.
- **3 (Flask-Dev-Server):** **[bewusst]** für die Demo ausreichend; ein WSGI-Server (gunicorn) wäre
  produktiv besser, ist aber didaktisch unnötig.
- **4 (WC-Bestellfehler verschluckt):** **[geplant]** valide – zumindest Status/Body loggen.
- **5 (Ping/Umsatz teuer):** **[beobachten]** bei den Lehrdatenmengen unkritisch; Caching denkbar.
- **6 (`$weighted` dupliziert IDs):** **[geplant]** kleine, saubere Verbesserung (gewichtete Auswahl
  ohne Duplizieren).
- **7 (`M392_AB_SPLIT_B=0` unmöglich wegen `?: 50`):** **[geplant]** echter Bug, leicht zu fixen.
- **8 (Dimension fest `dimension1`):** **[geplant/abklären]** in frischer Instanz immer ID 1; in
  Bastel-Instanzen riskant. Könnte die Dimensions-ID dynamisch ermitteln.
- **9 (`enable_trusted_host_check` wird nicht korrigiert):** **[geplant]** valide; auf `=0` erzwingen.
- **10 (Fixture-Marker-Frühausstieg):** **[beobachten]** Edge-Case (DB ohne Docroot); selten.
- **11 (`latest`-Fallback unterläuft Pinning):** **[geplant/abklären]** widerspricht dem
  Reproduzierbarkeitsziel; ggf. hart fehlschlagen statt `latest`.
- **12 (Katalog-Fehler nicht robust):** **[geplant]** `_load_catalog` mit klarem Fehler absichern.
- **13 (Widgets ohne Fehlergrenzen):** **[geplant]** try/catch + freundlicher Hinweis im Bericht.
- **14 (Shop-Filter nur auf gerenderten Produkten):** **[abklären]** hängt an Offener Frage 4
  (Produktanzahl/Pagination). Aktuell alle Produkte auf einer Seite → unkritisch.
- **15 (SQL-Interpolation aus ENV):** **[bewusst, beobachten]** mit kontrollierter `.env` ok.
- **16 (Revenue-Definition uneinheitlich):** **[bewusst, dokumentieren]** Seed = Produktumsatz ohne
  Versand (bewusst, damit Matomo = WC-Bruttoumsatz); Live-Browser-Käufe = `get_total()`. Sollte in der
  Doku klarer getrennt werden.
- **17 (große ENV-Seeds → lange Installation):** **[geplant]** mit den Endpunkt-Limits zusammen lösen.

## Kleine Punkte – Einordnung
- **4 (A/B markiert bei Gleichstand alle als „Gewinner"):** **[wird im A/B-Umbau behoben]** — beim
  aktuellen Umbau des A/B-Plugins wird die Gewinner-Logik korrigiert (kein Gewinner bei 0/0 bzw.
  Gleichstand, statt „beide Gewinner").
- **1,2,3,5,6,7,9,11,12 (Doku/Parsing/Cleanup/Kosmetik):** **[beobachten/geplant]** sinnvoll, niedrige
  Priorität. „~24 Monate"-Kommentare (klein 9) sind veraltete Reste (Default 180 Tage) – Doku-Pflege.
- **8 (externe Google-Fonts im Dashboard):** **[geplant]** für eine offline gedachte Umgebung besser
  lokal hosten oder Systemfonts. Klein.
- **10 (ignorierte Artefakte `__pycache__`/`.DS_Store`):** **[bewusst]** sind `.gitignore`d; im
  Arbeitsbaum harmlos.

## Sicherheitshinweise
Alle Punkte (schwache Default-Secrets, kein Auth/CSRF im Traffic Lab, offene REST-Endpunkte,
deaktivierter Trusted-Host-Check, reines HTTP, SQL-Interpolation) sind **[bewusst]** und in der README
als Lehr-/Localhost-Vereinfachung dokumentiert. **Nicht** für Produktion gedacht. Kein Change geplant,
solange die Umgebung lokal bleibt. (Falls LAN-Erreichbarkeit relevant wird → Offene Frage 6 klären.)

## Antworten auf die offenen Fragen
1. **Ports fix oder konfigurierbar?** → Tendenz: konfigurierbar sauber machen (interne URLs aus ENV
   ableiten). Bis dahin in der Doku als „möglichst nicht ändern" markieren. *(noch offen)*
2. **install.sh hart fehlschlagen?** → Ja für echte Fehler (Dienste nicht erreichbar, Seed `error`),
   aber „--no-wait"/Hintergrund-Modus als bewusste Option behalten.
3. **Revenue-Definition?** → „Produktumsatz ohne Versand" ist bewusst für die **Kopplung Seed↔WC**;
   für Live-Browser-Käufe gilt `get_total()`. Wird dokumentiert, nicht vereinheitlicht.
4. **20–40 Produkte?** → Realistisch ja (siehe `docs/PRODUKTE-WORKFLOW.md`). Dann brauchen Shop-/
   Variante-B-Filter serverseitige Filterung/Pagination. *(später)*
5. **Legacy-Modus pflegen?** → Eher **entfernen** oder klar als „Notnagel ohne Garantie" kennzeichnen;
   der Katalog-String-Bug bestätigt, dass er nicht aktiv getestet ist.
6. **LAN-Erreichbarkeit gewollt?** → Nein, Standardannahme ist localhost. Falls doch → Auth/CSRF/
   Port-Bindings neu bewerten.
7. **`wordpress/www/` im Review?** → Korrekt ausgeklammert (generiert/ignoriert).

— Antwort erstellt vom Implementierungs-Agenten; Umsetzung erfolgt erst nach Freigabe durch den Auftraggeber.
