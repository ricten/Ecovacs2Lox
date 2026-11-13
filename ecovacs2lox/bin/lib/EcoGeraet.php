<?php
/**
 * Ein Roboter: Protokoll erkennen (XML oder JSON), den Zustand abfragen und aus Push-Meldungen zusammenfuehren, nur geaenderte Werte
 * veroeffentlichen und Befehle aus Loxone mit Quittung ausfuehren.
 *
 * Alle Anfragen an die Cloud laufen asynchron (EcoHttp) und nacheinander (hoechstens eine je Roboter), damit der Dienst
 * in der Zwischenzeit MQTT bedient. Abfragen, die ein Roboter nicht kennt, laufen in einen Timeout (Cloud-Fehler 500, bis 20 s):
 * Sie werden nach zwei Fehlschlaegen als "nicht unterstuetzt" gemerkt und nicht mehr gestellt.
 *
 * Erwartete Zustaende sind kein Fehler: Ein ausgeschalteter Roboter (Cloud-Fehler 4200) ist "aus", status/ok bleibt 1.
 * Fehler sind: Anmeldung bei der Cloud ungueltig, Cloud nicht erreichbar, Protokoll nicht erkennbar, Roboter erreichbar aber ohne Antwort.
 */
final class EcoGeraet
{
    private const REFRESH = 300;            // Sekunden: alle Werte auch ohne Aenderung erneut senden
    private const CMD_TIMEOUT = 25;         // Sekunden bis zum Abbruch einer Anfrage (die Cloud antwortet selbst nach etwa 20 s mit 500)
    private const MIN_ABSTAND = 1.0;        // hoechstens ein Befehl je Sekunde
    private const SCHNELL_AKTIV = 20;       // Sekunden zwischen den schnellen Abfragen waehrend der Reinigung
    private const SCHNELL_RUHE = 120;       // ... sonst
    private const UNSUPPORTED_DAUER = 21600;    // 6 Stunden

    public string $name;
    public string $did;

    private array $cfg;
    private Logger $log;
    private EcoHttp $http;
    private string $land = 'de';
    private ?array $tok = null;
    private ?EcoProtokoll $proto = null;
    private ?array $erkanntNeu = null;           // Ergebnis der Protokollerkennung (einmal abholbar, zum Speichern)
    private string $phase = 'start';             // start | xml | json | v2 | bereit
    private int $phaseNaechster = 0;             // naechster Erkennungsversuch nach einem Fehlschlag
    private array $z = [];                       // vereinheitlichter Zustand
    private array $sent = [];                    // Topic => [Wert, Zeit]
    private array $queue = [];                   // wartende Anfragen
    private bool $laeuft = false;                // eine Anfrage ist unterwegs
    private array $fails = [];                   // Anfrage-Kennung => Fehlschlaege in Folge
    private array $unsupported = [];             // Anfrage-Kennung => bis
    private int $lastOk = 0;
    private int $lastFast = 0;
    private int $lastFull = 0;
    private int $nachfrage = 0;                  // schnelle Abfrage nach einem Befehl
    private string $error = '';
    private bool $aus = false;
    private bool $tokenUngueltig = false;
    private array $acks = [];                    // fertige Quittungen: [befehl, text]
    private float $lastCmd = 0.0;
    private int $abfrageAbstand = 300;
    private int $antworten = 0;
    private int $ersteAntwort = 0;               // Zeitpunkt der ersten brauchbaren Antwort (fuer die Statusanzeige)

