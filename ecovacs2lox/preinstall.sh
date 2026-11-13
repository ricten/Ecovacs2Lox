#!/bin/bash
# $1 Temp-Name, $2 Plugin-Name, $3 Ordner, $4 Version, $5 LBHOMEDIR
# PHP-Pruefung: Das Plugin braucht PHP 7.4 oder neuer (Kommandozeile fuer den Dienst, Apache fuer die Weboberflaeche) mit curl und openssl.
# Exit 2 bricht die Installation bzw. das Update ab (Exit 1 wuerde nur warnen); die Meldung steht im Installationslog.
ECO_MODS="${ECO_APACHE_MODS:-/etc/apache2/mods-enabled}"
eco_phpbin="$(command -v php 2>/dev/null || echo /usr/bin/php)"
eco_id="$("$eco_phpbin" -r 'echo PHP_VERSION_ID;' 2>/dev/null)"
eco_ver="$("$eco_phpbin" -r 'echo PHP_VERSION;' 2>/dev/null)"
eco_fehler=""
if ! [ "${eco_id:-0}" -ge 70400 ] 2>/dev/null; then
  if [ -n "$eco_ver" ]; then
    eco_fehler="Auf der Kommandozeile laeuft PHP $eco_ver."
  else
    eco_fehler="Auf der Kommandozeile ist kein PHP verfuegbar."
  fi
fi
# die Erweiterungen curl (Cloud-Abfragen) und openssl (HTTPS) muessen geladen sein
if [ -n "$eco_ver" ]; then
  for eco_e in curl openssl; do
    "$eco_phpbin" -r "exit(extension_loaded('$eco_e') ? 0 : 1);" >/dev/null 2>&1 || eco_fehler="$eco_fehler Die PHP-Erweiterung $eco_e fehlt."
  done
fi
for eco_m in "$ECO_MODS"/php*.load; do
  [ -e "$eco_m" ] || continue
  eco_w="$(basename "$eco_m" .load)"
  eco_w="${eco_w#php}"
  case "$eco_w" in [0-9]*.[0-9]*) ;; *) continue ;; esac
  if [ $(( ${eco_w%%.*} * 100 + ${eco_w#*.} )) -lt 704 ]; then
    eco_fehler="$eco_fehler Der Webserver (Apache) nutzt PHP $eco_w."
  fi
done
if [ -n "$eco_fehler" ]; then
  echo "FEHLER: Dieses Plugin braucht PHP 7.4 oder neuer mit den Erweiterungen curl und openssl. $eco_fehler"
  echo "Das Plugin wurde NICHT installiert. Auf Debian 11 und 12 liefert LoxBerry 4.0 PHP 7.4, auf Debian 13 (Trixie) PHP 8.4; ältere Systeme (Debian 10) haben nur PHP 7.3."
  echo "Anleitung zum Upgrade der Debian-Version: https://wiki.loxberry.de/installation_von_loxberry/upgrade_distribution/start"
  exit 2
fi
exit 0
