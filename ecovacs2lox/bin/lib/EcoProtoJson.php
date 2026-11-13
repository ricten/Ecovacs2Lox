<?php
/**
 * Neues Protokoll (JSON) der Deebot-Modelle ab OZMO 920/950 (T-, N-, X-Reihen u. a.).
 *
 * ACHTUNG: Dieser Teil ist nach der Beschreibung des Protokolls gebaut und am echten Roboter NICHT geprueft (am Entwicklungsort
 * steht nur ein Deebot 605 mit dem alten XML-Protokoll). Er ist fuer Tester gedacht; die Oberflaeche und die Doku weisen darauf hin.
 *
 * Befehle gehen als JSON {header, body:{data}} im Feld payload (payloadType j) an die Cloud; die Antwort steht im Feld resp
 * ({header, body:{code, msg, data}}). Die "V2"-Modelle haengen _V2 an manche Befehle (clean_V2, getCleanInfo_V2).
 */
final class EcoProtoJson implements EcoProtokoll
{
    private const TYP_VON = ['auto' => 'auto', 'edge' => 'edge', 'spot' => 'spot', 'spotArea' => 'spot_area', 'customArea' => 'custom_area',
        'singlePoint' => 'custom_area', 'area' => 'custom_area', 'stop' => 'stop', 'pause' => 'pause', 'goCharging' => 'returning', 'idle' => 'idle'];
    private const TEIL = ['brush' => 'hauptbuerste', 'sideBrush' => 'seitenbuerste', 'heap' => 'filter'];
    private const SAUG_VON = [1000 => 1, 0 => 2, 1 => 3, 2 => 4];
    private const SAUG_NACH = [1 => 1000, 2 => 0, 3 => 1, 4 => 2];

    private bool $v2;

    public function __construct(bool $v2 = false)
    {
        $this->v2 = $v2;
    }

    public function v2(): bool
    {
        return $this->v2;
    }

    public function name(): string
    {
        return 'json';
    }

    public function mqttTyp(): string
    {
        return 'j';
    }

    public function pruefAnfrage(): array
    {
        return ['getBattery', []];
    }

    /** Anfrage zur Erkennung der V2-Befehle (nur bei dieser Familie). */
    public function v2PruefAnfrage(): array
    {
        return ['getCleanInfo_V2', []];
    }

    public function abfragen(bool $voll): array
    {
        $liste = [[$this->v2 ? 'getCleanInfo_V2' : 'getCleanInfo', []], ['getChargeState', []], ['getBattery', []]];
        if ($voll) {
            $liste = array_merge($liste, [['getSpeed', []], ['getWaterInfo', []], ['getStats', []], ['getTotalStats', []],
                ['getLifeSpan', ['liste' => ['brush', 'sideBrush', 'heap']]], ['getError', []], ['getSleep', []]]);
        }
        return $liste;
    }

    public function anfrage(string $befehl, array $args): array
    {
        $daten = isset($args['liste']) ? $args['liste'] : array_merge($args['daten'] ?? [], ['id' => sprintf('%08d', random_int(0, 99999999))]);
        $nutz = ['header' => ['pri' => '1', 'ts' => (int)(microtime(true) * 1000), 'tzm' => 480, 'ver' => '0.0.50'], 'body' => ['data' => $daten]];
        return [$befehl, (string)json_encode($nutz), 'j'];
    }

    /** Ereignisname vereinheitlichen: ohne Unterstriche, "on"/"get" davor und "_V2" dahinter. */
    private static function ereignisName(string $e): string
    {
        $e = trim($e, '_');
        $e = (string)preg_replace('/_V2$/', '', $e);
        $e = (string)preg_replace('/^(on|get)(?=[A-Z])/i', '', $e);
        return $e;
    }

    public function antwort(string $befehl, $resp): ?array
    {
        if (!is_array($resp) || (int)($resp['body']['code'] ?? -1) !== 0) {
            return null;
        }
        $d = $resp['body']['data'] ?? [];
        return $this->werte(self::ereignisName($befehl), is_array($d) ? $d : []);
    }

    public function push(string $ereignis, string $nutzlast): ?array
    {
        $j = json_decode($nutzlast, true);
        if (!is_array($j) || !isset($j['body'])) {
            return null;
        }
        $d = array_key_exists('data', (array)$j['body']) ? $j['body']['data'] : $j['body'];
        return $this->werte(self::ereignisName($ereignis), is_array($d) ? $d : []);
    }

