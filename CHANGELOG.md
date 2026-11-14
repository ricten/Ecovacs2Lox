# Changelog

Alle Änderungen an diesem Plugin. Schema: MAJOR.MINOR.PATCH.

## [0.1.1] - 2026-11-13

### Behoben
- Speichern im Reiter „Geräte“ mit leerem Feld „Name im Topic“ setzte den Namen fest auf `roboter`, statt den Namen aus der Ecovacs-App zu behalten. Dadurch wechselten die Topics (`ecovacs/roboter/…` statt `ecovacs/<Name aus der App>/…`) und bereits angelegte Gateway-Abos und Miniserver-Eingänge blieben ohne Werte. Ein leeres Feld bleibt jetzt leer, es gilt der Name aus der App. **Wer den Namen schon auf `roboter` gespeichert hat:** im Reiter „Geräte“ das Feld leeren (oder den gewünschten Namen eintragen) und speichern.

## [0.1.0] - 2026-11-13

Erste Version (Beta). Ersetzt den ioBroker-Adapter ecovacs-deebot.

### Neu
- Anmeldung bei der Ecovacs-Cloud (E-Mail, Passwort, Land) mit **Geräteverifizierung per E-Mail-Code**, die das Plugin in der Weboberfläche abwickelt; der Zugriffstoken wird gespeichert, rechtzeitig erneuert und übersteht Neustarts und Updates.
- Alle Roboter des Kontos (bis zu fünf), je Roboter Name im Topic, Protokoll und Befehlsfreigabe.
- **Ältere Modelle (Protokoll XML**, z. B. Deebot 500/600/700/900): Status, Akku, Reinigungsart, Ladezustand, Saug- und Wasserstufe, Wassertank, Ruhezustand, Summen, Verschleißteile, Fehler. **Am Deebot 605 geprüft** (Anmeldung, Abfragen, Push-Meldungen und die Steuerbefehle finden, start, pause, weiter, stopp, laden, saugstufe; `wasserstufe` nicht, der Roboter hatte keinen Wassertank eingesetzt). Der 605 fährt nach `pause` nach wenigen Sekunden von selbst zur Station: `weiter` wirkt nur, wenn es sofort nach der Pause kommt.
- **Neuere Modelle (Protokoll JSON**, OZMO 920/950, T-, N- und X-Reihen, mit und ohne V2-Befehle): nach der Protokollbeschreibung gebaut, **ungeprüft** (kein Testgerät). Das Protokoll wird automatisch erkannt und gemerkt.
- Push-Meldungen der Roboter über den MQTT-Server der Cloud, dazu Abfragen per HTTPS (alle 20 s während der Reinigung, sonst alle 2 Minuten, alle Werte alle 5 Minuten einstellbar).
- Befehle aus Loxone (ab Werk gesperrt, mit Quittung): start (auto, kante, spot), stopp, pause, weiter, laden, saugstufe, wasserstufe, finden; in den drei Stufen „steuern“, „einstellen“, „finden“ je Roboter freizugeben. Die Steuerbefehle sind am Deebot 605 geprüft (siehe oben), an neueren Modellen nicht.
- Lebenszeichen `ecovacs/status/ok|zaehler|ts`, Healthcheck, Gateway-Abo-Prüfung, Weboberfläche im LoxBerry Design System (ohne JavaScript), PHP 7.4 bis 8.4.
- Abfragen, die ein Roboter nicht kennt (laufen in einen Timeout von 20 s), werden nach zwei Fehlschlägen nicht mehr gestellt.

### Hinweise
- Inoffizielle Schnittstelle der Ecovacs-App: Ecovacs kann sie ändern oder sperren.
- Der MQTT-Server der Cloud nutzt ein Zertifikat einer eigenen Ecovacs-Zertifizierungsstelle; es wird nicht geprüft (Anmeldung und Befehle gehen per HTTPS mit geprüftem Zertifikat).
- Nicht enthalten: Karten, Räume und Zonen, Zeitpläne, Luftreiniger, Modelle mit XMPP (eco-legacy).
