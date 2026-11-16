# Ecovacs2Lox

LoxBerry-Plugin für **Ecovacs-Deebot-Saugroboter**. Es meldet sich wie die Ecovacs-App bei der Ecovacs-Cloud an, liest Zustand und Messwerte der Roboter und stellt sie als **MQTT-Topics** auf dem MQTT-Broker des LoxBerry bereit. Das LoxBerry-MQTT-Gateway macht daraus **virtuelle Eingänge** für den Loxone Miniserver. In der Gegenrichtung nimmt das Plugin **Befehle** aus Loxone an (Start, Stopp, Pause, zurück zur Station, Saug- und Wasserstufe, Ton).

Ersetzt den ioBroker-Adapter `ecovacs-deebot`. Spezifikation und Protokollbefunde: [SPEZIFIKATION.md](SPEZIFIKATION.md).

**Version 0.1.1 (Beta).** Läuft mit PHP 7.4, 8.2, 8.3 und 8.4 (LoxBerry 4.0 oder neuer).

```
Roboter  <->  Ecovacs-Cloud  <->  Ecovacs2Lox (LoxBerry)  ->  MQTT-Broker  ->  MQTT-Gateway  ->  Miniserver
```

## Stand: was geprüft ist und was nicht

| Teil | Stand |
|---|---|
| Anmeldung, E-Mail-Verifizierung, Geräteliste, Token-Erneuerung | an einem echten Konto geprüft |
| **Ältere Modelle** (Protokoll XML: Deebot 500/600/700/900 u. a.) | **am Deebot 605 geprüft**: Abfragen, Antworten, Push-Meldungen, alle Werte |
| Steuerbefehle für ältere Modelle | **am Deebot 605 geprüft** (2026-11-13): `finden`, `start`, `pause`, `weiter`, `stopp`, `laden`, `saugstufe`; `wasserstufe` nicht (der 605 hatte keinen Wassertank eingesetzt). Der 605 fährt nach `pause` nach wenigen Sekunden von selbst zur Station, `weiter` muss deshalb sofort danach kommen |
| **Neuere Modelle** (Protokoll JSON: OZMO 920/950, T-, N-, X-Reihen) | nach der Protokollbeschreibung gebaut, **ungeprüft** (kein Testgerät). Rückmeldungen sind willkommen |
| Tests | 270 automatische Prüfungen gegen eine Fake-Cloud, unter PHP 7.4, 8.2, 8.3 und 8.4 |

Es gibt **keine lokale Schnittstelle** der Roboter: Plugin und Roboter reden nur über die Ecovacs-Cloud. Ohne Internet geht nichts. Es ist eine **inoffizielle** Schnittstelle (die der Ecovacs-App); Ecovacs kann sie ändern oder sperren. Das Plugin ist nicht von Ecovacs.

## Funktionen

- **Alle Roboter des Kontos** (bis zu fünf), je Roboter Name im Topic, Protokoll („automatisch erkennen“ oder fest) und Befehlsfreigabe.
- **Werte:** Status als Klartext, Reinigungsart, Akku, Ladezustand, Saug- und Wasserstufe, Wassertank, Ruhezustand, Fläche und Dauer der Reinigung, Summen, Verschleißteile (Hauptbürste, Seitenbürste, Filter), Fehler mit Klartext.
- **Push und Abfrage:** Der Roboter meldet Änderungen selbst (MQTT der Cloud); zusätzlich fragt das Plugin per HTTPS ab (alle 20 s bei Reinigung, sonst alle 2 Minuten, alle Werte alle 5 Minuten einstellbar).
- **Befehle aus Loxone** (ab Werk gesperrt, mit Quittung): `start` (auto, kante, spot), `stopp`, `pause`, `weiter`, `laden`, `saugstufe`, `wasserstufe`, `finden`; in drei Stufen je Roboter freizugeben.
- Veröffentlicht nur **Änderungen** (alle 5 Minuten alle Werte). Lebenszeichen `ecovacs/status/ok`, `zaehler`, `ts`, Autostart, Watchdog, Healthcheck, Prüfung des Gateway-Abos.
- Weboberfläche im LoxBerry Design System, **ohne JavaScript**.

