# Code Review

## Zusammenfassung
Die App ist als lokale Lehrumgebung grundsätzlich nachvollziehbar aufgebaut: Docker-Compose, WordPress/WooCommerce, Matomo und das Traffic Lab greifen mit klarer Absicht ineinander. Die größten Risiken liegen nicht in einzelnen Syntaxfehlern, sondern in Robustheit und Betriebswahrheit: mehrere lange oder fehlerhafte Prozesse werden als "fertig" behandelt, Eingaben in der Traffic-API sind kaum begrenzt, und einige Konfigurationsversprechen aus der Doku werden im Code nur teilweise eingelöst. Besonders kritisch sind der Installationspfad, der bei Fehlern trotzdem erfolgreich wirken kann, und die synchronen Generator-Endpunkte, die die App mit großen oder ungültigen Parametern blockieren oder mit 500 abbrechen können. Sicherheitsprobleme sind vorhanden, aber für die deklarierte lokale Lern-/Docker-Umgebung nachrangig.

Hinweis zum Umfang: Ich habe den versionierten Eigen-Code vollständig gelesen. Der generierte WordPress-Docroot `wordpress/www/` enthält sehr viel Drittcode bzw. installierte WordPress-/WooCommerce-/Elementor-Dateien und ist per `.gitignore` als generierter Bind-Mount markiert; er wurde nicht als eigener Projektcode bewertet. Die Fixture-Archive wurden nicht entpackt oder verändert, nur strukturell/metadatenseitig betrachtet.

## Kritische Probleme

1. `install.sh` kann trotz defekter Installation erfolgreich durchlaufen.
   - Dateien: `install.sh:123-124`, `install.sh:246-255`, `install.sh:270-277`, `traffic/app.py:192-216`
   - `wait_http`-Fehler für Shop und Matomo werden mit `|| true` ignoriert. Danach laufen Plugin-Aktivierung, Ready-Polling und Abschlussmeldung weiter.
   - Zusätzlich wertet `/api/ready` Seed-Phasen mit Status `error` als "done" (`done = all(v in ("done", "off", "error") ...)`) und liefert dann HTTP 200. `install.sh` interpretiert HTTP 200 als abgeschlossene Startbefüllung.
   - Ergebnis: Eine Installation mit fehlender Historie, fehlenden Bestellungen oder nicht erreichbaren Diensten kann am Ende "Installation abgeschlossen" und "Matomo ist vorbefuellt" melden.

2. Traffic-Endpunkte können durch ungültige oder sehr große Eingaben abstürzen oder lange blockieren.
   - Dateien: `traffic/app.py:219-253`, `traffic/app.py:265-290`
   - `count`, `days`, `visitors_per_hour`, `conversion_rate` und `returning_rate` werden direkt mit `int(...)`/`float(...)` geparst. Nicht-numerische Werte führen zu 500-Fehlern.
   - Für `/api/generate-visits`, `/api/generate-orders` und `/api/backfill` gibt es keine sinnvollen Obergrenzen auf Flask-Ebene. Sehr große Werte lösen synchrone Schleifen mit vielen Matomo-/WordPress-Requests aus und können den Traffic-Service für längere Zeit blockieren.
   - Das betrifft Stabilität und Unterrichtsbetrieb direkt: Ein falscher Request aus UI, Browser-Konsole oder Script kann die Umgebung einfrieren lassen.

3. Der dokumentierte Legacy-Aufbau ohne Fixture ist mit dem aktuellen `catalog.json`-Schema kaputt.
   - Dateien: `wordpress/init/wp-init.sh:357-366`, `wordpress/init/wp-init.sh:373-388`, `seed/catalog.json:15-33`
   - `catalog.json` enthält Kategorien als Objekte (`{"name": "...", "slug": "..."}`), der Legacy-Code behandelt Kategorien aber als Strings und macht `echo $c`. In PHP führt das bei `stdClass` zu einem Fehler ("Object of class stdClass could not be converted to string").
   - Der Fallback "kein Fixture-Abbild vorhanden -> Shop von Grund auf aufbauen" ist damit nicht zuverlässig nutzbar.

