# MQTT-Topics

Schema `ecovacs/<name>/<wert>`; `<name>` ist der im Reiter „Geräte“ vergebene Name (nur a–z, 0–9, `_`, `-`; leer = Name aus der Ecovacs-App). Zahlen mit Punkt ohne Einheit, Zustände 0/1, nicht retained. Gesendet werden nur Änderungen und alle 5 Minuten alle Werte. Werte, die der Roboter nicht liefert, werden nicht gesendet.

Der Loxone-Eingangsname ist das Topic mit `_` statt `/`, z. B. `ecovacs_sauger_akku`. Texte als virtuelle **Texteingänge** anlegen.

## Werte je Roboter

| Topic | Einheit | Bedeutung |
|---|---|---|
| `status` | Text | Zusammengefasster Zustand: Lädt, In der Station (voll geladen), Reinigt (Auto/Kante/Punkt/Räume/Bereich/Raum), Pausiert, Fährt zur Ladestation, Ruhezustand, Bereit, Fehler: … |
| `modus` | Text | Reinigungsart bzw. -zustand: `auto`, `edge`, `spot`, `spot_area`, `custom_area`, `single_room`, `pause`, `stop`, `returning`, `idle` |
| `reinigt` | 0/1 | reinigt gerade (nicht bei Pause) |
| `laedt` | 0/1 | lädt in der Station |
| `zurueck` | 0/1 | fährt zur Ladestation |
| `verbunden` | 0/1 | Antworten der Cloud kommen an (zuletzt vor weniger als 15 Minuten) |
| `online` | 0/1 | 0 = Roboter nicht erreichbar (ausgeschaltet oder ohne WLAN); **kein** Fehler |
| `akku` | % | Akkustand |
| `akku_schwach` | 0/1 | Warnung (bis 15 %) |
| `saugstufe` | 1–4 | Saugstufe; ältere Modelle: 2 = normal, 3 = stark |
| `wasserstufe` | 1–4 | Wasserstufe (Modelle mit Wischfunktion) |
| `wassertank` | 0/1 | Wassertank eingesetzt |
| `schlaf` | 0/1 | Ruhezustand des Roboters |
| `flaeche_m2` | m² | Fläche der laufenden oder letzten Reinigung (Einheit nicht bestätigt) |
| `dauer_s` | s | Dauer der laufenden oder letzten Reinigung (Einheit nicht bestätigt) |
| `summe_flaeche_m2`, `summe_dauer_s`, `summe_anzahl` | m², s, - | Summen über alle Reinigungen |
| `verschleiss_hauptbuerste`, `verschleiss_seitenbuerste`, `verschleiss_filter` | % | Restlebensdauer (100 = neu) |
| `fehler_code` | - | Fehlercode (0 = kein Fehler) |
| `fehler_text` | Text | Klartext zum Fehlercode (leer = kein Fehler) |
| `protokoll` | Text | erkanntes Protokoll: `xml` (ältere Modelle) oder `json` (neuere Modelle), `unbekannt` bis zur Erkennung |
| `modell` | Text | Modellname aus der Cloud |

## Status des Plugins

| Topic | Bedeutung |
|---|---|
| `ecovacs/status/ok` | 1 = Anmeldung bei Ecovacs und alle aktiven Roboter in Ordnung (ein ausgeschalteter Roboter ist kein Fehler); der Broker setzt 0, wenn der Dienst abbricht |
| `ecovacs/status/zaehler` | Lebenszeichen 0–999, zählt alle 10 s |
| `ecovacs/status/ts` | Unix-Zeitstempel des letzten Lebenszeichens |

Im MQTT-Gateway `ecovacs/status/ok` und `ecovacs/status/zaehler` auf *No Cache* stellen.

## Befehle

`ecovacs/befehl/<name>/<befehl>` = Wert; Quittung unter `…/<befehl>/quittung` (`angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`). Ab Werk gesperrt; je Roboter in drei Stufen freizugeben.

| Befehl | Wert | Stufe | Wirkung |
|---|---|---|---|
| `start` | `auto`, `kante`, `spot` (oder `1` = auto) | steuern | Reinigung starten (nicht, wenn er schon reinigt) |
| `stopp` | `1` | steuern | Reinigung beenden |
| `pause` | `1` | steuern | pausieren (nur während der Reinigung) |
| `weiter` | `1` | steuern | Pausierte Reinigung fortsetzen |
| `laden` | `1` | steuern | zurück zur Ladestation |
| `saugstufe` | `1`–`4` | einstellen | Saugstufe (ältere Modelle: 1/2 = normal, 3/4 = stark) |
| `wasserstufe` | `1`–`4` | einstellen | Wasserstufe |
| `finden` | `1` | finden | Ton abspielen |

Höchstens ein Befehl je Sekunde und Roboter. Gründe für `abgelehnt`: gesperrt, ungültiger Wert, zu schnell, Roboter nicht erreichbar, der Roboter reinigt bereits, es läuft keine Reinigung, die Reinigung ist nicht pausiert, der Roboter ist schon in der Ladestation, bei diesem Modell nicht möglich.
