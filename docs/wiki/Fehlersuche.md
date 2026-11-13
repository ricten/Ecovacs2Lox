# Fehlersuche

Zuerst im Plugin den Reiter **Test** → *Selbstprüfung* und die **Logdatei** ansehen. Den Loglevel stellt die LoxBerry-Plugin-Verwaltung ein. In Logs und Meldungen stehen nie das Passwort oder der Zugriffstoken.

| Symptom | Ursache / Lösung |
|---|---|
| Installation bricht ab: „Dieses Plugin braucht PHP 7.4 oder neuer …“ | Auf dem LoxBerry läuft ein älteres PHP (unter 7.4) oder curl/openssl fehlen. Debian-Upgrade siehe [Anleitung im LoxBerry-Wiki](https://wiki.loxberry.de/installation_von_loxberry/upgrade_distribution/start) |
| „Verifizierung nötig“ | Ecovacs verlangt einmal einen E-Mail-Code (Reiter Konto): Code anfordern, Code eintragen. Kommt keine E-Mail, Spam-Ordner prüfen und die Adresse des Kontos kontrollieren |
| „Der Code ist ungültig oder abgelaufen“ | Neuen Code anfordern und den **neuesten** eintragen (jede Anforderung macht den alten ungültig) |
| „Konto oder Passwort sind falsch“ | Eingaben prüfen, speichern, dann „Anmeldung erneut versuchen“. Der Dienst wiederholt das nicht von selbst, damit Ecovacs das Konto nicht sperrt. Das Land muss zum Konto passen |
| „Anmeldung: Zeitüberschreitung / Name … lässt sich nicht auflösen“ | Der LoxBerry hat keinen Internetzugang oder DNS-Problem; der Dienst versucht es mit wachsendem Abstand weiter |
| „Fehlercode …: …“ bei der Anmeldung | Ecovacs hat die Schnittstelle geändert oder meldet etwas Neues. Issue im Repository anlegen (Fehlercode nennen) |
| Keine Roboter im Reiter Geräte | Erst anmelden. Roboter, die nur in einem anderen Land oder Konto registriert sind, erscheinen nicht |
| Roboter „nicht erreichbar (ausgeschaltet oder ohne WLAN)“ | Kein Fehler (`online` = 0, `status/ok` bleibt 1). Roboter einschalten bzw. WLAN prüfen |
| „Das Protokoll des Roboters wurde nicht erkannt“ | Weder XML noch JSON antwortet. Im Reiter Geräte das Protokoll fest einstellen oder Issue anlegen (Modell nennen); Modelle mit XMPP (`eco-legacy`) werden nicht unterstützt |
| „Der Roboter antwortet seit über 15 Minuten nicht“ | Die Cloud erreicht den Roboter nicht. Roboter neu starten, in der Ecovacs-App prüfen, ob er dort online ist |
| Befehl wird mit „abgelehnt: Befehle sind gesperrt …“ quittiert | Im Reiter Geräte die Stufe freigeben |
| Befehl „abgelehnt: Roboter antwortet nicht“ | Cloud-Timeout (Fehler 500); der Roboter ist erreichbar, kennt den Befehl aber nicht oder schläft |
| Keine Werte in Loxone, aber in MQTT | Gateway V2: Abo `ecovacs/#` von Hand eintragen (Reiter MQTT prüft es) |
| „MQTT der Ecovacs-Cloud“ im Reiter MQTT getrennt | Die Push-Meldungen fehlen, die Abfragen per HTTPS laufen weiter (alle 2 Minuten, bei Reinigung alle 20 Sekunden). Meist ein Netzwerkproblem zum Server `mq-eu.ecouser.net:8883` (Firewall?) |
| Einzelne Werte fehlen (z. B. Wasserstufe) | Das Modell liefert sie nicht. Abfragen, die ein Roboter nicht kennt, laufen in einen Timeout von 20 Sekunden; das Plugin merkt sich das nach zwei Fehlschlägen und fragt nicht mehr |
| In `start-error.log` steht einmal „log_db_queryid: Could not find database entry“ | Harmlos: Beim allerersten Start kennt die LoxBerry-Logbibliothek die neue Logdatei noch nicht |

Neuere Modelle (JSON): Das Protokoll ist ungeprüft. Wenn etwas nicht klappt, bitte ein Issue mit Modell, Logauszug (Loglevel *Debug*, ohne Zugangsdaten) und der Angabe, was die Ecovacs-App anzeigt.
