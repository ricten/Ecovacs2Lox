<?php
/**
 * Anmeldung bei der Ecovacs-Cloud, Geraeteverifizierung und Geraeteliste (inoffizielle Schnittstelle der Ecovacs-App).
 *
 * Ablauf: (1) user/login (Konto + MD5 des Passworts, mit App-Schluessel signiert) -> uid, accessToken;
 * (2) getAuthCode -> authCode; (3) loginByItToken -> Zugriffstoken (gilt meist 7 Tage); (4) Geraeteliste.
 * Unbekannte Installationen (neue Geraetekennung) muessen einmal per E-Mail-Code verifiziert werden (Antwortcode 1013).
 *
 * Passwort, Token und Codes erscheinen nie in Meldungen oder im Log (der Aufrufer meldet nur die Texte aus 'text').
 */
final class EcoAuth
{
    private EcoHttp $http;
    private string $land;
    private string $email;
    private string $passwort;
    private string $geraeteId;

    public function __construct(EcoHttp $http, string $land, string $email, string $passwort, string $geraeteId)
    {
        $this->http = $http;
        $this->land = EcoConfig::cleanLand($land);
        $this->email = trim($email);
        $this->passwort = $passwort;
        $this->geraeteId = $geraeteId;
    }

    /** resource = die ersten 8 Zeichen der Geraetekennung (so benennt es die App). */
    public function resource(): string
    {
        return substr($this->geraeteId, 0, 8);
    }

    /** Kennzeichen eines Tokens: gehoert er zu diesem Konto, Land und dieser Installation? (kein Klartext) */
    public function kennzeichen(): string
    {
        return md5($this->email . '|' . $this->land . '|' . $this->geraeteId);
    }

    /** Meta-Angaben, die in die Signatur und in den Pfad der Anmeldung eingehen. */
    private function meta(bool $verify): array
    {
        return ['country' => $verify ? $this->land : strtoupper($this->land), 'lang' => 'EN', 'deviceId' => $this->geraeteId,
            'appCode' => EcoKonstanten::APP_CODE, 'appVersion' => $verify ? EcoKonstanten::APP_VERSION_VERIFY : EcoKonstanten::APP_VERSION,
            'channel' => EcoKonstanten::CHANNEL, 'deviceType' => '1'];
    }

    /** Signatur: MD5 ueber Schluessel + alle Parameter (nach Namen sortiert, "name=wert") + Geheimnis; ergaenzt authAppkey und authSign. */
    public static function signieren(array $params, array $signMit, string $appkey, string $secret): array
    {
        $alle = array_merge($signMit, $params);       // bei gleichem Namen gewinnt der Parameter
        ksort($alle);
        $t = $appkey;
        foreach ($alle as $k => $v) {
            $t .= $k . '=' . $v;
        }
        $params['authAppkey'] = $appkey;
        $params['authSign'] = md5($t . $secret);
        return $params;
    }

    private static function query(array $p): string
    {
        return http_build_query($p, '', '&', PHP_QUERY_RFC3986);
    }

    /** Antwort der Anmelde-Schnittstelle (code 0000 = ok). @return array{ok:bool,code:string,data:mixed,text:string} */
    private function antwortAuth(array $r): array
    {
        if ($r['body'] === null) {
            return ['ok' => false, 'code' => '', 'data' => null, 'text' => $r['error'] !== '' ? $r['error'] : 'keine Antwort'];
        }
        $code = (string)($r['body']['code'] ?? '');
        if ($code === '0000') {
            return ['ok' => true, 'code' => $code, 'data' => $r['body']['data'] ?? null, 'text' => ''];
        }
        $msg = (string)($r['body']['msg'] ?? '');
        return ['ok' => false, 'code' => $code, 'data' => null, 'text' => 'Fehlercode ' . $code . ($msg !== '' ? ': ' . self::sichtbar($msg) : '')];
    }

