<?php
/**
 * Test-Runner ohne Abhaengigkeiten: php tests/run.php
 * Teil 1: Unit-Tests (XML-Leser, Signatur, beide Protokolle gegen echte Antworten eines Deebot 605 und synthetische JSON-Beispiele,
 *         Befehle, Konfiguration, Texte)
 * Teil 2: Ende-zu-Ende: der Dienst (ecovacsd.php im Entwicklungsmodus) gegen eine Fake-Cloud (Anmeldung mit Signaturpruefung,
 *         Verifizierung, Geraeteliste, Befehle), einen Fake-MQTT-Server der Cloud und einen Fake-Broker.
 * Teil 3: Healthcheck, Weboberflaeche mit Attrappen, plugin.cfg, build.sh und ZIP.
 * Nicht getestet werden koennen hier: LoxBerry-Bibliotheken, das echte Aussehen der Oberflaeche, das JSON-Protokoll an einem echten Roboter
 * (nur ein Deebot 605 mit dem XML-Protokoll stand zur Verfuegung) und die Steuerbefehle an einem echten Roboter.
 * Die XML-Antworten unten sind Mitschnitte eines Deebot 605 (ohne Kennungen); die JSON-Beispiele sind nach der Protokollbeschreibung gebaut.
 */
$root = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo'] as $c) {
    require_once $root . '/../common/php/' . $c . '.php';
}
foreach (['EcoKonstanten', 'EcoConfig', 'EcoHttp', 'EcoAuth', 'EcoXml', 'EcoTexte', 'EcoProtokoll', 'EcoProtoXml', 'EcoProtoJson', 'EcoCommands', 'EcoGeraet', 'AppVersion'] as $c) {
    require_once $root . '/ecovacs2lox/bin/lib/' . $c . '.php';
}

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}
function waitFor(callable $f, float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($f()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function startProc(array $cmd, array $env = []): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/eco_test_out.txt', 'a'], 2 => ['file', sys_get_temp_dir() . '/eco_test_err.txt', 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    return [$p, $pipes];
}
function stopProc(array $pp): void
{
    if (!is_resource($pp[0])) {
        return;
    }
    @proc_terminate($pp[0]);
    foreach ($pp[1] as $h) {
        @fclose($h);
    }
    @proc_close($pp[0]);
}
function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$d/$f") ? rrmdir("$d/$f") : @unlink("$d/$f");
        }
    }
    @rmdir($d);
}

$dir = sys_get_temp_dir() . '/eco_test_' . getmypid();
rrmdir($dir);
mkdir($dir, 0777, true);

// ---------------------------------------------------------------- Unit: XML-Leser und -Schreiber
$x = EcoXml::parse("<ctl id='92686810' ret='ok'><clean speed='standard' type='auto' st='h' mode='S' t='100' a='100'/></ctl>");
check('XML: Wurzel, Attribute, Kind', [$x['tag'], $x['attrs'], $x['kinder'][0]['tag'], $x['kinder'][0]['attrs']['st']], ['ctl', ['id' => '92686810', 'ret' => 'ok'], 'clean', 'h']);
$x = EcoXml::parse("<ctl ret='ok' speed=\"standard\"/>");
check('XML: selbstschliessend, doppelte Anfuehrungszeichen', [$x['attrs']['speed'], $x['kinder']], ['standard', []]);
$x = EcoXml::parse("<?xml version='1.0'?><ctl td='Error' new='102' a='x &amp; y'/>");
check('XML: Kopfzeile ignoriert, Entities', [$x['attrs']['new'], $x['attrs']['a']], ['102', 'x & y']);
check('XML: Unlesbares = null', [EcoXml::parse(''), EcoXml::parse('kein xml'), EcoXml::parse('<ctl><a></ctl>'), EcoXml::parse("<ctl/><ctl/>"), EcoXml::parse(str_repeat('<ctl>', 3))], [null, null, null, null, null]);
check('XML: leeres Attribut', EcoXml::parse("<ctl a='' b='1'/>")['attrs'], ['a' => '', 'b' => '1']);
check('XML: bauen ohne Kind', EcoXml::bauen(['id' => '1', 'type' => 'Brush']), "<ctl id='1' type='Brush'/>");
check('XML: bauen mit Kind, Sonderzeichen maskiert', EcoXml::bauen(['id' => '2'], ['clean', ['type' => 'a\'b', 'act' => 's']]), "<ctl id='2'><clean type='a&apos;b' act='s'/></ctl>");
check('XML: gebautes Ergebnis ist wieder lesbar', EcoXml::parse(EcoXml::bauen(['id' => '2'], ['clean', ['type' => 'auto']]))['kinder'][0]['attrs'], ['type' => 'auto']);

// ---------------------------------------------------------------- Unit: Signatur
$s = EcoAuth::signieren(['b' => '2', 'a' => '1'], ['c' => '3', 'a' => '9'], 'KEY', 'SEC');
check('Signatur: sortiert, Parameter vor Meta, Schluessel vorn, Geheimnis hinten', [$s['authSign'], $s['authAppkey']], [md5('KEYa=1b=2c=3SEC'), 'KEY']);
check('Signatur: ohne Meta nur Parameter', EcoAuth::signieren(['x' => 'y'], [], 'K', 'S')['authSign'], md5('Kx=yS'));
$au = new EcoAuth(new EcoHttp(), 'DE', ' a@b.c ', 'pw', str_repeat('a', 32));
check('Auth: resource sind die ersten 8 Zeichen der Kennung, Kennzeichen ohne Klartext', [$au->resource(), strlen($au->kennzeichen()), str_contains($au->kennzeichen(), 'pw')], ['aaaaaaaa', 32, false]);
$tf = $dir . '/token_test.json';
$tok = ['uid' => 'u', 'token' => 't', 'resource' => 'aaaaaaaa', 'gueltig_bis' => time() + 7200, 'kennzeichen' => $au->kennzeichen()];
EcoAuth::tokenSpeichern($tf, $tok);
check('Token: laden, wenn Kennzeichen passt und er noch gilt', $au->tokenLaden($tf)['uid'] ?? null, 'u');
check('Token: anderes Konto = nicht laden', (new EcoAuth(new EcoHttp(), 'de', 'x@y.z', 'pw', str_repeat('a', 32)))->tokenLaden($tf), null);
EcoAuth::tokenSpeichern($tf, ['gueltig_bis' => time() + 100] + $tok);
check('Token: laeuft in unter 10 Minuten ab = nicht laden', $au->tokenLaden($tf), null);
EcoAuth::tokenSpeichern($tf, null);
check('Token: loeschen', is_file($tf), false);

// ---------------------------------------------------------------- Unit: altes Protokoll (XML), echte Antworten eines Deebot 605
$px = new EcoProtoXml();
check('XML-Protokoll: Name und MQTT-Typ', [$px->name(), $px->mqttTyp(), $px->pruefAnfrage()], ['xml', 'x', ['GetBatteryInfo', []]]);
$a = fn(string $cmd, string $xml) => $px->antwort($cmd, $xml);
check('605: GetCleanState (gestoppt)', $a('GetCleanState', "<ctl id='92686810' ret='ok'><clean speed='standard' type='auto' st='h' mode='S' t='100' a='100'/></ctl>"), ['modus' => 'stop', 'saugstufe' => 2]);
check('605: GetChargeState laedt', $a('GetChargeState', "<ctl id='63355117' ret='ok'><charge type='SlotCharging'/></ctl>"), ['ladeart' => 'charging', 'laedt' => 1]);
check('605: GetBatteryInfo 099', $a('GetBatteryInfo', "<ctl id='34434820' ret='ok'><battery power='099'/></ctl>"), ['akku' => 99, 'akku_schwach' => 0]);
check('605: GetCleanSpeed', $a('GetCleanSpeed', "<ctl ret='ok' speed='standard'/>"), ['saugstufe' => 2]);
check('605: GetWaterPermeability', $a('GetWaterPermeability', "<ctl id='16767613' ret='ok' v='4'/>"), ['wasserstufe' => 4]);
check('605: GetWaterBoxInfo', $a('GetWaterBoxInfo', "<ctl id='43762556' ret='ok' on='0'/>"), ['wassertank' => 0]);
check('605: GetSleepStatus', $a('GetSleepStatus', "<ctl ret='ok' st='1'/>"), ['schlaf' => 1]);
check('605: GetCleanSum (fuehrende Nullen)', $a('GetCleanSum', "<ctl id='55524611' ret='ok' a='000013356' l='000582379' c='000000216'/>"), ['summe_flaeche_m2' => 13356, 'summe_dauer_s' => 582379, 'summe_anzahl' => 216]);
check('605: GetLifeSpan Hauptbuerste 15421/18000', $a('GetLifeSpan', "<ctl id='1' ret='ok' type='Brush' left='15421' total='18000'/>"), ['verschleiss_hauptbuerste' => 85.7]);
check('605: GetLifeSpan Seitenbuerste', $a('GetLifeSpan', "<ctl id='1' ret='ok' type='SideBrush' left='06421' total='9000'/>"), ['verschleiss_seitenbuerste' => 71.3]);
check('605: GetLifeSpan Filter', $a('GetLifeSpan', "<ctl id='1' ret='ok' type='DustCaseHeap' left='04621' total='7200'/>"), ['verschleiss_filter' => 64.2]);
check('605: GetError ohne Code = kein Fehler', $a('GetError', "<ctl id='28490003' ret='ok'/>"), ['fehler_code' => '0', 'fehler_text' => '']);
check('XML: ret nicht ok = unbrauchbar', [$a('GetBatteryInfo', "<ctl ret='fail'/>"), $a('GetBatteryInfo', 'Unsinn'), $px->antwort('GetBatteryInfo', null)], [null, null, null]);
check('XML: Akku <= 15 = schwach', $a('GetBatteryInfo', "<ctl ret='ok'><battery power='015'/></ctl>")['akku_schwach'], 1);
check('XML: unbekannte Antwort = leere Liste (kein Fehler)', $a('GetIrgendwas', "<ctl ret='ok' a='1'/>"), []);
// Push-Meldungen
check('XML-Push: Akku', $px->push('BatteryInfo', "<ctl td='BatteryInfo' ts='1'><battery power='042'/></ctl>"), ['akku' => 42, 'akku_schwach' => 0]);
check('XML-Push: Ladezustand "fahrt zur Station"', $px->push('ChargeState', "<ctl td='ChargeState'><charge type='Going'/></ctl>"), ['ladeart' => 'returning', 'laedt' => 0]);
check('XML-Push: Reinigung laeuft (Auto, Flaeche, Zeit)', $px->push('CleanReport', "<ctl td='CleanReport'><clean type='auto' st='s' a='12' t='340' speed='strong'/></ctl>"),
    ['modus' => 'auto', 'saugstufe' => 3, 'flaeche_m2' => 12, 'dauer_s' => 340]);
