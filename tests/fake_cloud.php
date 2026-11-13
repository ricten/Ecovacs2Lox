<?php
/**
 * Fake-Ecovacs-Cloud fuer Tests: php tests/fake_cloud.php <port> <verzeichnis>
 * Ahmt Anmeldung (mit Pruefung der Signatur), Geraeteverifizierung (E-Mail-Code 123456), Geraeteliste und die Befehle der Roboter nach.
 * Steuerung ueber <verzeichnis>/cloud_ctl.json:
 *   modus        xml | json | json_v2   (welche Sprache der Roboter spricht)
 *   email, passwort   erwartete Zugangsdaten
 *   verifiziert  false = Anmeldung antwortet mit 1013, bis verifyDevice mit dem richtigen Code lief
 *   offline      true = Roboter antwortet mit Fehler 4200
 *   unsupported  Liste von Befehlsnamen, die mit Fehler 500 antworten
 *   token_last   Gueltigkeit des Zugriffstokens in ms (Standard 604800000)
 * Protokoll: <verzeichnis>/cloud.log, eine Zeile je Anfrage ("GET <endpunkt>", "POST <pfad> <todo>", "CMD <name> <payload>").
 * Zustand des Roboters: <verzeichnis>/cloud_state.json (wird von Befehlen veraendert).
 */
require_once __DIR__ . '/../../common/php/Util.php';
require_once __DIR__ . '/../ecovacs2lox/bin/lib/EcoKonstanten.php';
require_once __DIR__ . '/../ecovacs2lox/bin/lib/EcoConfig.php';
require_once __DIR__ . '/../ecovacs2lox/bin/lib/EcoAuth.php';

$port = (int)($argv[1] ?? 18860);
$dir = $argv[2] ?? sys_get_temp_dir();
$srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}

// RSA-Schluessel fuer die Verifizierung (Windows ohne openssl.cnf: eigene Minimalkonfiguration)
$cnf = sys_get_temp_dir() . '/eco_test_openssl.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=localhost\n");
$ok = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$priv = openssl_pkey_new($ok);
$det = openssl_pkey_get_details($priv);
$pubPem = $det['key'];
$pubDer = base64_encode((string)base64_decode((string)preg_replace('/-----[A-Z ]+-----|\s+/', '', $pubPem)));