    /** @return array<string,mixed> */
    private function werte(string $e, array $d): array
    {
        $w = [];
        switch ($e) {
            case 'Battery':
                if (isset($d['value']) && is_numeric($d['value'])) {
                    $w['akku'] = max(0, min(100, (int)$d['value']));
                    $w['akku_schwach'] = isset($d['isLow']) ? ((int)$d['isLow'] ? 1 : 0) : ($w['akku'] <= 15 ? 1 : 0);
                }
                break;
            case 'CleanInfo':
                $state = (string)($d['state'] ?? '');
                if ($state === 'clean' && is_array($d['cleanState'] ?? null)) {
                    $cs = $d['cleanState'];
                    $typ = (string)($cs['type'] ?? '');
                    if (is_array($cs['content'] ?? null)) {
                        $typ = (string)($cs['content']['type'] ?? $typ);
                    }
                    $motion = (string)($cs['motionState'] ?? '');
                    $w['modus'] = $motion === 'working' ? (self::TYP_VON[$typ] ?? $typ) : (self::TYP_VON[$motion] ?? $motion);
                } elseif ($state !== '') {
                    $w['modus'] = self::TYP_VON[$state] ?? $state;
                }
                if (($w['modus'] ?? '') === 'returning') {
                    $w['ladeart'] = 'returning';
                    $w['laedt'] = 0;
                }
                break;
            case 'ChargeState':
                if (isset($d['isCharging'])) {
                    $w['laedt'] = (int)$d['isCharging'] === 1 ? 1 : 0;
                    $w['ladeart'] = $w['laedt'] ? 'charging' : 'idle';
                }
                break;
            case 'Speed':
                if (isset($d['speed']) && isset(self::SAUG_VON[(int)$d['speed']])) {
                    $w['saugstufe'] = self::SAUG_VON[(int)$d['speed']];
                }
                break;
            case 'WaterInfo':
                if (isset($d['amount']) && is_numeric($d['amount'])) {
                    $w['wasserstufe'] = (int)$d['amount'];
                }
                if (isset($d['enable']) && is_numeric($d['enable'])) {
                    $w['wassertank'] = (int)$d['enable'] ? 1 : 0;
                }
                break;
            case 'Stats':
                if (isset($d['area'], $d['time'])) {
                    $w['flaeche_m2'] = (int)$d['area'];
                    $w['dauer_s'] = (int)$d['time'];
                }
                break;
            case 'TotalStats':
                if (isset($d['area'], $d['time'], $d['count'])) {
                    $w['summe_flaeche_m2'] = (int)$d['area'];
                    $w['summe_dauer_s'] = (int)$d['time'];
                    $w['summe_anzahl'] = (int)$d['count'];
                }
                break;
            case 'LifeSpan':
                foreach ($d as $eintrag) {
                    $teil = is_array($eintrag) ? (self::TEIL[(string)($eintrag['type'] ?? '')] ?? null) : null;
                    if ($teil !== null && (float)($eintrag['total'] ?? 0) > 0 && isset($eintrag['left'])) {
                        $w['verschleiss_' . $teil] = round((float)$eintrag['left'] / (float)$eintrag['total'] * 100, 1);
                    }
                }
                break;
            case 'Error':
                $c = $d['code'] ?? [];
                $c = is_array($c) ? (count($c) ? (string)end($c) : '0') : (string)$c;
                $w['fehler_code'] = ($c === '' || $c === '100') ? '0' : $c;
                $w['fehler_text'] = EcoTexte::fehler($w['fehler_code']);
                break;
            case 'Sleep':
                if (isset($d['enable']) && is_numeric($d['enable'])) {
                    $w['schlaf'] = (int)$d['enable'] ? 1 : 0;
                }
                break;
        }
        return $w;
    }

    public function befehl(string $name, string $wert, array $z): ?array
    {
        $wert = trim($wert);
        switch ($name) {
            case 'start':
                $typ = ['auto' => 'auto', 'kante' => 'edge', 'spot' => 'spot'][$wert] ?? 'auto';
                if ($this->v2) {
                    return [['clean_V2', ['daten' => ['act' => 'start', 'content' => ['count' => 1, 'donotClean' => '', 'type' => $typ], 'mode' => '', 'router' => 'plan']]]];
                }
                return [['clean', ['daten' => ['act' => 'start', 'count' => 1, 'donotClean' => 0, 'router' => 'plan', 'type' => $typ]]]];
            case 'stopp':
                return [['clean', ['daten' => ['act' => 'stop']]]];
            case 'pause':
                return [['clean', ['daten' => ['act' => 'pause']]]];
            case 'weiter':
                return [['clean', ['daten' => ['act' => 'resume']]]];
            case 'laden':
                return [['charge', ['daten' => ['act' => 'go']]]];
            case 'saugstufe':
                return [['setSpeed', ['daten' => ['speed' => self::SAUG_NACH[max(1, min(4, (int)$wert))]]]]];
            case 'wasserstufe':
                return [['setWaterInfo', ['daten' => ['amount' => max(1, min(4, (int)$wert))]]]];
            case 'finden':
                return [['playSound', ['daten' => ['sid' => 0]]]];
        }
        return null;
    }
}