check('XML-Push: Pause', $px->push('CleanReport', "<ctl td='CleanReport'><clean type='spot' st='p' a='1' t='5'/></ctl>")['modus'], 'pause');
check('XML-Push: Kantenreinigung', $px->push('CleanReport', "<ctl td='CleanReport'><clean type='border' st='s'/></ctl>")['modus'], 'edge');
check('XML-Push: Ende der Reinigung (CleanSt)', $px->push('CleanSt', "<ctl td='CleanSt' a='15' l='600' type='auto'/>"), ['flaeche_m2' => 15, 'dauer_s' => 600]);
check('XML-Push: Fehler 102', $px->push('Error', "<ctl td='Error' new='102'/>"), ['fehler_code' => '102', 'fehler_text' => 'Roboter ist vom Boden abgehoben']);
check('XML-Push (Mitschnitt 605): td="error" klein mit errs und error = Hauptbuerste verheddert', $px->push('error', "<ctl td='error' errs='109' error='RollAbnormal'/>"), ['fehler_code' => '109', 'fehler_text' => 'Hauptbürste verheddert']);
check('XML-Push (Mitschnitt 605): Ladezustand mit Zusatzattributen, Reinigung angehalten (rsn)', [$px->push('ChargeState', "<ctl td='ChargeState'><charge type='Going' h='0' r='a'/></ctl>"), $px->push('CleanReport', "<ctl td='CleanReport'><clean type='auto' speed='standard' st='h' rsn='b' mode='S'/></ctl>")['modus']], [['ladeart' => 'returning', 'laedt' => 0], 'stop']);
check('605: GetCleanState liefert Platzhalter 100/100 fuer Flaeche und Dauer, auch waehrend der Reinigung: nicht uebernehmen', $a('GetCleanState', "<ctl id='1' ret='ok'><clean speed='standard' type='auto' st='s' mode='S' t='100' a='100'/></ctl>"), ['modus' => 'auto', 'saugstufe' => 2]);
check('XML-Push (Mitschnitt 605): errs leer und error=NoError = kein Fehler', $px->push('error', "<ctl td='error' errs='' error='NoError'/>"), ['fehler_code' => '0', 'fehler_text' => '']);
check('XML-Push: Fehler 100 = kein Fehler', $px->push('Error', "<ctl td='Error' new='100'/>")['fehler_code'], '0');
check('XML-Push: Verschleissteil', $px->push('LifeSpan', "<ctl td='LifeSpan' type='SideBrush' left='4500' total='9000'/>"), ['verschleiss_seitenbuerste' => 50.0]);
check('XML-Push: Name mit On-Vorsatz und Unterstrichen', $px->push('__onBattery__', "<ctl td='OnBatteryInfo'><battery power='010'/></ctl>")['akku'] ?? null, 10);
check('XML-Push: Unlesbares = null, Unbekanntes = leer', [$px->push('x', 'kaputt'), $px->push('Pos', "<ctl td='Pos' p='1,2'/>")], [null, []]);
// Befehle bauen
$req = function (EcoProtokoll $p, string $name, string $wert, array $z = []) {
    $l = $p->befehl($name, $wert, $z);
    return $l === null ? null : array_map(fn($e) => [$e[0], preg_replace("/id='\\d{8}'/", "id='N'", $p->anfrage($e[0], $e[1])[1])], $l);
};
check('XML-Befehl start auto', $req($px, 'start', 'auto'), [['Clean', "<ctl id='N'><clean type='auto' speed='standard' act='s'/></ctl>"]]);
check('XML-Befehl start kante mit starker Saugstufe', $req($px, 'start', 'kante', ['saugstufe' => 3]), [['Clean', "<ctl id='N'><clean type='border' speed='strong' act='s'/></ctl>"]]);
check('XML-Befehl start 1 = auto', $req($px, 'start', '1')[0][1], "<ctl id='N'><clean type='auto' speed='standard' act='s'/></ctl>");
check('XML-Befehl stopp', $req($px, 'stopp', '1'), [['Clean', "<ctl id='N'><clean type='stop' speed='standard' act='h'/></ctl>"]]);
check('XML-Befehl pause setzt die zuletzt laufende Art ein, weiter ebenso', [$req($px, 'pause', '1', ['modus_lauf' => 'spot'])[0][1], $req($px, 'weiter', '1', ['modus_lauf' => 'edge'])[0][1]],
    ["<ctl id='N'><clean type='spot' speed='standard' act='p'/></ctl>", "<ctl id='N'><clean type='border' speed='standard' act='r'/></ctl>"]);
check('XML-Befehl laden', $req($px, 'laden', '1'), [['Charge', "<ctl id='N'><charge type='go'/></ctl>"]]);
check('XML-Befehl saugstufe 1/2 = standard, 3/4 = strong', [$req($px, 'saugstufe', '2')[0][1], $req($px, 'saugstufe', '4')[0][1]], ["<ctl id='N' speed='standard'/>", "<ctl id='N' speed='strong'/>"]);
check('XML-Befehl wasserstufe', $req($px, 'wasserstufe', '3'), [['SetWaterPermeability', "<ctl id='N' v='3'/>"]]);
check('XML-Befehl finden', $req($px, 'finden', '1')[0][0], 'PlaySound');
check('XML-Befehl unbekannt = null', $px->befehl('format', '1', []), null);
check('XML: cmdName und payloadType der Anfrage', [$px->anfrage('GetBatteryInfo', [])[0], $px->anfrage('GetBatteryInfo', [])[2]], ['GetBatteryInfo', 'x']);
check('XML: abfragen schnell 3, voll 12 Eintraege, GetOnOff/GetNetInfo nie', [count($px->abfragen(false)), count($px->abfragen(true)), count(array_filter($px->abfragen(true), fn($e) => in_array($e[0], ['GetOnOff', 'GetNetInfo'], true)))], [3, 12, 0]);

// ---------------------------------------------------------------- Unit: neues Protokoll (JSON), synthetische Beispiele
$pj = new EcoProtoJson(true);
$pn = new EcoProtoJson(false);
$j = fn(array $data) => ['header' => ['fwVer' => '1.2'], 'body' => ['code' => 0, 'msg' => 'ok', 'data' => $data]];
check('JSON-Protokoll: Name, MQTT-Typ, V2', [$pj->name(), $pj->mqttTyp(), $pj->v2(), $pn->v2()], ['json', 'j', true, false]);
check('JSON: Akku', $pj->antwort('getBattery', $j(['value' => 88, 'isLow' => 0])), ['akku' => 88, 'akku_schwach' => 0]);
check('JSON: Akku schwach ohne isLow', $pj->antwort('getBattery', $j(['value' => 10]))['akku_schwach'], 1);
check('JSON: Reinigung laeuft (Auto)', $pj->antwort('getCleanInfo_V2', $j(['trigger' => 'app', 'state' => 'clean', 'cleanState' => ['router' => 'plan', 'type' => 'auto', 'motionState' => 'working']])), ['modus' => 'auto']);
check('JSON: Raumreinigung (Inhalt als Objekt)', $pj->antwort('getCleanInfo', $j(['state' => 'clean', 'cleanState' => ['type' => 'spotArea', 'content' => ['type' => 'spotArea', 'value' => '1,2'], 'motionState' => 'working']]))['modus'], 'spot_area');
check('JSON: Pause', $pj->antwort('getCleanInfo', $j(['state' => 'clean', 'cleanState' => ['type' => 'auto', 'motionState' => 'pause']]))['modus'], 'pause');
check('JSON: idle und Rueckfahrt', [$pj->antwort('getCleanInfo', $j(['state' => 'idle']))['modus'], $pj->antwort('getCleanInfo', $j(['state' => 'goCharging']))], ['idle', ['modus' => 'returning', 'ladeart' => 'returning', 'laedt' => 0]]);
check('JSON: Ladezustand', [$pj->antwort('getChargeState', $j(['isCharging' => 1, 'mode' => 'slot'])), $pj->antwort('getChargeState', $j(['isCharging' => 0]))['ladeart']], [['laedt' => 1, 'ladeart' => 'charging'], 'idle']);
check('JSON: Saugstufen der Cloud (1000, 0, 1, 2) = 1 bis 4', array_map(fn($v) => $pj->antwort('getSpeed', $j(['speed' => $v]))['saugstufe'], [1000, 0, 1, 2]), [1, 2, 3, 4]);
check('JSON: Wasser', $pj->antwort('getWaterInfo', $j(['amount' => 2, 'enable' => 1])), ['wasserstufe' => 2, 'wassertank' => 1]);
check('JSON: Statistik laufend und gesamt', [$pj->antwort('getStats', $j(['area' => 5, 'time' => 60, 'type' => 'auto'])), $pj->antwort('getTotalStats', $j(['area' => 100, 'time' => 3600, 'count' => 10]))],
    [['flaeche_m2' => 5, 'dauer_s' => 60], ['summe_flaeche_m2' => 100, 'summe_dauer_s' => 3600, 'summe_anzahl' => 10]]);
check('JSON: Verschleissteile', $pj->antwort('getLifeSpan', $j([['type' => 'brush', 'left' => 5000, 'total' => 10000], ['type' => 'sideBrush', 'left' => 2500, 'total' => 10000], ['type' => 'heap', 'left' => 7500, 'total' => 10000], ['type' => 'unbekannt', 'left' => 1, 'total' => 2]])),
    ['verschleiss_hauptbuerste' => 50.0, 'verschleiss_seitenbuerste' => 25.0, 'verschleiss_filter' => 75.0]);
