# Lernpfad Modul 392 – Nutzer-Daten mittels Analysetools auswerten (mit Matomo)

Diese Datei leitet aus der **Modulbeschreibung 392** (ICT-Berufsbildung Schweiz, v1.0, 22.01.2025)
die Themen ab, mit denen sich Lernende sinnvollerweise in **Matomo** auseinandersetzen, und ordnet
jedes Thema einer konkreten **Matomo-Funktion dieser Lehrumgebung** zu. Sie ist als Basis für einen
**Lernpfad** gedacht: nach Lernschritten gruppiert, von Grundlagen zu vertiefenden Themen.

> Lesehilfe je Thema: **Modul-Bezug** (Handlungsziel `HZ` / handlungsnotwendige Kenntnis `K`) ·
> **Matomo-Funktion** (wo im Lab) · **Lernziel** (was die Lernenden können sollen).

---

## Modul 392 auf einen Blick

- **Kompetenz:** Nutzerdaten basierend auf rechtlichen und ethischen Vorgaben auswerten, Kennzahlen
  (KPI) zielgruppengerecht aufbereiten.
- **Objekt (Endprodukt):** *Bericht mit Handlungsempfehlung aus der Datenanalyse.*
- **Handlungsziele:**
  1. **HZ1** – Nutzt verfügbare Datenquellen und geeignete Analysetools zur Auswertung.
  2. **HZ2** – Analysiert das Nutzungsverhalten, leitet aussagekräftige KPIs ab.
  3. **HZ3** – Berücksichtigt ethische, moralische und rechtliche Anforderungen.
  4. **HZ4** – Leitet aus den Analyseergebnissen zielgerichtete Handlungsempfehlungen ab.

### Übersicht: Handlungsziel → Matomo-Bereiche

| Handlungsziel | Schwerpunkt | Matomo-Bereiche in diesem Lab |
|---|---|---|
| **HZ1** Datenquellen & Tools | Tool-Auswahl, Tracking, Datenqualität | Tracking-Code, *Besucher → Echtzeit*, Tool-Vergleich (Matomo/GA/Hotjar), *Diagnose* |
| **HZ2** Verhalten & KPIs | Kennzahlen, Segmente, Funnel, Muster | *Besucher*, *Verhalten*, *Ziele*, *E-Commerce*, *Segmente*, *Funnels*, *A/B-Tests* |
| **HZ3** Recht & Ethik | DSGVO/DSG, Anonymisierung, Einwilligung | *Administration → Datenschutz (PrivacyManager)*, Cookie-/Consent-Konzept |
| **HZ4** Handlungsempfehlungen | Optimierung, Priorisierung, Reporting | *Dashboard*, *Custom Reports*, Export/PDF, *Akquisition*, *Trichter*-Erkenntnisse |

---

## Stufe 0 – Orientierung & Grundlagen *(HZ1)*

Ziel der Stufe: Verstehen, **was** Webanalyse ist, **woher** die Daten kommen und **wie** man Matomo bedient.

### 0.1 Webanalyse & Tool-Landschaft einordnen
- **Modul-Bezug:** HZ1 · K1.3 (gängige Datenquellen: Google Analytics, Matomo, Hotjar – Funktionsumfang & Datenschutz)
- **Matomo-Funktion:** Matomo als datenschutzfreundliches, self-hosted Analysetool; Abgrenzung zu GA (Cloud) und Hotjar (Heatmaps/Session-Recordings).
- **Lernziel:** Die Lernenden können Matomo, Google Analytics und Hotjar nach Funktionsumfang und Datenschutz einordnen und begründen, wann welches Tool passt.

