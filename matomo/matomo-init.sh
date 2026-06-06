#!/bin/sh
# ---------------------------------------------------------------------------
# M392 Matomo Lab - Headless-Provisionierung von Matomo 5.x
#
# Dieses Skript laeuft einmalig im Service "matomo-init" (curlimages/curl) und
# treibt den Web-Installer von Matomo per curl + Cookie-Jar durch. Anschliessend
# wird die Konfiguration gehaertet (enable_trusted_host_check=0) und ein
# app-spezifischer API-Token fuer den Admin in ein geteiltes Volume geschrieben,
# damit der Traffic-Generator (Task 7) historische Daten nachfuellen kann.
#
# Das Skript ist IDEMPOTENT: ist Matomo bereits installiert, wird die
# Installation uebersprungen und nur Token + Trusted-Host-Einstellung
# sichergestellt.
# ---------------------------------------------------------------------------
set -eu

BASE="http://matomo"
JAR="$(mktemp)"
TOKEN_DIR="/token"
TOKEN_FILE="${TOKEN_DIR}/token_auth"
CFG="/var/www/html/config/config.ini.php"

log() { echo "[matomo-init] $*"; }

# --- Auf Matomo warten -----------------------------------------------------
log "Warte auf Matomo unter ${BASE} ..."
i=0
until curl -s -o /dev/null -w '%{http_code}' "${BASE}/" | grep -qE '^(200|30[0-9])$'; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    log "FEHLER: Matomo wurde nicht erreichbar."
    exit 1
  fi
  sleep 3
done
log "Matomo ist erreichbar."

# --- Pruefen, ob bereits installiert ---------------------------------------
# Ist die Installation abgeschlossen, liefert der Aufruf des Installer-Moduls
# keine Installer-Seite mehr (Matomo leitet auf Login / verweigert den Zugriff).
already_installed=0
if [ -f "$CFG" ] && grep -q '^\[PluginsInstalled\]' "$CFG" 2>/dev/null; then
  # config.ini.php mit installierten Plugins vorhanden -> Installation fertig.
  already_installed=1
elif ! curl -s "${BASE}/index.php?module=Installation&action=welcome" \
        | grep -qiE 'welcome|willkommen|systemCheck'; then
  already_installed=1
fi

if [ "$already_installed" -eq 1 ]; then
  log "Matomo ist bereits installiert - ueberspringe Installation."
else
  log "Starte headless Installation des Matomo-Web-Installers ..."

  # Schritt 1: Welcome (setzt Session-Cookie)
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=welcome" >/dev/null

  # Schritt 2: System-Check
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=systemCheck" >/dev/null

  # Schritt 3: Datenbank-Setup. Das Formular ist aus den ENV-Variablen
  # vorbefuellt, das Passwort wird aber maskiert angezeigt - daher das echte
  # Passwort uebergeben. Matomo 5.x verlangt zusaetzlich das Feld "schema".
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=databaseSetup" \
    --data-urlencode "type=InnoDB" \
    --data-urlencode "host=db" \
    --data-urlencode "username=${MATOMO_DB_USER}" \
    --data-urlencode "password=${MATOMO_DB_PASSWORD}" \
    --data-urlencode "dbname=${MATOMO_DB_NAME}" \
    --data-urlencode "tables_prefix=matomo_" \
    --data-urlencode "adapter=PDO\\MYSQL" \
    --data-urlencode "schema=Mariadb" \
    --data-urlencode "submit=Next »" >/dev/null

  # Schritt 4: Tabellen anlegen
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=tablesCreation" >/dev/null

  # Schritt 5: Superuser anlegen
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=setupSuperUser" \
    --data-urlencode "login=${MATOMO_ADMIN_USER}" \
    --data-urlencode "password=${MATOMO_ADMIN_PASSWORD}" \
    --data-urlencode "password_bis=${MATOMO_ADMIN_PASSWORD}" \
    --data-urlencode "email=${MATOMO_ADMIN_EMAIL}" \
    --data-urlencode "subscribe_newsletter_piwikorg=0" \
    --data-urlencode "subscribe_newsletter_professionalservices=0" \
    --data-urlencode "submit=Next »" >/dev/null

  # Schritt 6: Erste Website (E-Commerce aktiviert)
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=firstWebsiteSetup" \
    --data-urlencode "siteName=Demo-Shop M392" \
    --data-urlencode "url=http://localhost:${WORDPRESS_PORT}" \
    --data-urlencode "timezone=Europe/Zurich" \
    --data-urlencode "ecommerce=1" \
    --data-urlencode "submit=Next »" >/dev/null

  # Schritt 7: Installation abschliessen. (trackingCode-Schritt wird nicht
  # benoetigt und kann je nach Umgebung 500 liefern - daher uebersprungen.)
  curl -s -c "$JAR" -b "$JAR" \
    "${BASE}/index.php?module=Installation&action=finished" \
    --data-urlencode "setup_geoip2=0" \
    --data-urlencode "anonymise_ip=0" \
    --data-urlencode "submit=Continue to Matomo »" >/dev/null

  # Verifizieren, dass die Installation tatsaechlich abgeschlossen ist.
  if [ ! -f "$CFG" ] || ! grep -q '^\[PluginsInstalled\]' "$CFG" 2>/dev/null; then
    log "FEHLER: Installation scheint fehlgeschlagen (keine vollstaendige config.ini.php)."
    exit 1
  fi
  log "Installation abgeschlossen."