check('JSON: Fehler (leere Liste = keiner, Code 102)', [$pj->antwort('getError', $j(['code' => []]))['fehler_code'], $pj->antwort('getError', $j(['code' => [0, 102]]))['fehler_code']], ['0', '102']);
check('JSON: Ruhezustand', $pj->antwort('getSleep', $j(['enable' => 1])), ['schlaf' => 1]);
check('JSON: Fehler der Cloud (code != 0) und Unsinn = null', [$pj->antwort('getBattery', ['body' => ['code' => 500]]), $pj->antwort('getBattery', 'x'), $pj->antwort('getBattery', null)], [null, null, null]);
check('JSON-Push: Akku mit on-Vorsatz', $pj->push('onBattery', json_encode($j(['value' => 55]))), ['akku' => 55, 'akku_schwach' => 0]);
check('JSON-Push: ohne data-Feld wird body gelesen', $pj->push('onBattery', json_encode(['body' => ['value' => 44]]))['akku'], 44);
check('JSON-Push: Unsinn = null', $pj->push('onBattery', 'xx'), null);
check('JSON: Anfrage mit Kopf und Kennung', (function () use ($pj) {
    $r = $pj->anfrage('getBattery', []);
    $o = json_decode($r[1], true);
    return [$r[0], $r[2], $o['header']['ver'], preg_match('/^\d{8}$/', $o['body']['data']['id'])];
})(), ['getBattery', 'j', '0.0.50', 1]);
check('JSON: getLifeSpan sendet die Liste ohne Kennung', json_decode($pj->anfrage('getLifeSpan', ['liste' => ['brush', 'sideBrush', 'heap']])[1], true)['body']['data'], ['brush', 'sideBrush', 'heap']);
$jreq = function (EcoProtokoll $p, string $name, string $wert) {
    $l = $p->befehl($name, $wert, []);
    return array_map(function ($e) use ($p) {
        $o = json_decode($p->anfrage($e[0], $e[1])[1], true)['body']['data'];
        unset($o['id']);
        return [$e[0], $o];
    }, $l);
};
check('JSON-Befehl start (V2)', $jreq($pj, 'start', 'spot'), [['clean_V2', ['act' => 'start', 'content' => ['count' => 1, 'donotClean' => '', 'type' => 'spot'], 'mode' => '', 'router' => 'plan']]]);
check('JSON-Befehl start (ohne V2)', $jreq($pn, 'start', 'kante'), [['clean', ['act' => 'start', 'count' => 1, 'donotClean' => 0, 'router' => 'plan', 'type' => 'edge']]]);
check('JSON-Befehle stopp, pause, weiter, laden', [$jreq($pj, 'stopp', '1')[0], $jreq($pj, 'pause', '1')[0], $jreq($pj, 'weiter', '1')[0], $jreq($pj, 'laden', '1')[0]],
    [['clean', ['act' => 'stop']], ['clean', ['act' => 'pause']], ['clean', ['act' => 'resume']], ['charge', ['act' => 'go']]]);
check('JSON-Befehle saugstufe (1 bis 4 = 1000, 0, 1, 2), wasserstufe, finden', [array_map(fn($v) => $jreq($pj, 'saugstufe', (string)$v)[0][1]['speed'], [1, 2, 3, 4]), $jreq($pj, 'wasserstufe', '3')[0], $jreq($pj, 'finden', '1')[0]],
    [[1000, 0, 1, 2], ['setWaterInfo', ['amount' => 3]], ['playSound', ['sid' => 0]]]);
check('JSON: abfragen V2 und ohne V2', [$pj->abfragen(false)[0][0], $pn->abfragen(false)[0][0], count($pj->abfragen(true))], ['getCleanInfo_V2', 'getCleanInfo', 10]);

// ---------------------------------------------------------------- Unit: Befehle, Texte
$frei = ['steuern' => true, 'einstellen' => true, 'finden' => true];
check('Befehlstopic zerlegen', [EcoCommands::parseTopic('ecovacs/befehl/sauger/start'), EcoCommands::parseTopic('ecovacs/befehl/sauger/start/quittung'), EcoCommands::parseTopic('anders/befehl/x/y')], [['sauger', 'start'], null, null]);
check('Befehl: unbekannt', EcoCommands::pruefen($frei, 'format', '1', []), 'Befehl nicht erlaubt');
check('Befehl: gesperrt mit Stufenname', EcoCommands::pruefen(['steuern' => false], 'start', 'auto', []), 'Befehle sind gesperrt (Stufe „Start, Stopp, Pause, Weiter, zurück zur Station“ freigeben)');
check('Befehl: ungueltiger Wert', [EcoCommands::pruefen($frei, 'saugstufe', '9', []), EcoCommands::pruefen($frei, 'start', 'tief', []), EcoCommands::pruefen($frei, 'pause', '0', ['modus' => 'auto'])], ['ungültiger Wert', 'ungültiger Wert', 'ungültiger Wert']);
check('Befehl: start, wenn er schon reinigt', EcoCommands::pruefen($frei, 'start', 'auto', ['modus' => 'auto']), 'der Roboter reinigt bereits');
check('Befehl: start im Stillstand ok', EcoCommands::pruefen($frei, 'start', 'auto', ['modus' => 'stop', 'laedt' => 1]), null);
check('Befehl: pause nur waehrend der Reinigung, weiter nur nach Pause', [EcoCommands::pruefen($frei, 'pause', '1', ['modus' => 'stop']), EcoCommands::pruefen($frei, 'pause', '1', ['modus' => 'spot']), EcoCommands::pruefen($frei, 'weiter', '1', ['modus' => 'auto']), EcoCommands::pruefen($frei, 'weiter', '1', ['modus' => 'pause'])],
    ['es läuft keine Reinigung', null, 'die Reinigung ist nicht pausiert', null]);
check('Befehl: stopp bei Reinigung, Pause und Rueckfahrt ok, sonst nicht', [EcoCommands::pruefen($frei, 'stopp', '1', ['modus' => 'auto']), EcoCommands::pruefen($frei, 'stopp', '1', ['modus' => 'pause']), EcoCommands::pruefen($frei, 'stopp', '1', ['ladeart' => 'returning']), EcoCommands::pruefen($frei, 'stopp', '1', ['modus' => 'stop'])],
    [null, null, null, 'es läuft keine Reinigung']);
check('Befehl: laden nicht, wenn er schon in der Station laedt', [EcoCommands::pruefen($frei, 'laden', '1', ['laedt' => 1]), EcoCommands::pruefen($frei, 'laden', '1', ['modus' => 'auto'])], ['der Roboter ist schon in der Ladestation', null]);
check('Befehl: Stufen einzeln freigebbar', [EcoCommands::pruefen(['steuern' => true, 'einstellen' => false, 'finden' => false], 'saugstufe', '2', []) !== null, EcoCommands::pruefen(['finden' => true], 'finden', '1', []), count(EcoCommands::LISTE)], [true, null, 8]);
check('Texte: Status', [EcoTexte::status([]), EcoTexte::status(['laedt' => 1, 'akku' => 80]), EcoTexte::status(['laedt' => 1, 'akku' => 100]), EcoTexte::status(['modus' => 'auto']), EcoTexte::status(['modus' => 'edge']),
    EcoTexte::status(['modus' => 'pause']), EcoTexte::status(['ladeart' => 'returning']), EcoTexte::status(['schlaf' => 1]), EcoTexte::status(['fehler_code' => '105', 'modus' => 'auto'])],
    ['Bereit', 'Lädt', 'In der Station (voll geladen)', 'Reinigt (Auto)', 'Reinigt (Kante)', 'Pausiert', 'Fährt zur Ladestation', 'Ruhezustand', 'Fehler: Roboter steckt fest']);
check('Texte: Fehler 0 und 100 sind keine Fehler, unbekannter Code', [EcoTexte::istFehler('0'), EcoTexte::istFehler('100'), EcoTexte::istFehler('7'), EcoTexte::fehler('9999')], [false, false, true, 'Fehler 9999']);

// ---------------------------------------------------------------- Unit: Konfiguration
$c0 = EcoConfig::load($dir . '/gibtsnicht.json');
check('Konfiguration: Standardwerte (Land de, keine Kennung, 300 s, keine Geraete)', [$c0['konto']['land'], $c0['geraete_id'], $c0['abfrage_abstand'], $c0['geraete'], EcoConfig::kontoVollstaendig($c0)], ['de', '', 300, [], false]);
$p1 = EcoConfig::fromPost($c0, ['konto' => ['email' => ' me@example.invalid ', 'passwort' => 'geheim1234', 'land' => 'ch']]);
check('Konfiguration: Konto speichern, Land, Kennung wird erzeugt (32 Hex)', [$p1['konto']['email'], $p1['konto']['land'], (bool)preg_match('/^[0-9a-f]{32}$/', $p1['geraete_id']), EcoConfig::kontoVollstaendig($p1)], ['me@example.invalid', 'ch', true, true]);
$p2 = EcoConfig::fromPost($p1, ['konto' => ['email' => 'me@example.invalid', 'passwort' => '', 'land' => 'ch']]);
check('Konfiguration: leeres Passwortfeld behaelt das Passwort, Kennung bleibt', [$p2['konto']['passwort'], $p2['geraete_id']], ['geheim1234', $p1['geraete_id']]);
check('Konfiguration: unbekanntes Land faellt auf de', EcoConfig::fromPost($p2, ['konto' => ['email' => 'a', 'land' => 'cn']])['konto']['land'], 'de');
check('Konfiguration: Kontinent je Land', [EcoConfig::kontinent('de'), EcoConfig::kontinent('us'), EcoConfig::kontinent('jp'), EcoConfig::kontinent('zz')], ['eu', 'na', 'as', 'eu']);
$p3 = EcoConfig::fromPost($p2, ['abfrage_abstand' => '5', 'geraete' => ['did1' => ['aktiv' => '1', 'name' => 'Küche Sauger!', 'protokoll' => 'json', 'befehle' => ['steuern' => '1']],
    'did2' => ['aktiv' => '1', 'name' => 'Küche Sauger!', 'protokoll' => 'quatsch'], 'bö$e' => ['aktiv' => '1']]]);
check('Konfiguration: Abstand mindestens 60, Namen ASCII ohne Leerzeichen, doppelte nummeriert, Protokoll geprueft, Kennung bereinigt',
    [$p3['abfrage_abstand'], $p3['geraete']['did1']['name'], $p3['geraete']['did2']['name'], $p3['geraete']['did2']['protokoll'], $p3['geraete']['did1']['protokoll'], isset($p3['geraete']['b$e']), array_keys($p3['geraete'])],
    [60, 'kueche_sauger', 'kueche_sauger_2', 'auto', 'json', false, ['did1', 'did2', 'be']]);