### 0.2 Anforderungen, Ziele & Stakeholder klären
- **Modul-Bezug:** HZ1 · K1.1, K1.2 (Anforderungen/Ziele klären; Stakeholder & Bedürfnisse)
- **Matomo-Funktion:** *Ziele* (Goals) und *E-Commerce* als Abbild der Geschäftsziele; Auswahl der relevanten Berichte je Fragestellung.
- **Lernziel:** Die Lernenden formulieren aus einer Geschäftsfrage messbare Ziele und wählen die dafür passenden Matomo-Berichte aus (statt „alles anzuschauen").

### 0.3 Matomo bedienen: Dashboard, Zeiträume, Echtzeit
- **Modul-Bezug:** HZ1 · HZ2 (Grundbedienung als Voraussetzung)
- **Matomo-Funktion:** *Dashboard*, Datums-/Zeitraum-Wähler, *Besucher → Echtzeit* und *Besucherprotokoll*.
- **Lernziel:** Die Lernenden navigieren sicher durch Matomo, stellen Zeiträume/Segmente ein und lesen Echtzeit- und Verlaufsdaten.

### 0.4 Wie entstehen die Daten? (Tracking verstehen)
- **Modul-Bezug:** HZ1 · K1.4 (Datenqualität, Repräsentativität)
- **Matomo-Funktion:** Tracking-Code im Shop (Must-Use-Plugin), das **Datengenerierungstool** (Traffic Lab, `:8092`) als sichtbare Datenquelle; *Diagnose*.
- **Lernziel:** Die Lernenden erklären, wie ein Seitenaufruf zu einem Datensatz wird, und erkennen, dass generierte/uneinheitliche Daten die Aussagekraft beeinflussen.

---

## Stufe 1 – Besucher & Nutzungsverhalten verstehen *(HZ2)*

Ziel der Stufe: Das **Nutzungsverhalten** beschreiben – wer kommt, woher, mit welchem Gerät, was wird angeschaut.

### 1.1 Besucher-Überblick: neu vs. wiederkehrend, Engagement
- **Modul-Bezug:** HZ2 · K2.1, K2.3 (KPIs; Muster/Trends)
- **Matomo-Funktion:** *Besucher → Übersicht*, *Engagement* (neu vs. wiederkehrend, Besuchshäufigkeit, Besuchsdauer).
- **Lernziel:** Die Lernenden unterscheiden neue und wiederkehrende Nutzer:innen und beschreiben Engagement-Muster über die Zeit.

### 1.2 Wer und womit: Standort, Geräte, Software
- **Modul-Bezug:** HZ2 · K2.3
- **Matomo-Funktion:** *Besucher → Standorte (Karte)*, *Geräte*, *Software/Browser*.
- **Lernziel:** Die Lernenden charakterisieren das Publikum (Region, Gerätetypen) und leiten erste Hypothesen ab (z. B. Mobile-Anteil).

### 1.3 Was wird genutzt: Seiten, Ein-/Ausstieg, Suche, Downloads
- **Modul-Bezug:** HZ2 · K2.1 · HZ4 · K4.3 (User Journey)
- **Matomo-Funktion:** *Verhalten → Seiten*, *Einstiegs-/Ausstiegsseiten*, *On-Site-Suche* (Site-Search), *Downloads*, *Ausgehende Links*.
- **Lernziel:** Die Lernenden identifizieren wichtige Seiten, häufige Ausstiegspunkte und Suchbegriffe und beschreiben den groben Nutzungspfad.

### 1.4 Eine einzelne Customer Journey nachvollziehen
- **Modul-Bezug:** HZ4 · K4.3 (User Journey identifizieren/visualisieren)
- **Matomo-Funktion:** *Besucher → Besucherprotokoll* (einzelne Besuche Schritt für Schritt).
- **Lernziel:** Die Lernenden lesen einen konkreten Besuchsverlauf und erkennen Reibungspunkte aus Nutzersicht.

---

## Stufe 2 – KPIs erkennen und interpretieren *(HZ2)*

Ziel der Stufe: Von Rohzahlen zu **aussagekräftigen Kennzahlen** – und diese kritisch einordnen.

### 2.1 Die zentralen KPIs: Conversion Rate, Bounce Rate, Besuchsdauer
- **Modul-Bezug:** HZ2 · K2.1 (typische KPIs: Conversion Rate, Bounce Rate, Task Time)
- **Matomo-Funktion:** *Besucher*/*Verhalten*-Kennzahlen (Absprungrate, Ø Besuchsdauer, Aktionen je Besuch) und *Ziele*/*E-Commerce* (Conversion Rate).
- **Lernziel:** Die Lernenden definieren die Kern-KPIs, lesen sie in Matomo ab und erklären deren Aussagekraft (und Grenzen).

### 2.2 Muster & Trends über die Zeit erkennen
- **Modul-Bezug:** HZ2 · K2.3 (Muster und Trends)
- **Matomo-Funktion:** Zeitreihen-/Evolutionsgraphen, Vergleich von Zeiträumen, *Besucher → Zeiten/Wochentage*.
- **Lernziel:** Die Lernenden erkennen Trends, Saisonalität und Ausreisser und unterscheiden Signal von Rauschen.

### 2.3 Kritisch bleiben: Korrelation ≠ Kausalität, Datenqualität
- **Modul-Bezug:** HZ2 · K2.4 (Korrelation vs. Kausalität) · K1.4 (Datenqualität/Repräsentativität)
- **Matomo-Funktion:** Gegenüberstellung von Kennzahlen/Segmenten; bewusste Reflexion über Stichprobengrösse und Datenherkunft.
- **Lernziel:** Die Lernenden hinterfragen scheinbare Zusammenhänge, erkennen Stichproben-/Qualitätsgrenzen und formulieren belastbare statt vorschneller Insights.

---

## Stufe 3 – Vertiefende Auswertungsmethoden *(HZ2 → HZ4)*

Ziel der Stufe: Mit **Segmenten, Zielen, Funnels, E-Commerce und A/B-Tests** gezielt analysieren.

### 3.1 Segmentierung
- **Modul-Bezug:** HZ2 · K2.2 (Segmentierung als Auswertungsmethode) · K1.5 (Aggregation/Kombination)
- **Matomo-Funktion:** *Segmente* (z. B. Land, Gerät, Kanal, neu/wiederkehrend, Custom Dimension „AB-Variante") – auf jeden Bericht anwendbar.
- **Lernziel:** Die Lernenden bilden sinnvolle Segmente und vergleichen das Verhalten von Nutzergruppen statt nur Durchschnittswerte zu betrachten.

### 3.2 Ziele (Goals) & Conversions
- **Modul-Bezug:** HZ2 · K2.1, K2.2
- **Matomo-Funktion:** *Ziele* – im Lab vorkonfiguriert: PDF-Download (INCI-Leitfaden), Kontaktanfrage (`/danke`), die vier Funnel-Schritte; Conversion-Rate je Ziel.
- **Lernziel:** Die Lernenden definieren/verstehen Ziele, messen Conversions und bewerten, welche Aktionen geschäftlich zählen.

### 3.3 Funnel-Analyse (Conversion-Trichter)
- **Modul-Bezug:** HZ2 · K2.2 (Funnel-Analyse) · HZ4 · K4.3 (User Journey)
- **Matomo-Funktion:** **M392-Funnel-Plugin** (Menü *Funnels → Trichter (M392)*): Sankey-Diagramm Produkt → Warenkorb → Kasse → Kauf mit Drop-off je Schritt und konkreter Seiten-Zuordnung.
- **Lernziel:** Die Lernenden lesen einen Conversion-Trichter, lokalisieren den grössten Absprung und leiten daraus eine Optimierungshypothese ab.

### 3.4 E-Commerce-Analyse
- **Modul-Bezug:** HZ2 · K2.1, K2.2 · HZ4 · K4.2 (Business-Kontext)
- **Matomo-Funktion:** *E-Commerce → Übersicht, Produkte, Verkäufe, abgebrochene Warenkörbe, Gesamteinnahmen*.
- **Lernziel:** Die Lernenden verbinden Verhaltensdaten mit Umsatz/Produkten und erkennen, wo Wert entsteht oder verloren geht.

### 3.5 Akquisition: Kanäle, Kampagnen, Verweise
- **Modul-Bezug:** HZ2 · K2.3 · K1.5 (Quellen kombinieren)
- **Matomo-Funktion:** *Akquisition → Kanäle, Verweis-Websites, Kampagnen* (Newsletter als `pk_campaign`), Suchmaschinen.
- **Lernziel:** Die Lernenden bewerten, welche Kanäle Besuche und Conversions bringen, und kombinieren Quellen zu einem Gesamtbild.

### 3.6 A/B-Tests: Varianten datenbasiert vergleichen
- **Modul-Bezug:** HZ2 · K2.2, K2.4 · HZ4 · K4.1 (Optimierung), K4.4 (Priorisierung)
- **Matomo-Funktion:** **M392-A/B-Test-Plugin** (Menü *A/B Tests → Vergleich (M392)*): Variantenvergleich (Original vs. Shop-Variante), Conversion-Rate je Variante, **Bayes-Wahrscheinlichkeit „besser als Original"**, kumulierte Auswertung seit Teststart.
- **Lernziel:** Die Lernenden interpretieren einen A/B-Test (Gewinner, Wahrscheinlichkeit, Unsicherheit) und verstehen, warum man kumuliert statt monatlich entscheidet.

---

## Stufe 4 – Recht, Ethik & Datenschutz *(HZ3, Querschnitt)*

Ziel der Stufe: Verantwortungsvoll auswerten – **rechtlich und ethisch**. (Begleitet alle Stufen, hier vertieft.)

### 4.1 Rechtliche Grundlagen: DSGVO & Schweizer DSG
- **Modul-Bezug:** HZ3 · K3.1 (Datenschutzgesetze/-richtlinien)
- **Matomo-Funktion:** Konzeptthema; in Matomo sichtbar an *Administration → Datenschutz* und an der first-party/cookieless-Architektur.
- **Lernziel:** Die Lernenden benennen die Anforderungen von DSGVO/DSG an Erhebung, Verarbeitung und Nutzung und beurteilen ein Tracking-Setup darauf.

### 4.2 Ethik: Anonymisierung, Einwilligung, Zweckbindung
- **Modul-Bezug:** HZ3 · K3.2 (ethische Grundsätze)
- **Matomo-Funktion:** *Datenschutz → IP-Anonymisierung*, *Anonymisierung von Tracking-Daten*, Consent-/Opt-out-Konzept; Zweckbindung der erhobenen KPIs.
- **Lernziel:** Die Lernenden begründen, welche Daten nötig/zulässig sind, und konfigurieren bzw. bewerten Anonymisierung und Einwilligung.

### 4.3 Technisch-organisatorische Sicherheit & Betroffenenrechte
- **Modul-Bezug:** HZ3 · K3.3 (Sicherheitsmassnahmen)
- **Matomo-Funktion:** *Datenschutz → Datenaufbewahrung*, *Recht auf Auskunft/Löschung (DSAR)*, Nutzer-/Rechteverwaltung.
- **Lernziel:** Die Lernenden kennen Massnahmen für den sicheren Umgang mit Daten (Aufbewahrungsfristen, Zugriffsrechte, Auskunft/Löschung) und können sie anwenden.

> **Hinweis Lab:** Diese Lehrumgebung läuft bewusst lokal mit vereinfachten Einstellungen (HTTP,
> deaktivierter Trusted-Host-Check, schwache Demo-Passwörter). Das ist ein **Lerngegenstand**: Die
> Lernenden sollen erkennen, was für einen produktiven, datenschutzkonformen Betrieb zusätzlich nötig wäre.

---

## Stufe 5 – Von der Analyse zur Handlungsempfehlung *(HZ4)*

Ziel der Stufe: Erkenntnisse in **priorisierte, businessrelevante Empfehlungen** und einen **Bericht** überführen (= Modul-Objekt).

### 5.1 Von KPI zu Massnahme
- **Modul-Bezug:** HZ4 · K4.1 (Optimierungsmassnahmen: Navigation, Landing Pages)
- **Matomo-Funktion:** Erkenntnisse aus *Verhalten*, *Funnel* und *A/B-Test* in konkrete Massnahmen übersetzen.
- **Lernziel:** Die Lernenden leiten aus einem Befund (z. B. hoher Drop-off an der Kasse) eine konkrete Optimierung ab.

### 5.2 Business-Kontext & Priorisierung
- **Modul-Bezug:** HZ4 · K4.2 (Business-Kontext), K4.4 (Priorisierung), K4.5 (Legitimation)
- **Matomo-Funktion:** *E-Commerce*-Umsatzbezug; Abwägung Wirksamkeit / Aufwand / Nutzen.
- **Lernziel:** Die Lernenden übersetzen KPIs in geschäftlichen Nutzen und priorisieren Massnahmen nachvollziehbar.

### 5.3 Aufbereitung & Visualisierung
- **Modul-Bezug:** HZ4 · K4.6 (Dashboards/Infografiken), K4.3 (User-Journey-Visualisierung)
- **Matomo-Funktion:** Eigenes *Dashboard* aus Widgets zusammenstellen, Berichte exportieren (CSV/PDF), geplante *E-Mail-Berichte*, Sankey-Funnel als Visualisierung. *(Custom Reports ist in Matomo ein kostenpflichtiges Plugin – im Lab nur als Konzept/Promo sichtbar.)*
- **Lernziel:** Die Lernenden stellen die für die Zielgruppe relevanten Kennzahlen klar und verständlich dar.

### 5.4 Endprodukt: Bericht mit Handlungsempfehlung
- **Modul-Bezug:** **Modul-Objekt** · HZ4 gesamthaft
- **Matomo-Funktion:** Synthese aller vorherigen Stufen.
- **Lernziel:** Die Lernenden erstellen einen strukturierten Bericht: Fragestellung → Datengrundlage/Tool-Wahl → Analyse (KPIs, Segmente, Funnel, A/B) → datenschutzkonforme Einordnung → priorisierte Handlungsempfehlungen.

---

## Anhang: Kenntnis → Matomo-Umsetzung (Vollständigkeits-Check)

| Kenntnis | Kurzbeschreibung | Matomo-Umsetzung im Lab | Stufe |
|---|---|---|---|
| K1.1 | Anforderungen/Ziele klären | Ziele & Berichtsauswahl je Fragestellung | 0.2 |
| K1.2 | Stakeholder & Bedürfnisse | Ziel-/KPI-Definition aus Geschäftsfrage | 0.2 |
| K1.3 | Datenquellen (GA/Matomo/Hotjar) | Tool-Einordnung & Datenschutzvergleich | 0.1 |
| K1.4 | Datenqualität/Repräsentativität | Tracking verstehen, kritische Reflexion | 0.4, 2.3 |
| K1.5 | Quellen kombinieren (Aggregation) | Segmente + Akquisitionskanäle kombinieren | 3.1, 3.5 |
| K2.1 | Typische KPIs (CR, Bounce, Task Time) | Besucher-/Verhaltens-/Ziel-Kennzahlen | 2.1 |
| K2.2 | Methoden (Segmentierung, Funnel) | Segmente, Ziele, Funnel-Plugin, A/B | 3.1–3.6 |
| K2.3 | Muster & Trends | Zeitreihen, Zeiten/Wochentage | 2.2 |
| K2.4 | Korrelation vs. Kausalität | Segmentvergleich, A/B-Test, Reflexion | 2.3, 3.6 |
| K3.1 | Datenschutzgesetze (DSGVO/DSG) | Datenschutz-Einstellungen, Architektur | 4.1 |
| K3.2 | Ethik (Anonymisierung/Einwilligung) | IP-Anonymisierung, Consent/Opt-out | 4.2 |
| K3.3 | Sicherheitsmassnahmen | Datenaufbewahrung, DSAR, Rechte | 4.3 |
| K4.1 | Optimierungsmassnahmen | Funnel-/A-B-Erkenntnisse → Massnahme | 5.1 |
| K4.2 | Business-Kontext | E-Commerce-Umsatzbezug | 5.2 |
| K4.3 | User Journey visualisieren | Besucherprotokoll, Sankey-Funnel | 1.4, 3.3 |
| K4.4 | Priorisierung | Wirksamkeit/Aufwand/Nutzen abwägen | 5.2 |
| K4.5 | Relevante Infos & Legitimation | datenbasierte Begründung im Bericht | 5.2, 5.4 |
| K4.6 | Aufbereitung/Visualisierung | Dashboard-Widgets, Export (CSV/PDF), E-Mail-Berichte | 5.3 |

---

## Empfohlene Lernprogression (Kurzform)

1. **Grundlagen** (Stufe 0): Tool & Tracking verstehen, Ziele klären.
2. **Beobachten** (Stufe 1): Besucher- und Nutzungsverhalten beschreiben.
3. **Messen** (Stufe 2): KPIs ablesen und kritisch interpretieren.
4. **Vertiefen** (Stufe 3): Segmente, Ziele, Funnel, E-Commerce, A/B-Tests.
5. **Verantworten** (Stufe 4): Datenschutz & Ethik (begleitend, hier vertieft).
6. **Handeln** (Stufe 5): Empfehlungen priorisieren, Bericht erstellen.

> Quelle: Modulbeschreibung 392 „Nutzer-Daten mittels Analysetools auswerten", ICT-Berufsbildung
> Schweiz, Modulversion 1.0 (publiziert 22.01.2025).
