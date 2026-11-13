# Ecovacs2Lox – LoxBerry-Plugin

**Version: siehe `ecovacs2lox/bin/lib/AppVersion.php` (muss mit `ecovacs2lox/plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Läuft mit PHP 7.4, 8.2, 8.3 und 8.4 (CLI + Web).**

Ersetzt den ioBroker-Adapter `ecovacs-deebot`. Liest Ecovacs-Deebot-Roboter über die Ecovacs-Cloud (HTTPS für Befehle, MQTT der Cloud für Push-Meldungen) und veröffentlicht per MQTT auf dem LoxBerry-Broker; nimmt Befehle aus Loxone an.
Spezifikation und Protokollbefunde: [SPEZIFIKATION.md](SPEZIFIKATION.md).
Übergeordnete Regeln (Topics, Status, Versionierung): `../CLAUDE.md` und `../README.md`. Bei Widerspruch gelten die Regeln dieser Datei.

## Layout
- `ecovacs2lox/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, cron/, daemon/, uninstall/, webfrontend/, *.sh)
- `../common/php/` = gemeinsamer Code (Util, Logger, MqttClient, GatewayInfo). `build.sh` kopiert ihn nach `bin/lib` des ZIP; nie aus einem anderen Plugin importieren
- `tests/` = `php tests/run.php` (Unit, Ende-zu-Ende mit Fake-Cloud `fake_cloud.php` und Fake-Brokern `fake_mqtt.php`, Healthcheck, Oberfläche, Build) und `bash tests/test_phpcheck.sh` – **nicht** im ZIP

