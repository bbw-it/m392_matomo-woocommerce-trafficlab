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
