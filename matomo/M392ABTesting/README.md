# M392 A/B-Testing (nachgebautes Matomo-Plugin)

Kostenfreier Ersatz für Matomos bezahltes A/B-Testing – für die Lehrumgebung Modul 392.

## Idee
Jede:r Besucher:in wird einer **Variante A oder B** der Shop-Seite zugewiesen (fix per Cookie).
Die Variante wird in der Matomo-**Custom Dimension „AB-Variante"** (Scope: Besuch) getrackt. Matomo
erzeugt daraus automatisch einen Bericht, der **A vs. B** mit allen Kennzahlen inкл. **E-Commerce-
Conversion und Umsatz** vergleicht. Variante B konvertiert bewusst etwas besser → sichtbarer Lerneffekt.

## Bestandteile
| Teil | Wo | Zweck |
|---|---|---|
| **Daten/Setup** | `setup.sh` (hier) | legt die Custom Dimension „AB-Variante" an (von `matomo-init.sh` aufgerufen) |
| **Varianten-Rendering** | `wordpress/init/mu-plugins/m392-ab-test.php` *(Phase 2)* | zeigt Variante A/B der Shop-Seite + setzt die Dimension im Tracking |
| **Traffic** | `traffic/generator.py` | weist generierten Besuchen A/B zu (`dimension1`) + Conversion-Bias |
| **Report-Seite** | `plugin/` *(Phase 2)* | natives Matomo-Plugin mit A/B-Vergleichstabelle |

## Konfiguration (`.env`)
- `M392_AB_TEST_ENABLED` – A/B-Test aktiv (true/false)
- `M392_AB_SPLIT_B` – Prozent der Besuche → Variante B
- `M392_AB_CONV_FACTOR_B` – um diesen Faktor konvertiert B besser

## Auswertung in Matomo
*Besucher → Custom Dimensions → AB-Variante* (oder als Segment „AB-Variante == Shop-Variante" über
jeden Bericht). Dieser **eingebaute** Bericht vergleicht Original vs. Shop-Variante inkl. E-Commerce-
Conversion und Umsatz – ganz ohne Plugin.

> **Hinweis zu `plugin/`:** Die native Report-Seite (`plugin/`) ist im Repo enthalten, wird aber in
> der Standard-Installation **nicht aktiviert**. Grund: In der Headless-Umgebung ersetzt eine
> config.ini-`[Plugins]`-Sektion die Default-Plugins (inkl. Login) und legt Matomo lahm. Die Analyse
> läuft daher über den eingebauten Custom-Dimension-Bericht (gleiche Daten). Wer die native Seite
> dennoch will, mountet `plugin/` nach `/var/www/html/plugins/M392ABTesting` und aktiviert sie mit
> `console plugin:activate M392ABTesting` (auf eigenes Risiko).
