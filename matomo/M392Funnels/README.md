# M392 Funnels (nachgebautes Matomo-Plugin)

Kostenfreier Ersatz für Matomos bezahltes Funnels-Plugin – für die Lehrumgebung Modul 392.

## Idee
Der klassische E-Commerce-**Trichter** in vier Schritten. Jeder Schritt ist ein Matomo-**Ziel**
(URL-basiert); die Anzahl Conversions pro Ziel = Besuche, die den Schritt erreicht haben. Der
**Abbruch (Drop-off)** ergibt sich aus dem Verhältnis aufeinanderfolgender Schritte.

| Schritt | Ziel | URL |
|---|---|---|
| 1 | Produkt angesehen | enthält `/product/` |
| 2 | In den Warenkorb | enthält `/cart/` |
| 3 | Kasse | Regex `/checkout/$` |
| 4 | Kauf abgeschlossen | enthält `/order-received` |

Wichtig: Die Schritte werden als **Seitenaufrufe** getrackt (Matomo wertet URL-Ziele nur für
Pageviews aus, nicht für E-Commerce-Aktionen). Der `traffic`-Generator durchläuft den Trichter
realistisch (jeder Schritt verliert Besucher:innen), sodass die Zahlen mit Besuchen/Käufen **matchen**.

## Bestandteile
| Teil | Wo | Zweck |
|---|---|---|
| **Daten/Setup** | `setup.sh` (hier) | legt die 4 Funnel-Ziele an (von `matomo-init.sh` aufgerufen) |
| **Traffic** | `traffic/generator.py` | erzeugt den Schritt-für-Schritt-Pfad (Produkt→Warenkorb→Kasse→Kauf) |
| **Report-Seite** | `plugin/` (Category + Subcategory + Widget) | natives Matomo-Plugin mit Trichter-Diagramm |

## Report-Seite (natives Plugin)
Der Ordner `plugin/` ist ein vollwertiges Matomo-5-Plugin. Es wird per Bind-Mount in den
Matomo-Container gelegt (`docker-compose.yml`) und in `install.sh` **automatisch aktiviert**:

```
console plugin:activate M392Funnels
```

Das ist sicher: `console plugin:activate` schreibt die **vollständige** Plugin-Liste (inkl. Login/
Auth) in die `config.ini.php`. Ein *manueller* `[Plugins]`-Eintrag würde dagegen die Default-Plugins
ersetzen und Matomo lahmlegen – diesen Weg meiden wir bewusst.

Technik (Matomo 5): **Subcategory + Widget** (`Categories/`, `Widgets/`) hängen die Seite unter den
bestehenden Menüpunkt **„Funnels"** (Promo-Kategorie `ProfessionalServices_PromoFunnels`, Icon
`icon-funnel`). Der ältere `configureReportingMenu`-Weg erzeugt in Matomo 5 KEINEN Sidebar-Eintrag.
Aufruf der Report-Seite: Berichts-Menü → **„Funnels" → „Trichter (M392)"** (Trichter mit Drop-off je Schritt).

## Auswertung in Matomo (alternativ, ganz ohne Plugin)
*Ziele* → die vier „Funnel-…"-Ziele zeigen je Schritt Conversions und Conversion-Rate; der Vergleich
der aufeinanderfolgenden Schritte ergibt den Trichter (Drop-off). Pro A/B-Variante: Segment
„AB-Variante" hinzufügen.