    /** Meldungstexte der Cloud koennen chinesisch sein: nur druckbare ASCII-Zeichen behalten. */
    private static function sichtbar(string $s): string
    {
        $o = trim((string)preg_replace('/[^\x20-\x7e]+/', '?', $s));
        return $o === '' || $o === '?' ? '(Text der Cloud nicht lesbar)' : substr($o, 0, 120);
    }

    private function pfadApi(bool $verify, string $endpunkt): string
    {
        $m = $this->meta($verify);
        return EcoHttp::url('api', $this->land, 'v1/private/' . $m['country'] . '/' . $m['lang'] . '/' . $m['deviceId'] . '/' . $m['appCode'] . '/'
            . $m['appVersion'] . '/' . $m['channel'] . '/' . $m['deviceType'] . '/' . $endpunkt);
    }

    /**
     * Voll anmelden (Schritte 1 bis 3).
     * @return array{status:string,text:string,token:?array}  status: ok | verifizierung | falsch | fehler
     */
    public function anmelden(): array
    {
        $p = ['account' => $this->email, 'password' => md5($this->passwort), 'authTimeZone' => 'GMT-8', 'authTimespan' => (int)(microtime(true) * 1000),
            'requestId' => md5(uniqid('', true))];
        $p = self::signieren($p, $this->meta(false), EcoKonstanten::LOGIN_APPKEY, EcoKonstanten::LOGIN_SECRET);
        $a = $this->antwortAuth($this->http->sync($this->pfadApi(false, 'user/login') . '?' . self::query($p)));
        if (!$a['ok']) {
            return $this->fehlerStatus($a, 'Anmeldung');
        }
        $uid = (string)($a['data']['uid'] ?? '');
        $at = (string)($a['data']['accessToken'] ?? '');
        if ($uid === '' || $at === '') {
            return ['status' => 'fehler', 'text' => 'Anmeldung: Antwort ohne Benutzerkennung', 'token' => null];
        }
        return $this->weiter($uid, $at);
    }

    /** Schritte 2 und 3 mit dem Anmelde-Token aus Schritt 1 oder aus der Verifizierung. */
    private function weiter(string $uid, string $accessToken): array
    {
        $p = ['uid' => $uid, 'accessToken' => $accessToken, 'bizType' => '', 'deviceId' => $this->geraeteId,
            'authTimespan' => (int)(microtime(true) * 1000), 'openId' => 'global'];
        $p = self::signieren($p, [], EcoKonstanten::AUTH_APPKEY, EcoKonstanten::AUTH_SECRET);
        $a = $this->antwortAuth($this->http->sync(EcoHttp::url('openapi', $this->land, 'v1/global/auth/getAuthCode') . '?' . self::query($p)));
        if (!$a['ok'] || (string)($a['data']['authCode'] ?? '') === '') {
            return $this->fehlerStatus($a['ok'] ? ['ok' => false, 'code' => '', 'data' => null, 'text' => 'Antwort ohne Authentifizierungscode'] : $a, 'Authentifizierung');
        }
        $r = $this->http->sync(EcoHttp::url('portal', $this->land, 'users/user.do'), json_encode([
            'todo' => 'loginByItToken', 'edition' => 'ECOGLOBLE', 'userId' => $uid, 'token' => (string)$a['data']['authCode'],
            'realm' => EcoKonstanten::REALM, 'resource' => $this->resource(), 'org' => 'ECOWW', 'last' => '', 'country' => strtoupper($this->land),
        ]));
        $b = $r['body'];
        if ($b === null || (($b['result'] ?? '') !== 'ok' && ($b['ret'] ?? '') !== 'ok') || (string)($b['token'] ?? '') === '') {
            $t = $b === null ? $r['error'] : 'Fehler ' . (string)($b['errno'] ?? $b['error'] ?? '?') . ' (' . self::sichtbar((string)($b['error'] ?? '')) . ')';
            return ['status' => 'fehler', 'text' => 'Zugriffstoken: ' . $t, 'token' => null];
        }
        $gueltig = (int)($b['last'] ?? 0);
        $gueltig = $gueltig > 0 ? $gueltig : 604800000;        // Standard: 7 Tage (Millisekunden)
        return ['status' => 'ok', 'text' => '', 'token' => [
            'uid' => (string)($b['userId'] ?? $uid), 'token' => (string)$b['token'], 'resource' => $this->resource(),
            'gueltig_bis' => time() + (int)floor($gueltig / 1000 * 0.99), 'kennzeichen' => $this->kennzeichen(),
        ]];
    }

