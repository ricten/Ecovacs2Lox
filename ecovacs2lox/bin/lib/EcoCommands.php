<?php
/**
 * Befehle aus Loxone an den Roboter: ecovacs/befehl/<name>/<befehl> = Wert, Quittung unter .../quittung (nie retained).
 *
 * Erlaubt sind nur die hier aufgelisteten Befehle, in drei Stufen, die je Roboter einzeln freizugeben sind (ab Werk alle gesperrt):
 *   steuern   - Start, Stopp, Pause, Weiter, zurueck zur Station
 *   einstellen - Saugstufe, Wasserstufe
 *   finden    - Ton abspielen
 * Nie vorgesehen: Karten loeschen, Zeitplaene, Firmware, Werksreset, Konto- und Netzwerkeinstellungen.
 */
final class EcoCommands
{
    /** Befehl => [Stufe, erlaubte Werte als Regex, Bezeichnung]. */
    public const LISTE = [
        'start' => ['steuern', '/^(1|auto|kante|spot)$/', 'Reinigung starten (1 oder auto = Auto, kante = Kante, spot = Punkt)'],
        'stopp' => ['steuern', '/^1$/', 'Reinigung beenden'],
        'pause' => ['steuern', '/^1$/', 'Reinigung pausieren (nur während der Reinigung)'],
        'weiter' => ['steuern', '/^1$/', 'Pausierte Reinigung fortsetzen'],
        'laden' => ['steuern', '/^1$/', 'Zurück zur Ladestation fahren'],
        'saugstufe' => ['einstellen', '/^[1-4]$/', 'Saugstufe (1 leise, 2 normal, 3 stark, 4 max; ältere Modelle kennen nur normal und stark)'],
        'wasserstufe' => ['einstellen', '/^[1-4]$/', 'Wasserstufe (1 bis 4, nur mit Wassertank)'],
        'finden' => ['finden', '/^1$/', 'Ton abspielen (Roboter finden)'],
    ];

    /** Befehlstopic zerlegen: ecovacs/befehl/<name>/<befehl>; Quittungen (…/quittung) und Fremdes ergeben null. @return array{0:string,1:string}|null */
    public static function parseTopic(string $topic): ?array
    {
        return preg_match('#^ecovacs/befehl/([a-z0-9_-]+)/([a-z0-9_]+)$#', $topic, $m) ? [$m[1], $m[2]] : null;
    }

    /**
     * Prueft einen Befehl gegen Freigabe, Wert und Zustand. Liefert null (in Ordnung) oder den Ablehnungsgrund (ohne "abgelehnt: ").
     * @param array $befehle Freigaben des Roboters (steuern, einstellen, finden)
     * @param array<string,mixed> $z Zustand (modus, ladeart, laedt)
     */
    public static function pruefen(array $befehle, string $befehl, string $wert, array $z): ?string
    {
        if (!isset(self::LISTE[$befehl])) {
            return 'Befehl nicht erlaubt';
        }
        [$stufe, $regex] = self::LISTE[$befehl];
        if (empty($befehle[$stufe])) {
            return 'Befehle sind gesperrt (Stufe „' . EcoConfig::STUFEN[$stufe] . '“ freigeben)';
        }
        if (!preg_match($regex, trim($wert))) {
            return 'ungültiger Wert';
        }
        $modus = (string)($z['modus'] ?? '');
        $reinigt = EcoTexte::reinigtModus($modus);
        if ($befehl === 'start' && $reinigt) {
            return 'der Roboter reinigt bereits';
        }
        if ($befehl === 'pause' && !$reinigt) {
            return 'es läuft keine Reinigung';
        }
        if ($befehl === 'weiter' && $modus !== 'pause') {
            return 'die Reinigung ist nicht pausiert';
        }
        if ($befehl === 'stopp' && !$reinigt && $modus !== 'pause' && ($z['ladeart'] ?? '') !== 'returning') {
            return 'es läuft keine Reinigung';
        }
        if ($befehl === 'laden' && !empty($z['laedt'])) {
            return 'der Roboter ist schon in der Ladestation';
        }
        return null;
    }
}