## Installation

1. ZIP von der Release-Seite laden (oder im LoxBerry-Shop installieren), in der **Plugin-Verwaltung** installieren. Voraussetzung: LoxBerry 4.0 mit PHP 7.4 oder neuer, mit curl und openssl.
2. Plugin öffnen, Reiter **Konto**: E-Mail, Passwort und Land des Ecovacs-Kontos eintragen, speichern. Ein zweites Konto, mit dem der Roboter geteilt ist, ist sicherer als das Hauptkonto.
3. Beim ersten Mal verlangt Ecovacs eine **Verifizierung per E-Mail-Code**: im Reiter Konto „Code per E-Mail anfordern“, den Code aus der E-Mail eintragen, „Code bestätigen“. Das ist einmalig.
4. Reiter **Geräte**: Roboter aktivieren, Namen vergeben, bei Bedarf Befehle freigeben, speichern.
5. Im **MQTT Gateway** das Abo `ecovacs/#` von Hand eintragen (Gateway V2 liest die Abo-Dateien der Plugins nicht; das Plugin prüft es im Reiter MQTT). `ecovacs/status/ok` und `ecovacs/status/zaehler` im Gateway auf *No Cache* stellen.

## Topics

Schema `ecovacs/<name>/<wert>`, Zahlen mit Punkt ohne Einheit, Zustände 0/1, nicht retained. Die vollständige Liste mit Eingangsnamen steht im Plugin im Reiter „Einbindung in Loxone“ und in [docs/wiki/MQTT-Topics.md](docs/wiki/MQTT-Topics.md).

## Befehle

Virtueller Ausgang in Loxone: Adresse `/dev/udp/<LoxBerry-IP>/<UDP-Port>` (Standard 11884), Befehl bei EIN `publish ecovacs/befehl/<name>/<befehl> <wert>`. Quittung unter `…/quittung`: `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`. Der Roboter fährt bei `start` los.

## Sicherheit und Datenschutz

- Das Passwort liegt in der Konfigurationsdatei des Plugins (Rechte 0600), wird nie angezeigt, geloggt oder per MQTT gesendet und geht nur als MD5-Hash an Ecovacs. Der Zugriffstoken liegt im Datenordner (0600).
- Anmeldung und Befehle gehen per HTTPS mit geprüftem Zertifikat. Der **MQTT-Server der Ecovacs-Cloud** nutzt ein Zertifikat einer eigenen Ecovacs-Zertifizierungsstelle, das sich mit öffentlichen Zertifikatsspeichern nicht prüfen lässt; die Verbindung ist verschlüsselt, der Server aber nicht beglaubigt.
- Ab Werk gesperrte Befehle, höchstens einer je Sekunde und Roboter.

## Entwicklung

- Tests: `php tests/run.php` (Unit, Ende-zu-Ende gegen Fake-Cloud und Fake-Broker, Healthcheck, Weboberfläche, Build) und `bash tests/test_phpcheck.sh`. Die Tests brauchen die PHP-Erweiterungen openssl und curl.
- Build: `bash build.sh` erzeugt `ecovacs2lox-<version>.zip` und `ecovacs2lox.zip` (ohne Version).
- Gemeinsamer Code (`Util`, `Logger`, `MqttClient`, `GatewayInfo`) kommt aus `../common/php` und wird vom Build ins ZIP kopiert.
- Der Code läuft ab PHP 7.4: keine PHP-8-Syntax (kein `match`, keine Konstruktor-Promotion, keine Union-Typen …).

## Lizenz und Hinweise

MIT-Lizenz (siehe [LICENSE](LICENSE)). Das Plugin wurde als eigene PHP-Umsetzung geschrieben; Protokoll und Datenfelder stammen aus der Beobachtung der Cloud-Schnittstelle und aus dem öffentlichen Wissen der Projekte `ecovacs-deebot.js` und `ioBroker.ecovacs-deebot` (nur als Vorlage für das Protokoll, es wurde kein Code übernommen). Ich stehe in keiner Verbindung zu Ecovacs Robotics Co., Ltd.