function ctl(string $dir): array
{
    $c = json_decode((string)@file_get_contents("$dir/cloud_ctl.json"), true);
    return is_array($c) ? array_merge(['modus' => 'xml', 'email' => 'test@example.invalid', 'passwort' => 'geheim1234', 'verifiziert' => true, 'offline' => false,
        'unsupported' => [], 'token_last' => 604800000], $c) : ['modus' => 'xml', 'email' => 'test@example.invalid', 'passwort' => 'geheim1234', 'verifiziert' => true,
        'offline' => false, 'unsupported' => [], 'token_last' => 604800000];
}
function setCtl(string $dir, array $c): void
{
    file_put_contents("$dir/cloud_ctl.json", json_encode($c));
}
function state(string $dir): array
{
    $s = json_decode((string)@file_get_contents("$dir/cloud_state.json"), true);
    return is_array($s) ? $s : ['akku' => 99, 'act' => 'h', 'typ' => 'auto', 'laden' => 'SlotCharging', 'speed' => 'standard', 'wasser' => 4, 'tank' => 0, 'schlaf' => 1];
}
function logZeile(string $dir, string $l): void
{
    file_put_contents("$dir/cloud.log", $l . "\n", FILE_APPEND);
}
function antwort($sock, int $status, array $body): void
{
    $j = json_encode($body);
    fwrite($sock, "HTTP/1.1 $status X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($j) . "\r\nConnection: close\r\n\r\n" . $j);
}
function auth(string $code, $data = null, string $msg = 'ok'): array
{
    return ['code' => $code, 'msg' => $msg, 'data' => $data];
}
function xmlAttrs(string $xml): array
{
    $a = [];
    if (preg_match_all("/([\\w:.-]+)='([^']*)'/", $xml, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $a[$x[1]] = $x[2];
        }
    }
    return $a;
}

while (true) {
    $c = @stream_socket_accept($srv, 5);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 5);
    $req = '';
    while (!str_contains($req, "\r\n\r\n")) {
        $d = fread($c, 8192);
        if ($d === '' || $d === false) {
            break;
        }
        $req .= $d;
    }
    [$kopf, $rest] = explode("\r\n\r\n", $req, 2) + ['', ''];
    $cl = preg_match('/Content-Length:\s*(\d+)/i', $kopf, $m) ? (int)$m[1] : 0;
    while (strlen($rest) < $cl) {
        $d = fread($c, 8192);
        if ($d === '' || $d === false) {
            break;
        }
        $rest .= $d;
    }
    preg_match('#^(GET|POST) (\S+)#', $kopf, $m);
    $methode = $m[1] ?? 'GET';
    $ziel = $m[2] ?? '/';
    $pfad = (string)parse_url($ziel, PHP_URL_PATH);
    parse_str((string)parse_url($ziel, PHP_URL_QUERY), $q);
    $cfg = ctl($dir);
    $st = state($dir);

    // ---------------------------------------------------------- Anmeldung und Verifizierung (GET /api/v1/private/...)
    if (preg_match('#^/api/v1/private/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)/(.+)$#', $pfad, $p)) {
        $endpunkt = $p[8];
        logZeile($dir, "GET $endpunkt");
        $meta = ['country' => $p[1], 'lang' => $p[2], 'deviceId' => $p[3], 'appCode' => $p[4], 'appVersion' => $p[5], 'channel' => $p[6], 'deviceType' => $p[7]];
        $params = $q;
        $sign = (string)($params['authSign'] ?? '');
        unset($params['authSign'], $params['authAppkey']);
        $erwartet = EcoAuth::signieren($params, $meta, EcoKonstanten::LOGIN_APPKEY, EcoKonstanten::LOGIN_SECRET);
        if (($q['authAppkey'] ?? '') !== EcoKonstanten::LOGIN_APPKEY || $sign !== $erwartet['authSign']) {
            antwort($c, 200, auth('1001', null, 'sign error'));
        } elseif ($endpunkt === 'user/login') {
            if (($q['account'] ?? '') !== $cfg['email'] || ($q['password'] ?? '') !== md5($cfg['passwort'])) {
                antwort($c, 200, auth('1005', null, 'bad password'));
            } elseif (empty($cfg['verifiziert'])) {
                antwort($c, 200, auth('1013', null, 'verify device'));
            } else {
                antwort($c, 200, auth('0000', ['uid' => 'testuid', 'accessToken' => 'testat']));
            }
        } elseif ($endpunkt === 'common/getConfig') {
            antwort($c, 200, auth('0000', [['key' => 'PUBLIC.KEY.CONFIG', 'value' => json_encode(['publicKey' => $pubDer])]]));
        } elseif ($endpunkt === 'user/sendEmailVerifyCode' || $endpunkt === 'user/verifyDevice') {
            $feld = $endpunkt === 'user/verifyDevice' ? 'encryptAccount' : 'encryptEmail';
            $klar = '';
            $dec = @openssl_private_decrypt((string)base64_decode((string)($q[$feld] ?? '')), $klar, $priv, OPENSSL_PKCS1_PADDING);
            if (!$dec || $klar !== $cfg['email']) {
                antwort($c, 200, auth('1002', null, 'bad encrypted account'));
            } elseif ($endpunkt === 'user/sendEmailVerifyCode') {
                antwort($c, 200, auth('0000'));
            } elseif (($q['verifyCode'] ?? '') !== '123456') {
                antwort($c, 200, auth('1012', null, 'bad code'));
            } else {
                $cfg['verifiziert'] = true;
                setCtl($dir, $cfg);
                antwort($c, 200, auth('0000', ['uid' => 'testuid', 'accessToken' => 'testat']));
            }
        } else {
            antwort($c, 404, auth('9999'));
        }
        fclose($c);
        continue;
    }
    if (str_starts_with($pfad, '/openapi/')) {
        logZeile($dir, 'GET getAuthCode');
        $params = $q;
        $sign = (string)($params['authSign'] ?? '');
        unset($params['authSign'], $params['authAppkey']);
        $erwartet = EcoAuth::signieren($params, [], EcoKonstanten::AUTH_APPKEY, EcoKonstanten::AUTH_SECRET);
        if ($sign !== $erwartet['authSign'] || ($q['accessToken'] ?? '') !== 'testat') {
            antwort($c, 200, auth('1001', null, 'sign error'));
        } else {
            antwort($c, 200, auth('0000', ['authCode' => 'testcode']));
        }
        fclose($c);
        continue;
    }

    // ---------------------------------------------------------- Portal (POST JSON)
    $body = json_decode($rest, true) ?: [];
    $json = str_starts_with($cfg['modus'], 'json');
    if (str_ends_with($pfad, '/users/user.do') || str_ends_with($pfad, '/appsvr/app.do')) {
        $todo = (string)($body['todo'] ?? '');
        logZeile($dir, "POST $pfad $todo");
        $dev = ['did' => 'testdid01', 'class' => $json ? 'x5d34r' : 'dl8fht', 'resource' => 'abcd', 'company' => 'eco-ng', 'nick' => 'Test Roboter', 'name' => 'e2000testname'];
        if ($todo === 'loginByItToken') {
            if (($body['token'] ?? '') !== 'testcode') {
                antwort($c, 200, ['result' => 'fail', 'errno' => 3, 'error' => 'bad token']);
            } else {
                antwort($c, 200, ['result' => 'ok', 'userId' => 'testuid', 'token' => 'testtoken', 'last' => (string)$cfg['token_last']]);
            }
        } elseif ($todo === 'GetDeviceList') {
            antwort($c, 200, ['result' => 'ok', 'devices' => [$dev]]);
        } elseif ($todo === 'GetGlobalDeviceList') {
            antwort($c, 200, ['ret' => 'ok', 'devices' => [$dev + ['deviceName' => $json ? 'DEEBOT OZMO T8 AIVI' : 'DEEBOT 600 Series', 'model' => 'D600']]]);
        } else {
            antwort($c, 200, ['result' => 'fail', 'errno' => 1, 'error' => 'unknown']);
        }
        fclose($c);
        continue;
    }
    if (str_ends_with($pfad, '/iot/devmanager.do')) {
        $cmd = (string)($body['cmdName'] ?? '');
        $payload = $body['payload'] ?? '';
        logZeile($dir, "CMD $cmd " . (is_string($payload) ? $payload : json_encode($payload)));
        $fail = fn(int $no, string $e) => antwort($c, 200, ['ret' => 'fail', 'errno' => $no, 'error' => $e]);
        if (($body['auth']['token'] ?? '') !== 'testtoken') {
            $fail(3, 'auth');
        } elseif (!empty($cfg['offline'])) {
            $fail(4200, 'robot offline');
        } elseif (in_array($cmd, (array)$cfg['unsupported'], true) || (($body['payloadType'] ?? '') === 'x') === $json) {
            $fail(500, 'timeout');          // nicht unterstuetzt oder falsche Sprache
        } elseif (!$json) {
            $a = xmlAttrs((string)$payload);
            $r = fn(string $inner = '', string $attrs = '') => antwort($c, 200, ['ret' => 'ok', 'resp' => "<ctl id='1' ret='ok'$attrs>$inner</ctl>", 'id' => 'xyz', 'payloadType' => 'x']);
            switch ($cmd) {
                case 'GetBatteryInfo':
                    $r("<battery power='" . sprintf('%03d', $st['akku']) . "'/>");
                    break;
                case 'GetCleanState':
                    $r("<clean speed='{$st['speed']}' type='{$st['typ']}' st='{$st['act']}' mode='S' t='100' a='100'/>");
                    break;
                case 'GetChargeState':
                    $r("<charge type='{$st['laden']}'/>");
                    break;
                case 'GetCleanSpeed':
                    $r('', " speed='{$st['speed']}'");
                    break;
                case 'GetWaterPermeability':
                    $r('', " v='{$st['wasser']}'");
                    break;
                case 'GetWaterBoxInfo':
                    $r('', " on='{$st['tank']}'");
                    break;
                case 'GetSleepStatus':
                    $r('', " st='{$st['schlaf']}'");
                    break;
                case 'GetCleanSum':
                    $r('', " a='000013356' l='000582379' c='000000216'");
                    break;
                case 'GetLifeSpan':
                    $t = $a['type'] ?? '';
                    $tot = ['Brush' => 18000, 'SideBrush' => 9000, 'DustCaseHeap' => 7200][$t] ?? 1;
                    $r('', " type='$t' left='" . sprintf('%05d', (int)($tot / 2)) . "' total='$tot'");
                    break;
                case 'GetError':
                    $r();
                    break;
                case 'Clean':
                    preg_match("/<clean ([^>]*)\\/>/", (string)$payload, $mc);
                    $ca = xmlAttrs($mc[1] ?? '');
                    $st['act'] = $ca['act'] ?? 'h';
                    $st['typ'] = ($ca['type'] ?? 'auto') === 'stop' ? $st['typ'] : ($ca['type'] ?? 'auto');
                    $st['laden'] = $st['act'] === 's' || $st['act'] === 'r' ? 'Idle' : $st['laden'];
                    file_put_contents("$dir/cloud_state.json", json_encode($st));
                    $r();
                    break;
                case 'Charge':
                    $st['laden'] = 'Going';
                    $st['act'] = 'h';
                    file_put_contents("$dir/cloud_state.json", json_encode($st));
                    $r();
                    break;
                case 'SetCleanSpeed':
                    $st['speed'] = $a['speed'] ?? 'standard';
                    file_put_contents("$dir/cloud_state.json", json_encode($st));
                    $r();
                    break;
                case 'SetWaterPermeability':
                    $st['wasser'] = (int)($a['v'] ?? 4);
                    file_put_contents("$dir/cloud_state.json", json_encode($st));
                    $r();
                    break;
                case 'PlaySound':
                    $r();
                    break;
                default:
                    $fail(500, 'unknown command');
            }
        } else {
            $v2 = $cfg['modus'] === 'json_v2';
            $ok2 = fn($data) => antwort($c, 200, ['ret' => 'ok', 'resp' => ['header' => ['fwVer' => '1.0'], 'body' => ['code' => 0, 'msg' => 'ok', 'data' => $data]]]);
            switch ($cmd) {
                case 'getBattery':
                    $ok2(['value' => $st['akku'], 'isLow' => 0]);
                    break;
                case 'getCleanInfo':
                case 'getCleanInfo_V2':
                    if ($cmd === 'getCleanInfo_V2' && !$v2) {
                        $fail(500, 'unknown');
                        break;
                    }
                    $ok2($st['act'] === 's' ? ['trigger' => 'app', 'state' => 'clean', 'cleanState' => ['router' => 'plan', 'type' => 'auto', 'motionState' => 'working']] : ['trigger' => 'none', 'state' => 'idle']);
                    break;
                case 'getChargeState':
                    $ok2(['isCharging' => $st['laden'] === 'SlotCharging' ? 1 : 0, 'mode' => 'slot']);
                    break;
                case 'getSpeed':
                    $ok2(['speed' => 0]);
                    break;
                case 'getWaterInfo':
                    $ok2(['amount' => 2, 'enable' => 1]);
                    break;
                case 'getStats':
                    $ok2(['area' => 5, 'time' => 60, 'type' => 'auto']);
                    break;
                case 'getTotalStats':
                    $ok2(['area' => 100, 'time' => 3600, 'count' => 10]);
                    break;
                case 'getLifeSpan':
                    $ok2([['type' => 'brush', 'left' => 5000, 'total' => 10000], ['type' => 'sideBrush', 'left' => 2500, 'total' => 10000], ['type' => 'heap', 'left' => 7500, 'total' => 10000]]);
                    break;
                case 'getError':
                    $ok2(['code' => []]);
                    break;
                case 'getSleep':
                    $ok2(['enable' => 0]);
                    break;
                case 'clean':
                case 'clean_V2':
                    $pd = $payload['body']['data'] ?? [];
                    $st['act'] = ($pd['act'] ?? '') === 'start' ? 's' : 'h';
                    $st['laden'] = $st['act'] === 's' ? 'Idle' : $st['laden'];
                    file_put_contents("$dir/cloud_state.json", json_encode($st));
                    $ok2([]);
                    break;
                case 'charge':
                case 'setSpeed':
                case 'setWaterInfo':
                case 'playSound':
                    $ok2([]);
                    break;
                default:
                    $fail(500, 'unknown command');
            }
        }
        fclose($c);
        continue;
    }
    antwort($c, 404, ['error' => 'not found']);
    fclose($c);
}