## Harte Regeln
- **PHP 7.4 bis 8.4:** keine PHP-8-Syntax (kein `match`, keine Konstruktor-Promotion, kein `readonly`, keine Union-Typen, kein `mixed`, kein `catch (X)` ohne Variable, kein `?->`, keine benannten Argumente, keine typisierten Klassenkonstanten); `str_contains` & Co. kommen aus `common/php/Util.php`. Vor Releases `php -l` und `php tests/run.php` mit 7.4, 8.2, 8.3 und 8.4.
- **Die Weboberfläche hat kein JavaScript** (nur Formulare); Änderungen an ihr nie mit Umbauskripten über die Datei laufen lassen (Lehre aus Senec2Lox 1.8.1). Der Test prüft, dass kein `<script` vorkommt.
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden. `.gitattributes`/`.editorconfig` beachten.
- Plugin-Identität nie ändern (`AUTHOR.NAME` + `AUTHOR.EMAIL` + `PLUGIN.NAME` + `PLUGIN.FOLDER`), sonst entsteht beim Installieren ein zweites Plugin.
- Plugin-Ordner nie fest eintragen (kann `ecovacs2lox_000` heißen): Skripte nehmen ihn aus `$3` bzw. aus ihrem Ort. MQTT-Client-ID enthält den Ordner (`ecovacs_<Ordner>`).
- Weboberfläche und Dienst: `LBWeb::lbheader()` und `mqtt_connectiondetails()` überschreiben die globale Variable `$cfg`. Eigene Konfiguration heißt deshalb `$eco`.
- Laufzeitstatus (`status.json`, PID, Sperre) nur nach `/dev/shm/<Ordner>/`. Konfiguration und Token atomar schreiben (Temp + rename), Rechte 0600.
- **Zugangsdaten** (Passwort des Ecovacs-Kontos, Zugriffstoken, Passwort eines eigenen Brokers, Verifizierungscodes) nie loggen (Logger maskiert registrierte Secrets), nie per MQTT senden, nie in der Oberfläche, in `status.json` oder in `geraete.json` anzeigen (Passwortfeld, leer = unverändert). In Fehlermeldungen und Logs stehen keine Adressen der Anmeldung (sie enthält Konto und Passwort-Hash in der Query). Keine E-Mail, Geräte-ID, Seriennummer oder Roboter-Kennung eines echten Kontos in Code, Tests, Fixtures oder Doku; Tests prüfen das.
- **App-Schlüssel** (`EcoKonstanten`) sind die der öffentlichen Ecovacs-App (stehen in mehreren quelloffenen Projekten), kein Geheimnis des Benutzers. Sie stehen nur in dieser einen Datei und werden nicht in Chat, Doku oder Log wiederholt.
- **Lizenz:** `ecovacs-deebot.js` ist GPL-3.0 (Wissensquelle für das Protokoll). **Keinen Code, keine Tabellen wörtlich übernehmen**; alles ist eigene PHP-Umsetzung.
- **Anmeldung schonend:** Nie im Sekundentakt anmelden. Bei „Konto oder Passwort falsch“ (1005/1010) **nicht** von selbst wiederholen (sonst droht eine Sperre), bei Verifizierung (1013) nur alle 2 Minuten, bei anderen Fehlern mit wachsendem Abstand (30 s bis 15 min). Der Token wird gespeichert und erst eine Stunde vor Ablauf erneuert. Die Geräte-Kennung (`geraete_id`) bleibt stabil; eine neue verlangt eine neue Verifizierung per E-Mail.
- **Cloud-Zertifikate:** HTTPS prüft das Zertifikat. Der MQTT-Server der Cloud nutzt eine eigene Ecovacs-CA und wird nicht geprüft (steht in Oberfläche und Doku, die Option dafür gibt es bewusst nicht).
- **Befehle:** nur die in `EcoCommands::LISTE`, in drei Stufen je Roboter, ab Werk alle gesperrt, immer mit Quittung (`angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`), nie retained. Nie: Karten löschen, Zeitpläne, Firmware, Werksreset, Konto-/Netzwerkeinstellungen. Höchstens ein Befehl je Sekunde und Roboter. **Befehle an einen echten Roboter nur mit Ricos ausdrücklichem Go** (der Roboter fährt los; vorher die Befehle nennen, einmal das Go abfragen).
- **Erwartete Zustände sind kein Fehler:** Ein ausgeschalteter oder offline Roboter (Cloud-Fehler 4200) ist `aus` (`online` = 0, `status/ok` bleibt 1). Fehler sind: Anmeldung ungültig, Cloud nicht erreichbar, Protokoll nicht erkennbar, Roboter erreichbar aber ohne Antwort. Fällt ein Roboter aus, laufen die anderen weiter, `status/zaehler` läuft weiter.
- **Nicht unterstützte Abfragen** laufen in einen Timeout von 20 s (Cloud-Fehler 500): nach zwei Fehlschlägen merken und nicht mehr stellen (`EcoGeraet`). Alle Cloud-Anfragen laufen asynchron (`EcoHttp`/curl_multi), damit der Dienst MQTT weiter bedient.
- Nur Änderungen veröffentlichen, alle 5 Minuten alle Werte. Ohne gültigen Wert wird nichts gesendet. `status` erst, wenn Reinigungs- und Ladezustand bekannt sind.
- Deutungen ohne gesicherte Doku sind als solche gekennzeichnet (Einheiten von `flaeche_m2`/`dauer_s`, Skala der Wasserstufe, `PlaySound` sid 0). Nichts erfinden: was unklar ist, nicht senden.
- Topic-Namen unter `ecovacs/` nie umbenennen (Loxone-Eingänge hängen daran), sonst MAJOR-Version erhöhen. Keine Leerzeichen in Topics und Gerätenamen.
- **Das neue (JSON-)Protokoll ist ungeprüft** (kein Testgerät). Änderungen daran nur mit Rückmeldung aus der Praxis oder neuen Fixtures; die Oberfläche, README und Doku sagen das offen.
- **Logo und Icons:** `tools/make_logo.py` erzeugt `docs/logo/ecovacs2lox.png` und `ecovacs2lox/icons/icon_{64,128,256,512}.png` im Stil der anderen Plugins (runder grüner LoxBerry-Stil, links ein Saugroboter in der Draufsicht ohne Herstellerlogo, rechts der Miniserver). Die Dateinamen `icon_*.png` sind vom Installer vorgegeben, nie umbenennen. Kein `icon.svg` (der Installer würde es vor den PNGs nehmen). Änderungen am Logo nur auf Wunsch von Rico.

