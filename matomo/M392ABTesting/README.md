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
| **Report-Seite** | `plugin/` (Category + Subcategory + Widget) | natives Matomo-Plugin mit A/B-Vergleichstabelle |

## Konfiguration (`.env`)
- `M392_AB_TEST_ENABLED` – A/B-Test aktiv (true/false)
- `M392_AB_SPLIT_B` – Prozent der Besuche → Variante B
- `M392_AB_CONV_FACTOR_B` – um diesen Faktor konvertiert B besser

## Report-Seite (natives Plugin)
Der Ordner `plugin/` ist ein vollwertiges Matomo-5-Plugin. Es wird per Bind-Mount in den
Matomo-Container gelegt (`docker-compose.yml`) und in `install.sh` **automatisch aktiviert**:

```
console plugin:activate M392ABTesting
```

Das ist sicher: `console plugin:activate` schreibt die **vollständige** Plugin-Liste (inkl. Login/
Auth) in die `config.ini.php`. Ein *manueller* `[Plugins]`-Eintrag würde dagegen die Default-Plugins
ersetzen und Matomo lahmlegen – diesen Weg meiden wir bewusst.

Technik (Matomo 5): **Subcategory + Widget** (`Categories/`, `Widgets/`) hängen die Seite unter den
bestehenden Menüpunkt **„A/B Tests"** (Promo-Kategorie `ProfessionalServices_PromoAbTesting`, Icon
`icon-lab`). Der ältere `configureReportingMenu`-Weg erzeugt in Matomo 5 KEINEN Sidebar-Eintrag.
Aufruf der Report-Seite: Berichts-Menü → **„A/B Tests" → „Vergleich (M392)"** (Original vs. Shop-Variante
inkl. E-Commerce-Conversion und Umsatz, Gewinner markiert).

## Auswertung in Matomo (alternativ, ganz ohne Plugin)
*Besucher → Custom Dimensions → AB-Variante* (oder als Segment „AB-Variante == Shop-Variante" über
jeden Bericht). Dieser **eingebaute** Bericht zeigt dieselben Daten.