4. Port-Konfiguration ist nur teilweise umgesetzt; zentrale Tracking-Funktionen brechen bei geänderten Ports.
   - Dateien: `docker-compose.yml:32-33`, `docker-compose.yml:88-89`, `wordpress/init/mu-plugins/matomo-tracking.php:8-11`, `seed/catalog.json:3-7`, `README.md:426-429`
   - `WORDPRESS_PORT` und `MATOMO_PORT` sind konfigurierbar, aber der Browser-Tracker ist fest auf `http://localhost:8091/` gesetzt. Wird `MATOMO_PORT` geändert, senden echte Shop-Besuche weiter an den alten Port.
   - `seed/catalog.json` enthält fest `http://localhost:8090` in Shop-/Blog-/PDF-/Kontakt-URLs. Wird `WORDPRESS_PORT` geändert, erzeugt das Traffic Lab falsche Matomo-URLs.
   - Die Doku erwähnt diesen Punkt teilweise als Troubleshooting, aber aus Nutzersicht sind die Ports in `.env` als anpassbar beschrieben. Das ist ein Kernfunktionsbruch, wenn Lernende Ports wegen Konflikten ändern.

## Mittlere Probleme

1. Manuell erzwungene Käufe zählen im Dashboard nicht als Besuche.
   - Dateien: `traffic/app.py:228-239`, `traffic/generator.py:640-648`
   - `generator.generate_orders()` simuliert pro Kauf einen kompletten Besuch, gibt aber nur `purchases` und `revenue` zurück. `app.py` akkumuliert deshalb keine `visits`.
   - Matomo erhält Besuchsdaten, das Traffic-Lab-Dashboard zeigt aber eine zu niedrige Besuchszahl und eine zu hohe effektive Conversion-Rate.

2. Shared `requests.Session()` wird aus mehreren Threads verwendet.
   - Dateien: `traffic/generator.py:79-80`, `traffic/orders.py:17`, `traffic/app.py:497-500`
   - Drip-Thread, History-/Order-Seed-Threads und manuelle API-Requests können parallel `generator.SESSION` bzw. `orders.SESSION` verwenden.
   - `requests.Session` ist nicht als thread-sicheres Shared-Objekt gedacht. Das kann seltene, schwer reproduzierbare Request-/Connection-Pool-Fehler verursachen.

3. Der Traffic-Service nutzt den Flask-Development-Server als Laufzeitserver.
   - Datei: `traffic/app.py:502-503`
   - `app.run(host="0.0.0.0", port=8092)` ist für Demo okay, aber bei langen synchronen Requests, parallelen Klicks oder hoher Last weniger robust als ein WSGI-Server.
   - In Kombination mit unlimitierten Generator-Endpunkten erhöht das die Freeze-Wahrscheinlichkeit.

4. Fehler beim Anlegen echter WooCommerce-Bestellungen werden verschluckt.
   - Dateien: `traffic/orders.py:66-78`, `traffic/app.py:127-134`, `traffic/app.py:231-238`
   - `orders.create_orders()` gibt bei Request-/JSON-Fehlern still `count=0` zurück. HTTP-Status, Response-Body und Ursache gehen verloren.
   - Die UI/Logs können dadurch Matomo-Käufe melden, während im Shop keine echten Bestellungen entstehen.

5. `/wp-json/m392/v1/ping` und Umsatzberechnung skalieren schlecht.
   - Dateien: `wordpress/init/mu-plugins/m392-order-api.php:35-45`, `wordpress/init/mu-plugins/m392-order-api.php:85-98`
   - Der Ping lädt alle Produkte, alle Bestell-IDs und berechnet zusätzlich den Umsatz durch Iteration über alle relevanten Bestellungen.
   - Bei hohen Seed-Werten oder häufiger Abfrage wird ein eigentlich leichter Readiness-Check teuer.

6. Produktgewichtung in der Order-API kann unnötig viel Speicher verbrauchen.
   - Datei: `wordpress/init/mu-plugins/m392-order-api.php:221-230`
   - Für jedes Produkt wird die ID `total_sales`-mal in `$weighted` kopiert. Je länger die Umgebung läuft oder je höher der Seed ist, desto größer wird diese Liste.
   - Eine gewichtete Auswahl ohne Duplizieren wäre robuster.

7. A/B-Split kann nicht auf 0 Prozent Variante B gesetzt werden.
   - Datei: `wordpress/init/mu-plugins/m392-ab-test.php:22-27`
   - `$n ?: 50` macht aus `0` wieder `50`. `M392_AB_SPLIT_B=0` ist damit unmöglich, obwohl der Wertebereich offensichtlich 0..100 sein soll.