## Entwicklung
- PHP lokal nötig; fehlt es, das offen sagen und nichts als „getestet“ melden. Unter Windows ohne `php.ini` laufen die Tests mit `php -d extension_dir=<php>/ext -d extension=openssl -d extension=curl tests/run.php`.
- **Tests nie parallel** starten (feste Ports 18861 bis 18863). Beim Abbruch einer Testsuite bleiben Fake-Server und Dienst-Prozesse stehen: vor dem nächsten Lauf beenden. Nie `| head` hinter den Testlauf hängen (beendet nur die Pipe, nicht die Kindprozesse).
- Nicht lokal testbar (nur auf dem LoxBerry bzw. am echten Roboter): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), das Aussehen der Weboberfläche, das echte Cloud-Zertifikat des MQTT-Servers (am echten Konto geprüft, siehe SPEZIFIKATION), das JSON-Protokoll und alle Steuerbefehle an einem echten Roboter.
- Dienst lokal: `ECO_DEV_DIR=<Ordner mit ecovacs.json> ECO_DEV_MQTT=127.0.0.1:18861 ECO_DEV_CLOUD=http://127.0.0.1:18863 ECO_DEV_ECOMQTT=127.0.0.1:18862 php ecovacs2lox/bin/ecovacsd.php` (Fake-Server: `php tests/fake_cloud.php 18863 <Ordner>`, `php tests/fake_mqtt.php 18861 <Ordner>`). Ohne `ECO_DEV_CLOUD` spricht der Dienst mit der echten Cloud (dann `openssl.cafile` und `curl.cainfo` auf ein CA-Bündel setzen, wenn das System keins hat).
- Build: `bash build.sh` → `ecovacs2lox-<version>.zip` und `ecovacs2lox.zip` (ohne Version).

## Release-Ablauf (Autoupdate über `release.cfg`)
1. Version in `plugin.cfg` und `AppVersion.php` erhöhen, Eintrag in `CHANGELOG.md`, Tests, `bash build.sh` (baut beide ZIPs).
2. Committen (ohne `release.cfg`), pushen, Tag `vX.Y.Z` setzen, `gh release create vX.Y.Z ecovacs2lox-X.Y.Z.zip ecovacs2lox.zip …` (beide ZIPs anhängen: das ZIP ohne Version macht den Link `https://github.com/ricten/Ecovacs2Lox/releases/latest/download/ecovacs2lox.zip` zum festen Link auf das neueste Release).
3. **Danach** `bash build.sh release-cfg`, `release.cfg` committen und pushen. Erst dann sehen die Installationen das Update. Die Datei darf nie auf eine Version zeigen, deren ZIP noch nicht existiert.
- **`release.cfg` nur nach Rücksprache mit Rico anlegen oder ändern** (auch `bash build.sh release-cfg` nicht ohne Freigabe): Sie löst bei allen Installationen mit aktivierten Auto-Updates sofort ein Update aus.
- Commit, Push, Veröffentlichung nur auf Ricos Anweisung.
- `plugin.cfg` enthält `AUTOMATIC_UPDATES=true` und `RELEASECFG=https://raw.githubusercontent.com/ricten/Ecovacs2Lox/main/release.cfg` (`PRERELEASECFG` leer).
- Weboberfläche: LoxBerry Design System (`lb-*`, `lbheader(…, true)`), kein jQuery Mobile; Farben nur über `var(--lb-…, Rückfall)`.

## Vorgaben aus dem LoxBerry-Entwicklerwiki
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl frei. Version dreiteilig ohne Buchstaben, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist zeilenweise (`ecovacs/#`); nur das Gateway V1 liest sie, das Gateway V2 nicht. Die Oberfläche prüft deshalb `subscriptions.json` (`GatewayInfo::subscription`). Nie behaupten, das Plugin trage das Abo selbst ein.
- **UDP-Port** des Gateways nie fest eintragen (`GatewayInfo::udpPort`), Standard 11884. Befehlsform `publish <topic> <wert>`.
- Healthcheck: `bin/healthcheck` (`title` und `check`, JSON, Status 3/4/5/6, schnell, nur lesend). Ein erwarteter Zustand ist nie Fehler oder Warnung.
- Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren). Tests rendern jeden Reiter mit Attrappen (`tests/stubs`, `tests/ui_page.php`).
- Ungenutzte Installationsskripte nicht mitliefern; keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren).