check('Konfiguration: Befehlsstufen einzeln, Rest gesperrt', [$p3['geraete']['did1']['befehle'], $p3['geraete']['did2']['befehle']], [['steuern' => true, 'einstellen' => false, 'finden' => false], ['steuern' => false, 'einstellen' => false, 'finden' => false]]);
$gef = [['did' => 'did1', 'class' => 'dl8fht', 'resource' => 'ab', 'company' => 'eco-ng', 'nick' => 'Wohnen', 'modell' => 'M1'], ['did' => 'did2', 'class' => 'x', 'resource' => 'cd', 'nick' => 'Wohnen'], ['did' => 'did3', 'class' => 'y', 'nick' => ''], ['did' => '', 'class' => 'z']];
$rb = EcoConfig::roboter($p3, $gef);
check('Roboter: Name aus der Konfiguration, sonst aus dem Cloud-Namen, sonst roboter<Nr>; doppelte nummeriert; leere Kennung uebersprungen',
    array_map(fn($r) => $r['name'], $rb), ['kueche_sauger', 'kueche_sauger_2', 'roboter3']);
$p4 = $p3;
$p4['geraete']['did2']['aktiv'] = false;
$p4['geraete']['did1']['name'] = '';
check('Roboter: inaktive fehlen, Name leer = Cloud-Name (ohne Leerzeichen)', array_map(fn($r) => $r['name'], EcoConfig::roboter($p4, $gef)), ['wohnen', 'roboter3']);
check('Roboter: Klasse, resource, Modell und Freigaben werden mitgegeben', [$rb[0]['class'], $rb[0]['resource'], $rb[0]['modell'], $rb[0]['befehle']['steuern']], ['dl8fht', 'ab', 'M1', true]);
check('Roboter: hoechstens fuenf', count(EcoConfig::roboter($c0, array_map(fn($i) => ['did' => "d$i", 'class' => 'c', 'nick' => "r$i"], range(1, 8)))), 5);
check('Geheimnisse: Konto- und Brokerpasswort, nichts Kurzes', EcoConfig::secrets(['konto' => ['passwort' => 'geheim1234'], 'mqtt' => ['passwort' => 'abc']]), ['geheim1234']);
check('Eigener Broker: nur mit Haken und Host', [EcoConfig::ownBroker($c0), EcoConfig::ownBroker(['mqtt' => ['eigener_broker' => true, 'host' => 'b.lan', 'port' => 0, 'benutzer' => 'u', 'passwort' => 'p']])], [null, ['b.lan', 1883, 'u', 'p']]);
Util::jsonSave($dir . '/cfg.json', $p3);
check('Konfigurationsdatei: Rechte 0600 (ausser unter Windows)', PHP_OS_FAMILY === 'Windows' ? '600' : substr(sprintf('%o', fileperms($dir . '/cfg.json')), -3), '600');
check('Konfiguration laden = gespeichert', EcoConfig::load($dir . '/cfg.json')['geraete']['did1']['name'], 'kueche_sauger');
putenv('ECO_DEV_CLOUD=http://127.0.0.1:1');
check('Cloud-Adressen im Test umleitbar, MQTT-Server der Cloud', [EcoHttp::url('portal', 'de', 'users/user.do'), EcoHttp::url('api', 'de', '/x')], ['http://127.0.0.1:1/portal/users/user.do', 'http://127.0.0.1:1/api/x']);
putenv('ECO_DEV_CLOUD');
check('Cloud-Adressen echt: Portal, Anmeldung, MQTT-Server', [EcoHttp::url('portal', 'de', 'iot/devmanager.do'), EcoHttp::url('api', 'DE', 'v1/x'), EcoHttp::url('openapi', 'de', 'v1/y'), EcoHttp::mqttServer('de'), EcoHttp::mqttServer('us')[0]],
    ['https://api-app.dc-eu.ww.ecouser.net/api/iot/devmanager.do', 'https://gl-de-api.ecovacs.com/v1/x', 'https://gl-de-openapi.ecovacs.com/v1/y', ['mq-eu.ecouser.net', 8883, true], 'mq-na.ecouser.net']);

// ---------------------------------------------------------------- Prozesse (Fake-Cloud, Fake-Broker)
$php = PHP_BINARY;
$phpOpt = [];
if (php_ini_loaded_file()) {
    $phpOpt = ['-c', php_ini_loaded_file()];
} elseif (PHP_OS_FAMILY === 'Windows') {
    $phpOpt = ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=openssl', '-d', 'extension=curl'];
}
$ext = extension_loaded('openssl') && extension_loaded('curl');
if (!$ext) {
    $fails++;
    $count++;
    echo "FEHLT: PHP-Erweiterungen openssl und curl; Ende-zu-Ende-Tests uebersprungen\n";
}
$mqPort = 18861;       // Broker des LoxBerry
$emPort = 18862;       // MQTT-Server der Cloud
$clPort = 18863;       // Fake-Cloud
$mdir = "$dir/mq";
$edir = "$dir/em";
$cdir = "$dir/cloud";
foreach ([$mdir, $edir, $cdir] as $d) {
    mkdir($d, 0777, true);
}
$mlog = fn() => (string)@file_get_contents($mdir . '/mqtt.log');
$elog = fn() => (string)@file_get_contents($edir . '/mqtt.log');
$clog = fn() => (string)@file_get_contents($cdir . '/cloud.log');
$has = fn(string $topic, string $val) => (bool)preg_match('/' . preg_quote($topic, '/') . "\t" . preg_quote($val, '/') . '(\r?\n|$)/', $mlog());
// Nachrichten einspeisen und warten, bis der Fake-Broker sie abgeholt hat (sonst ueberschreibt die naechste die vorige)
$einspeisen = function (string $d, string $zeilen): void {
    waitFor(fn() => !is_file($d . '/inject.txt'), 3);
    file_put_contents($d . '/inject.txt', $zeilen);
    waitFor(fn() => !is_file($d . '/inject.txt'), 3);
};
$inject = fn(string $zeilen) => $einspeisen($mdir, $zeilen);
$injectE = fn(string $zeilen) => $einspeisen($edir, $zeilen);
$nachAbfrage = function (string $befehl) use ($cdir): void {
    $n = fn() => substr_count((string)@file_get_contents($cdir . '/cloud.log'), 'CMD ' . $befehl);
    $vorher = $n();
    waitFor(fn() => $n() > $vorher, 8);
    usleep(400000);
};
$cloudCtl = fn(array $c) => file_put_contents($cdir . '/cloud_ctl.json', json_encode($c));
const EMAIL = 'test@example.invalid';
const PW = 'geheim1234';
const GID = 'abcdef0123456789abcdef0123456789';
$resetCloud = function (array $ctl = []) use ($cdir, $cloudCtl) {
    foreach (['cloud.log', 'cloud_state.json'] as $f) {
        @unlink("$cdir/$f");
    }
    $cloudCtl($ctl + ['modus' => 'xml', 'email' => EMAIL, 'passwort' => PW, 'verifiziert' => true, 'offline' => false, 'unsupported' => []]);
};

