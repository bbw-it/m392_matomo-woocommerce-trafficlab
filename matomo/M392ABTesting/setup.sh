#!/bin/sh
# ---------------------------------------------------------------------------
# M392 A/B-Testing – Matomo-Konfiguration (nachgebautes A/B-Plugin, Teil "Daten")
#
# Legt eine besuchsbezogene Custom Dimension "AB-Variante" an. Der Shop sendet
# je Besuch die zugewiesene Variante (A/B) in dieser Dimension; Matomo erzeugt
# daraus automatisch einen Bericht, der A vs. B inkl. E-Commerce-Conversion und
# Umsatz vergleicht. Die hübsche Vergleichs-/Trichter-Oberfläche liefert das
# native Matomo-Plugin (siehe ./plugin/), diese Datei stellt nur die Dimension
# bereit. Idempotent.
#
# Erwartet: BASE (z. B. http://matomo) und TOKEN (Matomo API-Token) als Env.
# ---------------------------------------------------------------------------
set -eu
: "${BASE:?BASE fehlt}"; : "${TOKEN:?TOKEN fehlt}"
log() { echo "[m392-abtesting] $*"; }

# Schon konfiguriert? (Name "AB-Variante" in den Dimensionen vorhanden)
existing="$(curl -s "${BASE}/index.php?module=API&method=CustomDimensions.getConfiguredCustomDimensions&idSite=1&format=json&token_auth=${TOKEN}" || true)"
if echo "$existing" | grep -q '"name":"AB-Variante"'; then
  log "Custom Dimension 'AB-Variante' bereits vorhanden."
else
  log "Lege Custom Dimension 'AB-Variante' (Scope: Besuch) an ..."
  curl -s -o /dev/null "${BASE}/index.php" \
    --data-urlencode "module=API" \
    --data-urlencode "method=CustomDimensions.configureNewCustomDimension" \
    --data-urlencode "idSite=1" \
    --data-urlencode "name=AB-Variante" \
    --data-urlencode "scope=visit" \
    --data-urlencode "active=1" \
    --data-urlencode "token_auth=${TOKEN}" \
    --data-urlencode "format=json" \
    || log "WARN: Custom Dimension konnte nicht angelegt werden."
fi

# Absichern: Shop-Tracker (m392-ab-test.php) und Generator senden die A/B-Variante
# hartcodiert in 'dimension1'. Prüfen, dass Matomo der Dimension "AB-Variante"
# wirklich Index 1 zugewiesen hat – sonst landeten die A/B-Daten in der falschen
# (oder keiner) Dimension. Auf frischem Matomo ist es die erste Visit-Dimension → 1.
config="$(curl -s "${BASE}/index.php?module=API&method=CustomDimensions.getConfiguredCustomDimensions&idSite=1&format=json&token_auth=${TOKEN}" || true)"
idx="$(printf '%s' "$config" | sed 's/.*"name":"AB-Variante"//' | grep -o '"index":"[0-9]*"' | head -1 | grep -o '[0-9]*' || true)"
if [ -z "$idx" ]; then
  log "WARN: Index von 'AB-Variante' nicht ermittelbar – dimension1 nicht verifiziert."
elif [ "$idx" != "1" ]; then
  log "FEHLER: 'AB-Variante' hat Index ${idx}, erwartet 1 – Tracker/Generator senden"
  log "        aber hartcodiert 'dimension1'. A/B-Daten würden falsch zugeordnet. Abbruch."
  exit 9   # distinkter Fatal-Code: matomo-init.sh bricht NUR hierbei ab (sonst WARN)
else
  log "Dimension 'AB-Variante' verifiziert (Index 1, passend zu 'dimension1')."
fi
