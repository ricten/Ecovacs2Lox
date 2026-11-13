<?php
/**
 * Klartexte (Deutsch): Fehlercodes der Roboter, Reinigungsarten und die zusammengefasste Statusanzeige.
 * Die Codes stammen aus den Meldungen der Roboter; Deutungen ohne offizielle Doku sind bewusst knapp gehalten.
 */
final class EcoTexte
{
    /** Fehlercode => Text. 0 und 100 bedeuten "kein Fehler". */
    private const FEHLER = [
        '-3' => 'Antwort nicht lesbar', '-2' => 'Interner Fehler des Plugins', '-1' => 'Roboter oder Cloud nicht erreichbar',
        '3' => 'Anmeldung bei der Cloud abgelaufen', '5' => 'Zugriff verweigert', '500' => 'Roboter antwortet nicht (Zeitüberschreitung)',
        '4200' => 'Roboter nicht erreichbar (ausgeschaltet oder ohne WLAN)',
        '101' => 'Akku schwach', '102' => 'Roboter ist vom Boden abgehoben', '103' => 'Antriebsrad gestört',
        '104' => 'Absturzsensoren verschmutzt', '105' => 'Roboter steckt fest', '106' => 'Seitenbürste verbraucht',
        '107' => 'Staubbehälter-Filter verbraucht', '108' => 'Seitenbürste verheddert', '109' => 'Hauptbürste verheddert',
        '110' => 'Staubbehälter nicht eingesetzt', '111' => 'Stoßsensor klemmt', '112' => 'Laser-Entfernungssensor gestört',
        '113' => 'Hauptbürste verbraucht', '114' => 'Staubbehälter voll', '115' => 'Akkufehler', '116' => 'Sensor nach vorn gestört',
        '117' => 'Kreiselsensor gestört', '118' => 'Filter verstopft', '119' => 'Lüfterfehler', '120' => 'Wassertank gestört',
        '121' => 'Wischplatte klemmt', '125' => 'Wassertank gestört', '126' => 'Wassertank nicht eingesetzt',
        '128' => 'Wischplatten nicht eingesetzt', '129' => 'Wischplatte verheddert',
        '301' => 'Frischwassertank leer', '302' => 'Schmutzwassertank voll', '303' => 'Frischwassertank fehlt',
        '304' => 'Schmutzwassertank fehlt', '307' => 'Station gestört', '308' => 'Kommunikationsfehler mit der Station',
        '310' => 'Deckel offen', '311' => 'Staubbeutel wechseln', '312' => 'Staubbeutel voll',
    ];

    /** Reinigungsart (intern) => Text. */
    private const MODUS = [
        'auto' => 'Auto', 'edge' => 'Kante', 'spot' => 'Punkt', 'spot_area' => 'Räume', 'custom_area' => 'Bereich', 'single_room' => 'Raum',
    ];

    public static function istFehler(string $code): bool
    {
        return $code !== '' && $code !== '0' && $code !== '100';
    }

    public static function fehler(string $code): string
    {
        if (!self::istFehler($code)) {
            return '';
        }
        return self::FEHLER[$code] ?? ('Fehler ' . $code);
    }

    /** Ist das eine Reinigungsart, bei der der Roboter gerade reinigt? */
    public static function reinigtModus(string $modus): bool
    {
        return isset(self::MODUS[$modus]);
    }

    /** Zusammengefasster Zustand fuer die Anzeige und fuer Loxone ("status"). */
    public static function status(array $z): string
    {
        $code = (string)($z['fehler_code'] ?? '');
        if (self::istFehler($code)) {
            return 'Fehler: ' . self::fehler($code);
        }
        $modus = (string)($z['modus'] ?? '');
        if (($z['ladeart'] ?? '') === 'returning' || $modus === 'returning') {
            return 'Fährt zur Ladestation';
        }
        if (self::reinigtModus($modus)) {
            return 'Reinigt (' . self::MODUS[$modus] . ')';
        }
        if ($modus === 'pause') {
            return 'Pausiert';
        }
        if (!empty($z['laedt'])) {
            return (int)($z['akku'] ?? 0) >= 100 ? 'In der Station (voll geladen)' : 'Lädt';
        }
        if (!empty($z['schlaf'])) {
            return 'Ruhezustand';
        }
        return 'Bereit';
    }
}
