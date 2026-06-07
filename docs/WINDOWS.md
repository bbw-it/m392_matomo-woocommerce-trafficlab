# M392 Matomo Lab unter Windows – Schritt für Schritt

Das Projekt läuft in **Docker** und wird über ein **Bash-Skript** (`install.sh`) gestartet.
Unter Windows geht das am einfachsten über **WSL2** (Linux-in-Windows). Folge den Schritten genau –
einfach Befehle kopieren und einfügen.

> Zeitbedarf: ~20–40 Min (vor allem Downloads). Du brauchst **Internet** und **~3 GB** freien Platz.

---

## Schritt 1 – WSL2 installieren (einmalig)

1. **Start-Menü** → „PowerShell" tippen → Rechtsklick → **„Als Administrator ausführen"**.
2. Diesen Befehl eingeben und Enter:
   ```powershell
   wsl --install
   ```
3. **PC neu starten**, wenn er das verlangt.
4. Nach dem Neustart öffnet sich automatisch ein **Ubuntu**-Fenster. Beim ersten Mal:
   einen **Benutzernamen** und ein **Passwort** festlegen (Passwort merken – beim Tippen sieht man nichts).

> Falls kein Ubuntu-Fenster kommt: Start-Menü → **„Ubuntu"** suchen und öffnen.

---

## Schritt 2 – Docker Desktop installieren (einmalig)

1. Docker Desktop herunterladen: <https://www.docker.com/products/docker-desktop/> → **Download for Windows**.
2. Installieren, dabei **„Use WSL 2 based engine"** aktiviert lassen. Danach **Docker Desktop starten**.
3. In Docker Desktop: **⚙ Settings → Resources → WSL Integration** → bei **Ubuntu** den Schalter **einschalten** → **Apply & Restart**.
4. Warten, bis Docker Desktop oben links **„Engine running"** (grün) zeigt. **Docker Desktop muss laufen**, solange du das Lab nutzt.

---

## Schritt 3 – Projekt holen (im Ubuntu-Fenster)

Alles ab hier passiert im **Ubuntu-Terminal** (nicht in PowerShell).

1. Git bereitstellen (einmalig):
   ```bash
   sudo apt update && sudo apt install -y git
   ```
   (Passwort aus Schritt 1 eingeben, wenn gefragt.)
2. Ins Linux-Heimverzeichnis wechseln und Projekt klonen. **Wichtig:** hierher klonen, **nicht** nach
   `C:\…` – das ist deutlich schneller und vermeidet Fehler:
   ```bash
   cd ~
   git clone <REPO-URL>
   ```
   `<REPO-URL>` durch die URL eures Git-Repos ersetzen. Danach in den Projektordner:
   ```bash
   cd 392*Matomo*   # oder: cd <Ordnername des Projekts>
   ```

---

## Schritt 4 – Starten

1. Konfiguration anlegen (einmalig):
   ```bash
   cp .env.example .env
   ```
2. Lab einrichten und starten:
   ```bash
   bash install.sh
   ```
   Das lädt Images, baut alles auf und füllt Demo-Daten. Beim ersten Mal dauert es einige Minuten –
   der Balken zeigt den Fortschritt. Warten, bis **„Installation abgeschlossen"** erscheint.

> Alternative ohne Skript: `docker compose up -d` (richtet alles ein, nur ohne Fortschrittsanzeige).

---

## Schritt 5 – Öffnen

Im **Windows-Browser** (Edge/Chrome) aufrufen:

| Tool | Adresse | Login |
|---|---|---|
| Shop | <http://localhost:8090> | – |
| **Matomo** | <http://localhost:8091> | `admin` / `matomo123` |
| Datengenerierungstool | <http://localhost:8092> | – |

Fertig. 🎉

---

## Stoppen / wieder starten

```bash
docker compose stop     # anhalten (Daten bleiben)
docker compose start    # wieder hochfahren
```
Komplett neu aufsetzen (alle Daten löschen): `bash install.sh` erneut ausführen.

---

## Wenn etwas klemmt

| Problem | Lösung |
|---|---|
| `docker: command not found` oder `Cannot connect to the Docker daemon` | Docker Desktop **starten** und in **Settings → WSL Integration** Ubuntu **einschalten** (Schritt 2.3). |
| `bash install.sh` bricht mit `\r` / „bad interpreter" ab | Repo wurde unter Windows (`C:\…`) geklont. Stattdessen wie in Schritt 3 **in `~` (WSL)** klonen. |
| Port `8090/8091/8092` ist belegt | In `.env` die `*_PORT`-Werte auf freie Ports ändern, dann neu starten. |
| Sehr langsam | Projekt liegt unter `/mnt/c/…`. In `~` (WSL-Dateisystem) klonen (Schritt 3). |
| Seiten laden nicht sofort | Erststart dauert; warten bis `wp-init`/`matomo-init` fertig sind: `docker compose logs -f wp-init matomo-init`. |

Mehr Details: [`../README.md`](../README.md).