if ($ext) {
    $mqProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$mqPort, $mdir]);
    $emProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$emPort, $edir]);
    $clProc = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_cloud.php', (string)$clPort, $cdir]));
    foreach ([$mqPort, $emPort, $clPort] as $pt) {
        waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pt", $e, $es, 1) !== false, 8);
    }
    $baseEnv = fn(string $d, int $cloud = 0) => ['ECO_DEV_DIR' => $d, 'ECO_DEV_MQTT' => "127.0.0.1:$mqPort", 'ECO_DEV_CLOUD' => 'http://127.0.0.1:' . ($cloud ?: $clPort),
        'ECO_DEV_ECOMQTT' => "127.0.0.1:$emPort", 'ECO_DEV_LOGLEVEL' => 'debug'];
    $starte = fn(string $d, array $env = []) => startProc(array_merge([$php], $phpOpt, [$root . '/ecovacs2lox/bin/ecovacsd.php']), $env + $baseEnv($d));
    /** Neues Dienst-Verzeichnis mit Konfiguration. */
    $neuDir = function (string $name, array $konfig = []) use ($dir): string {
        $d = "$dir/$name";
        rrmdir($d);
        mkdir($d, 0777, true);
        $c = EcoConfig::defaults();
        $c['konto'] = ['email' => EMAIL, 'passwort' => PW, 'land' => 'de'];
        $c['geraete_id'] = GID;
        $c['abfrage_abstand'] = 60;
        $c['geraete'] = ['testdid01' => ['aktiv' => true, 'name' => 'sauger', 'protokoll' => 'auto', 'befehle' => ['steuern' => false, 'einstellen' => false, 'finden' => false]]];
        Util::jsonSave("$d/ecovacs.json", array_replace_recursive($c, $konfig));
        return $d;
    };
    $reset = function () use ($mdir, $edir) {
        foreach ([$mdir, $edir] as $d) {
            @unlink("$d/mqtt.log");
            @unlink("$d/inject.txt");
        }
    };
    $statusVon = fn(string $d) => Util::jsonLoad("$d/run/status.json");
    $stopWait = function (array $svc) {
        stopProc($svc);
        usleep(600000);
    };

    // ---------------------------------------------------------------- A: Altmodell (XML), Verifizierung, Werte, Push, Befehle
    $resetCloud(['verifiziert' => false]);
    $reset();
    $dA = $neuDir('A');
    putenv('ECO_DEV_CLOUD=http://127.0.0.1:' . $clPort);
    $svc = $starte($dA);
    $logA = fn() => (string)@file_get_contents("$dA/ecovacs.log");
    check('A: Letzter Wille fuer status/ok beim Broker angemeldet', waitFor(fn() => str_contains($mlog(), "CONNECT\tecovacs_ecovacs2lox\tecovacs/status/ok"), 12), true);
    check('A: Ecovacs verlangt Verifizierung (Anmeldung 1013)', waitFor(fn() => ($statusVon($dA)['anmeldung']['status'] ?? '') === 'verifizierung', 12), true);
    check('A: status/ok ist 0, solange nicht angemeldet', waitFor(fn() => $has('ecovacs/status/ok', '0'), 12), true);
    check('A: Anmeldung mit gueltiger Signatur (Fake-Cloud prueft sie), kein Passwort im Klartext in der Anfrage', [str_contains($clog(), 'GET user/login'), str_contains($clog(), PW)], [true, false]);
    // die Weboberflaeche loest die Verifizierung aus (hier mit denselben Klassen)
    $ui = new EcoAuth(new EcoHttp(), 'de', EMAIL, PW, GID);
    $rc = $ui->codeAnfordern();
    check('Verifizierung: Code anfordern (E-Mail wird mit dem Schluessel der Cloud verschluesselt)', [$rc['ok'], str_contains($clog(), 'GET user/sendEmailVerifyCode')], [true, true]);
    check('Verifizierung: falscher Code', $ui->codePruefen('000000')['status'], 'code');
    check('Verifizierung: Unsinn als Code wird gar nicht gesendet', $ui->codePruefen('12 3')['status'], 'code');
    $rv = $ui->codePruefen('123456');
    check('Verifizierung: richtiger Code liefert Token', [$rv['status'], $rv['token']['uid'] ?? null, $rv['token']['resource'] ?? null], ['ok', 'testuid', 'abcdef01']);
    EcoAuth::tokenSpeichern("$dA/data/token.json", $rv['token']);
    check('A: Dienst uebernimmt den gespeicherten Token (angemeldet)', waitFor(fn() => ($statusVon($dA)['anmeldung']['status'] ?? '') === 'ok', 15), true);
    check('A: Geraeteliste: ein Roboter, Datei fuer die Oberflaeche', waitFor(fn() => (Util::jsonLoad("$dA/data/geraete.json")['geraete'][0]['did'] ?? '') === 'testdid01', 8), true);
    check('A: Protokoll XML erkannt', waitFor(fn() => $has('ecovacs/sauger/protokoll', 'xml'), 15), true);
    check('A: Modell aus der Cloud', $has('ecovacs/sauger/modell', 'DEEBOT 600 Series'), true);
    check('A: Werte wie am Deebot 605 (Akku, laedt, Saugstufe, Wasser, Summen, Verschleiss, kein Fehler)', waitFor(fn() => $has('ecovacs/sauger/akku', '99') && $has('ecovacs/sauger/laedt', '1') && $has('ecovacs/sauger/saugstufe', '2')
        && $has('ecovacs/sauger/wasserstufe', '4') && $has('ecovacs/sauger/summe_anzahl', '216') && $has('ecovacs/sauger/verschleiss_filter', '50') && $has('ecovacs/sauger/fehler_code', '0'), 15), true);
    check('A: Status Laedt, Modus stop, reinigt 0, schlaf 1', [$has('ecovacs/sauger/status', 'Lädt'), $has('ecovacs/sauger/modus', 'stop'), $has('ecovacs/sauger/reinigt', '0'), $has('ecovacs/sauger/schlaf', '1')], [true, true, true, true]);
    check('A: Status wurde nie vorzeitig als "Bereit" gesendet', str_contains($mlog(), "ecovacs/sauger/status\tBereit"), false);
    check('A: status/ok 1', waitFor(fn() => $has('ecovacs/status/ok', '1'), 15), true);
    check('A: Lebenszeichen zaehler und ts', waitFor(fn() => (bool)preg_match('/ecovacs\/status\/zaehler\t\d+/', $mlog()) && (bool)preg_match('/ecovacs\/status\/ts\t\d{10}/', $mlog())), true);
    check('A: MQTT der Cloud: Benutzer@Realm/resource und Abo auf die Meldungen des Roboters', waitFor(fn() => str_contains($elog(), "CONNECT\ttestuid@ecouser/abcdef01") && str_contains($elog(), "SUB\tiot/atr/+/testdid01/dl8fht/abcd/x"), 12), true);
    // Push-Meldung des Roboters
    $nachAbfrage('GetBatteryInfo');
    $injectE("iot/atr/BatteryInfo/testdid01/dl8fht/abcd/x\t<ctl td='BatteryInfo' ts='1'><battery power='042'/></ctl>\n");
    check('A: Push-Meldung Akku 42 kommt an', waitFor(fn() => $has('ecovacs/sauger/akku', '42'), 8), true);
    $injectE("iot/atr/Error/testdid01/dl8fht/abcd/x\t<ctl td='Error' new='105'/>\n");
    check('A: Push-Fehler 105: Code, Text und Status', waitFor(fn() => $has('ecovacs/sauger/fehler_code', '105') && $has('ecovacs/sauger/status', 'Fehler: Roboter steckt fest'), 8), true);
    @unlink($mdir . '/mqtt.log');
    $injectE("iot/atr/Error/testdid01/dl8fht/abcd/x\t<ctl td='Error' new='100'/>\n");
    check('A: Push-Fehler 100 = Fehler weg', waitFor(fn() => $has('ecovacs/sauger/fehler_code', '0') && $has('ecovacs/sauger/status', 'Lädt'), 8), true);
    $injectE("iot/atr/BatteryInfo/andere/dl8fht/abcd/x\t<ctl td='BatteryInfo'><battery power='011'/></ctl>\n");
    sleep(2);
    check('A: Meldung eines fremden Roboters wird ignoriert', $has('ecovacs/sauger/akku', '11'), false);
    // nur Aenderungen
    @unlink($mdir . '/mqtt.log');
    sleep(4);
    check('A: nur Aenderungen: gleiche Werte werden nicht erneut gesendet', [str_contains($mlog(), "ecovacs/sauger/summe_anzahl\t"), str_contains($mlog(), "ecovacs/sauger/protokoll\t"), str_contains($mlog(), "ecovacs/sauger/verschleiss_filter\t")], [false, false, false]);
    // Zugangsdaten nie im Log, in MQTT oder im Status
    $alles = $logA() . $mlog() . $elog() . (string)@file_get_contents("$dA/run/status.json") . (string)@file_get_contents("$dA/data/geraete.json");
    check('A: Passwort und Zugriffstoken nirgends im Log, in MQTT, in der Geraeteliste oder im Status', [str_contains($alles, PW), str_contains($alles, 'testtoken'), str_contains($alles, 'testat'), str_contains((string)file_get_contents("$dA/data/token.json"), PW)], [false, false, false, false]);
    check('A: Token-Datei im Datenordner (Rechte 0600 ausser unter Windows)', PHP_OS_FAMILY === 'Windows' ? '600' : substr(sprintf('%o', fileperms("$dA/data/token.json")), -3), '600');

    // Befehle
    $reset();
    $inject("ecovacs/befehl/sauger/start\tauto\n");
    check('A: Befehl gesperrt (keine Stufe frei)', waitFor(fn() => str_contains($mlog(), "ecovacs/befehl/sauger/start/quittung\tabgelehnt: Befehle sind gesperrt"), 8), true);
    check('A: gesperrt: nichts an die Cloud gesendet', str_contains($clog(), 'CMD Clean'), false);
    $reset();
    $inject("ecovacs/befehl/gibtsnicht/start\tauto\n");
    check('A: Befehl fuer unbekannten Roboter', waitFor(fn() => str_contains($mlog(), "ecovacs/befehl/gibtsnicht/start/quittung\tabgelehnt: unbekanntes Geraet"), 8), true);
    $conf = EcoConfig::load("$dA/ecovacs.json");
    $conf['geraete']['testdid01']['befehle'] = ['steuern' => true, 'einstellen' => true, 'finden' => false];
    sleep(1);
    Util::jsonSave("$dA/ecovacs.json", $conf);
    sleep(2);
    $reset();
    $inject("ecovacs/befehl/sauger/start\tauto\n");
    check('A: Befehl start: erst angenommen, dann ausgefuehrt', waitFor(fn() => str_contains($mlog(), "start/quittung\tangenommen") && str_contains($mlog(), "start/quittung\tausgefuehrt"), 15), true);
    check('A: Cloud bekam das XML des Befehls (Clean, Auto, Start)', (bool)preg_match("/CMD Clean <ctl id='\\d{8}'><clean type='auto' speed='standard' act='s'\\/><\\/ctl>/", $clog()), true);
    check('A: nach dem Befehl meldet der Roboter "Reinigt (Auto)"', waitFor(fn() => $has('ecovacs/sauger/status', 'Reinigt (Auto)') && $has('ecovacs/sauger/reinigt', '1') && $has('ecovacs/sauger/laedt', '0'), 12), true);
    $reset();
    $inject("ecovacs/befehl/sauger/start\tauto\n");
    check('A: start, waehrend er reinigt = abgelehnt', waitFor(fn() => str_contains($mlog(), "start/quittung\tabgelehnt: der Roboter reinigt bereits"), 8), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/pause\t1\n");
    check('A: pause ausgefuehrt (Clean mit act p)', waitFor(fn() => str_contains($mlog(), "pause/quittung\tausgefuehrt") && str_contains($clog(), "act='p'"), 12), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/saugstufe\t3\n");
    check('A: saugstufe 3 = strong gesendet und ausgefuehrt', waitFor(fn() => str_contains($mlog(), "saugstufe/quittung\tausgefuehrt") && (bool)preg_match("/CMD SetCleanSpeed <ctl id='\\d{8}' speed='strong'\\/>/", $clog()), 12), true);
    check('A: danach meldet der Roboter Saugstufe 3', waitFor(fn() => $has('ecovacs/sauger/saugstufe', '3'), 10), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/wasserstufe\t2\n");
    check('A: wasserstufe 2 an die Cloud', waitFor(fn() => str_contains($mlog(), "wasserstufe/quittung\tausgefuehrt") && (bool)preg_match("/CMD SetWaterPermeability <ctl id='\\d{8}' v='2'\\/>/", $clog()), 12), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/saugstufe\t9\n");
    check('A: ungueltiger Wert abgelehnt', waitFor(fn() => str_contains($mlog(), "saugstufe/quittung\tabgelehnt: ungültiger Wert"), 8), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/finden\t1\n");
    check('A: Stufe "finden" ist gesperrt, auch wenn andere Stufen frei sind', waitFor(fn() => str_contains($mlog(), 'finden/quittung\tabgelehnt: Befehle sind gesperrt (Stufe „Ton abspielen“'), 8) || str_contains($mlog(), "finden/quittung\tabgelehnt: Befehle sind gesperrt"), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/stopp\t1\n");
    check('A: stopp ausgefuehrt (Clean mit act h)', waitFor(fn() => str_contains($mlog(), "stopp/quittung\tausgefuehrt") && str_contains($clog(), "type='stop' speed='strong' act='h'"), 12), true);
    $reset();
    sleep(1);
    $inject("ecovacs/befehl/sauger/laden\t1\n");
    check('A: laden: Cloud bekommt Charge/go', waitFor(fn() => str_contains($mlog(), "laden/quittung\tausgefuehrt") && (bool)preg_match("/CMD Charge <ctl id='\\d{8}'><charge type='go'\\/><\\/ctl>/", $clog()), 12), true);
    check('A: danach "Faehrt zur Ladestation"', waitFor(fn() => $has('ecovacs/sauger/status', 'Fährt zur Ladestation') && $has('ecovacs/sauger/zurueck', '1'), 10), true);
    check('A: Befehle ohne Wiederholung schneller als einmal je Sekunde werden abgelehnt', (function () use ($inject, $mlog, $reset) {
        $reset();
        $inject("ecovacs/befehl/sauger/saugstufe\t2\necovacs/befehl/sauger/saugstufe\t3\n");
        return waitFor(fn() => str_contains($mlog(), 'abgelehnt: zu schnell'), 8);
    })(), true);

    // Roboter nicht erreichbar (4200) ist kein Fehler
    $cloudCtl(['modus' => 'xml', 'email' => EMAIL, 'passwort' => PW, 'verifiziert' => true, 'offline' => true, 'unsupported' => []]);
    $reset();
    check('A: Roboter aus (Cloud 4200): online 0, status/ok bleibt 1', waitFor(fn() => $has('ecovacs/sauger/online', '0'), 15), true);
    check('A: Roboter aus: status.json aus=true, ok=true, Fehlertext leer', [$statusVon($dA)['roboter']['sauger']['aus'] ?? null, $statusVon($dA)['roboter']['sauger']['ok'] ?? null, $statusVon($dA)['roboter']['sauger']['error'] ?? null], [true, true, '']);
    check('A: Roboter aus: status/ok ist nie 0', $has('ecovacs/status/ok', '0'), false);
    $cloudCtl(['modus' => 'xml', 'email' => EMAIL, 'passwort' => PW, 'verifiziert' => true, 'offline' => false, 'unsupported' => []]);
    check('A: Roboter wieder da: online 1', waitFor(fn() => $has('ecovacs/sauger/online', '1'), 15), true);

    // Neustart: gespeicherter Token wird wiederverwendet, keine neue Anmeldung
    $logins = substr_count($clog(), 'GET user/login');
    $stopWait($svc);
    $reset();
    $svc = $starte($dA);
    check('A: nach Neustart: gespeicherter Token, Roboter und Protokoll aus dem Zwischenspeicher', waitFor(fn() => str_contains($logA(), 'Anmeldung bei Ecovacs erfolgreich (gespeicherter Token)') && $has('ecovacs/sauger/protokoll', 'xml'), 15), true);
    check('A: nach Neustart keine neue Anmeldung bei Ecovacs', substr_count($clog(), 'GET user/login'), $logins);
    $stopWait($svc);

    // ---------------------------------------------------------------- B: falsches Passwort
    $resetCloud();
    $reset();
    $dB = $neuDir('B', ['konto' => ['passwort' => 'falschfalsch']]);
    $svc = $starte($dB);
    check('B: falsches Passwort: Anmeldung "falsch"', waitFor(fn() => ($statusVon($dB)['anmeldung']['status'] ?? '') === 'falsch', 15), true);
    sleep(4);
    check('B: nur ein Anmeldeversuch (keine Wiederholung, sonst droht eine Sperre)', substr_count($clog(), 'GET user/login'), 1);
    check('B: status/ok 0, Lebenszeichen laeuft', [waitFor(fn() => $has('ecovacs/status/ok', '0'), 10), (bool)preg_match('/ecovacs\/status\/zaehler\t\d+/', $mlog())], [true, true]);
    check('B: falsches Passwort steht nicht im Log', str_contains((string)@file_get_contents("$dB/ecovacs.log") . $clog(), 'falschfalsch'), false);
    // Knopf "erneut versuchen" (Datei anfassen) mit richtigem Passwort
    $c = EcoConfig::load("$dB/ecovacs.json");
    $c['konto']['passwort'] = PW;
    sleep(1);
    Util::jsonSave("$dB/ecovacs.json", $c);
    check('B: nach geaendertem Passwort meldet sich der Dienst neu an', waitFor(fn() => ($statusVon($dB)['anmeldung']['status'] ?? '') === 'ok', 15), true);
    $stopWait($svc);

    // ---------------------------------------------------------------- C: Cloud nicht erreichbar
    $reset();
    $dC = $neuDir('C');
    $svc = $starte($dC, ['ECO_DEV_CLOUD' => 'http://127.0.0.1:18899']);
    check('C: Cloud nicht erreichbar: Anmeldung "fehler", status/ok 0', waitFor(fn() => ($statusVon($dC)['anmeldung']['status'] ?? '') === 'fehler' && $has('ecovacs/status/ok', '0'), 20), true);
    $z1 = $statusVon($dC)['zaehler'] ?? 0;
    check('C: Lebenszeichen laeuft trotzdem weiter', waitFor(fn() => ($statusVon($dC)['zaehler'] ?? 0) !== $z1, 25), true);
    check('C: Fehlertext ohne Adresse und ohne Zugangsdaten', [str_contains((string)($statusVon($dC)['anmeldung']['text'] ?? ''), '18899'), str_contains((string)($statusVon($dC)['anmeldung']['text'] ?? ''), PW)], [false, false]);
    $stopWait($svc);

    // ---------------------------------------------------------------- D: neues Protokoll (JSON mit V2)
    $resetCloud(['modus' => 'json_v2']);
    $reset();
    $dD = $neuDir('D');
    $svc = $starte($dD);
    check('D: Protokoll JSON erkannt (XML-Probe scheitert, JSON-Probe klappt, V2-Probe klappt)', waitFor(fn() => $has('ecovacs/sauger/protokoll', 'json'), 25), true);
    check('D: Erkennung: zuerst XML, dann JSON, dann V2', (bool)preg_match('/CMD GetBatteryInfo .*CMD getBattery .*CMD getCleanInfo_V2/s', $clog()), true);
    check('D: Werte des JSON-Roboters (Akku, laedt, Saugstufe, Wasser, Summen, Verschleiss 50/25/75)', waitFor(fn() => $has('ecovacs/sauger/akku', '99') && $has('ecovacs/sauger/laedt', '1') && $has('ecovacs/sauger/saugstufe', '2')
        && $has('ecovacs/sauger/wasserstufe', '2') && $has('ecovacs/sauger/summe_anzahl', '10') && $has('ecovacs/sauger/verschleiss_hauptbuerste', '50') && $has('ecovacs/sauger/verschleiss_seitenbuerste', '25')
        && $has('ecovacs/sauger/verschleiss_filter', '75') && $has('ecovacs/sauger/fehler_code', '0'), 20), true);
    check('D: Abo auf den JSON-Kanal (j)', waitFor(fn() => str_contains($elog(), "SUB\tiot/atr/+/testdid01/x5d34r/abcd/j"), 10), true);
    check('D: Anfragen im JSON-Aufbau, nicht per XML-Text', (bool)preg_match('/CMD getBattery \{"header":\{"pri":"1","ts":\d+,"tzm":480,"ver":"0.0.50"\},"body":\{"data":\{"id":"\d{8}"\}\}\}/', $clog()), true);
    $nachAbfrage('getBattery ');
    $injectE("iot/atr/onBattery/testdid01/x5d34r/abcd/j\t" . json_encode(['header' => [], 'body' => ['data' => ['value' => 61, 'isLow' => 0]]]) . "\n");
    check('D: Push (JSON) Akku 61', waitFor(fn() => $has('ecovacs/sauger/akku', '61'), 8), true);
    $conf = EcoConfig::load("$dD/ecovacs.json");
    $conf['geraete']['testdid01']['befehle']['steuern'] = true;
    sleep(1);
    Util::jsonSave("$dD/ecovacs.json", $conf);
    sleep(2);
    $reset();
    $inject("ecovacs/befehl/sauger/start\tspot\n");
    check('D: start spot (V2): clean_V2 mit Inhalt', waitFor(fn() => str_contains($mlog(), "start/quittung\tausgefuehrt") && (bool)preg_match('/CMD clean_V2 .*"act":"start","content":\{"count":1,"donotClean":"","type":"spot"\}/s', $clog()), 15), true);
    check('D: danach "Reinigt (Auto)" laut Roboter', waitFor(fn() => $has('ecovacs/sauger/status', 'Reinigt (Auto)'), 12), true);
    $stopWait($svc);

    // ---------------------------------------------------------------- E: neues Protokoll ohne V2
    $resetCloud(['modus' => 'json']);
    $reset();
    $dE = $neuDir('E');
    $svc = $starte($dE);
    check('E: JSON ohne V2 erkannt (getCleanInfo_V2 scheitert, getCleanInfo klappt)', waitFor(fn() => $has('ecovacs/sauger/protokoll', 'json') && $has('ecovacs/sauger/akku', '99'), 30), true);
    check('E: Anfragen ohne _V2', [str_contains($clog(), 'CMD getCleanInfo_V2'), (bool)preg_match('/CMD getCleanInfo \{/', $clog())], [true, true]);
    check('E: Protokoll im Zwischenspeicher', Util::jsonLoad("$dE/data/protokoll.json")['testdid01'] ?? null, ['proto' => 'json', 'v2' => false]);
    $stopWait($svc);

    // ---------------------------------------------------------------- F: nicht unterstuetzte Abfrage wird nicht mehr gestellt
    $resetCloud(['unsupported' => ['GetWaterBoxInfo']]);
    $reset();
    $dF = $neuDir('F', ['abfrage_abstand' => 1]);
    $svc = $starte($dF);
    check('F: Roboter laeuft trotz nicht unterstuetzter Abfrage (Werte da, status/ok 1)', waitFor(fn() => $has('ecovacs/sauger/akku', '99') && $has('ecovacs/status/ok', '1'), 20), true);
    sleep(7);
    check('F: GetWaterBoxInfo nach zwei Fehlschlaegen nicht mehr gestellt', substr_count($clog(), 'CMD GetWaterBoxInfo'), 2);
    check('F: Abfragen laufen sonst weiter, kein Fehler', [substr_count($clog(), 'CMD GetBatteryInfo') > 2, $statusVon($dF)['roboter']['sauger']['ok'] ?? null, $has('ecovacs/status/ok', '0')], [true, true, false]);
    check('F: wassertank wird nie gesendet (Roboter kennt es nicht)', str_contains($mlog(), 'ecovacs/sauger/wassertank'), false);
    $stopWait($svc);

    // ---------------------------------------------------------------- G: Roboter ohne Antwort auf Protokoll-Probe (Protokoll fest eingestellt)
    $resetCloud();
    $reset();
    $dG = $neuDir('G', ['geraete' => ['testdid01' => ['protokoll' => 'xml']]]);
    $svc = $starte($dG);
    check('G: festes Protokoll xml: keine Erkennung (kein getBattery-JSON, Werte direkt)', waitFor(fn() => $has('ecovacs/sauger/akku', '99'), 20), true);
    check('G: festes Protokoll: keine JSON-Probe', str_contains($clog(), 'CMD getBattery '), false);
    $stopWait($svc);

    foreach ([$mqProc, $emProc, $clProc] as $pp) {
        stopProc($pp);
    }
}

// ---------------------------------------------------------------- Healthcheck (LoxBerry-Healthcheck-Seite)
$hcDir = $dir . '/hc';
mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/ecovacs2lox/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['ECO_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'mqtt_error' => '', 'version' => AppVersion::CURRENT, 'anmeldung' => ['status' => 'ok', 'text' => ''],
    'roboter' => ['sauger' => ['ok' => true, 'aus' => false, 'error' => '']]];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Ecovacs2Lox: Dienst, Anmeldung bei Ecovacs und Roboter');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3, Exitcode 0, keine Fehlerausgabe', [$j['status'], $rc, $e], [3, 0, '']);
check('Healthcheck: veralteter Status = Fehler 3', $res(['time' => time() - 120] + $basis)[0]['status'], 3);
check('Healthcheck: Broker weg = Warnung 4', $res(['mqtt' => 0, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0]['status'], 4);
check('Healthcheck: kein Konto = Info 6', $res(['anmeldung' => ['status' => 'keine_daten']] + $basis)[0]['status'], 6);
check('Healthcheck: Verifizierung noetig = Warnung 4', $res(['anmeldung' => ['status' => 'verifizierung', 'text' => 'x']] + $basis)[0]['status'], 4);
check('Healthcheck: falsches Passwort = Fehler 3', $res(['anmeldung' => ['status' => 'falsch', 'text' => 'x']] + $basis)[0]['status'], 3);
check('Healthcheck: Anmeldung gestoert = Warnung 4 mit Grund', [$res(['anmeldung' => ['status' => 'fehler', 'text' => 'Zeitüberschreitung']] + $basis)[0]['status'], str_contains($res(['anmeldung' => ['status' => 'fehler', 'text' => 'Zeitüberschreitung']] + $basis)[0]['result'], 'Zeitüberschreitung')], [4, true]);
check('Healthcheck: Anmeldung laeuft = Info 6', $res(['anmeldung' => ['status' => 'anmelden']] + $basis)[0]['status'], 6);
check('Healthcheck: kein Roboter aktiv = Info 6', $res(['roboter' => []] + $basis)[0]['status'], 6);
$j = $res(['roboter' => ['sauger' => ['ok' => false, 'aus' => false, 'error' => 'Das Protokoll des Roboters wurde nicht erkannt']]] + $basis)[0];
check('Healthcheck: Roboter mit Problem = Warnung 4 mit Name und Grund', [$j['status'], str_contains($j['result'], 'sauger (Das Protokoll des Roboters wurde nicht erkannt)')], [4, true]);
$j = $res(['roboter' => ['sauger' => ['ok' => true, 'aus' => true, 'error' => '']]] + $basis)[0];
check('Healthcheck: ausgeschalteter Roboter = OK 5, mit Hinweis', [$j['status'], str_contains($j['result'], 'vermutlich ausgeschaltet: sauger')], [5, true]);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5, Text zaehlt Roboter und nennt Version', [$j['status'], $j['result']], [5, '1 Roboter in Ordnung, Version ' . AppVersion::CURRENT]);
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/ecovacs.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/ecovacs.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

// ---------------------------------------------------------------- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken
$uiDir = $dir . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev', 'sess', 'dev/run', 'dev/data'] as $sub) {
    mkdir("$uiDir/$sub", 0777, true);
}
$uiPage = function (string $tab, array $env = []) use ($php, $phpOpt, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/ecovacs2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/ecovacs2lox/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'ECO_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = array_merge([$php], $phpOpt, ['-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path="' . $root . '/tests/stubs"', '-d', 'session.save_path="' . "$uiDir/sess" . '"',
        $root . '/tests/ui_page.php', $tab]);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2, 'Udpinport' => '12345']]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
file_put_contents("$uiDir/log/test.log", "2026-01-01 <INFO> Testzeile\n");
$uiConf = EcoConfig::defaults();
$uiConf['konto'] = ['email' => 'ui@example.invalid', 'passwort' => 'uigeheim777', 'land' => 'de'];
$uiConf['geraete_id'] = GID;
$uiConf['geraete'] = ['did-ui-1' => ['aktiv' => true, 'name' => 'wohnzimmer', 'protokoll' => 'auto', 'befehle' => ['steuern' => true, 'einstellen' => false, 'finden' => false]]];
$uiConf['mqtt']['passwort'] = 'brokerpasswort77';
Util::jsonSave("$uiDir/dev/ecovacs.json", $uiConf);
Util::jsonSave("$uiDir/dev/data/geraete.json", ['zeit' => time(), 'geraete' => [['did' => 'did-ui-1', 'class' => 'dl8fht', 'resource' => 'ab', 'company' => 'eco-ng', 'nick' => 'Wohnen', 'modell' => 'DEEBOT 600 Series']]]);
foreach (['konto', 'geraete', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab, ['STUB_UDP' => '12345']);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 6 Eintraegen, genau ein aktiver (dieser)", [count($nav), count(array_filter($nav, fn($n) => !empty($n['active']))), array_values(array_filter($nav, fn($n) => !empty($n['active'])))[0]['URL'] ?? null], [6, 1, '?tab=' . $tab]);
    check("UI $tab: Design System (nojqm gesetzt, lb-content, kein jQuery-Mobile-Rest)", [str_contains($o, '<!--NOJQM:1-->'), str_contains($o, 'class="lb-content'), str_contains($o, 'data-role='), str_contains($o, 'data-ajax=')], [true, true, false, false]);
    check("UI $tab: kein Zugangsdatum im HTML", [str_contains($o, 'uigeheim777'), str_contains($o, 'brokerpasswort77')], [false, false]);
    check("UI $tab: kein JavaScript (nur Formulare)", [str_contains($o, '<script'), str_contains($o, 'onclick=')], [false, false]);
}
[$o] = $uiPage('konto');
check('UI Konto: Felder, Passwort als leeres Passwortfeld mit "(gesetzt)", Land-Auswahl', [str_contains($o, 'name="konto[email]" value="ui@example.invalid"'), str_contains($o, 'type="password" name="konto[passwort]" value=""'), str_contains($o, 'placeholder="(gesetzt)"'),
    str_contains($o, '<option value="de" selected>Deutschland</option>'), str_contains($o, 'inoffizielle')], [true, true, true, true, true]);
check('UI Konto: ohne Dienst "der Dienst läuft nicht"', str_contains($o, 'der Dienst läuft nicht'), true);
Util::jsonSave("$uiDir/dev/run/status.json", ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'anmeldung' => ['status' => 'verifizierung', 'text' => 'Ecovacs verlangt eine Verifizierung'], 'roboter' => []]);
[$o, $e] = $uiPage('konto');
check('UI Konto: Verifizierung zeigt beide Schritte (Code anfordern, Code bestaetigen)', [str_contains($o, 'value="code_anfordern"'), str_contains($o, 'value="code_pruefen"'), str_contains($o, 'name="code"'), $bad($o, $e)], [true, true, true, false]);
Util::jsonSave("$uiDir/dev/run/status.json", ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'cloud_mqtt' => 1, 'anmeldung' => ['status' => 'falsch', 'text' => 'x'], 'roboter' => []]);
[$o] = $uiPage('konto');
check('UI Konto: "falsch" bietet erneuten Versuch an, ohne Verifizierungsformular', [str_contains($o, 'value="neu_anmelden"'), str_contains($o, 'value="code_pruefen"')], [true, false]);
Util::jsonSave("$uiDir/dev/run/status.json", ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'cloud_mqtt' => 1, 'version' => AppVersion::CURRENT, 'anmeldung' => ['status' => 'ok', 'text' => '', 'gueltig_bis' => time() + 86400],
    'roboter' => ['wohnzimmer' => ['ok' => true, 'aus' => false, 'error' => '', 'status' => 'Lädt', 'akku' => 99, 'protokoll' => 'xml', 'antworten' => 5]]]);
