# Konzept: Produkte effizient hinzufügen

> Ziel: Aus heute **8 Produkten** soll der Demo-Shop schnell auf **20–40+** wachsen –
> reproduzierbar (überlebt `install.sh`), konsistent (Matomo zeigt dieselben Produkte)
> und mit vertretbarem Aufwand. Der eigentliche Engpass sind die **Produktbilder**, die
> mit einer Bild-KI erzeugt werden müssen.

Dieses Dokument beschreibt einen **Workflow**, kein fertiges Skript. Abschnitt 10 listet die
Helfer-Skripte, die wir dafür bauen würden; Abschnitt 12 die Entscheidungen, die vorher feststehen
müssen.

---

## 1. Wie Produkte heute im System „leben" (Grundlage)

Wichtig, um den Workflow richtig aufzusetzen — ein Produkt steckt an **drei** Stellen:

| Ort | Was | Pflicht? |
|---|---|---|
| **Fixture** `wordpress/init/fixture/shop.sql.gz` | Produkt-Post (Name, Beschreibung, Preis, Kategorie, SKU, Bewertungen) **+ Bild-Anhang** | **Ja** – ohne das ist das Produkt nach `install.sh` weg |
| **Fixture** `wordpress/init/fixture/uploads.tar.gz` | die eigentliche **Bilddatei** (`wp-content/uploads/...`) | **Ja** – Bild-Anhang zeigt darauf |
| **`seed/catalog.json`** | Spiegel für das Traffic Lab: `sku`, `slug`, `name`, `price`, `category`, **`popularity`** | empfohlen |

**Was `catalog.json` (nicht) leistet:** Das Traffic Lab liest die Produkte zur Laufzeit **live** aus
dem Shop (`GET /wp-json/m392/v1/products`) – neue Produkte erscheinen also **automatisch** in den
Matomo-Berichten, ohne `catalog.json` anzufassen. `catalog.json` wird nur gebraucht für
(a) den **Fallback**, falls der Shop kurz nicht erreichbar ist, (b) den **Bestseller-Prior**
(`wp-init` setzt `total_sales` je Produkt aus `popularity`, gematcht über den **`slug`**) und
(c) die On-Site-**Suchbegriffe**. → Neue Produkte trotzdem nachtragen, sonst starten sie mit
Bestseller-Gewicht 1 und tauchen in der „Beliebtheit"-Story nicht prominent auf.

**Folge für den Workflow:** Ein neues Produkt muss am Ende **in die Fixture eingebacken** werden.
Das ist der Schritt, der „echte Reproduzierbarkeit" sichert (vgl. `docs/ARCHITECTURE.md` §8).

---

## 2. Der Engpass: Bilder

Texte (Name, Beschreibung), Preise und Kategorien sind in Minuten erstellt – zur Not per KI-Text.
Der teure Teil sind **konsistente, glaubwürdige Produktbilder**. Drei Probleme:

1. **Konsistenz** – alle Produkte sollen wie aus *einem* Shop wirken (gleicher Hintergrund, gleiche
   Licht-/Packaging-Sprache), nicht wie ein zufälliger Stock-Mix.
2. **Reproduzierbarkeit** – wir wollen ein Bild später regenerieren können (gleicher Prompt).
3. **Schlankheit** – die Bilder landen im Repo (`uploads.tar.gz`, heute ~15 MB). Jedes Bild also
   klein halten (WebP, komprimiert).

→ Lösung in Abschnitt 5 (Pipeline) + Abschnitt 6 (Bild-Playbook).

---

## 3. Leitidee: **Eine Quelle der Wahrheit**

Statt Produkte an drei Orten von Hand zu pflegen, definieren wir sie **einmal** in einer Tabelle und
generieren den Rest daraus:

