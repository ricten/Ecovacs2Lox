<?php
/**
 * HTTPS zur Ecovacs-Cloud (curl): synchron fuer Anmeldung und Verifizierung, asynchron (curl_multi) fuer die Befehle an die Roboter,
 * damit der Dienst waehrend langer Antwortzeiten (bis 20 s bei nicht unterstuetzten Befehlen) weiter MQTT bedient.
 *
 * Die Cloud antwortet gelegentlich mit HTTP 502 (Bad Gateway): dann wird bis zu dreimal wiederholt.
 * In Adressen und Fehlermeldungen stehen nie Zugangsdaten (die Anmeldung uebertraegt Konto und Passwort-Hash in der Adresse):
 * Meldungen nennen nur den Fehlertext von curl ohne Adresse.
 *
 * Fuer Tests: ECO_DEV_CLOUD=http://127.0.0.1:<port> leitet alle Cloud-Adressen auf einen Fake-Server (Pfad /<art>/...).
 */
final class EcoHttp
{
    private const RETRIES = 3;

    /** @var resource|\CurlMultiHandle|null */
    private $multi = null;
    /** @var array<int,array{ch:resource|\CurlHandle,cb:callable,url:string,body:string,timeout:int,versuch:int}> */
    private array $jobs = [];
    private int $nextId = 1;

    /**
     * Adresse in der Cloud bilden.
     * @param string $art api (Anmeldung), openapi (Authentifizierungscode), portal (Geraete und Befehle)
     */
    public static function url(string $art, string $land, string $pfad = ''): string
    {
        $dev = getenv('ECO_DEV_CLOUD');
        if ($dev) {
            return rtrim($dev, '/') . '/' . $art . '/' . ltrim($pfad, '/');
        }
        $land = strtolower($land);
        $kont = EcoConfig::kontinent($land);
        switch ($art) {
            case 'api':
                return 'https://gl-' . $land . '-api.ecovacs.com/' . ltrim($pfad, '/');
            case 'openapi':
                return 'https://gl-' . $land . '-openapi.ecovacs.com/' . ltrim($pfad, '/');
            default:
                return 'https://api-app.dc-' . $kont . '.ww.ecouser.net/api/' . ltrim($pfad, '/');
        }
    }

    /** MQTT-Server der Cloud: [host, port, tls]. Fuer Tests ECO_DEV_ECOMQTT=host:port (ohne TLS). */
    public static function mqttServer(string $land): array
    {
        $dev = getenv('ECO_DEV_ECOMQTT');
        if ($dev) {
            [$h, $p] = explode(':', $dev, 2) + [1 => '8883'];
            return [$h, (int)$p, false];
        }
        return ['mq-' . EcoConfig::kontinent($land) . '.' . EcoKonstanten::REALM, 8883, true];
    }

    private static function curlFehler(int $no, string $msg): string
    {
        $map = [6 => 'Name des Servers lässt sich nicht auflösen (DNS)', 7 => 'Verbindung zum Server nicht möglich', 28 => 'Zeitüberschreitung',
            35 => 'TLS-Verbindung fehlgeschlagen', 60 => 'Zertifikat des Servers nicht prüfbar', 51 => 'Zertifikat des Servers stimmt nicht'];
        return $map[$no] ?? ('Netzwerkfehler (' . $no . ')');
    }

    /** @return resource|\CurlHandle */
    private function handle(string $url, ?string $json, int $timeout)
    {
        $ch = curl_init($url);
        $head = ['User-Agent: ' . EcoKonstanten::USER_AGENT, 'Accept: application/json'];
        if ($json !== null) {
            $head[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $head, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        return $ch;
    }

    /** Antwort auswerten. @return array{status:int,body:?array,raw:string,error:string} */
    private static function ergebnis($ch, ?string $raw, int $no, string $msg): array
    {
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === null || $raw === '' || $no !== 0) {
            return ['status' => $status, 'body' => null, 'raw' => '', 'error' => $no !== 0 ? self::curlFehler($no, $msg) : 'Leere Antwort (HTTP ' . $status . ')'];
        }
        $b = json_decode($raw, true);
        return ['status' => $status, 'body' => is_array($b) ? $b : null, 'raw' => $raw,
            'error' => is_array($b) ? '' : 'Unverständliche Antwort (HTTP ' . $status . ')'];
    }

    /** Synchron abfragen (GET oder, mit $json, POST). @return array{status:int,body:?array,raw:string,error:string} */
    public function sync(string $url, ?string $json = null, int $timeout = 30): array
    {
        $r = ['status' => 0, 'body' => null, 'raw' => '', 'error' => 'nicht gesendet'];
        for ($i = 0; $i < self::RETRIES; $i++) {
            $ch = $this->handle($url, $json, $timeout);
            $raw = curl_exec($ch);
            $r = self::ergebnis($ch, is_string($raw) ? $raw : null, curl_errno($ch), curl_error($ch));
            curl_close($ch);
            if ($r['status'] !== 502) {
                break;
            }
            usleep(getenv('ECO_DEV_DIR') ? 50000 : 1000000);
        }
        return $r;
    }

    /**
     * Asynchron senden (POST JSON). Der Rueckruf bekommt das Ergebnis wie bei sync(). Mit pump() weiterlaufen lassen.
     * @return int Auftragsnummer
     */
    public function senden(string $url, string $json, int $timeout, callable $cb): int
    {
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }
        $id = $this->nextId++;
        $ch = $this->handle($url, $json, $timeout);
        curl_multi_add_handle($this->multi, $ch);
        $this->jobs[$id] = ['ch' => $ch, 'cb' => $cb, 'url' => $url, 'body' => $json, 'timeout' => $timeout, 'versuch' => 1];
        return $id;
    }

    /** Laufende Auftraege voranbringen und fertige abliefern (kurz, nicht blockierend). */
    public function pump(): void
    {
        if ($this->multi === null || !$this->jobs) {
            return;
        }
        do {
            $mrc = curl_multi_exec($this->multi, $aktiv);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $ch = $info['handle'];
            $id = null;
            foreach ($this->jobs as $k => $j) {
                if ($j['ch'] === $ch) {
                    $id = $k;
                    break;
                }
            }
            if ($id === null) {
                curl_multi_remove_handle($this->multi, $ch);
                continue;
            }
            $job = $this->jobs[$id];
            $raw = curl_multi_getcontent($ch);
            $r = self::ergebnis($ch, is_string($raw) ? $raw : null, (int)$info['result'], (string)curl_error($ch));
            curl_multi_remove_handle($this->multi, $ch);
            curl_close($ch);
            unset($this->jobs[$id]);
            if ($r['status'] === 502 && $job['versuch'] < self::RETRIES) {      // Bad Gateway: noch einmal
                $nch = $this->handle($job['url'], $job['body'], $job['timeout']);
                curl_multi_add_handle($this->multi, $nch);
                $job['ch'] = $nch;
                $job['versuch']++;
                $this->jobs[$id] = $job;
                continue;
            }
            ($job['cb'])($r);
        }
    }

    /** Laufen noch Auftraege? */
    public function offen(): int
    {
        return count($this->jobs);
    }

    /** Alles abbrechen (beim Beenden). */
    public function abbrechen(): void
    {
        foreach ($this->jobs as $j) {
            if ($this->multi !== null) {
                curl_multi_remove_handle($this->multi, $j['ch']);
            }
            curl_close($j['ch']);
        }
        $this->jobs = [];
        if ($this->multi !== null) {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }
}