[$o] = $uiPage('konto');
check('UI Konto: angemeldet mit Gueltigkeit', [str_contains($o, '<b>angemeldet</b>'), str_contains($o, 'Der Zugriffstoken gilt bis')], [true, true]);
[$o] = $uiPage('geraete');
check('UI Geraete: Roboter mit Schalter, Name, Protokoll-Auswahl und Stufen im Design System', [str_contains($o, 'name="geraete[did-ui-1][aktiv]"'), str_contains($o, 'name="geraete[did-ui-1][name]" value="wohnzimmer"'), str_contains($o, 'name="geraete[did-ui-1][protokoll]"'),
    str_contains($o, 'name="geraete[did-ui-1][befehle][steuern]" value="1" checked'), str_contains($o, 'name="geraete[did-ui-1][befehle][einstellen]" value="1">'), str_contains($o, 'class="lb-toggle"'), str_contains($o, 'name="abfrage_abstand"')], [true, true, true, true, true, true, true]);
check('UI Geraete: Status mit Klartext und Akku, Hinweis zu geprueft/ungeprueft', [str_contains($o, 'Lädt, Akku 99 %, Protokoll xml'), str_contains($o, 'Deebot 605'), str_contains($o, 'ungeprüft')], [true, true, true]);
check('UI Geraete: Modell aus der Cloud', str_contains($o, 'DEEBOT 600 Series'), true);
[$o] = $uiPage('loxone', ['STUB_UDP' => '12345']);
check('UI Loxone: Topic-Tabelle und Eingangsnamen', [str_contains($o, 'ecovacs/wohnzimmer/akku'), str_contains($o, 'ecovacs_wohnzimmer_status'), str_contains($o, 'ecovacs/status/zaehler')], [true, true, true]);
check('UI Loxone: Befehlstabelle mit UDP-Port, Quittung und Topics', [str_contains($o, '/dev/udp/&lt;LoxBerry-IP&gt;/12345'), str_contains($o, 'ecovacs/befehl/wohnzimmer/start'), str_contains($o, 'ecovacs/befehl/wohnzimmer/saugstufe'), str_contains($o, 'quittung'), str_contains($o, 'ausgefuehrt')], [true, true, true, true, true]);
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „ecovacs/#“ fehlt'), true);
check('UI mqtt: Hinweis zum Zertifikat der Ecovacs-Cloud und Status der Cloud-Verbindung', [str_contains($o, 'eigenen Ecovacs-Zertifizierungsstelle'), str_contains($o, 'MQTT der Ecovacs-Cloud')], [true, true]);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'ecovacs/#']]]));
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Abo eingetragen = gruenes Feld', str_contains($o, 'Gateway V2: eingetragen (ecovacs/#)') && str_contains($o, 'gw-abo-ok'), true);
[$o] = $uiPage('logs');
check('UI logs: zeigt die Logdatei', str_contains($o, 'Testzeile'), true);
$uiCode = (string)file_get_contents($root . '/ecovacs2lox/webfrontend/htmlauth/index.php');
check('UI-Code: kein JavaScript und kein PHP-Rest wie in 1.8.1 (catch ohne Variable in Skripten gibt es nicht)', [str_contains($uiCode, '<script'), str_contains($uiCode, '$ignoriert')], [false, false]);

// ---------------------------------------------------------------- plugin.cfg, Installationsskripte, Bauen, ZIP
$pcfg = (string)file_get_contents($root . '/ecovacs2lox/plugin.cfg');
check('plugin.cfg: Identitaet', [str_contains($pcfg, "NAME=Rico Tengler\nEMAIL=rico@cod-lan.de\n"), str_contains($pcfg, "NAME=ecovacs2lox\nFOLDER=ecovacs2lox\nTITLE=Ecovacs2Lox\n"), str_contains($pcfg, 'VERSION=' . AppVersion::CURRENT . "\n")], [true, true, true]);
check('plugin.cfg: Autoupdate an, RELEASECFG auf raw.githubusercontent.com (main), PRERELEASECFG leer, WEBSITE',
    [str_contains($pcfg, "\nAUTOMATIC_UPDATES=true\n"), str_contains($pcfg, "\nRELEASECFG=https://raw.githubusercontent.com/ricten/Ecovacs2Lox/main/release.cfg\n"), str_contains($pcfg, "\nPRERELEASECFG=\n"), str_contains($pcfg, "\nWEBSITE=https://github.com/ricten/Ecovacs2Lox\n")], [true, true, true, true]);
check('plugin.cfg: Loglevel-Auswahl frei, TITLE hoechstens 25 Zeichen', [str_contains($pcfg, "\nCUSTOM_LOGLEVELS=true\n"), strlen('Ecovacs2Lox') <= 25], [true, true]);
check('Plugin-Icons als PNG in allen vier Groessen, kein icon.svg', [is_file($root . '/ecovacs2lox/icons/icon_64.png') && is_file($root . '/ecovacs2lox/icons/icon_128.png') && is_file($root . '/ecovacs2lox/icons/icon_256.png') && is_file($root . '/ecovacs2lox/icons/icon_512.png'), is_file($root . '/ecovacs2lox/icons/icon.svg')], [true, false]);
check('Abo-Datei: ein Topic je Zeile, keine release.cfg im Plugin', [file_get_contents($root . '/ecovacs2lox/config/mqtt_subscriptions.cfg'), is_file($root . '/ecovacs2lox/release.cfg')], ["ecovacs/#\n", false]);
$hart = [];
$ips = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $pfad = str_replace('\\', '/', $f->getPathname());
    if (str_contains($pfad, '/.git/') || preg_match('/\.(png|zip)$/', $pfad) || str_contains($pfad, '/build/') || str_contains($pfad, '/tests/tmp/')) {
        continue;
    }
    $t = (string)file_get_contents($f->getPathname());
    if (str_contains($pfad, '/ecovacs2lox/')) {
        if (str_contains($t, '/opt/loxberry')) {
            $hart[] = $f->getFilename();
        }
        if (str_contains($t, "\r\n")) {
            $hart[] = 'CRLF:' . $f->getFilename();
        }
    }
    if (preg_match('/\b(?!127\.|0\.|192\.0\.2\.)\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', preg_replace('/\b\d+\.\d+\.\d+\.\d+(?=[\'"]?\s*(?:Build|\)))/', '', $t) ?? $t, $mm) && preg_match('/\b192\.168\./', $t)) {
        $ips[] = $f->getFilename();
    }
}
check('Keine festen /opt/loxberry-Pfade, nur LF-Zeilenenden im Plugin', $hart, []);
check('Keine privaten IP-Adressen (192.168.x) im Repo', $ips, []);

