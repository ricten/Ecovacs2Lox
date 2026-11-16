# Entwurf für den Beitrag im LoxForum (Rubrik LoxBerry → Plugins)

**Titel:** [LoxBerry-Plugin] Ecovacs2Lox – Ecovacs-Deebot-Saugroboter per MQTT an den Miniserver (Beta, Tester gesucht)

---

Hallo zusammen,

ich habe ein LoxBerry-Plugin für **Ecovacs-Deebot-Saugroboter** geschrieben, als Ersatz für den ioBroker-Adapter `ecovacs-deebot`. Es meldet sich wie die Ecovacs-App bei der Ecovacs-Cloud an, liest die Roboter des Kontos und stellt Status, Akku, Reinigungsart, Saug- und Wasserstufe, Verschleißteile, Fehler und mehr als **MQTT-Topics** bereit. Über das MQTT-Gateway landen sie als virtuelle Eingänge im Miniserver. Dazu gibt es **Befehle aus Loxone** (Start, Stopp, Pause, zurück zur Station, Saug- und Wasserstufe, Ton), ab Werk gesperrt und mit Quittung.

**Wichtig vorab:** Es gibt keine lokale Schnittstelle der Roboter, alles läuft über die Ecovacs-Cloud. Das ist die inoffizielle Schnittstelle der Ecovacs-App; Ecovacs kann sie ändern oder sperren. Das Plugin ist nicht von Ecovacs.

**Stand: Beta, und ich suche Tester.**

- **Ältere Modelle** (Deebot 500/600/700/900 u. a., altes XML-Protokoll): am **Deebot 605** geprüft: Anmeldung, Abfragen, Push-Meldungen, alle Werte und die Steuerbefehle (Start, Pause, Weiter, Stopp, zurück zur Station, Saugstufe, Ton) funktionieren. Nur die Wasserstufe ist nicht getestet (kein Tank eingesetzt). Der 605 fährt nach „Pause“ nach wenigen Sekunden von selbst zur Station, „Weiter“ wirkt nur direkt danach.
- **Neuere Modelle** (OZMO 920/950, T-, N-, X-Reihen, neues JSON-Protokoll): nach der Protokollbeschreibung gebaut, aber **ungeprüft**, weil ich keinen solchen Roboter habe. Wenn ihr einen habt und testen mögt, wäre ich sehr dankbar für eine Rückmeldung (Modell, was geht, was nicht, Logauszug ohne Zugangsdaten).

**Was es kann**

- Alle Roboter des Kontos (bis zu fünf), je Roboter Name, Protokoll („automatisch erkennen“) und Befehlsfreigabe
- Anmeldung mit der **E-Mail-Verifizierung**, die Ecovacs inzwischen verlangt (einmalig, in der Weboberfläche des Plugins)
- Push-Meldungen der Roboter plus Abfragen per HTTPS; nur Änderungen werden veröffentlicht
- Status als Klartext, Lebenszeichen (`ecovacs/status/ok|zaehler|ts`), Healthcheck, Autoupdate
- Läuft mit PHP 7.4 bis 8.4 (LoxBerry 4.0 oder neuer), Weboberfläche im LoxBerry Design System
- 270 automatische Tests gegen eine nachgebaute Cloud

**Nicht enthalten:** Karten, Räume und Zonen, Zeitpläne, Luftreiniger, Modelle mit XMPP.

**Hinweis zur Sicherheit:** Am besten ein **zweites Ecovacs-Konto** verwenden, mit dem der Roboter geteilt ist. Das Passwort liegt nur in der Konfiguration des Plugins (Rechte 0600) und geht nur als MD5-Hash an Ecovacs. Der MQTT-Server der Ecovacs-Cloud nutzt ein Zertifikat einer eigenen Ecovacs-Zertifizierungsstelle, das sich nicht prüfen lässt; Anmeldung und Befehle gehen per HTTPS mit geprüftem Zertifikat.

**Download und Quellcode:** https://github.com/ricten/Ecovacs2Lox (MIT-Lizenz), Release: https://github.com/ricten/Ecovacs2Lox/releases/latest

Rückmeldungen, Fehler und Wünsche gern hier oder als Issue auf GitHub.

Viele Grüße
Rico