```
   seed/products.csv   (die EINE Quelle: 1 Zeile = 1 Produkt, inkl. Bild-Prompt)
          │
          ├─►  Bild-KI  ──►  rohe Bilder  ──►  [optimize]  ──►  seed/product-images/<sku>.webp
          │
          ├─►  [import]  ──►  WooCommerce (laufender Shop)  ──►  [bake-fixture]  ──►  Fixture
          │
          └─►  [build-catalog]  ──►  seed/catalog.json
```

Vorteile: kein Drift zwischen Shop/Fixture/Katalog, neue Produkte sind ein paar Zeilen + Bilder,
und alles ist versioniert/reviewbar (CSV-Diff statt SQL-Dump-Diff).

---

## 4. Empfohlener Workflow (Schritt für Schritt)

**Voraussetzung:** Stack läuft (`docker compose up -d`), Shop auf `:8090` erreichbar.

1. **Produkte in `seed/products.csv` eintragen** – pro Produkt eine Zeile (Schema → Abschnitt 7),
   inkl. einer Spalte `image_prompt` (der KI-Prompt, der das Bild beschreibt).
2. **Bilder erzeugen** (Bild-KI, Playbook → Abschnitt 6). Dateiname **= `<sku>.webp`**, ablegen unter
   `seed/product-images/`.
3. **Bilder optimieren** – `scripts/optimize-images.sh`: quadratisch zuschneiden, auf 1000×1000
   skalieren, als WebP komprimieren (<~150 KB), Metadaten strippen. (Idempotent.)
4. **In den Shop importieren** – `scripts/import-products.sh`: liest `products.csv`, legt je Zeile ein
   WooCommerce-Produkt an (oder aktualisiert es per SKU), lädt `<sku>.webp` als **Beitragsbild** hoch,
   setzt Kategorie/Preis/Beschreibung. (Idempotent über die SKU.)
5. **Sichtprüfung** im Shop (`:8090/shop`) – Bild, Preis, Kategorie, Beschreibung ok?
6. **`catalog.json` neu bauen** – `scripts/build-catalog.sh`: erzeugt `seed/catalog.json` aus
   `products.csv` (übernimmt `popularity`, `search_terms`).