fi

# --- Konfiguration haerten: trusted-host-Check deaktivieren -----------------
# Damit funktionieren sowohl http://localhost:8091 (Host) als auch das interne
# http://matomo (Container-Netz) ohne "Invalid Host"-Fehler.
if [ -f "$CFG" ]; then
  if ! grep -q 'enable_trusted_host_check' "$CFG"; then
    # In den bestehenden [General]-Abschnitt direkt nach der Sektion einfuegen.
    if grep -q '^\[General\]' "$CFG"; then
      tmp="$(mktemp)"
      awk '
        { print }
        /^\[General\]/ && !done { print "enable_trusted_host_check = 0"; done=1 }
      ' "$CFG" > "$tmp" && cat "$tmp" > "$CFG" && rm -f "$tmp"
    else
      printf '\n[General]\nenable_trusted_host_check = 0\n' >> "$CFG"
    fi
    log "enable_trusted_host_check=0 gesetzt."
  else
    log "enable_trusted_host_check bereits konfiguriert."
  fi
else
  log "WARN: config.ini.php nicht gefunden - kann Trusted-Host-Check nicht setzen."
fi

# --- App-spezifischen API-Token erzeugen und speichern ---------------------
mkdir -p "$TOKEN_DIR"

need_token=1
if [ -f "$TOKEN_FILE" ]; then
  existing="$(cat "$TOKEN_FILE" 2>/dev/null || true)"
  # Plausibilitaet: 32-stelliger Hex-Token? Dann gegen die API verifizieren.
  if echo "$existing" | grep -qE '^[a-f0-9]{32}$'; then
    check="$(curl -s "${BASE}/index.php?module=API&method=SitesManager.getAllSites&format=json&token_auth=${existing}" || true)"
    if echo "$check" | grep -q '"idsite"'; then
      log "Gueltiger API-Token bereits vorhanden - behalte ihn."
      need_token=0
    fi
  fi
fi

if [ "$need_token" -eq 1 ]; then
  log "Erzeuge app-spezifischen API-Token ..."
  TOKEN="$(curl -s "${BASE}/index.php" \
    --data-urlencode "module=API" \
    --data-urlencode "method=UsersManager.createAppSpecificTokenAuth" \
    --data-urlencode "userLogin=${MATOMO_ADMIN_USER}" \
    --data-urlencode "passwordConfirmation=${MATOMO_ADMIN_PASSWORD}" \
    --data-urlencode "description=m392-traffic-generator" \
    --data-urlencode "format=json" \
    | sed -n 's/.*"value":"\([a-f0-9]*\)".*/\1/p')"

  if echo "${TOKEN:-}" | grep -qE '^[a-f0-9]{32}$'; then
    printf '%s' "$TOKEN" > "$TOKEN_FILE"
    log "API-Token gespeichert unter ${TOKEN_FILE}."
  else
    log "FEHLER: Kein gueltiger API-Token erhalten."
    exit 1
  fi
fi

# --- Website: Waehrung EUR + E-Commerce + On-Site-Suche aktivieren ----------
# SitesManager.updateSite laesst nicht uebergebene Site-Einstellungen
# unveraendert; diese Werte mehrfach zu setzen ist harmlos (idempotent).
# siteSearch=1 sorgt dafuer, dass Matomo die Site-Search-Reports verarbeitet.
TOKEN="$(cat "${TOKEN_FILE}")"
if [ -n "${TOKEN:-}" ]; then
  log "Setze Waehrung (EUR), E-Commerce und On-Site-Suche der Website ..."
  curl -s -o /dev/null "${BASE}/index.php" \
    --data-urlencode "module=API" \
    --data-urlencode "method=SitesManager.updateSite" \
    --data-urlencode "idSite=1" \
    --data-urlencode "currency=EUR" \
    --data-urlencode "ecommerce=1" \
    --data-urlencode "siteSearch=1" \
    --data-urlencode "token_auth=${TOKEN}" \
    --data-urlencode "format=json" || echo "[matomo-init] WARN: Site-Einstellungen konnten nicht gesetzt werden."
else
  log "WARN: Kein gueltiger Token vorhanden - ueberspringe Site-Einstellungen."
fi