$bashOk = false;
$pr = @proc_open(['bash', '-c', 'command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
if (is_resource($pr)) {
    stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    $bashOk = proc_close($pr) === 0;
}
if ($bashOk) {
    // build.sh in einer Kopie des Repos (sonst entstuende eine echte release.cfg bzw. ein ZIP im Repo)
    $rc = $dir . '/rc';
    $copy = function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    };
    mkdir("$rc/ecovacs", 0777, true);
    copy($root . '/build.sh', "$rc/ecovacs/build.sh");
    copy($root . '/CHANGELOG.md', "$rc/ecovacs/CHANGELOG.md");
    $copy($root . '/ecovacs2lox', "$rc/ecovacs/ecovacs2lox");
    $copy(dirname($root) . '/common', "$rc/common");
    $ver = AppVersion::CURRENT;
    $bs = function (string $arg = '') use ($rc): string {
        $cmd = $arg !== '' ? ['bash', 'build.sh', $arg] : ['bash', 'build.sh'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$rc/ecovacs");
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $o;
    };
    $bo = $bs();
    if (!file_exists("$rc/ecovacs/ecovacs2lox-$ver.zip")) {
        echo "build.sh-Ausgabe: " . substr($bo, 0, 600) . "
";
    }
    check('build.sh: baut beide ZIPs (mit und ohne Version), ohne release.cfg zu schreiben', [file_exists("$rc/ecovacs/ecovacs2lox-$ver.zip"), file_exists("$rc/ecovacs/ecovacs2lox.zip"), file_exists("$rc/ecovacs/release.cfg")], [true, true, false]);
    check('build.sh: die beiden ZIPs sind gleich', md5_file("$rc/ecovacs/ecovacs2lox-$ver.zip"), md5_file("$rc/ecovacs/ecovacs2lox.zip"));
    $bs('release-cfg');
    $cfgtxt = (string)@file_get_contents("$rc/ecovacs/release.cfg");
    check('build.sh release-cfg: Version, ZIP-Link, Info-Link, LF', [str_contains($cfgtxt, "[AUTOUPDATE]\n"), str_contains($cfgtxt, "\nVERSION=$ver\n"),
        str_contains($cfgtxt, "\nARCHIVEURL=https://github.com/ricten/Ecovacs2Lox/releases/download/v$ver/ecovacs2lox-$ver.zip\n"), str_contains($cfgtxt, "\nINFOURL=https://github.com/ricten/Ecovacs2Lox/releases/tag/v$ver\n"), str_contains($cfgtxt, "\r")], [true, true, true, true, false]);
    $zp = proc_open(['bash', '-c', 'P=$(command -v python3 || command -v python); "$P" -c "import sys,zipfile;z=zipfile.ZipFile(sys.argv[1]);print(chr(10).join(z.namelist()));print(chr(0));print(sum(1 for n in z.namelist() if b\'geheim1234\' in z.read(n) or b\'test@example\' in z.read(n)))" "$1"', 'x', "$rc/ecovacs/ecovacs2lox-$ver.zip"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $zpp);
    $zout = str_replace("\r\n", "\n", stream_get_contents($zpp[1]));
    stream_get_contents($zpp[2]);
    proc_close($zp);
    [$zl, $zs] = array_pad(explode("\n\0\n", $zout, 2), 2, '');
    $namen = explode("\n", $zl);
    check('ZIP: plugin.cfg zuerst, Dienst, Healthcheck, gemeinsame und eigene Klassen, Weboberflaeche', [$namen[0] ?? '', in_array('bin/ecovacsd.php', $namen, true), in_array('bin/healthcheck', $namen, true), in_array('bin/lib/MqttClient.php', $namen, true),
        in_array('bin/lib/EcoGeraet.php', $namen, true), in_array('bin/lib/EcoProtoJson.php', $namen, true), in_array('webfrontend/htmlauth/index.php', $namen, true), in_array('uninstall/uninstall', $namen, true)], ['plugin.cfg', true, true, true, true, true, true, true]);
    check('ZIP: keine release.cfg, vier PNG-Icons und kein icon.svg, keine Tests, keine ZIPs', [in_array('release.cfg', $namen, true), count(array_filter($namen, fn($n) => preg_match('#^icons/icon_(64|128|256|512)\.png$#', $n))), in_array('icons/icon.svg', $namen, true), count(array_filter($namen, fn($n) => str_contains($n, 'tests/'))), count(array_filter($namen, fn($n) => str_contains($n, 'zip')))], [false, 4, false, 0, 0]);
    check('ZIP: keine Zugangsdaten der Tests in den Dateien', trim($zs), '0');
    file_put_contents("$rc/ecovacs/release.cfg", str_replace("VERSION=$ver", 'VERSION=0.0.1', $cfgtxt));
    check('build.sh: abweichende release.cfg nur als Hinweis (kein Fehler)', str_contains($bs(), 'Hinweis: release.cfg zeigt auf 0.0.1'), true);
} else {
    echo "Hinweis: bash/python fehlen, build.sh-Tests uebersprungen\n";
}

if (getenv('ECO_TEST_KEEP')) {
    echo "Testverzeichnis behalten: $dir\n";
} else {
    rrmdir($dir);
}
echo "\n$count Pruefungen, $fails Fehler\n";
exit($fails ? 1 : 0);