    public function __construct(array $cfg, Logger $log, EcoHttp $http, int $abfrageAbstand = 300)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $this->http = $http;
        $this->name = $cfg['name'];
        $this->did = $cfg['did'];
        $this->abfrageAbstand = $abfrageAbstand;
        $this->festesProtokoll();
    }

    /** Einstellungen aktualisieren (Name, Freigaben, Protokoll). */
    public function configure(array $cfg, int $abfrageAbstand): void
    {
        $protoAlt = $this->cfg['protokoll'] ?? 'auto';
        $this->cfg = $cfg;
        $this->name = $cfg['name'];
        $this->abfrageAbstand = $abfrageAbstand;
        if ($protoAlt !== ($cfg['protokoll'] ?? 'auto')) {
            $this->proto = null;
            $this->phase = 'start';
            $this->festesProtokoll();
        }
    }

    /** Fest eingestelltes Protokoll sofort uebernehmen (bei xml gibt es nichts zu erkennen). */
    private function festesProtokoll(): void
    {
        $p = (string)($this->cfg['protokoll'] ?? 'auto');
        if ($p === 'xml') {
            $this->proto = new EcoProtoXml();
            $this->phase = 'bereit';
        } elseif ($p === 'json') {
            $this->phase = 'v2';
        }
    }

    /** Zugang zur Cloud setzen (nach Anmeldung und nach jeder Token-Erneuerung). */
    public function setZugang(array $tok, string $land): void
    {
        $this->tok = $tok;
        $this->land = $land;
        $this->tokenUngueltig = false;
    }

    public function ohneZugang(): void
    {
        $this->tok = null;
    }

    /** Hat die Cloud den Token abgelehnt (Fehler 3)? Der Dienst meldet sich dann neu an. */
    public function tokenUngueltig(): bool
    {
        $b = $this->tokenUngueltig;
        $this->tokenUngueltig = false;
        return $b;
    }

    /** Frueher erkanntes Protokoll uebernehmen ([proto => xml|json, v2 => bool]); nur bei Einstellung "automatisch". */
    public function setErkannt(array $e): void
    {
        if (($this->cfg['protokoll'] ?? 'auto') !== 'auto' || $this->proto !== null) {
            return;
        }
        if (($e['proto'] ?? '') === 'xml') {
            $this->proto = new EcoProtoXml();
            $this->phase = 'bereit';
        } elseif (($e['proto'] ?? '') === 'json') {
            $this->proto = new EcoProtoJson(!empty($e['v2']));
            $this->phase = 'bereit';
        }
    }

    /** Neu erkanntes Protokoll zum Speichern abholen (einmal). @return array{proto:string,v2:bool}|null */
    public function erkanntNeu(): ?array
    {
        $e = $this->erkanntNeu;
        $this->erkanntNeu = null;
        return $e;
    }

    public function protokollName(): string
    {
        return $this->proto !== null ? $this->proto->name() : '';
    }

    /** MQTT-Abo fuer die Push-Meldungen dieses Roboters, sobald das Protokoll feststeht. */
    public function abo(): ?string
    {
        if ($this->proto === null || (string)($this->cfg['resource'] ?? '') === '') {
            return null;
        }
        return 'iot/atr/+/' . $this->did . '/' . $this->cfg['class'] . '/' . $this->cfg['resource'] . '/' . $this->proto->mqttTyp();
    }

    // ------------------------------------------------------------------ Anfragen

    private static function kennung(string $cmd, array $args): string
    {
        return $cmd . ($args ? ':' . json_encode($args) : '');
    }

    private function einreihen(string $cmd, array $args, string $quelle, ?string $befehl = null, bool $vorn = false): void
    {
        $e = ['cmd' => $cmd, 'args' => $args, 'quelle' => $quelle, 'befehl' => $befehl, 'proto' => null];
        if ($vorn) {
            array_unshift($this->queue, $e);
        } else {
            $this->queue[] = $e;
        }
    }

    private function pollOffen(): bool
    {
        foreach ($this->queue as $e) {
            if ($e['quelle'] === 'poll') {
                return true;
            }
        }
        return false;
    }

    /** Naechste wartende Anfrage losschicken (nur eine gleichzeitig). */
    private function abarbeiten(int $now): void
    {
        if ($this->laeuft || $this->tok === null) {
            return;
        }
        while ($this->queue) {
            $e = array_shift($this->queue);
            $k = self::kennung($e['cmd'], $e['args']);
            if ($e['quelle'] === 'poll' && isset($this->unsupported[$k]) && $this->unsupported[$k] > $now) {
                continue;
            }
            $p = $e['proto'] ?? $this->proto;
            if ($p === null) {
                continue;
            }
            [$cmdName, $payload, $ptype] = $p->anfrage($e['cmd'], $e['args']);
            $this->laeuft = true;
            $url = EcoHttp::url('portal', $this->land, 'iot/devmanager.do');
            if ($p->name() === 'json') {
                $url .= '?cv=1.94.78&t=a&av=2.2.4&mid=' . rawurlencode((string)$this->cfg['class']) . '&did=' . rawurlencode($this->did) . '&td=q&u=' . rawurlencode((string)$this->tok['uid']);
            }
            $body = ['cmdName' => $cmdName, 'payload' => $ptype === 'j' ? json_decode($payload, true) : $payload, 'payloadType' => $ptype,
                'auth' => ['realm' => EcoKonstanten::REALM, 'resource' => (string)$this->tok['resource'], 'token' => (string)$this->tok['token'],
                    'userid' => (string)$this->tok['uid'], 'with' => 'users'],
                'td' => 'q', 'toId' => $this->did, 'toRes' => (string)($this->cfg['resource'] ?? ''), 'toType' => (string)($this->cfg['class'] ?? '')];
            $this->http->senden($url, (string)json_encode($body), self::CMD_TIMEOUT, function (array $r) use ($e, $p, $k) {
                $this->laeuft = false;
                $this->fertig($e, $p, $k, $r, time());
            });
            return;
        }
    }

    /** Antwort der Cloud auf eine Anfrage verarbeiten. */
    private function fertig(array $e, EcoProtokoll $p, string $k, array $r, int $now): void
    {
        $b = $r['body'];
        $istBefehl = $e['quelle'] === 'befehl';
        if ($b === null) {                      // Netzwerk: Cloud nicht erreichbar
            $this->error = 'Cloud nicht erreichbar: ' . $r['error'];
            $this->log->warn("{$this->name}: " . $this->error, 'cloud-' . $this->did);
            $this->phaseFehler($e, $now, true);
            if ($istBefehl) {
                $this->acks[] = [$e['befehl'], 'abgelehnt: Cloud nicht erreichbar'];
            }
            return;
        }
        $ok = ($b['result'] ?? '') === 'ok' || ($b['ret'] ?? '') === 'ok';
        $w = null;
        if ($ok) {
            $w = $p->antwort($e['cmd'], $b['resp'] ?? null);
        }
        if ($w !== null) {                       // brauchbare Antwort
            $this->antworten++;
            $this->ersteAntwort = $this->ersteAntwort ?: $now;
            $this->lastOk = $now;
            $this->aus = false;
            $this->error = '';
            $this->fails[$k] = 0;
            $this->merge($w);
            if ($e['quelle'] === 'erkennen') {
                $this->erkanntOk($e, $p, $now);
            }
            if ($istBefehl) {
                $this->acks[] = [$e['befehl'], 'ausgefuehrt'];
                $this->nachfrage = $now + 2;
            }
            return;
        }
        // Fehler der Cloud oder des Roboters
        $errno = (int)($b['errno'] ?? 0);
        $text = $ok ? 'Antwort des Roboters unbrauchbar' : ('Cloud-Fehler ' . ($errno ?: '?') . (isset($b['error']) && is_string($b['error']) ? ' (' . preg_replace('/[^\x20-\x7e]+/', '?', $b['error']) . ')' : ''));
        if ($errno === 3) {                      // Anmeldung ungueltig
            $this->tokenUngueltig = true;
            $this->error = 'Anmeldung bei der Cloud abgelaufen';
        } elseif ($errno === 4200) {             // Roboter nicht erreichbar: erwartet (ausgeschaltet, kein WLAN)
            $this->aus = true;
            $this->error = '';
            $this->lastOk = max($this->lastOk, 0);
        } elseif ($e['quelle'] !== 'erkennen') {
            $this->zaehleFehlschlag($k, $now);
        }
        $this->phaseFehler($e, $now, false);
        $this->log->debug("{$this->name}: {$e['cmd']}: $text", 'cmd-' . $this->did . $k);
        if ($istBefehl) {
            $this->acks[] = [$e['befehl'], 'abgelehnt: ' . ($errno === 4200 ? 'Roboter nicht erreichbar' : ($errno === 500 ? 'Roboter antwortet nicht' : $text))];
        }
    }

    /** Eine Abfrage zaehlt als nicht unterstuetzt, wenn sie zweimal in Folge scheitert, waehrend der Roboter sonst antwortet. */
    private function zaehleFehlschlag(string $k, int $now): void
    {
        $this->fails[$k] = ($this->fails[$k] ?? 0) + 1;
        if ($this->fails[$k] >= 2 && $now - $this->lastOk < 600 && $this->lastOk > 0) {
            $this->unsupported[$k] = $now + self::UNSUPPORTED_DAUER;
            $this->log->info("{$this->name}: Abfrage $k wird nicht unterstützt und nicht mehr gestellt", 'unsup-' . $this->did . $k);
        }
    }

    // ------------------------------------------------------------------ Protokollerkennung

    /** Erkennungsschritt abschliessen: Protokoll steht fest. */
    private function erkanntOk(array $e, EcoProtokoll $p, int $now): void
    {
        if ($this->phase === 'xml') {
            $this->proto = $p;
            $this->phase = 'bereit';
            $this->erkanntNeu = ['proto' => 'xml', 'v2' => false];
            $this->log->info("{$this->name}: Protokoll erkannt: XML (ältere Modelle)");
        } elseif ($this->phase === 'json') {
            $this->phase = 'v2';
            $this->proto = null;
        } elseif ($this->phase === 'v2') {
            $v2 = $e['cmd'] === 'getCleanInfo_V2';
            $this->proto = new EcoProtoJson($v2);
            $this->phase = 'bereit';
            $this->erkanntNeu = ['proto' => 'json', 'v2' => $v2];
            $this->log->info("{$this->name}: Protokoll erkannt: JSON" . ($v2 ? ' (V2)' : ''));
        }
    }

    /** Fehlschlag in der Erkennung: naechster Kandidat oder spaeter wieder von vorn. */
    private function phaseFehler(array $e, int $now, bool $netz): void
    {
        if ($e['quelle'] !== 'erkennen') {
            return;
        }
        if ($netz || $this->aus || $this->tokenUngueltig) {         // kein Hinweis auf das Protokoll: spaeter dasselbe nochmal
            $this->phaseNaechster = $now + 60;
            return;
        }
        if ($this->phase === 'xml') {
            $this->phase = 'json';
        } elseif ($this->phase === 'json') {
            $this->phase = 'start';
            $this->phaseNaechster = $now + 120;
            $this->error = 'Das Protokoll des Roboters wurde nicht erkannt (weder XML noch JSON)';
            $this->log->warn("{$this->name}: " . $this->error, 'proto-' . $this->did);
        } elseif ($this->phase === 'v2') {
            if ($e['cmd'] === 'getCleanInfo_V2') {          // kein V2: Basisbefehle versuchen
                $this->einreihen('getCleanInfo', [], 'erkennen', null, true);
                $this->queue[0]['proto'] = new EcoProtoJson(false);
            } else {
                $this->phase = 'start';
                $this->phaseNaechster = $now + 120;
                $this->error = 'Das Protokoll des Roboters wurde nicht erkannt';
            }
        }
    }

    private function erkennen(int $now): void
    {
        if ($this->proto !== null || $this->laeuft || $this->queue || $now < $this->phaseNaechster) {
            return;
        }
        if ($this->phase === 'start') {
            $this->phase = 'xml';
        }
        switch ($this->phase) {
            case 'xml':
                $p = new EcoProtoXml();
                [$c, $a] = $p->pruefAnfrage();
                $this->einreihen($c, $a, 'erkennen');
                $this->queue[0]['proto'] = $p;
                break;
            case 'json':
                $p = new EcoProtoJson(false);
                [$c, $a] = $p->pruefAnfrage();
                $this->einreihen($c, $a, 'erkennen');
                $this->queue[0]['proto'] = $p;
                break;
            case 'v2':
                $p = new EcoProtoJson(true);
                [$c, $a] = $p->v2PruefAnfrage();
                $this->einreihen($c, $a, 'erkennen');
                $this->queue[0]['proto'] = $p;
                break;
        }
    }

    // ------------------------------------------------------------------ Zustand

    /** Werte in den Zustand einarbeiten. @param array<string,mixed> $w */
    private function merge(array $w): void
    {
        foreach ($w as $k => $v) {
            $this->z[$k] = $v;
        }
        if (isset($w['modus']) && EcoTexte::reinigtModus((string)$w['modus'])) {
            $this->z['modus_lauf'] = $w['modus'];
        }
        if (!empty($w['laedt']) && ($this->z['modus'] ?? '') === 'returning') {
            $this->z['modus'] = 'idle';      // angekommen
        }
        if (isset($w['fehler_code']) && !isset($w['fehler_text'])) {
            $this->z['fehler_text'] = EcoTexte::fehler((string)$w['fehler_code']);
        }
    }

    /** Push-Meldung des Roboters (MQTT) verarbeiten. */
    public function push(string $ereignis, string $nutzlast): void
    {
        if ($this->proto === null) {
            return;
        }
        $w = $this->proto->push($ereignis, $nutzlast);
        if ($w) {
            $this->ersteAntwort = $this->ersteAntwort ?: time();
            $this->lastOk = time();
            $this->aus = false;
            $this->merge($w);
        }
    }

    /** Wird einmal je Durchlauf aufgerufen: Erkennung, Abfragen planen, Anfragen abarbeiten. */
    public function tick(int $now): void
    {
        if ($this->tok === null) {
            return;
        }
        $this->erkennen($now);
        if ($this->proto !== null) {
            $aktiv = EcoTexte::reinigtModus((string)($this->z['modus'] ?? '')) || (($this->z['ladeart'] ?? '') === 'returning');
            $schnell = getenv('ECO_DEV_DIR') ? ($aktiv ? 2 : 3) : ($aktiv ? self::SCHNELL_AKTIV : self::SCHNELL_RUHE);       // im Test kurze Abstaende
            if (!$this->pollOffen()) {
                if ($now - $this->lastFull >= $this->abfrageAbstand) {
                    $this->lastFull = $now;
                    $this->lastFast = $now;
                    foreach ($this->proto->abfragen(true) as [$c, $a]) {
                        $this->einreihen($c, $a, 'poll');
                    }
                } elseif ($now - $this->lastFast >= $schnell || ($this->nachfrage > 0 && $now >= $this->nachfrage)) {
                    $this->lastFast = $now;
                    $this->nachfrage = 0;
                    foreach ($this->proto->abfragen(false) as [$c, $a]) {
                        $this->einreihen($c, $a, 'poll');
                    }
                }
            }
        }
        $this->abarbeiten($now);
    }

    /** Abfragen sofort wiederholen (nach Neuverbindung zur Cloud o. ae.). */
    public function neuAbfragen(): void
    {
        $this->lastFull = 0;
    }

    /** Veroeffentlichte Werte (Topic-Ende => Text). Nur Bekanntes. @return array<string,string> */
    public function werte(): array
    {
        $z = $this->z;
        $w = ['verbunden' => ($this->lastOk > 0 && time() - $this->lastOk < 900 && !$this->aus) ? '1' : '0', 'online' => $this->aus ? '0' : '1',
            'protokoll' => $this->protokollName() !== '' ? $this->protokollName() : 'unbekannt'];
        if (!empty($this->cfg['modell'])) {
            $w['modell'] = (string)$this->cfg['modell'];
        }
        if ($this->antworten === 0 && !$z) {
            return $w;
        }
        // Die Statusanzeige erst, wenn Reinigungs-, Ladezustand und Akku bekannt sind ("voll geladen" haengt vom Akku ab) (sonst springt sie beim Start kurz auf "Bereit"); Modelle, die einen
        // der beiden nie melden, bekommen sie nach 90 s.
        if ((isset($z['modus']) && isset($z['ladeart']) && isset($z['akku'])) || ($this->ersteAntwort > 0 && time() - $this->ersteAntwort > 90)) {
            $w['status'] = EcoTexte::status($z);
        }
        if (isset($z['modus'])) {
            $w['modus'] = (string)$z['modus'];
            $w['reinigt'] = EcoTexte::reinigtModus((string)$z['modus']) ? '1' : '0';
        }
        if (isset($z['laedt'])) {
            $w['laedt'] = (string)(int)$z['laedt'];
        }
        if (isset($z['ladeart']) || isset($z['modus'])) {
            $w['zurueck'] = (($z['ladeart'] ?? '') === 'returning' || ($z['modus'] ?? '') === 'returning') ? '1' : '0';
        }
        foreach (['akku', 'akku_schwach', 'saugstufe', 'wasserstufe', 'wassertank', 'schlaf', 'flaeche_m2', 'dauer_s',
            'summe_flaeche_m2', 'summe_dauer_s', 'summe_anzahl', 'verschleiss_hauptbuerste', 'verschleiss_seitenbuerste', 'verschleiss_filter'] as $k) {
            if (isset($z[$k])) {
                $w[$k] = is_float($z[$k]) ? rtrim(rtrim(number_format($z[$k], 1, '.', ''), '0'), '.') : (string)$z[$k];
            }
        }
        if (isset($z['fehler_code'])) {
            $w['fehler_code'] = (string)$z['fehler_code'];
            $w['fehler_text'] = (string)($z['fehler_text'] ?? '');
        }
        return $w;
    }

    /** Geaenderte Werte (oder alle alle 5 Minuten) auf den LoxBerry-Broker senden. */
    public function veroeffentlichen(MqttClient $out, int $now): int
    {
        $n = 0;
        foreach ($this->werte() as $t => $v) {
            $key = "ecovacs/{$this->name}/$t";
            if (!isset($this->sent[$key]) || $this->sent[$key][0] !== $v || $now - $this->sent[$key][1] >= self::REFRESH) {
                if ($out->publish($key, $v)) {
                    $this->sent[$key] = [$v, $now];
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Beim neuen Verbinden zum LoxBerry-Broker alle Werte erneut senden. */
    public function vergesseGesendet(): void
    {
        $this->sent = [];
    }

    // ------------------------------------------------------------------ Befehle

    /** Befehl aus Loxone: erste Quittung sofort ("angenommen" oder "abgelehnt: ..."); das Ergebnis folgt ueber takeAcks(). */
    public function befehl(string $befehl, string $wert, float $jetzt): string
    {
        if (!isset(EcoCommands::LISTE[$befehl])) {
            $this->log->warn("Befehl $befehl für {$this->name} abgelehnt: nicht erlaubt");
            return 'abgelehnt: Befehl nicht erlaubt';
        }
        $grund = EcoCommands::pruefen($this->cfg['befehle'] ?? [], $befehl, $wert, $this->z);
        if ($grund === null) {
            if ($this->tok === null || $this->proto === null) {
                $grund = 'keine Verbindung zum Roboter (Anmeldung oder Protokollerkennung läuft noch)';
            } elseif ($jetzt - $this->lastCmd < self::MIN_ABSTAND) {
                $grund = 'zu schnell (höchstens ein Befehl je Sekunde)';
            }
        }
        if ($grund === null) {
            $anfragen = $this->proto->befehl($befehl, $wert, $this->z);
            if ($anfragen === null) {
                $grund = 'bei diesem Modell nicht möglich';
            }
        }
        if ($grund !== null) {
            $this->log->warn("Befehl $befehl für {$this->name} abgelehnt: $grund");
            return 'abgelehnt: ' . $grund;
        }
        $this->lastCmd = $jetzt;
        $this->log->info("Befehl $befehl" . (EcoCommands::LISTE[$befehl][1] === '/^1$/' ? '' : " = $wert") . " an {$this->name}");
        $erste = true;
        foreach (array_reverse($anfragen) as [$c, $a]) {
            $this->einreihen($c, $a, 'befehl', $erste ? $befehl : null, true);
            $erste = false;
        }
        return 'angenommen';
    }

    /** Fertige Quittungen abholen: Liste von [befehl, text]. */
    public function takeAcks(): array
    {
        $a = $this->acks;
        $this->acks = [];
        return $a;
    }

    public function close(): void
    {
        $this->queue = [];
    }

    /**
     * Zustand fuer status.json und Healthcheck.
     * @return array{ok:bool,aus:bool,error:string,verbunden:bool,protokoll:string,status:string,akku:?int,antworten:int}
     */
    public function status(int $now): array
    {
        $fehler = $this->error;
        if ($fehler === '' && !$this->aus && $this->proto !== null && $this->lastOk > 0 && $now - $this->lastOk > 900) {
            $fehler = 'Der Roboter antwortet seit über 15 Minuten nicht';
        }
        return ['ok' => $fehler === '' || $this->aus, 'aus' => $this->aus, 'error' => $this->aus ? '' : $fehler,
            'verbunden' => $this->lastOk > 0 && $now - $this->lastOk < 900, 'protokoll' => $this->protokollName(),
            'status' => $this->z ? EcoTexte::status($this->z) : '', 'akku' => isset($this->z['akku']) ? (int)$this->z['akku'] : null,
            'antworten' => $this->antworten];
    }
}
