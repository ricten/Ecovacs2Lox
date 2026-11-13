<?php
/**
 * Ein Sprachdialekt der Ecovacs-Roboter. Es gibt zwei Familien:
 *   xml  - aeltere Modelle (Deebot 500/600/700/900, OZMO 930 ...): Befehle und Meldungen als XML ("MQTT/XML")
 *   json - neuere Modelle (OZMO 920/950, T-, N-, X-Reihen ...): Befehle und Meldungen als JSON
 * Beide liefern dieselben, vereinheitlichten Werte (Schluessel siehe EcoGeraet::werte()).
 */
interface EcoProtokoll
{
    /** 'xml' oder 'json'. */
    public function name(): string;

    /** Letztes Pfadteil des MQTT-Abos: 'x' oder 'j'. */
    public function mqttTyp(): string;

    /** Anfrage zur Erkennung des Protokolls (muss jeder Roboter dieser Familie beantworten). @return array{0:string,1:array} [Befehl, Argumente] */
    public function pruefAnfrage(): array;

    /**
     * Abfragen, die den Zustand liefern.
     * @param bool $voll false: nur das Schnelle (Reinigungs-, Ladezustand, Akku); true: alles
     * @return list<array{0:string,1:array}> [Befehl, Argumente]
     */
    public function abfragen(bool $voll): array;

    /**
     * Anfrage fuer die Cloud bauen.
     * @param array $args Argumente (protokollabhaengig)
     * @return array{0:string,1:string,2:string} [cmdName, payload als Text, payloadType x|j]
     */
    public function anfrage(string $befehl, array $args): array;

    /**
     * Antwort der Cloud (Feld resp) auswerten.
     * @param mixed $resp
     * @return array<string,mixed>|null vereinheitlichte Werte; null = Antwort unbrauchbar oder Fehler
     */
    public function antwort(string $befehl, $resp): ?array;

    /**
     * Push-Meldung des Roboters (MQTT) auswerten.
     * @param string $ereignis Name aus dem Topic (iot/atr/<ereignis>/...)
     * @return array<string,mixed>|null
     */
    public function push(string $ereignis, string $nutzlast): ?array;

    /**
     * Loxone-Befehl in Cloud-Anfragen uebersetzen.
     * @param array<string,mixed> $z aktueller Zustand
     * @return list<array{0:string,1:array}>|null null = nicht moeglich bei diesem Modell
     */
    public function befehl(string $name, string $wert, array $z): ?array;
}
