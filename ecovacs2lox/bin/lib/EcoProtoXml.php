<?php
/**
 * Altes Protokoll (XML) der Deebot-Modelle der Reihen 500, 600, 700, 900 u. a. Am Deebot 605 (Klasse "DEEBOT 600 Series") geprueft:
 * Abfragen, Antworten und Push-Format. Die Steuerbefehle sind nach der Beschreibung des Protokolls gebaut und am echten Roboter
 * noch nicht ausprobiert (Start, Stopp, Pause, Weiter, Laden, Saug- und Wasserstufe, Ton).
 *
 * Befehle gehen als XML-Text im Feld payload (payloadType x) an die Cloud, die Antwort steht sofort im Feld resp. Der Roboter meldet
 * Aenderungen zusaetzlich per MQTT (Topic iot/atr/<ereignis>/<did>/<klasse>/<resource>/x, Nutzlast XML mit dem Ereignis im Attribut td).
 */
final class EcoProtoXml implements EcoProtokoll
{
    private const TYP_VON = ['auto' => 'auto', 'border' => 'edge', 'spot' => 'spot', 'SpotArea' => 'spot_area', 'singleroom' => 'single_room',
        'stop' => 'stop', 'pause' => 'pause', 'going' => 'returning'];
    private const TYP_NACH = ['auto' => 'auto', 'edge' => 'border', 'spot' => 'spot', 'spot_area' => 'SpotArea', 'single_room' => 'singleroom'];
    private const TEIL = ['Brush' => 'hauptbuerste', 'SideBrush' => 'seitenbuerste', 'sideBrush' => 'seitenbuerste',
        'DustCaseHeap' => 'filter', 'heap' => 'filter'];

    public function name(): string
    {
        return 'xml';
    }

    public function mqttTyp(): string
    {
        return 'x';
    }

    public function pruefAnfrage(): array
    {
        return ['GetBatteryInfo', []];
    }

    public function abfragen(bool $voll): array
    {
        $liste = [['GetCleanState', []], ['GetChargeState', []], ['GetBatteryInfo', []]];
        if ($voll) {
            $liste = array_merge($liste, [['GetCleanSpeed', []], ['GetWaterPermeability', []], ['GetWaterBoxInfo', []], ['GetSleepStatus', []],
                ['GetCleanSum', []], ['GetLifeSpan', ['attrs' => ['type' => 'Brush']]], ['GetLifeSpan', ['attrs' => ['type' => 'SideBrush']]],
                ['GetLifeSpan', ['attrs' => ['type' => 'DustCaseHeap']]], ['GetError', []]]);
        }
        return $liste;
    }

    public function anfrage(string $befehl, array $args): array
    {
        $id = sprintf('%08d', random_int(0, 99999999));
        $xml = EcoXml::bauen(array_merge(['id' => $id], $args['attrs'] ?? []), $args['kind'] ?? null);
        return [$befehl, $xml, 'x'];
    }

    /** Ereignisname vereinheitlichen: ohne Unterstriche, "Get", "Server" und "On" ("OnOff" bleibt). */
    private static function ereignisName(string $e): string
    {
        $e = trim($e, '_');
        $e = (string)preg_replace('/^Get/', '', str_replace('Server', '', $e));
        if (preg_match('/^[Oo]n[A-Z]/', $e)) {
            $e = substr($e, 2);
        }
        // Der Roboter schreibt manche Ereignisse klein (am Deebot 605: td='error'): auf die bekannte Schreibweise bringen
        foreach (['CleanState', 'CleanReport', 'CleanSt', 'ChargeState', 'BatteryInfo', 'CleanSpeed', 'WaterPermeability', 'WaterBoxInfo', 'SleepStatus', 'CleanSum', 'LifeSpan', 'Error'] as $bekannt) {
            if (strcasecmp($bekannt, $e) === 0) {
                return $bekannt;
            }
        }
        return $e;
    }

    public function antwort(string $befehl, $resp): ?array
    {
        $root = is_string($resp) ? EcoXml::parse($resp) : null;
        if ($root === null || ($root['attrs']['ret'] ?? '') !== 'ok') {
            return null;
        }
        $w = $this->werte(self::ereignisName($befehl), $root);
        if (self::ereignisName($befehl) === 'CleanState') {
            unset($w['flaeche_m2'], $w['dauer_s']);         // am Deebot 605 liefert die Abfrage hier Platzhalter (100/100); gueltig sind nur die Push-Meldungen
        }
        if (self::ereignisName($befehl) === 'Error' && !isset($w['fehler_code'])) {
            $w = ['fehler_code' => '0', 'fehler_text' => ''];       // keine Fehlerangabe = kein Fehler
        }
        return $w;
    }

    public function push(string $ereignis, string $nutzlast): ?array
    {
        $root = EcoXml::parse($nutzlast);
        if ($root === null) {
            return null;
        }
        $name = (string)($root['attrs']['td'] ?? $ereignis);
        return $this->werte(self::ereignisName($name), $root);
    }