# --- Ziel: PDF-Download (INCI-Leitfaden) -----------------------------------
# Loest aus, wenn eine Datei mit "inci" im Namen heruntergeladen wird (Matomo
# trackt PDF-Downloads automatisch via enableLinkTracking). Idempotent.
if [ -n "${TOKEN:-}" ]; then
  goals="$(curl -s "${BASE}/index.php?module=API&method=Goals.getGoals&idSite=1&format=json&token_auth=${TOKEN}" || true)"
  if echo "$goals" | grep -qi 'inci'; then
    log "Ziel 'PDF-Download INCI' bereits vorhanden."
  else
    log "Lege Ziel 'PDF-Download: INCI-Leitfaden' an (Datei-Download enthaelt 'inci') ..."
    curl -s -o /dev/null "${BASE}/index.php" \
      --data-urlencode "module=API" \
      --data-urlencode "method=Goals.addGoal" \
      --data-urlencode "idSite=1" \
      --data-urlencode "name=PDF-Download: INCI-Leitfaden" \
      --data-urlencode "matchAttribute=file" \
      --data-urlencode "patternType=contains" \
      --data-urlencode "pattern=inci" \
      --data-urlencode "caseSensitive=0" \
      --data-urlencode "allowMultipleConversionsPerVisit=1" \
      --data-urlencode "description=Download des INCI-Leitfaden-PDFs auf der Blog-Seite" \
      --data-urlencode "token_auth=${TOKEN}" \
      --data-urlencode "format=json" || echo "[matomo-init] WARN: Ziel konnte nicht angelegt werden."
  fi
fi

# --- Ziel: Kontaktanfrage (Danke-Seite) ------------------------------------
# Loest aus, wenn die Bestaetigungsseite /danke/ aufgerufen wird (WPForms leitet
# nach dem Absenden des Kontaktformulars dorthin weiter). Idempotent.
if [ -n "${TOKEN:-}" ]; then
  goals="$(curl -s "${BASE}/index.php?module=API&method=Goals.getGoals&idSite=1&format=json&token_auth=${TOKEN}" || true)"
  if echo "$goals" | grep -qi '/danke'; then
    log "Ziel 'Kontaktanfrage (Danke-Seite)' bereits vorhanden."
  else
    log "Lege Ziel 'Kontaktanfrage (Danke-Seite)' an (URL enthaelt '/danke') ..."
    curl -s -o /dev/null "${BASE}/index.php" \
      --data-urlencode "module=API" \
      --data-urlencode "method=Goals.addGoal" \
      --data-urlencode "idSite=1" \
      --data-urlencode "name=Kontaktanfrage (Danke-Seite)" \
      --data-urlencode "matchAttribute=url" \
      --data-urlencode "patternType=contains" \
      --data-urlencode "pattern=/danke" \
      --data-urlencode "caseSensitive=0" \
      --data-urlencode "allowMultipleConversionsPerVisit=0" \
      --data-urlencode "description=Absenden des Kontaktformulars (Weiterleitung auf /danke/)" \
      --data-urlencode "token_auth=${TOKEN}" \
      --data-urlencode "format=json" || echo "[matomo-init] WARN: Ziel konnte nicht angelegt werden."
  fi
fi

# --- M392-Plugins: native Matomo-Plugins aktivieren ------------------------
# Die Plugin-Ordner sind via docker-compose nach plugins/M392Funnels bzw.
# plugins/M392ABTesting gemountet. Aktivierung über config.ini.php (kein PHP im
# curl-Container). Die [Plugins]-Eintraege sind ADDITIV zu den Default-Plugins aus
# global.ini.php – fehlt die Sektion (frisches Matomo), wird sie angelegt. Idempotent.
ini_add() {  # $1=Sektion (Plugins|PluginsInstalled)  $2=Plugin-Name
  section="$1"; name="$2"
  grep -q "${section}\[\] = \"$name\"" "$CFG" 2>/dev/null && return
  if grep -q "^\[${section}\]" "$CFG" 2>/dev/null; then
    tmp="$(mktemp)"
    awk -v s="[${section}]" -v line="${section}[] = \"$name\"" \
      '{ print } $0==s && !d { print line; d=1 }' "$CFG" > "$tmp" \
      && cat "$tmp" > "$CFG" && rm -f "$tmp"
  else
    printf '\n[%s]\n%s[] = "%s"\n' "$section" "$section" "$name" >> "$CFG"
  fi
}
if [ -f "$CFG" ]; then
  for name in M392Funnels M392ABTesting; do
    ini_add "PluginsInstalled" "$name"
    ini_add "Plugins" "$name"
    log "Plugin $name aktiviert (config.ini)."
  done
fi

# --- M392-Plugins: Daten-Grundlage (A/B Custom Dimension + Funnel-Ziele) ----
# Setup-Skripte in matomo/M392ABTesting/ und matomo/M392Funnels/ (nach
# /matomo-src gemountet). Idempotent.
if [ -n "${TOKEN:-}" ]; then
  for setup in /matomo-src/M392ABTesting/setup.sh /matomo-src/M392Funnels/setup.sh; do
    if [ -f "$setup" ]; then
      BASE="$BASE" TOKEN="$TOKEN" sh "$setup" || log "WARN: $setup fehlgeschlagen."
    fi
  done
fi

log "Fertig."