8. A/B-Custom-Dimension ist fest als `dimension1` codiert.
   - Dateien: `wordpress/init/mu-plugins/m392-ab-test.php:57-66`, `traffic/generator.py:39-43`, `traffic/generator.py:433-435`, `matomo/M392ABTesting/setup.sh:23-33`
   - Das Setup legt eine Dimension namens "AB-Variante" an, erzwingt aber nicht, dass ihre ID 1 ist. Das Report-Widget sucht die Dimension dynamisch, der Tracker und Generator senden jedoch immer `dimension1`.
   - In einer nicht ganz frischen Matomo-Instanz oder nach manuellen Änderungen können Browser-/Traffic-Daten in der falschen Dimension landen und der A/B-Bericht leer bleiben.

9. `matomo-init.sh` setzt `enable_trusted_host_check` nur, wenn der Eintrag fehlt.
   - Datei: `matomo/matomo-init.sh:118-136`
   - Wenn `enable_trusted_host_check` bereits existiert, aber auf `1` steht, wird nur "bereits konfiguriert" geloggt. Der Wert wird nicht korrigiert.
   - Das kann spätere Starts mit Hostnamen `localhost`/`matomo` brechen.

10. `wp-init.sh` steigt im Fixture-Modus zu früh aus, wenn nur der DB-Marker existiert.
    - Datei: `wordpress/init/wp-init.sh:49-52`
    - Ist `m392_fixture_restored` gesetzt, beendet sich das Skript sofort. Es prüft nicht, ob Theme-/Plugin-Dateien, Uploads oder `.htaccess` noch vorhanden sind.
    - Wenn der Host-Docroot `wordpress/www` manuell gelöscht oder teilweise beschädigt wurde, aber die DB erhalten bleibt, kann der Stack in einen inkonsistenten Zustand geraten.

11. Version-Pinning wird durch Fallback auf `latest` unterlaufen.
    - Datei: `wordpress/init/wp-init.sh:75-99`
    - Bei nicht verfügbarer Plugin-/Theme-Version wird auf die neueste Version installiert. Das ist bequem, widerspricht aber dem Reproduzierbarkeitsziel der Lehrumgebung.
    - Ein Semester kann dadurch andere Plugin-Versionen bekommen als ein anderes.

12. Datenfehler im Katalog werden nicht robust behandelt.
    - Dateien: `traffic/generator.py:219-260`, `traffic/generator.py:399-405`, `traffic/generator.py:476-493`, `traffic/generator.py:789-794`
    - `_load_catalog()` fängt fehlende/ungültige JSON-Dateien nicht ab. `simulate_visit()` setzt `catalog["products"]` und mindestens ein Produkt voraus.
    - `backfill()` fängt nur `requests.RequestException`; `KeyError`, `ValueError` oder leere Gewichtungen brechen den ganzen Backfill ab.

13. Matomo-Report-Widgets haben kaum Fehlergrenzen um API-Antworten.
    - Dateien: `matomo/M392ABTesting/plugin/Widgets/GetAB.php:34-71`, `matomo/M392Funnels/plugin/Widgets/GetFunnel.php:43-65`
    - Die Widgets gehen davon aus, dass `Request::processRequest()` immer erwartete Objekte/Tabellen liefert. Fehler, fehlende Plugins oder veränderte API-Formen werden nicht abgefangen.
    - Ergebnis wäre kein kontrollierter Hinweis im Bericht, sondern ein sichtbarer Widget-/Matomo-Fehler.

14. Shop-Filter arbeiten nur auf den aktuell gerenderten Produkten.
    - Datei: `wordpress/init/mu-plugins/m392-shop-filters.php:203-337`
    - Die serverseitigen Daten enthalten alle Produkte, aber die JS-Filterung sortiert und versteckt nur die `li.product`-Elemente im aktuellen DOM.
    - Sobald WooCommerce paginiert oder mehr Produkte als die aktuelle Seite zeigt, zählen/filtert/sortiert die Leiste nur die sichtbare Seite, nicht den Gesamtkatalog.

15. Datenbank-Init ist empfindlich gegenüber Sonderzeichen in `.env`.
    - Datei: `db/init/01-init-databases.sh:12-24`
    - DB-Namen, Benutzer und Passwörter werden direkt in SQL interpoliert. Ein `'`, Backtick oder anderes SQL-relevantes Zeichen in `.env` kann das Init-Skript brechen.
    - Für die Demo-Defaults funktioniert es, aber "Passwörter bei Bedarf anpassen" ist dadurch eingeschränkt.