7. **Fixture neu einbacken** – `scripts/bake-fixture.sh`: exportiert den aktuellen Shop-Stand
   (DB-Dump **ohne** Bestellungen/Kund:innen) nach `shop.sql.gz` und packt `wp-content/uploads` nach
   `uploads.tar.gz`. (Das ist der Schritt, der die neuen Produkte „dauerhaft" macht.)
8. **Verifizieren** – `./install.sh` (am besten in einer Wegwerf-Kopie) und prüfen, dass die neuen
   Produkte frisch wieder erscheinen. Dann committen.

> Schritte 3–7 sind ein **Durchlauf von Skripten** – nach dem Einrichten ist „neue Produkte
> hinzufügen" praktisch: *CSV-Zeilen ergänzen → Bilder ablegen → ein Befehl*.

---

## 5. Bild-Playbook (das Herzstück)

### 5.1 Feste Bild-Spezifikation
- **Format/Größe:** quadratisch **1000×1000 px**, **WebP**, Qualität ~80, Ziel **<150 KB**.
- **Hintergrund:** einheitlich, **nahtlos off-white / hellgrau** (Studio-Packshot), Produkt zentriert,
  weicher Schatten. (Kein Lifestyle-Wirrwarr – sonst leidet die Konsistenz.)
- **Kein Text/Logo im Bild** (sonst wirkt es wie ein fremdes Markenfoto und ist schwer konsistent).

### 5.2 Konsistenz erzwingen (der Trick)
Ein **fixer Stil-Block** (Prompt-Präambel), der bei **jedem** Produkt identisch vorangestellt wird –
nur der produktspezifische Teil variiert:

```
[STIL – immer gleich]
Studio product photograph, e-commerce packshot, single product centered,
seamless off-white background, soft diffused studio lighting, subtle soft shadow,
high detail, photorealistic, 1:1 square, no text, no logo, no props, no hands.

[PRODUKT – je Zeile aus products.csv "image_prompt"]
A frosted-glass cosmetics pump bottle, 150 ml, matte sage-green label, minimalist.
```

Zusätzlich für maximale Konsistenz:
- **Referenzbild** verwenden (Style-/Image-Reference des ersten gelungenen Produkts), damit
  Packaging-Sprache & Farbwelt über alle Produkte gleich bleiben.
- Immer **dieselbe KI/dasselbe Modell** und (falls verfügbar) denselben **Seed**/Stil-Anker.
- In **Batches einer Kategorie** generieren (alle „Gesichtspflege" zusammen) – erleichtert
  visuelle Konsistenz.

### 5.3 Reproduzierbarkeit
Der `image_prompt` steht **in der CSV** – damit ist jedes Bild jederzeit nachproduzierbar, und neue
Mitwirkende sehen, „wie" ein Bild gemeint war.

### 5.4 Nachbearbeitung (automatisiert)
`scripts/optimize-images.sh` macht aus beliebigen Roh-Bildern den einheitlichen Web-Asset:
quadratischer Center-Crop → 1000×1000 → WebP q80 → Metadaten weg. So ist es egal, in welcher Größe
die KI liefert.

---

## 6. Texte per KI (optional, beschleunigt)
Auch Beschreibungen lassen sich aus einem Template generieren – konsistent in Tonalität und Struktur:

```
Schreibe eine deutsche Produktbeschreibung (3–4 Sätze) für ein Naturkosmetik-Produkt
"<name>" (Kategorie <category>). Struktur: Nutzen – Anwendung – Hauttyp. Sachlich,
kein Werbe-Superlativ, kein Lorem Ipsum.
```
Ergebnis in die CSV-Spalte `description`. (Bleibt menschlich gegengelesen.)

---

## 7. Daten-Schema: `seed/products.csv`

Eine Zeile pro Produkt. Vorschlag (an den WooCommerce-CSV-Import angelehnt, plus unsere Felder):

| Spalte | Beispiel | Zweck |
|---|---|---|
| `sku` | `CLN-011` | eindeutige ID; Bildname `<sku>.webp`; Import-Idempotenz |
| `name` | `Sanftes Reinigungsöl` | Produktname |
| `category` | `Gesichtsreinigung` | Kategorie (wird bei Bedarf angelegt) |
| `price` | `19.90` | Preis (EUR) |
| `sale_price` | `` | optional Angebotspreis |
| `short_description` | … | Kurztext (Shop-Listing) |
| `description` | … | Langtext (Produktseite) |
| `popularity` | `60` | Bestseller-Prior (→ `total_sales`, Matomo-Beliebtheit) |
| `search_terms` | `reinigungsöl; cleansing oil` | speist On-Site-Suche im Lab |
| `image_prompt` | `A frosted glass bottle …` | KI-Prompt (Reproduzierbarkeit) |

> Format: UTF-8, Komma-getrennt, Kopfzeile. Lässt sich in Excel/Google Sheets pflegen.

---

## 8. Konventionen

- **SKU-Schema:** `<KAT>-<NNN>`, Kürzel je Kategorie, fortlaufend – z. B. `CLN-011`
  (Cleansing), `CRE-012` (Cream), `MKP-013` (Make-up). Das frühere `img_<SKU>.jpg`-Experiment
  zeigt schon in diese Richtung; wir formalisieren es als `<sku>.webp`.
- **Bilddatei = `<sku>.webp`** unter `seed/product-images/` (1:1 zur SKU → Skripte können automatisch
  zuordnen).
- **Slug** = aus dem Namen abgeleitet (WooCommerce macht das); `catalog.json` matcht über den Slug –
  daher Slug nach dem Import **nicht** mehr ändern.
- **Größenbudget:** Bild <150 KB; bei +30 Produkten wächst `uploads.tar.gz` nur um ~4–5 MB.

---

## 9. Werkzeuge/Skripte (zu bauen)

Vier kleine, idempotente Skripte unter `scripts/` – jedes für sich nutzbar:

| Skript | Aufgabe | Kern |
|---|---|---|
| `optimize-images.sh` | Roh-Bilder → einheitliche `<sku>.webp` | ImageMagick (`magick … -resize 1000x1000^ -gravity center -extent 1000x1000 -quality 80 webp`) |
| `import-products.sh` | `products.csv` → WooCommerce-Produkte (+ Bild) | WP-CLI im `wordpress`-Container: `wp wc product create/update`, `wp media import … --featured_image` (oder WooCommerce-CSV-Importer) |
| `build-catalog.sh` | `products.csv` → `seed/catalog.json` | Python (vorhandene Felder + `popularity`/`search_terms`) |
| `bake-fixture.sh` | aktuellen Shop → `shop.sql.gz` + `uploads.tar.gz` | `wp db export` (Order-/Kund:innen-Tabellen leeren) + `tar` der Uploads |

`bake-fixture.sh` formalisiert die bisher „chirurgisch" gemachte Fixture-Regeneration (Import in
Temp-DB, Order-/Customer-Tabellen leeren, neu dumpen) als einen wiederholbaren Befehl.

---

## 10. Leichtgewichtige Variante (1–2 Produkte, ohne Pipeline)

Wenn es nur mal schnell ein Produkt sein soll:
1. Bild mit KI erzeugen, mit `optimize-images.sh` normalisieren.
2. Im **WP-Admin** (`/wp-admin`) → *Produkte → Erstellen*: Name, Preis, Kategorie, Beschreibung,
   Beitragsbild setzen.
3. `bake-fixture.sh` laufen lassen + (optional) `catalog.json` ergänzen.

→ Für Bulk lohnt die Pipeline (Abschnitt 4), für Einzelfälle reicht der Admin.

---

## 11. Offene Entscheidungen (bevor wir Skripte bauen)

1. **Sortiment/Marke:** Bleibt es **Naturkosmetik** (kohärent zur aktuellen Story, einfachere
   Bildkonsistenz) – oder ein **gemischter Shop**? *(Empfehlung: bei Kosmetik bleiben.)*
2. **Zielanzahl:** Wie viele Produkte insgesamt? (10 / 25 / 40?) → bestimmt Aufwand & Kategorien.
3. **Bildstil:** **Packshot auf neutralem Hintergrund** (empfohlen, konsistent) vs. Lifestyle.
4. **Welche Bild-KI** nutzt du? (bestimmt, wie wir Referenzbild/Seed/Batching konkret beschreiben).
5. **Import-Weg:** WP-CLI-Skript (automatisierbar, mein Vorschlag) vs. WooCommerce-CSV-Importer im
   Admin (klickbar, ohne Skripte).

---

## 12. Nächste Schritte (Phasen)

- **Phase 0 (jetzt):** Entscheidungen aus Abschnitt 11 klären.
- **Phase 1:** `seed/products.csv` mit den **bestehenden 8** Produkten befüllen (Ist-Zustand als
  Startpunkt) + `build-catalog.sh` + `bake-fixture.sh` bauen → damit ist die Pipeline „rückwärts"
  verifiziert (gleiches Ergebnis wie heute).
- **Phase 2:** `optimize-images.sh` + `import-products.sh` bauen, an **2–3 neuen** Produkten
  durchspielen, `install.sh`-Roundtrip prüfen.
- **Phase 3:** Restliche Produkte (CSV-Zeilen + Bilder) ergänzen, einmal durch die Pipeline, committen.

> So ist nach Phase 2 der teure Teil (Bilder) der einzige verbleibende manuelle Aufwand – alles
> andere ist „CSV + ein Befehl".
