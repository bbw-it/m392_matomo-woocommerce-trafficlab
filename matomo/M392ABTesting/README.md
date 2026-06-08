# M392 A/B-Testing (nachgebautes Matomo-Plugin)

Kostenfreier Ersatz für Matomos bezahltes A/B-Testing – für die Lehrumgebung Modul 392.

## Idee
Mehrere **A/B-Tests** verwaltbar. Ein Test = Name + Hypothese + Beschreibung + N **Varianten**,
je definiert über ein Matomo-**Segment** (Custom Dimension „AB-Variante" oder Seiten-URL-Muster).
Die Report-Seite zeigt je Test eine Variations-Tabelle (Besuche / eindeutige Besucher / Bestellungen /
Conversion-Rate / Umsatz / Ø-Bestellwert) mit **Total-Zeile** und **Gewinner-Markierung**.

Der **Standard-Test „ShopVariante"** (Original `/shop/` vs. Shop-Variante `/shop-variante/`, über die
Custom Dimension) ist immer vorhanden; Variante B konvertiert bewusst etwas besser → sichtbarer
Lerneffekt. Weitere Tests legt man über **„+ Neuen A/B-Test anlegen"** an – das Formular öffnet
**inline im selben Fenster** (die Card wechselt, die Navigation bleibt), Demo-Look (Feld links,
Hilfe rechts). Tests sind in einer Matomo-Option gespeichert.

**Auswertungszeitraum:** Die Tabelle/der Bayes-Wert sind **kumuliert über die gesamte Laufzeit seit
Teststart** (unabhängig vom Datumsfilter oben) → Gewinner und P-Wert springen nicht monatlich.
Zusätzlich zeigt eine kleine **Monats-Verlaufskurve** der Conversion-Rate je Variante den zeitlichen
Trend als Kontext (nicht die Gewinner-Basis).

### Bayes-Auswertung (Beta-Binomial)
Die Conversion-Rate je Variante ist Beta-verteilt; mit Prior Beta(1,1) ist die Posterior
`Beta(1+Bestellungen, 1+Besuche−Bestellungen)`. **P(Variante besser als Original)** wird sofort als
**Normal-Näherung** angezeigt; der Button **„exakt"** rechnet eine **Monte-Carlo-Simulation**
(100 000 Stichproben, ~90 ms) und zeigt zusätzlich die erwartete relative **Steigerung** und das
**95 %-Intervall** der Conversion-Differenz.

Code: `Storage.php` (Test-CRUD via Option), `Stats.php` (Kennzahlen je Segment, kumulierte Range,
Monats-Verlauf, Bayes), `Controller.php` (Speichern/Löschen/Bayes-AJAX, Nonce-geschützt),
`Widgets/GetAB.php` (Übersicht, kumuliert), `templates/index.twig` (Übersicht **und** inline
Create-Formular).

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
