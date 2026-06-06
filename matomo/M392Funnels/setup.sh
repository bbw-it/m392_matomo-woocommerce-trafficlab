#!/bin/sh
# ---------------------------------------------------------------------------
# M392 Funnels – Matomo-Konfiguration (nachgebautes Funnel-Plugin, Teil "Daten")
#
# Legt die vier Trichter-Schritte als Matomo-Ziele an. Jeder Schritt ist ein
# URL-Ziel; die Anzahl Conversions pro Ziel = Besuche, die den Schritt erreicht
# haben. Daraus ergibt sich der Abbruch (Drop-off) von Schritt zu Schritt. Die
# hübsche Trichter-Visualisierung liefert das native Matomo-Plugin (siehe
# ./plugin/), diese Datei stellt nur die Ziel-Daten bereit. Idempotent.
#
# Schritte (klassischer E-Commerce-Funnel):
#   1) Produkt angesehen   url enthält  /product/
#   2) In den Warenkorb    url enthält  /cart/
#   3) Kasse               url-Regex    /checkout/$        (NUR die Kasse-Seite)
#   4) Kauf abgeschlossen  url enthält  /order-received
#
# Erwartet: BASE und TOKEN als Env.
# ---------------------------------------------------------------------------
set -eu
: "${BASE:?BASE fehlt}"; : "${TOKEN:?TOKEN fehlt}"
log() { echo "[m392-funnels] $*"; }

goals="$(curl -s "${BASE}/index.php?module=API&method=Goals.getGoals&idSite=1&format=json&token_auth=${TOKEN}" || true)"

add_goal() {  # $1=Name $2=patternType $3=pattern
  name="$1"; ptype="$2"; pattern="$3"
  if echo "$goals" | grep -qF "$name"; then
    log "Ziel '$name' bereits vorhanden."
    return
  fi
  log "Lege Ziel '$name' an ($ptype: $pattern) ..."
  curl -s -o /dev/null "${BASE}/index.php" \
    --data-urlencode "module=API" \
    --data-urlencode "method=Goals.addGoal" \
    --data-urlencode "idSite=1" \
    --data-urlencode "name=$name" \
    --data-urlencode "matchAttribute=url" \
    --data-urlencode "patternType=$ptype" \
    --data-urlencode "pattern=$pattern" \
    --data-urlencode "caseSensitive=0" \
    --data-urlencode "allowMultipleConversionsPerVisit=0" \
    --data-urlencode "description=M392 Funnel-Schritt" \
    --data-urlencode "token_auth=${TOKEN}" \
    --data-urlencode "format=json" \
    || log "WARN: Ziel '$name' konnte nicht angelegt werden."
}

add_goal "Funnel-1: Produkt angesehen" "contains" "/product/"
add_goal "Funnel-2: In den Warenkorb"  "contains" "/cart/"
add_goal "Funnel-3: Kasse"             "regex"    "/checkout/\$"
add_goal "Funnel-4: Kauf abgeschlossen" "contains" "/order-received"