16. Revenue-Definitionen sind nicht überall gleich.
    - Dateien: `wordpress/init/mu-plugins/matomo-tracking.php:100-106`, `traffic/generator.py:651-719`, `wordpress/init/mu-plugins/m392-order-api.php:402-412`
    - Die gekoppelte Seed-Spiegelung nutzt Produktumsatz ohne Versand. Echte Browser-Käufe tracken dagegen `order->get_total()` als Matomo-Revenue und zusätzlich Shipping/Subtotal.
    - Das ist eventuell gewollt, sollte aber klarer getrennt werden, weil Matomo-Zahlen je nach Quelle unterschiedlich definiert sind.

17. Große ENV-Werte können sehr lange Start-Seeds auslösen.
    - Dateien: `traffic/app.py:293-355`, `traffic/app.py:381-456`, `traffic/generator.py:772-800`
    - `TRAFFIC_BACKFILL_DAYS`, `TRAFFIC_SEED_ORDERS`, `TRAFFIC_SEED_ORDERS_DAYS` und `TRAFFIC_AVG_MONTHLY_REVENUE` werden kaum begrenzt.
    - Ein Tippfehler oder sehr hoher Richtwert kann Startbefüllung und Installation sehr lange blockieren.

## Kleine Probleme / Verbesserungsvorschläge

1. `install.sh` liest `.env` nur sehr grob.
   - Datei: `install.sh:57-68`
   - `read_env()` entfernt Leerzeichen und Quotes, aber nicht allgemein Inline-Kommentare. Für `TRAFFIC_BACKFILL_DAYS` wird nachträglich repariert, für Ports nicht.

2. `ensure_htaccess()` erkennt nur `RewriteEngine On`.
   - Datei: `wordpress/init/wp-init.sh:12-30`
   - Eine beschädigte `.htaccess`, die zufällig `RewriteEngine On` enthält, wird nicht repariert.

3. `wp-init.sh` und `matomo-init.sh` hinterlassen temporäre Dateien im Container.
   - Dateien: `wordpress/init/wp-init.sh:147-173`, `wordpress/init/wp-init.sh:178-186`, `wordpress/init/wp-init.sh:194-210`, `matomo/matomo-init.sh:17-20`
   - Für Wegwerf-Container harmlos, aber sauberer wären `trap`/Cleanup oder wiederverwendbare Skriptdateien.

4. Das A/B-Widget markiert bei Gleichstand alle Varianten als Gewinner.
   - Datei: `matomo/M392ABTesting/plugin/templates/index.twig:22-31`
   - Wenn beide Conversion-Rates `0` sind, erscheint überall "Gewinner". Das ist fachlich missverständlich.

5. `catalog.json` enthält `coupon.usage_rate`, die Order-API nutzt aber hart `18`.
   - Dateien: `seed/catalog.json:8-14`, `wordpress/init/mu-plugins/m392-order-api.php:247-252`
   - Die Konfiguration wirkt dadurch dynamischer, als sie ist.

6. UI meldet manuelle Aktion als gesendet, auch wenn der Request fehlschlägt.
   - Datei: `traffic/templates/index.html:334-379`
   - `post()` zeigt keine Fehlermeldung; der Button wechselt direkt auf "Gesendet". Bei 500/Timeout sieht der Benutzer nur ausbleibende Zahlen.

7. Clientseitiges Log-Clearing ist anfällig um Mitternacht.
   - Datei: `traffic/templates/index.html:454-463`
   - Log-Einträge enthalten nur Uhrzeit. `toEpoch()` hängt das heutige Datum an; nach Mitternacht können alte/neue Einträge falsch gefiltert werden.

8. Externe Google-Fonts im lokalen Dashboard.
   - Datei: `traffic/templates/index.html:7-9`
   - Für eine lokale/offline gedachte Lehrumgebung ist das eine unnötige externe Abhängigkeit. Fällt das Netzwerk aus, funktioniert die App zwar, aber die UI rendert anders.

9. Kommentare/Doku nennen teils noch "~24 Monate", obwohl Defaults 180 Tage sind.
   - Beispiele: `docker-compose.yml:153-156`, `traffic/orders.py:45-48`, `traffic/app.py:459-462`, `traffic/generator.py:722-728`
   - Das ist kein Laufzeitfehler, erschwert aber spätere Pflege.

10. Workspace enthält ignorierte Artefakte.
    - Beispiele: `traffic/__pycache__/`, `.DS_Store`, `matomo/.DS_Store`
    - Sie sind nicht versioniert, aber im Arbeitsbaum vorhanden. Für Reviews/Backups können sie rauschen.

