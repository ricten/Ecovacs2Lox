# Ecovacs2Lox – Spezifikation (Entwurf)

Stand: 2026-11-13 · Status: **Beta 0.1.0 umgesetzt** (Abschnitt 11); Recherche am echten Deebot 605 bestätigt (Abschnitt 2a). Die Abschnitte 3 bis 7 sind der ursprüngliche Entwurf, Abweichungen stehen in Abschnitt 11.
Ersetzt den ioBroker-Adapter `ecovacs-deebot` (mrbungle64/ioBroker.ecovacs-deebot, MIT-Lizenz). Er baut auf der Bibliothek `ecovacs-deebot.js` (mrbungle64, **GPL-3.0**) auf. Beide dienen nur als Vorlage für **Protokoll und Datenfelder**; es wird **kein Code übernommen**, sondern alles in PHP neu geschrieben (die Lizenz der Bibliothek wäre sonst auf das Plugin übertragbar).
Es gelten die Regeln aus `../CLAUDE.md` und `../README.md`, insbesondere: **Code läuft mit PHP 7.4, 8.2, 8.3 und 8.4** (keine PHP-8-Syntax, `Util.php`-Rückfälle), Änderungen nie per Skript über Dateien mit Inline-JavaScript, Zugangsdaten nie ins Repo/Log/MQTT.

## 1. Ziel

Das Plugin verbindet den LoxBerry mit dem **Ecovacs-Konto** (Cloud) und liest von einem oder mehreren **Deebot-Saugrobotern** Zustand und Messwerte. Es veröffentlicht sie per MQTT; über das MQTT-Gateway landen sie als virtuelle Eingänge im Miniserver. Befehle (Start, Stopp, Pause, zurück zur Station, Saugstufe, Wasserstufe …) nimmt das Plugin aus Loxone an, ab Werk gesperrt, mit Quittung.

Es gibt **keine lokale Schnittstelle**: Roboter und Plugin reden nur über die Ecovacs-Cloud. Ohne Internet läuft nichts. Das ist eine inoffizielle, nachgebaute Schnittstelle (die der Ecovacs-App); Ecovacs kann sie jederzeit ändern oder sperren.

Nicht Teil von 0.1: Karten und Kartenbild (brauchen LZMA/zstd; zstd gibt es in PHP 7.4 nicht), Räume/Zonen reinigen (kommt später, braucht die Karte), Videomanager, Luftreiniger (AIRBOT), alte XML-Modelle (900/Slim 2/OZMO 930 – auch die Bibliothek unterstützt sie ab 1.0 nicht mehr).

## 2. Das Protokoll (aus Bibliothek und Adapter ermittelt)

Zwei getrennte Wege: **Befehle gehen per HTTPS an die Cloud, Meldungen kommen per MQTT zurück.**

### 2.1 Anmeldung (vier Schritte, alles HTTPS GET/POST, JSON)

| Schritt | Aufruf | Ergebnis |
|---|---|---|
| 1 `user/login` | GET `https://gl-<land>-api.ecovacs.com/v1/private/<land>/<sprache>/<deviceId>/<appCode>/<appVersion>/<channel>/<deviceType>/user/login` mit `account`, `password` (**MD5 des Passworts**), Zeit, Request-ID, Signatur | `uid`, `accessToken` |
| 2 `user/getAuthCode` | GET `https://gl-<land>-openapi.ecovacs.com/v1/global/auth/getAuthCode` mit `uid`, `accessToken`, `openId=global`, Signatur | `authCode` |
| 3 `loginByItToken` | POST JSON an `https://api-app.dc-<kontinent>.ww.ecouser.net/api/users/user.do` (`todo: loginByItToken`, `userId`, `token = authCode`, `realm = ecouser.net`, `resource`, `org = ECOWW`, `edition = ECOGLOBLE`, `country`) | `token` (Zugriffstoken, gilt ca. 7 Tage), `userId` |
| 4 Geräteliste | POST `users/user.do` (`GetDeviceList`) und POST `appsvr/app.do` (`GetGlobalDeviceList`), zusammengeführt über `did` | je Roboter: `did`, `class` (Modellkennung), `resource`, `name`, `nick`, `company` |