    /** @return array<string,mixed> */
    private function werte(string $e, array $root): array
    {
        $a = $root['attrs'];
        $k = $root['kinder'] ? $root['kinder'][0]['attrs'] : [];
        $w = [];
        switch ($e) {
            case 'CleanState':
            case 'CleanReport':
                $c = $root['kinder'] ? $k : $a;
                $typ = (string)($c['type'] ?? '');
                $akt = (string)($c['st'] ?? ($c['act'] ?? ''));
                $modus = $typ !== '' ? (self::TYP_VON[$typ] ?? $typ) : null;
                if ($akt === 'h') {
                    $modus = 'stop';
                } elseif ($akt === 'p') {
                    $modus = 'pause';
                }
                if ($modus !== null) {
                    $w['modus'] = $modus;
                }
                if (isset($c['speed'])) {
                    $s = self::saugstufe((string)$c['speed']);
                    if ($s !== null) {
                        $w['saugstufe'] = $s;
                    }
                }
                if (in_array($akt, ['s', 'r', 'p'], true) && isset($c['a'], $c['t'])) {      // laufende Reinigung (Einheiten ungeprueft: m2 und Sekunden)
                    $w['flaeche_m2'] = (int)$c['a'];
                    $w['dauer_s'] = (int)$c['t'];
                }
                break;
            case 'CleanSt':
                if (isset($a['a'], $a['l'])) {
                    $w['flaeche_m2'] = (int)$a['a'];
                    $w['dauer_s'] = (int)$a['l'];
                }
                break;
            case 'ChargeState':
                $t = (string)($k['type'] ?? ($a['type'] ?? ''));
                $art = ['SlotCharging' => 'charging', 'slot_charging' => 'charging', 'WireCharging' => 'charging', 'Going' => 'returning',
                    'going' => 'returning', 'Idle' => 'idle', 'idle' => 'idle'][$t] ?? null;
                if ($art !== null) {
                    $w['ladeart'] = $art;
                    $w['laedt'] = $art === 'charging' ? 1 : 0;
                }
                break;
            case 'BatteryInfo':
                $p = $k['power'] ?? ($a['power'] ?? null);
                if ($p !== null && is_numeric($p)) {
                    $w['akku'] = max(0, min(100, (int)$p));
                    $w['akku_schwach'] = $w['akku'] <= 15 ? 1 : 0;
                }
                break;
            case 'CleanSpeed':
                $s = self::saugstufe((string)($a['speed'] ?? ($k['speed'] ?? '')));
                if ($s !== null) {
                    $w['saugstufe'] = $s;
                }
                break;
            case 'WaterPermeability':
                $v = $a['v'] ?? ($k['v'] ?? null);
                if ($v !== null && is_numeric($v)) {
                    $w['wasserstufe'] = (int)$v;
                }
                break;
            case 'WaterBoxInfo':
                $v = $a['on'] ?? ($k['on'] ?? null);
                if ($v !== null && is_numeric($v)) {
                    $w['wassertank'] = (int)$v ? 1 : 0;
                }
                break;
            case 'SleepStatus':
                $v = $a['st'] ?? ($k['st'] ?? null);
                if ($v !== null && is_numeric($v)) {
                    $w['schlaf'] = (int)$v ? 1 : 0;
                }
                break;
            case 'CleanSum':
                if (isset($a['a'], $a['l'], $a['c'])) {
                    $w['summe_flaeche_m2'] = (int)$a['a'];
                    $w['summe_dauer_s'] = (int)$a['l'];
                    $w['summe_anzahl'] = (int)$a['c'];
                }
                break;
            case 'LifeSpan':
                $teil = self::TEIL[trim((string)($a['type'] ?? ''))] ?? null;
                if ($teil !== null) {
                    $total = (float)($a['total'] ?? 0);
                    if (isset($a['left'], $a['total']) && $total > 0) {
                        $w['verschleiss_' . $teil] = round((float)$a['left'] / $total * 100, 1);      // Rest in Prozent
                    } elseif (isset($a['val'], $a['total']) && $total > 0) {
                        $w['verschleiss_' . $teil] = round((float)$a['val'] / $total * 100, 1);
                    }
                }
                break;
            case 'Error':
                if (($a['error'] ?? '') === 'NoError' && ($a['errs'] ?? '') === '') {      // der 605 meldet "kein Fehler" so (errs leer, error='NoError')
                    $w['fehler_code'] = '0';
                    $w['fehler_text'] = '';
                    break;
                }
                foreach (['new', 'code', 'errs'] as $n) {
                    if (isset($a[$n]) && $a[$n] !== '') {
                        $c = (string)$a[$n];
                        $w['fehler_code'] = $c === '100' ? '0' : $c;
                        $w['fehler_text'] = EcoTexte::fehler($w['fehler_code']);
                        break;
                    }
                }
                break;
        }
        return $w;
    }

    private static function saugstufe(string $s): ?int
    {
        return ['standard' => 2, 'strong' => 3][$s] ?? null;     // normal, stark (mehr Stufen kennt dieses Protokoll nicht)
    }

    public function befehl(string $name, string $wert, array $z): ?array
    {
        $wert = trim($wert);
        $lauf = (string)($z['modus_lauf'] ?? 'auto');
        $typ = self::TYP_NACH[$lauf] ?? 'auto';
        $speed = (int)($z['saugstufe'] ?? 2) >= 3 ? 'strong' : 'standard';
        $clean = fn(string $t, string $act) => [['Clean', ['kind' => ['clean', ['type' => $t, 'speed' => $speed, 'act' => $act]]]]];
        switch ($name) {
            case 'start':
                return $clean(['auto' => 'auto', 'kante' => 'border', 'spot' => 'spot'][$wert] ?? 'auto', 's');
            case 'stopp':
                return $clean('stop', 'h');
            case 'pause':
                return $clean($typ, 'p');
            case 'weiter':
                return $clean($typ, 'r');
            case 'laden':
                return [['Charge', ['kind' => ['charge', ['type' => 'go']]]]];
            case 'saugstufe':
                return [['SetCleanSpeed', ['attrs' => ['speed' => (int)$wert >= 3 ? 'strong' : 'standard']]]];
            case 'wasserstufe':
                return [['SetWaterPermeability', ['attrs' => ['v' => (int)$wert]]]];
            case 'finden':
                return [['PlaySound', ['attrs' => ['sid' => 0]]]];
        }
        return null;
    }
}