    private function fehlerStatus(array $a, string $schritt): array
    {
        switch ($a['code']) {
            case '1005':
            case '1010':
                return ['status' => 'falsch', 'text' => 'Konto oder Passwort sind falsch', 'token' => null];
            case '1013':
                return ['status' => 'verifizierung', 'text' => 'Ecovacs verlangt eine Verifizierung dieser Installation per E-Mail-Code', 'token' => null];
        }
        return ['status' => 'fehler', 'text' => $schritt . ': ' . $a['text'], 'token' => null];
    }

    /** Aufruf der Verifizierungs-Schnittstelle (eigene App-Version, Land klein). @return array{ok:bool,code:string,data:mixed,text:string} */
    private function verifyAufruf(string $endpunkt, array $params): array
    {
        $now = microtime(true);
        $p = array_merge($params, ['requestId' => md5((string)$now), 'authTimespan' => (int)($now * 1000), 'authTimeZone' => 'GMT-8']);
        $p = self::signieren($p, $this->meta(true), EcoKonstanten::LOGIN_APPKEY, EcoKonstanten::LOGIN_SECRET);
        return $this->antwortAuth($this->http->sync($this->pfadApi(true, $endpunkt) . '?' . self::query($p)));
    }

    /** E-Mail-Adresse mit dem oeffentlichen Schluessel der Cloud verschluesseln (RSA, PKCS#1 v1.5, Base64). @return string|null */
    private function verschluesselteMail(string &$fehler): ?string
    {
        $a = $this->verifyAufruf('common/getConfig', ['keys' => 'PUBLIC.KEY.CONFIG']);
        if (!$a['ok'] || !is_array($a['data'])) {
            $fehler = 'Öffentlicher Schlüssel nicht abrufbar: ' . $a['text'];
            return null;
        }
        $key = null;
        foreach ($a['data'] as $e) {
            if (is_array($e) && ($e['key'] ?? '') === 'PUBLIC.KEY.CONFIG') {
                $j = json_decode((string)($e['value'] ?? ''), true);
                $key = is_array($j) ? (string)($j['publicKey'] ?? '') : '';
            }
        }
        if ($key === null || $key === '') {
            $fehler = 'Öffentlicher Schlüssel fehlt in der Antwort der Cloud';
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $enc = '';
        if (!@openssl_public_encrypt($this->email, $enc, $pem, OPENSSL_PKCS1_PADDING)) {
            $fehler = 'E-Mail-Adresse konnte nicht verschlüsselt werden (PHP-Erweiterung openssl?)';
            return null;
        }
        return base64_encode($enc);
    }

    /** Schickt den Verifizierungscode per E-Mail an das Konto. @return array{ok:bool,text:string} */
    public function codeAnfordern(): array
    {
        $fehler = '';
        $enc = $this->verschluesselteMail($fehler);
        if ($enc === null) {
            return ['ok' => false, 'text' => $fehler];
        }
        $a = $this->verifyAufruf('user/sendEmailVerifyCode', ['encryptEmail' => $enc, 'verifyType' => 'EMAIL_VERIFY_DEVICE', 'supportChar' => 'N', 'isForce' => 'N']);
        return $a['ok'] ? ['ok' => true, 'text' => 'Der Code wurde per E-Mail gesendet'] : ['ok' => false, 'text' => 'Code-Anforderung: ' . $a['text']];
    }

    /**
     * Code bestaetigen und danach anmelden.
     * @return array{status:string,text:string,token:?array}  status wie bei anmelden(); "code" = Code ungueltig oder abgelaufen
     */
    public function codePruefen(string $code): array
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9]{3,12}$/', $code)) {
            return ['status' => 'code', 'text' => 'Der Code besteht aus Ziffern und Buchstaben (meist sechs Stellen)', 'token' => null];
        }
        $fehler = '';
        $enc = $this->verschluesselteMail($fehler);
        if ($enc === null) {
            return ['status' => 'fehler', 'text' => $fehler, 'token' => null];
        }
        $a = $this->verifyAufruf('user/verifyDevice', ['encryptAccount' => $enc, 'backUpEmail' => '', 'verifyCode' => $code, 'model' => 'Pixel 7', 'system' => 'Android 14']);
        if (!$a['ok']) {
            if ($a['code'] === '1012') {
                return ['status' => 'code', 'text' => 'Der Code ist ungültig oder abgelaufen', 'token' => null];
            }
            return ['status' => 'fehler', 'text' => 'Verifizierung: ' . $a['text'], 'token' => null];
        }
        $uid = (string)($a['data']['uid'] ?? '');
        $at = (string)($a['data']['accessToken'] ?? '');
        if ($uid === '' || $at === '') {
            return ['status' => 'fehler', 'text' => 'Verifizierung: Antwort ohne Benutzerkennung', 'token' => null];
        }
        return $this->weiter($uid, $at);
    }

    /**
     * Geraeteliste des Kontos (Liste der Geraete und globale Liste, zusammengefuehrt ueber did).
     * @param array{uid:string,token:string,resource:string} $tok
     * @return array{ok:bool,text:string,geraete:list<array<string,string>>}
     */
    public function geraete(array $tok): array
    {
        $auth = ['with' => 'users', 'userid' => $tok['uid'], 'realm' => EcoKonstanten::REALM, 'token' => $tok['token'], 'resource' => $tok['resource']];
        $liste = [];
        foreach ([['users/user.do', 'GetDeviceList'], ['appsvr/app.do', 'GetGlobalDeviceList']] as $i => [$pfad, $fn]) {
            $r = $this->http->sync(EcoHttp::url('portal', $this->land, $pfad), json_encode(['todo' => $fn, 'userid' => $tok['uid'], 'auth' => $auth]));
            $b = $r['body'];
            if ($b === null || (($b['result'] ?? '') !== 'ok' && ($b['ret'] ?? '') !== 'ok')) {
                if ($i === 0) {
                    $err = $b === null ? $r['error'] : 'Fehler ' . (string)($b['errno'] ?? '?') . ' ' . self::sichtbar((string)($b['error'] ?? ''));
                    return ['ok' => false, 'text' => 'Geräteliste: ' . $err, 'geraete' => []];
                }
                continue;       // die globale Liste ist nur eine Ergaenzung (Modellname)
            }
            foreach ((array)($b['devices'] ?? []) as $d) {
                if (!is_array($d) || (string)($d['did'] ?? '') === '') {
                    continue;
                }
                $did = (string)$d['did'];
                $liste[$did] = array_merge($liste[$did] ?? [], $d);
            }
        }
        $out = [];
        foreach ($liste as $did => $d) {
            $out[] = ['did' => $did, 'class' => (string)($d['class'] ?? ''), 'resource' => (string)($d['resource'] ?? ''), 'company' => (string)($d['company'] ?? ''),
                'nick' => (string)($d['nick'] ?? ''), 'modell' => (string)($d['deviceName'] ?? ($d['model'] ?? ''))];
        }
        return ['ok' => true, 'text' => '', 'geraete' => $out];
    }

    /** Gespeicherten Token laden, wenn er zu Konto, Land und Installation passt und noch mindestens 10 Minuten gilt. */
    public function tokenLaden(string $datei): ?array
    {
        $t = Util::jsonLoad($datei);
        if (($t['kennzeichen'] ?? '') !== $this->kennzeichen() || (int)($t['gueltig_bis'] ?? 0) < time() + 600
            || (string)($t['token'] ?? '') === '' || (string)($t['uid'] ?? '') === '') {
            return null;
        }
        return $t;
    }

    public static function tokenSpeichern(string $datei, ?array $tok): void
    {
        if ($tok === null) {
            @unlink($datei);
            return;
        }
        Util::jsonSave($datei, $tok);
    }
}