11. `m392_ab_split_b()` parst nur führende Ziffern.
    - Datei: `wordpress/init/mu-plugins/m392-ab-test.php:22-27`
    - Dezimalwerte oder Werte mit führendem Leerzeichen/Sonderformat werden nicht konsistent behandelt. Für Prozentwerte reicht Integer wahrscheinlich, sollte aber bewusst sein.

12. Produkt-/Kategorieauswahl nutzt jeweils den ersten Term.
    - Dateien: `wordpress/init/mu-plugins/matomo-tracking.php:43-45`, `wordpress/init/mu-plugins/m392-order-api.php:109-111`, `traffic/generator.py:252-258`
    - Bei Produkten mit mehreren Kategorien ist die Reihenfolge von WordPress-Terms nicht zwingend fachlich eindeutig.

## Sicherheitshinweise

1. Schwache Default-Secrets und Passwörter sind vorhanden.
   - Dateien: `.env.example:13-20`, `.env.example:25-34`, `.env.example:44-52`, `docker-compose.yml:39-41`, `traffic/orders.py:13-15`, `wordpress/init/mu-plugins/m392-order-api.php:23-28`
   - Für die Lernumgebung ist das bewusst dokumentiert. Nicht produktionsgeeignet.

2. Traffic Lab hat keine Authentifizierung und keine CSRF-Schutzschicht.
   - Dateien: `traffic/app.py:219-290`, `traffic/app.py:502-503`
   - Jeder, der den Port erreicht, kann Besuche, Backfills und Bestellungen auslösen. Da Docker-Ports standardmäßig auf allen Interfaces lauschen können, ist das im lokalen Netzwerk relevant.

3. Öffentliche WordPress-REST-Endpunkte geben Shop-Struktur und Status preis.
   - Datei: `wordpress/init/mu-plugins/m392-order-api.php:30-70`
   - `/ping` und `/products` sind absichtlich offen. Für Demo okay; produktiv nicht.

4. Matomo Trusted-Host-Check wird deaktiviert.
   - Datei: `matomo/matomo-init.sh:118-139`
   - In der README als bewusste Vereinfachung dokumentiert. Für lokale Docker-Nutzung nachvollziehbar, aber nicht produktionsfähig.

5. SQL-Interpolation aus ENV kann bei bösartigen oder kaputten Werten gefährlich werden.
   - Datei: `db/init/01-init-databases.sh:12-24`
   - In einer kontrollierten `.env` okay; mit fremden Eingaben nicht.

6. Alles läuft über HTTP.
   - Dateien: `docker-compose.yml`, `wordpress/init/mu-plugins/matomo-tracking.php:8-11`, `seed/catalog.json:3-7`
   - Für localhost/Demo okay. Außerhalb davon sind Login- und Trackingdaten unverschlüsselt.

## Offene Fragen

1. Soll `WORDPRESS_PORT`/`MATOMO_PORT` wirklich vollständig unterstützt werden, oder sollen die Ports bewusst fix bleiben? Der Code und die Doku sind hier nicht ganz auf derselben Linie.

2. Soll `install.sh` bei Seed-/Archivierungsfehlern hart fehlschlagen, oder ist "läuft im Hintergrund weiter" als Unterrichts-Komfort gewollt? Aktuell ist die Abschlussmeldung zu optimistisch.

3. Ist die Revenue-Definition "Produktumsatz ohne Versand" nur für den Richtwert-Seed gedacht, oder soll sie für alle Matomo-E-Commerce-Quellen gelten?

4. Wird der Shop künftig wirklich auf 20-40+ Produkte wachsen? Falls ja, müssen Produktfilter und Variante-B-Filter mit Pagination bzw. serverseitiger Filterung geklärt werden.

5. Soll der Legacy-Modus ohne Fixture aktiv gepflegt werden, oder kann er entfernt werden? Aktuell ist er sichtbar vorhanden, aber nicht kompatibel mit dem aktuellen Katalogschema.

6. Soll das Traffic Lab bewusst von anderen Geräten im LAN erreichbar sein? Falls nein, sollten Port-Bindings/Auth/CSRF anders bewertet werden.

7. Sollen Drittanbieter-Dateien unter `wordpress/www/` grundsätzlich außerhalb des Reviews bleiben? Das Verzeichnis ist generiert/ignoriert, liegt aber physisch im Workspace.