- **Signatur** der Schritte 1 und 2: MD5 über `appkey` + alle Parameter sortiert als `name=wert` + `secret`. Die App-Schlüssel (appkey/secret für Ecovacs, andere für yeedi) stecken als Konstanten in der Bibliothek; sie werden **nicht in dieses Dokument** übernommen. Beim Bau liegen sie in einer einzigen Konstantendatei.
- **deviceId**: eine zufällige, **dauerhaft gespeicherte** Kennung (MD5 eines Zufallswerts). Der Adapter nimmt MD5(Maschinen-ID + Gerätenummer); das Plugin erzeugt sie beim ersten Start einmal und legt sie in der Konfiguration ab. Ändert sie sich, verlangt Ecovacs eine neue Geräteverifizierung. `resource` = die ersten 8 Zeichen der deviceId.
- **Land/Kontinent**: Tabelle `countries.json` (Länderkürzel → Kontinent `eu`/`na`/`as`/`ww`). Für Deutschland `de` → `eu`. China ist anders aufgebaut (`.cn`, `CheckMobile`) und wird nicht unterstützt.
- **Antwortcodes** der Schritte 1/2: `0000` = ok, `1005`/`1010` = Konto oder Passwort falsch, **`1013` = Gerät muss verifiziert werden**, `1012` = Verifizierungscode ungültig. Die Cloud antwortet gelegentlich mit HTTP 502: mehrfach wiederholen.
- **Gerät verifizieren (Code 1013, neu in der Bibliothek 1.0)**: Ecovacs verlangt bei einer unbekannten deviceId einen Code per E-Mail. Ablauf: öffentlichen RSA-Schlüssel holen (`common/getConfig`, Schlüssel `PUBLIC.KEY.CONFIG`), die E-Mail-Adresse damit verschlüsseln (RSA, PKCS#1-v1.5, Base64; in PHP `openssl_public_encrypt`), `user/sendEmailVerifyCode` auslösen, dann den Code an `user/verifyDevice` schicken; danach weiter mit Schritt 2. **Der Adapter (alte Bibliothek 0.9.6) kennt das noch nicht**; das Plugin braucht dafür eine Eingabe in der Oberfläche („Code aus der E-Mail“).
- Der Zugriffstoken läuft ab (Gültigkeit steht in `last`, meist 7 Tage). Das Plugin meldet sich nach 99 % der Zeit neu an und verbindet MQTT mit dem neuen Token. Schlägt das fehl (Kennwort geändert, Verifizierung nötig): `status/ok = 0`, Hinweis in der Oberfläche, Wiederholung mit wachsendem Abstand.

### 2.2 Meldungen per MQTT

| Punkt | Wert |
|---|---|
| Broker | `mqtts://mq-<kontinent>.ecouser.net:8883` (für `ww`: gleiches Schema, Bibliothek nimmt `mq-ww…`) |
| Benutzername | `<uid>@ecouser` (Teil vor dem ersten Punkt des Realms `ecouser.net`) |
| Passwort | das Zugriffstoken aus Schritt 3 |
| Client-ID | `<Benutzername>/<resource>`, MQTT 3.1.1, Keepalive 120 s |
| Abo | `iot/atr/+/<did>/<class>/<resource>/j`; das dritte Segment des Topics ist der Meldungsname |
| Nutzlast | JSON `{"header":{…},"body":{"data":{…}}}` (Meldungen vom Roboter selbst) |
| Zertifikat | Die Bibliothek prüft es nicht (`rejectUnauthorized` aus). **Am echten Server geprüft:** `*.ecouser.net`, ausgestellt von einer **eigenen „ECOVACS CA“**, die in keinem öffentlichen Zertifikatsspeicher steht; die Kette enthält nur das Serverzertifikat (gültig bis 2035). Eine Prüfung gegen öffentliche CAs scheitert deshalb immer. Das Plugin prüft es nicht und sagt das offen (Oberfläche, README); die Option „Zertifikat prüfen“ gibt es bewusst nicht. |

`common/php/MqttClient.php` kann TLS und prüft das Zertifikat standardmäßig nicht (für Bambu). Ergänzt wurde der optionale Parameter `$tlsPruefen` (für Server mit öffentlichem Zertifikat); Ecovacs2Lox nutzt ihn nicht (siehe oben).

### 2.3 Befehle per HTTPS

POST JSON an `https://api-app.dc-<kontinent>.ww.ecouser.net/api/iot/devmanager.do?cv=1.94.78&t=a&av=2.2.4&mid=<class>&did=<did>&td=q&u=<uid>`, Kopf `Content-Type: application/json`, `User-Agent: Dalvik/2.1.0 (Linux; U; Android 5.1.1; A5010 Build/LMY48Z)`. Rumpf:

```
{ "cmdName": "<Befehl>", "payload": { "header": {"pri":"1","ts":<ms>,"tzm":480,"ver":"0.0.50"}, "body": {"data": <Argumente mit "id">} },
  "payloadType": "j", "auth": {"realm":"ecouser.net","resource":"<resource>","token":"<Token>","userid":"<uid>","with":"users"},
  "td": "q", "toId": "<did>", "toRes": "<resource>", "toType": "<class>" }
```

Antwort `{"ret":"ok","resp":{"header":…,"body":{"code":0,"data":{…}}}}`. Fehler: `errno` und `error`; `errno 4200` = Roboter offline. Die Antwort enthält die Daten sofort; zusätzlich kommen Änderungen auch als MQTT-Meldung. **Das Plugin braucht MQTT also vor allem für Push-Änderungen**; ein reines Abfragen per HTTPS im Takt (z. B. alle 60 s) ginge auch und wäre der Rückfall, wenn MQTT zum Ecovacs-Server nicht klappt.

**Modellgenerationen:** „950-Typ“ (OZMO 920/950, T5, T8 …) nutzt die Befehlsnamen unverändert (`clean`, `getCleanInfo`); „V2“-Modelle (T9, T10, T20, T30, N8, X1, X2 …) hängen `_V2` an (`clean_V2`, `getCleanInfo_V2`) und liefern teils andere Datenformen. Welche Modellkennung (`class`, z. B. `x5d34r` = T8 AIVI, `2o4lnm` = X1 Turbo) welche Generation ist, steht in der Tabelle `models.js` der Bibliothek (Name, Typ, V2-Flag, Fähigkeiten). Plan: kleine eigene Tabelle der bekannten Modelle plus **Selbstermittlung**: unbekannte Modelle probieren erst die V2-, dann die Basisbefehle; was antwortet, wird veröffentlicht.

## 2a. Befund am echten Roboter (2026-11-13): Deebot 605 = Altmodell mit XML

Geräteliste des Kontos: Modellkennung `dl8fht` (Name „DEEBOT 600 Series“, Modell `D600`), Firma **`eco-ng`**, also **MQTT-Server, aber XML statt JSON** („MQTT/XML“; XMPP mit `eco-legacy` gibt es bei diesem Roboter nicht). Anmeldung (Schritte 1 bis 3) und Geräteliste funktionieren; die **Geräteverifizierung per E-Mail-Code** war beim ersten Anmelden mit der neuen Geräte-ID nötig und ist erledigt (Geräte-ID im Scratchpad gespeichert).

Unterschiede zum JSON-Weg (Abschnitt 2.3), am Roboter geprüft:
- Befehle gehen an dieselbe Adresse, aber **ohne** die `cv/t/av/mid/did`-Parameter: `https://api-app.dc-<kontinent>.ww.ecouser.net/api/iot/devmanager.do`. Der Rumpf hat `"payloadType":"x"` und als `payload` einen **XML-Text** `<ctl id="<8 Ziffern>" …/>` (Argumente als Attribute oder Kindelement).
- Die Antwort kommt sofort im Feld `resp` als XML, z. B. `<ctl id='…' ret='ok'><battery power='099'/></ctl>`. Das Abo für die Push-Meldungen heißt `iot/atr/+/<did>/<class>/<resource>/x`, die Nutzlast ist XML mit dem Meldungsnamen im Attribut `td`.
- **Antwortzeit** 1 bis 2 s. Nicht unterstützte Befehle laufen dagegen **20 s in einen Timeout** (`errno 500`): `GetOnOff` (Nicht stören, Weiterreinigen, Sprachansage) und `GetNetInfo` gingen bei diesem Roboter so. Das Plugin merkt sich solche Befehle als „nicht unterstützt“ und fragt sie nicht mehr ab.

Gemessene Antworten (Roboter an der Station, 99 %):

| Befehl | Antwort (Attribute) | Bedeutung |
|---|---|---|
| `GetCleanState` | `<clean speed='standard' type='auto' st='h' mode='S' t='…' a='…'/>` | Modus `type`, Aktion `st` (`s` Start, `p` Pause, `r` weiter, `h` Halt), Zeit `t`, Fläche `a` der letzten Reinigung |
| `GetChargeState` | `<charge type='SlotCharging'/>` | `SlotCharging`/`WireCharging` = lädt, `Going` = fährt zur Station, `Idle` |
| `GetBatteryInfo` | `<battery power='099'/>` | Akku in Prozent |
| `GetCleanSpeed` | `speed='standard'` | `standard` (normal) oder `strong` (stark) |
| `GetWaterPermeability` | `v='4'` | Wasserstufe |
| `GetWaterBoxInfo` | `on='0'` | Wassertank eingesetzt (0/1) |
| `GetSleepStatus` | `st='1'` | Ruhezustand (0/1); im Ruhezustand antwortet er trotzdem |
| `GetCleanSum` | `a='000013356' l='000582379' c='000000216'` | Gesamtfläche (m²), Gesamtzeit (s), Anzahl Reinigungen |
| `GetLifeSpan type=Brush/SideBrush/DustCaseHeap` | `left='15421' total='18000'` | Rest und Gesamt (Minuten); Prozent = left/total |
| `GetError` | `<ctl ret='ok'/>` ohne Fehlercode | kein Fehler |

Steuerbefehle des Altprotokolls (aus der Bibliothek, am Roboter **noch nicht ausprobiert**): `Clean` mit `<clean type='auto|border|spot|stop' speed='standard|strong' act='s|p|r|h'/>`, `Charge` mit `<charge type='go'/>`, `SetCleanSpeed speed=…`, `SetWaterPermeability v=…`, `PlaySound sid=…`. Arten: `auto`, `border` (Kante), `spot`; keine Räume, keine Karte, **keine** Saugstufen 1 bis 4 (nur normal/stark), Wasserstufe 1 bis 4 (Tank nötig).

Für das Plugin heißt das: die Abschnitte 4 und 5 gelten für **beide** Generationen, die Wertebereiche (Saugstufe 2 oder 3 statt 1 bis 4, kein Raumbefehl, keine Station) richten sich nach der Modelltabelle.

## 3. Konfiguration (wie im Adapter)

Konto (E-Mail), Passwort (nie anzeigen/loggen/senden), Land (Liste), Gerätenummer bzw. **alle Roboter des Kontos** (Adapter: nur einer je Instanz; hier: bis zu 5, Namen frei vergebbar, wie bei Reolink2Lox), optional Anbieter Ecovacs/yeedi, Befehle freigeben, Abfrageintervall (Rückfall). Die Konfiguration überlebt Updates; Datei 0600.

## 4. MQTT-Topics (Lesen) – Vorschlag 0.1

Schema `ecovacs/<name>/<wert>` (Name = frei vergebener Gerätename ohne Leerzeichen, ASCII), Zahlen ohne Einheit, Zustände 0/1, nur Änderungen (alle 5 Minuten alles).

| Gruppe | Topics | Quelle |
|---|---|---|
| Status | `status_text` (Klartext), `status_roh`, `ladezustand` (`charging`/`idle`/`returning`), `laedt` (0/1), `akku` (%), `akku_schwach` (0/1), `online` (0/1), `fehler_code`, `fehler_text`, `schlaf` (0/1) | `getCleanInfo(_V2)`, `getChargeState`, `getBattery`, `getError`, `getSleep`, `Evt` |
| Reinigung | `modus` (`auto`/`spot`/`edge`/`custom_area`/`spot_area`/`pause`/`returning`/`idle`), `saugstufe` (1–4), `wasserstufe`, `wassertank` (0/1), `arbeitsmodus` (Saugen/Wischen/…), `teppich_boost` (0/1) | `getCleanInfo`, `getSpeed`, `getWaterInfo`, `getWorkMode`, `getCarpertPressure` |
| Laufende Reinigung | `flaeche_m2`, `dauer_s`, `typ` | `getStats` |
| Gesamt | `summe_flaeche_m2`, `summe_dauer_s`, `summe_anzahl` | `getTotalStats` |
| Verschleiß | `verschleiss/<teil>` in Prozent (Hauptbürste, Seitenbürste, Filter, Wischtuch, Pflegeeinheit) | `getLifeSpan` |
| Station | `station/absaugen` (aktiv), `station/staubbeutel_voll` (0/1), `station/status` | `getAutoEmpty`, `getStationState` (nur Modelle mit Station) |
| Einstellungen | `nicht_stoeren` (0/1), `lautstaerke`, `weiterreinigen` (0/1) | `getBlock`, `getVolume`, `getBreakPoint` |
| Gerät | `modell`, `firmware`, `name`, `wlan_signal`, `wlan_name` (nur Text) | Geräteliste, Kopfzeile der Antworten, `getNetInfo` |
| Plugin | `ecovacs/status/ok`, `zaehler`, `ts` (Pflicht) | – |

Die Zahlenwerte der Saug- und Wasserstufen übernimmt die Bibliothek aus der Cloud: Saugstufe Cloud `1000→1` (leise), `0→2` (normal), `1→3` (stark), `2→4` (max); Wasserstufe 1–4 je nach Modell. Genaue Skalen und Klartexte müssen am Roboter bestätigt werden.

## 5. Befehle (Schreiben) – ab Werk gesperrt, mit Quittung

Topic `ecovacs/<name>/befehl/<befehl>`, Quittung `…/quittung` (`angenommen`, `ausgefuehrt`, `abgelehnt: <Grund>`), Mindestabstand je Befehl.

| Befehl | Wert | Cloud-Befehl (950 / V2) |
|---|---|---|
| `start` | `auto`/`spot`/`edge` | `clean` / `clean_V2` (`act:start`, `type:auto|spot|edge`) |
| `stopp` | 1 | `clean` mit `act:stop` |
| `pause` | 1 | `clean` mit `act:pause` |
| `weiter` | 1 | `clean` mit `act:resume` |
| `laden` | 1 | `charge` mit `act:go` (zurück zur Station) |
| `saugstufe` | 1–4 | `setSpeed` |
| `wasserstufe` | 1–4 (je Modell) | `setWaterInfo` |
| `finden` | 1 | `playSound` (Ton abspielen) |
| `nicht_stoeren` | 0/1 | `setBlock` |
| `lautstaerke` | 0–10 | `setVolume` |
| `absaugen` | 1 | `setAutoEmpty` / `stationAction` (nur mit Station) |

Räume/Zonen („Küche“ reinigen) kommen mit den Karten in einer späteren Version.
**Hardware-Regel:** Befehle an den echten Roboter nur mit Ricos ausdrücklichem OK je Funktion (der Roboter fährt los).

## 6. Oberfläche (LoxBerry Design System)

Reiter: **Einstellungen · Konto (Anmeldung, Verifizierungscode) · Geräte · MQTT · Einbindung in Loxone · Test · Logdateien.** Menüleiste über `$navbar`, Healthcheck, Gateway-Abo-Prüfung wie in den anderen Plugins. Die Seite trägt **Inline-JavaScript**: Änderungen daran von Hand, nie mit Umbauskripten.

## 7. Struktur und Technik

- PHP-Dienst (Daemon) wie bei den anderen Plugins; `common/php/` per `build.sh` hineinkopiert; neue gemeinsame Teile: HTTP-Client mit Wiederholung bei 502 (Heizoel2Lox hat `HeizoelHttp.php` als Vorbild), RSA-Verschlüsselung per `openssl_*`, MQTT mit TLS-Prüfung.
- Eigene Klassen: `EcovacsAuth` (Anmeldung, Signatur, Verifizierung, Token-Erneuerung), `EcovacsApi` (Geräteliste, Befehle), `EcovacsMqtt` (Abo und Meldungen), `EcovacsMapper` (Meldung → Topics), `EcovacsModelle` (Modelltabelle), `Control` (Befehle).
- **PHP 7.4:** keine PHP-8-Syntax; `str_contains` & Co. über `Util.php`; `openssl` und `curl` werden in `preinstall.sh`/`preupgrade.sh` geprüft (wie Heizoel2Lox). Kein LZMA/zstd nötig, solange es keine Karten gibt.
- Tests: Fake-Cloud (lokaler HTTP-Server, der Anmeldung, Geräteliste und Befehle nachahmt) und Fake-MQTT-Broker wie bei Bambulab2Lox; anonymisierte Beispielmeldungen als Fixtures; PHP 7.4, 8.2, 8.3 und 8.4.

## 8. Risiken

1. **Inoffizielle Schnittstelle, App-Schlüssel nachgebaut:** Ecovacs kann ändern/sperren. Die Bibliothek beschränkt sich inzwischen auf MQTT/JSON-Modelle, und die Anmeldung wurde 2026 um die Geräteverifizierung erweitert (die Cloud ändert sich also tatsächlich).
2. **Ohne echten Roboter kein verlässlicher Test:** Format und Skalen der Meldungen pro Modell sind nur aus der Bibliothek bekannt. Beta erst nach Test an Ricos Gerät(en).
3. **Anmeldung kann fehlschlagen** (Verifizierung, Passwortwechsel, Sperre bei zu vielen Versuchen): Wiederholungen mit Abstand, nie im Sekundentakt anmelden.
4. **Konto-Zugangsdaten** liegen im Plugin (Konfiguration 0600, nie anzeigen/loggen).
5. **Lizenz:** Bibliothek GPL-3.0 → nur Wissen übernehmen, nichts kopieren (auch keine Tabellen wörtlich; die Modelltabelle wird selbst aufgebaut).

## 9. Unterschiede zum Adapter

- Alle Roboter des Kontos statt nur eine Instanz je Roboter.
- Geräteverifizierung (Code 1013) wird unterstützt; der Adapter stützt sich noch auf die alte Bibliothek.
- Kein Kartenbild, keine Zonen/Räume in 0.1.
- Keine ioBroker-Datenpunktnamen; Topics nach Schema `ecovacs/<name>/<wert>`, Texte deutsch.

## 10. Offene Entscheidungen für Rico

1. **Welcher Roboter** (Modell) soll getestet werden, und gibt es einen zweiten? Ohne Test am echten Gerät bleibt es bei einer Beta nach Protokollbeschreibung.
2. **Zugang zum Test:** E-Mail/Passwort des Ecovacs-Kontos in `…/scratchpad/ecovacs_zugang.txt` (nie in den Chat, Repo oder Log). Besser ein **zweites Konto mit Freigabe des Roboters** statt des Hauptkontos; dann lässt sich das Konto im Notfall sperren. (Ob die Ecovacs-App das Teilen eines Roboters mit einem zweiten Konto anbietet, ist noch zu prüfen.)
3. **Umfang 0.1:** Status/Messwerte plus die einfachen Befehle (Abschnitt 5), Räume und Karten später. Einverstanden?
4. **Lizenz des Plugins:** wie die anderen (Rico entscheidet); Bibliothek/Adapter nur als Wissen.
5. **Geräteverifizierung:** Ist der Roboter-Account schon einmal auf dem ioBroker angemeldet worden? Dann ist dessen deviceId bekannt; das Plugin braucht eine **eigene**, also wird Ecovacs einmal einen E-Mail-Code verlangen.

## 11. Umsetzung 0.1.0 (Stand 2026-11-13) und Abweichungen vom Entwurf

**Aufbau:** Dienst `ecovacsd.php` (eine Schleife wie bei Bambulab2Lox), Klassen `EcoAuth` (Anmeldung, Signatur, Verifizierung, Token), `EcoHttp` (curl, synchron für die Anmeldung, asynchron per curl_multi für Roboter-Anfragen), `EcoGeraet` (ein Roboter: Protokollerkennung, Abfragen, Push, Befehle), `EcoProtoXml` und `EcoProtoJson` (die beiden Sprachen hinter `EcoProtokoll`), `EcoXml` (kleiner XML-Leser/-Schreiber ohne PHP-XML-Erweiterung), `EcoCommands`, `EcoConfig`, `EcoTexte`, `EcoKonstanten`. Die Oberfläche hat kein JavaScript.

**Abweichungen vom Entwurf (Abschnitte 3 bis 7):**
- **Topics:** `status` (Klartext) statt `status_text`; `status_roh` entfällt; zusätzlich `reinigt`, `zurueck`, `online`, `verbunden`, `protokoll`, `modell`; `station/*`, `nicht_stoeren`, `lautstaerke`, `weiterreinigen`, `wlan_*` sind nicht enthalten (am 605 nicht verfügbar: `GetOnOff` und `GetNetInfo` laufen in einen Timeout; Station gibt es dort nicht). Saugstufe: Altmodelle 2 = normal, 3 = stark.
- **Befehle:** `start` (auto/kante/spot), `stopp`, `pause`, `weiter`, `laden`, `saugstufe`, `wasserstufe`, `finden`; Stufen `steuern`, `einstellen`, `finden`. `nicht_stoeren`, `lautstaerke`, `absaugen` fehlen in 0.1.
- **Geräteverifizierung** wird in der Oberfläche abgewickelt (zwei Schritte); der Dienst übernimmt danach den gespeicherten Token (er sieht alle 5 s nach, meldet sich alle 2 Minuten selbst neu an). „Konto oder Passwort falsch“ wird nie von selbst wiederholt.
- **Protokollerkennung:** erst XML-Probe (`GetBatteryInfo`), dann JSON (`getBattery`), dann V2 (`getCleanInfo_V2`, sonst `getCleanInfo`); Ergebnis im Datenordner (`protokoll.json`). Feste Einstellung je Roboter möglich.
- **Nicht unterstützte Abfragen** (Cloud-Timeout 500 nach 20 s): nach zwei Fehlschlägen 6 Stunden lang nicht mehr gestellt.
- **Fläche und Dauer** (`flaeche_m2`, `dauer_s`) kommen aus der Reinigungsmeldung; die Einheiten sind nicht bestätigt (im Ruhezustand meldet der 605 die Platzhalter 100/100, deshalb werden sie nur während einer Reinigung übernommen).
- **JSON-Modelle ungeprüft:** `EcoProtoJson` folgt der Protokollbeschreibung; Pause, Weiter, Stopp laufen wie dort mit `clean` (für X2 und Airbot wäre `clean_V2` nötig, nicht umgesetzt).

**Geprüft:** Anmeldung, Verifizierung, Geräteliste, Token-Wiederverwendung, MQTT-Verbindung zum echten Cloud-Server (TLS, Benutzer `<uid>@ecouser`, Client-ID `<benutzer>/<resource>`) und alle Lesewerte am Deebot 605 mit dem PHP-Dienst unter PHP 7.4. **Steuerbefehle am Deebot 605 geprüft (2026-11-13, mit Ricos Go):** `finden` (der Ton war zu hören), `start auto` (der Roboter löst sich von der Station und meldet „Reinigt (Auto)“), `pause`, `weiter` (nur direkt nach der Pause), `stopp` (der Roboter meldet `type='stop'`), `laden`, `saugstufe` 3 und zurück auf 2 (der Roboter meldet den neuen Wert). **Befunde:** Der 605 fährt nach `pause` binnen 4 bis 6 s von selbst zur Station und lädt; `weiter` wirkt nur unmittelbar nach der Pause (sonst lehnt das Plugin ab: „die Reinigung ist nicht pausiert“). Push-Meldungen kommen am echten Roboter an: `CleanReport` (Attribut `st` s/p/h, `rsn` Grund: a App, b Taste), `ChargeState` (`Going`/`SlotCharging`, auch klein `going`), `BatteryInfo`, `CleanSt` (`a` Fläche, `l` Sekunden, alle 10 s während der Reinigung) und `error` (klein geschrieben; `errs` Code, `error` Text; bei „kein Fehler“ `errs=''` und `error='NoError'`; Code 109 = RollAbnormal = Hauptbürste verheddert trat zweimal auf, bis die Bürste frei war). `GetCleanState` liefert beim 605 Platzhalter 100/100 für Fläche und Dauer. **Nicht geprüft:** `wasserstufe` (kein Tank eingesetzt), JSON-Modelle.

**Tests:** 268 Prüfungen (`php tests/run.php`), grün unter PHP 7.4, 8.2, 8.3 und 8.4, dazu `bash tests/test_phpcheck.sh`.

