# Einrichtung

**Voraussetzung:** LoxBerry 4.0 oder neuer mit **PHP 7.4 oder neuer** (mit curl und openssl), Internetzugang und ein Ecovacs-Konto mit mindestens einem Roboter. Die Installation prüft das und bricht sonst mit einem Hinweis im Installationslog ab.

## 1. Installieren

ZIP von der Release-Seite laden und in der LoxBerry-**Plugin-Verwaltung** installieren (oder über den Shop). Der Dienst startet automatisch. Neue Installationen stehen bei „Automatische Updates“ auf *Releases*: LoxBerry prüft täglich und installiert neuere Versionen selbst. Konfiguration und Anmeldung bleiben bei Updates erhalten.

## 2. Konto eintragen (Reiter „Konto“)

E-Mail (oder Ecovacs-ID), Passwort und Land des Kontos eintragen, speichern. Das Land bestimmt den Server der Cloud (Europa, Nordamerika, Asien); China wird nicht unterstützt.

Ein **zweites Konto**, mit dem der Roboter geteilt ist, ist sicherer als das Hauptkonto: Im Notfall lässt es sich sperren. Ob und wie sich ein Roboter teilen lässt, steht in der Ecovacs-App.

## 3. Verifizierung per E-Mail-Code (einmalig)

Ecovacs kennt eine neue Installation nicht und verlangt einmal einen Code. Im Reiter „Konto“ erscheint dann „Verifizierung nötig“:

1. **Code per E-Mail anfordern.** Ecovacs schickt eine E-Mail an die Adresse des Kontos (manchmal im Spam-Ordner). Der Code gilt nur kurz.
2. Code eintragen, **Code bestätigen.**

Danach meldet sich der Dienst selbst an (wenige Sekunden). Die Kennung dieser Installation ist jetzt bei Ecovacs bekannt; der Code wird nicht mehr gebraucht, solange Sie das Plugin nicht komplett löschen oder das Konto wechseln.

## 4. Roboter einrichten (Reiter „Geräte“)

Nach der Anmeldung erscheinen die Roboter des Kontos. Je Roboter:

- **Aktiv** und **Name im Topic** (leer = Name aus der Ecovacs-App).
- **Protokoll:** „automatisch erkennen“ genügt. Das Plugin probiert beim ersten Mal erst das alte (XML), dann das neue (JSON) Protokoll und merkt sich das Ergebnis.
- **Befehle freigeben:** ab Werk gesperrt. Die Stufen *steuern* (Start, Stopp, Pause, Weiter, zurück zur Station), *einstellen* (Saug- und Wasserstufe) und *finden* (Ton). Die Roboter fahren los, sobald `start` kommt.

Alle Werte lassen sich im Reiter „Einbindung in Loxone“ ansehen.

## 5. MQTT-Gateway

Im **MQTT Gateway** das Abonnement `ecovacs/#` **von Hand** eintragen (das Gateway V2 liest die Abo-Dateien der Plugins nicht; das Plugin prüft im Reiter „MQTT“, ob es steht). `ecovacs/status/ok` und `ecovacs/status/zaehler` im Gateway auf *No Cache* stellen. In Loxone entstehen virtuelle Eingänge wie `ecovacs_sauger_akku`.

## 6. Befehle aus Loxone

Virtueller Ausgang: Adresse `/dev/udp/<LoxBerry-IP>/<UDP-Port>` (Standard 11884, steht im Plugin im Reiter „Einbindung in Loxone“), Befehl bei EIN `publish ecovacs/befehl/<name>/<befehl> <wert>` (ohne HTTP-Header/Body). Die Quittung steht unter `…/quittung`.

## Hinweise zu Modellen

- **Ältere Modelle** (Deebot 500, 600, 700, 900 u. a., Protokoll XML): am Deebot 605 geprüft, auch die Steuerbefehle. Es gibt Auto, Kante und Spot, zwei Saugstufen (normal, stark), Wasserstufe 1–4 (mit Tank); keine Räume und keine Karte.
- **Neuere Modelle** (OZMO 920/950, T-, N- und X-Reihen, Protokoll JSON): nach der Protokollbeschreibung gebaut, **ungeprüft**. Bitte Rückmeldung im LoxForum oder als Issue auf GitHub (Modell, was geht, was nicht, Auszug aus der Logdatei ohne Zugangsdaten).
- Karten, Räume und Zonen, Zeitpläne und Luftreiniger sind nicht enthalten. Ältere Modelle mit XMPP (Firma `eco-legacy`) werden nicht unterstützt.
