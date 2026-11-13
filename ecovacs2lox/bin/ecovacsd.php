<?php
/**
 * Ecovacs-Dienst: meldet sich beim Ecovacs-Konto an, fragt die Roboter ab (HTTPS), empfaengt deren Push-Meldungen (MQTT der Cloud)
 * und veroeffentlicht nur geaenderte Werte auf dem MQTT-Broker des LoxBerry. Nimmt Befehle aus Loxone an (ecovacs/befehl/<name>/<befehl>).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!getenv('ECO_DEV_DIR')) {
    require_once 'loxberry_system.php';
    require_once 'loxberry_io.php';
    require_once 'loxberry_log.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

const T = 'ecovacs';    // Topic-Wurzel

$paths = Paths::detect();

// Nur eine Instanz
$lock = fopen($paths->lockFile(), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
file_put_contents($paths->pidFile(), (string)getmypid());

$log = new Logger($paths->logfile, 'Dienst', 'Ecovacs-Dienst');
/** Loglevel aus der LoxBerry-Pluginverwaltung (im Test ECO_DEV_LOGLEVEL). Wird im Betrieb regelmaessig neu gelesen. */
function applyLogLevel(Logger $log): void
{
    if (getenv('ECO_DEV_DIR')) {
        $log->setLevel(getenv('ECO_DEV_LOGLEVEL') ?: 'debug');
    } elseif (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
        $lvl = (int)LBSystem::pluginloglevel();
        if ($lvl > 0) {
            $log->setLoxberryLevel($lvl);
        }
    }
}
applyLogLevel($log);
$logLevelCheck = 0;
register_shutdown_function(function () use ($log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log->error('Dienst abgestuerzt: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/** Broker-Daten: eigener Broker aus den Einstellungen, sonst LoxBerry-Konfiguration; im Test ECO_DEV_MQTT=host:port. */
function brokerDetails(Logger $log, array $eco): ?array
{
    $dev = getenv('ECO_DEV_MQTT');
    if ($dev) {
        [$h, $p] = explode(':', $dev, 2) + [1 => '1883'];
        return [$h, (int)$p, '', ''];
    }
    $own = EcoConfig::ownBroker($eco);
    if ($own !== null) {
        return $own;
    }
    if (!function_exists('mqtt_connectiondetails')) {
        $log->error('LoxBerry-Funktion mqtt_connectiondetails() nicht verfuegbar', 'mqtt-details');
        return null;
    }
    $d = mqtt_connectiondetails();     // ueberschreibt die globale Variable $cfg - die Dienstkonfiguration heisst deshalb $eco
    $host = is_array($d) ? (string)($d['brokerhost'] ?? '') : '';
    $port = is_array($d) ? (int)($d['brokerport'] ?? 0) : 0;
    if (($host === '' || $port === 0) && is_array($d) && !empty($d['brokeraddress']) && str_contains((string)$d['brokeraddress'], ':')) {
        [$h2, $p2] = explode(':', (string)$d['brokeraddress'], 2);
        $host = $host !== '' ? $host : $h2;
        $port = $port ?: (int)$p2;
    }
    if ($host === '') {
        $log->error('Keine Broker-Daten in der LoxBerry-Konfiguration (MQTT eingerichtet?)', 'mqtt-details');
        return null;
    }
    return [$host, $port ?: 1883, (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? '')];
}

/** Befehl aus Loxone: ecovacs/befehl/<name>/<befehl> = Wert. Erste Quittung sofort, das Ergebnis des Roboters folgt (takeAcks). */
function handleCommand(string $topic, string $msg, array $robots, MqttClient $mqtt, Logger $log): void
{
    $p = EcoCommands::parseTopic($topic);
    if ($p === null) {
        return;         // auch alle .../quittung
    }
    [$name, $befehl] = $p;
    $quit = $topic . '/quittung';
    if (!isset($robots[$name])) {
        $log->warn("Befehl $befehl fuer $name abgelehnt: unbekanntes Geraet");
        $mqtt->publish($quit, 'abgelehnt: unbekanntes Geraet');
        return;
    }
    $mqtt->publish($quit, $robots[$name]->befehl($befehl, $msg, microtime(true)));
}

// ------------------------------------------------------------------ Hauptschleife
$eco = EcoConfig::load($paths->config);
$cfgMtime = -1;
$http = new EcoHttp();
/** @var array<string,EcoGeraet> $robots */
$robots = [];
$gefunden = [];                 // Geraeteliste des Kontos (aus der Cloud)
$gefundenZeit = 0;
$erkanntCache = Util::jsonLoad($paths->data . '/protokoll.json');     // did => [proto, v2]
$auth = null;
$tok = null;
$authStatus = 'keine_daten';    // keine_daten | anmelden | ok | verifizierung | falsch | fehler
$authText = '';
$authSeit = 0;
$authNext = 0;
$authBackoff = 30;
$authNetz = 0;
$erneuernNext = 0;
$ecoMqtt = null;
$ecoMqttErr = '';
$ecoMqttNext = 0;
$ecoMqttBackoff = 5;
$ecoMqttAbos = [];
$ecoMqttSince = 0;
$mqtt = null;
$mqttErr = '';
$mqttBackoff = 2;
$mqttNext = 0;
$mqttSince = 0;
$zaehler = 0;
$lastStatusTopics = 0;
$lastPublish = 0;
$lastStatus = 0;
$ok = 0;
$results = [];
$started = time();
$log->info('Ecovacs-Dienst ' . AppVersion::CURRENT . ' gestartet (PID ' . getmypid() . ')');

/** Roboter aus Konfiguration und Geraeteliste (neu) aufbauen. */
$baueRoboter = function () use (&$robots, &$eco, &$gefunden, &$tok, &$erkanntCache, $http, $log) {
    $soll = [];
    foreach (EcoConfig::roboter($eco, $gefunden) as $r) {
        $soll[$r['name']] = $r;
    }
    foreach ($robots as $n => $r) {            // entfernte, deaktivierte oder umbenannte Roboter
        if (!isset($soll[$n]) || $robots[$n]->did !== $soll[$n]['did']) {
            $r->close();
            unset($robots[$n]);
        }
    }
    foreach ($soll as $n => $r) {
        if (isset($robots[$n])) {
            $robots[$n]->configure($r, (int)$eco['abfrage_abstand']);
        } else {
            $robots[$n] = new EcoGeraet($r, $log, $http, (int)$eco['abfrage_abstand']);
            if (is_array($erkanntCache[$r['did']] ?? null)) {
                $robots[$n]->setErkannt($erkanntCache[$r['did']]);
            }
            if ($tok !== null) {
                $robots[$n]->setZugang($tok, $eco['konto']['land']);
            }
        }
    }
    $log->info('Roboter: ' . count($robots) . ($robots ? ' (' . implode(', ', array_keys($robots)) . ')' : ''));
};

/** Neuen Zugriffstoken uebernehmen: speichern (wenn neu angemeldet), den Robotern und der MQTT-Verbindung der Cloud mitgeben. */
$uebernehmen = function (array $neu, bool $ausDatei) use (&$tok, &$authStatus, &$authText, &$authSeit, &$authBackoff, &$erneuernNext, &$gefundenZeit,
    &$robots, &$ecoMqtt, &$ecoMqttNext, &$eco, $paths, $log) {
    $tok = $neu;
    if (!$ausDatei) {
        EcoAuth::tokenSpeichern($paths->tokenFile(), $tok);
    }
    $log->addSecret((string)$tok['token']);
    $authStatus = 'ok';
    $authText = '';
    $authSeit = time();
    $authBackoff = 30;
    $erneuernNext = 0;
    $gefundenZeit = 0;                      // Geraeteliste neu holen
    $log->info('Anmeldung bei Ecovacs erfolgreich' . ($ausDatei ? ' (gespeicherter Token)' : ''));
    foreach ($robots as $rb) {
        $rb->setZugang($tok, $eco['konto']['land']);
        $rb->neuAbfragen();
    }
    if ($ecoMqtt !== null) {                // neues Passwort fuer die MQTT-Verbindung der Cloud
        $ecoMqtt->close();
        $ecoMqtt = null;
        $ecoMqttNext = 0;
    }
};

while ($running) {
    try {
        $now = time();
        if ($now >= $logLevelCheck) {      // Loglevel-Aenderung der Pluginverwaltung ohne Neustart uebernehmen
            $logLevelCheck = $now + 30;
            applyLogLevel($log);
        }

        // Konfiguration neu laden, wenn sich die Datei geaendert hat
        clearstatcache(true, $paths->config);
        $mt = is_file($paths->config) ? (int)filemtime($paths->config) : 0;
        if ($mt !== $cfgMtime) {
            $cfgMtime = $mt;
            $alt = $eco;
            $eco = EcoConfig::load($paths->config);
            foreach (EcoConfig::secrets($eco) as $s) {
                $log->addSecret($s);
            }
            if ($eco['mqtt'] != $alt['mqtt'] && $mqtt !== null) {      // Broker geaendert: neu verbinden
                $mqtt->close();
                $mqtt = null;
                $mqttNext = 0;
                $mqttBackoff = 2;
            }
            if (EcoConfig::kontoVollstaendig($eco) && $eco['geraete_id'] === '') {        // Kennung dieser Installation einmal erzeugen
                $eco['geraete_id'] = EcoConfig::neueGeraeteId();
                Util::jsonSave($paths->config, $eco);
                clearstatcache(true, $paths->config);
                $cfgMtime = (int)filemtime($paths->config);
                $log->info('Kennung dieser Installation erzeugt (für die Verifizierung bei Ecovacs)');
            }
            $kontoNeu = $eco['konto'] != $alt['konto'] || $eco['geraete_id'] !== $alt['geraete_id'];
            if ($kontoNeu || $auth === null || $authStatus === 'falsch') {       // bei "falsch" genuegt eine Aenderung der Datei (Knopf "erneut versuchen")
                $auth = null;
                $tok = null;
                if ($ecoMqtt !== null) {
                    $ecoMqtt->close();
                    $ecoMqtt = null;
                }
                foreach ($robots as $r) {
                    $r->ohneZugang();
                }
                if (!EcoConfig::kontoVollstaendig($eco)) {
                    $authStatus = 'keine_daten';
                    $authText = 'E-Mail und Passwort des Ecovacs-Kontos fehlen (Reiter „Konto“)';
                } else {
                    $authStatus = 'anmelden';
                    $authText = 'Anmeldung läuft';
                    $authNext = 0;
                    $authBackoff = 30;
                    $auth = new EcoAuth($http, $eco['konto']['land'], $eco['konto']['email'], $eco['konto']['passwort'], $eco['geraete_id']);
                }
                $authSeit = $now;
            }
            if ($gefunden) {
                $baueRoboter();
            }
        }

        // Anmeldung: gespeicherter Token, sonst neu anmelden. Bei Verifizierung wartet der Dienst auf den Code aus der Weboberflaeche:
        // alle 5 s nach einem dort gespeicherten Token sehen, alle 2 Minuten neu anmelden.
        if ($auth !== null && in_array($authStatus, ['anmelden', 'verifizierung', 'fehler'], true) && $now >= $authNext) {
            $neu = $auth->tokenLaden($paths->tokenFile());
            $ausDatei = $neu !== null;
            $r = null;
            if ($neu === null && ($authStatus !== 'verifizierung' || $now >= $authNetz)) {
                $r = $auth->anmelden();
                $neu = $r['status'] === 'ok' ? $r['token'] : null;
            }
            if ($neu !== null) {
                $uebernehmen($neu, $ausDatei);
            } elseif ($r === null) {
                $authNext = $now + 5;                       // Verifizierung: warten
            } else {
                $authText = $r['text'];
                $authSeit = $now;
                if ($r['status'] === 'verifizierung') {
                    if ($authStatus !== 'verifizierung') {
                        $log->warn('Ecovacs verlangt die Verifizierung dieser Installation per E-Mail-Code (Reiter „Konto“)', 'verify');
                    }
                    $authStatus = 'verifizierung';
                    $authNetz = $now + 120;
                    $authNext = $now + 5;
                } elseif ($r['status'] === 'falsch') {
                    $authStatus = 'falsch';                  // erst nach geaenderten Zugangsdaten wieder versuchen (keine Sperre riskieren)
                    $log->error('Anmeldung bei Ecovacs fehlgeschlagen: ' . $r['text'], 'auth');
                } else {
                    $authStatus = 'fehler';
                    $authNext = $now + $authBackoff;
                    $log->error('Anmeldung bei Ecovacs: ' . $r['text'] . " (neuer Versuch in {$authBackoff} s)", 'auth');
                    $authBackoff = min(900, $authBackoff * 2);
                }
            }
        }
        // Token laeuft in weniger als einer Stunde ab: im Betrieb neu anmelden (die Roboter laufen mit dem alten Token weiter)
        if ($authStatus === 'ok' && $auth !== null && $tok !== null && (int)$tok['gueltig_bis'] - $now < 3600 && $now >= $erneuernNext) {
            $r = $auth->anmelden();
            if ($r['status'] === 'ok') {
                $uebernehmen($r['token'], false);
            } else {
                $erneuernNext = $now + 300;
                $log->warn('Token-Erneuerung bei Ecovacs fehlgeschlagen: ' . $r['text'] . ' (neuer Versuch in 5 Minuten)', 'erneuern');
                if ($r['status'] === 'verifizierung' || $r['status'] === 'falsch') {
                    $authStatus = $r['status'];
                    $authText = $r['text'];
                    $authSeit = $now;
                    $authNext = $now + 5;
                    $authNetz = $now + 120;
                }
            }
        }
        foreach ($robots as $r) {              // die Cloud hat den Token abgelehnt
            if ($r->tokenUngueltig() && $authStatus === 'ok') {
                $log->warn('Die Cloud hat den Zugriffstoken abgelehnt: neue Anmeldung', 'token-weg');
                EcoAuth::tokenSpeichern($paths->tokenFile(), null);
                $authStatus = 'anmelden';
                $authNext = $now + 5;
                $authText = 'Token abgelehnt, neue Anmeldung';
                break;
            }
        }

        // Geraeteliste des Kontos (nach der Anmeldung und danach alle 6 Stunden)
        if ($authStatus === 'ok' && $tok !== null && $now >= $gefundenZeit) {
            $g = $auth->geraete($tok);
            if ($g['ok']) {
                $gefunden = $g['geraete'];
                $gefundenZeit = $now + 21600;
                Util::jsonSave($paths->geraeteFile(), ['zeit' => $now, 'geraete' => $gefunden]);
                $log->info('Geräte im Konto: ' . count($gefunden));
                $baueRoboter();
            } else {
                $gefundenZeit = $now + 60;
                $log->error($g['text'], 'geraete');
            }
        }

        // MQTT zum LoxBerry-Broker (mit wachsendem Abstand bei Fehlern)
        if (($mqtt === null || !$mqtt->connected()) && $now >= $mqttNext) {
            $bd = brokerDetails($log, $eco);
            if ($bd === null) {
                $mqttErr = 'Keine Broker-Daten';
                $mqttNext = $now + 30;
            } else {
                $mqtt = new MqttClient($bd[0], $bd[1], $bd[2], $bd[3], 'ecovacs_' . $paths->folder, T . '/status/ok');
                $log->addSecret($bd[3]);
                $mqtt->subscribe(T . '/befehl/#');
                if ($mqtt->connect()) {
                    $log->info("MQTT verbunden ({$bd[0]}:{$bd[1]})");
                    $mqttErr = '';
                    $mqttSince = $now;
                    $mqttBackoff = 2;
                    foreach ($robots as $r) {
                        $r->vergesseGesendet();
                    }
                    $lastStatusTopics = 0;
                } else {
                    $mqttErr = $mqtt->lastError();
                    $log->error('MQTT: ' . $mqttErr, 'mqtt-connect');
                    $mqttNext = $now + $mqttBackoff;
                    $mqttBackoff = min(60, $mqttBackoff * 2);
                }
            }
        }
        $mqttOk = $mqtt !== null && $mqtt->connected();
        if ($mqttOk && !$mqtt->loop()) {
            $mqttErr = $mqtt->lastError();
            $mqttOk = false;
            $log->warn('MQTT getrennt: ' . $mqttErr, 'mqtt-lost');
        }
        if ($mqttOk) {
            foreach ($mqtt->takeMessages() as [$topic, $payload]) {
                handleCommand($topic, $payload, $robots, $mqtt, $log);
            }
        }

        // MQTT der Ecovacs-Cloud: Push-Meldungen der Roboter (die Abfragen per HTTPS laufen auch ohne)
        $abos = [];
        foreach ($robots as $r) {
            if (($a = $r->abo()) !== null) {
                $abos[] = $a;
            }
        }
        if ($tok !== null && $abos && ($ecoMqtt === null || !$ecoMqtt->connected()) && $now >= $ecoMqttNext) {
            [$eh, $ep, $etls] = EcoHttp::mqttServer($eco['konto']['land']);
            $benutzer = $tok['uid'] . '@' . explode('.', EcoKonstanten::REALM)[0];
            $ecoMqtt = new MqttClient($eh, $ep, $benutzer, (string)$tok['token'], $benutzer . '/' . $tok['resource'], '', 120, $etls, 8);          // TLS ohne Zertifikatspruefung: der Server nutzt eine eigene Ecovacs-CA
            $ecoMqttAbos = [];
            foreach ($abos as $a) {
                $ecoMqtt->subscribe($a);
                $ecoMqttAbos[$a] = true;
            }
            if ($ecoMqtt->connect()) {
                $log->info("MQTT der Ecovacs-Cloud verbunden ($eh)");
                $ecoMqttErr = '';
                $ecoMqttSince = $now;
                $ecoMqttBackoff = 5;
                foreach ($robots as $r) {
                    $r->neuAbfragen();
                }
            } else {
                $ecoMqttErr = $ecoMqtt->lastError();
                $log->warn('MQTT der Ecovacs-Cloud: ' . $ecoMqttErr . ' (die Abfragen per HTTPS laufen weiter)', 'ecomqtt-connect');
                $ecoMqtt = null;
                $ecoMqttNext = $now + $ecoMqttBackoff;
                $ecoMqttBackoff = min(120, $ecoMqttBackoff * 2);
            }
        }
        $ecoMqttOk = $ecoMqtt !== null && $ecoMqtt->connected();
        if ($ecoMqttOk) {
            foreach ($abos as $a) {                  // Roboter mit neu erkanntem Protokoll nachtraeglich abonnieren
                if (!isset($ecoMqttAbos[$a])) {
                    $ecoMqtt->subscribe($a);
                    $ecoMqttAbos[$a] = true;
                }
            }
            if (!$ecoMqtt->loop()) {
                $ecoMqttErr = $ecoMqtt->lastError();
                $ecoMqttOk = false;
                $log->warn('MQTT der Ecovacs-Cloud getrennt: ' . $ecoMqttErr, 'ecomqtt-lost');
                $ecoMqtt = null;
                $ecoMqttNext = $now + $ecoMqttBackoff;
            } else {
                foreach ($ecoMqtt->takeMessages() as [$topic, $payload]) {
                    $parts = explode('/', $topic);          // iot/atr/<ereignis>/<did>/<klasse>/<resource>/<x|j>
                    if (count($parts) < 7) {
                        continue;
                    }
                    foreach ($robots as $r) {
                        if ($r->did === $parts[3]) {
                            $log->debug("Push-Meldung {$parts[2]} fuer {$r->name}: " . substr($payload, 0, 300));
                            $r->push($parts[2], $payload);
                        }
                    }
                }
            }
        }

        // Roboter: Anfragen an die Cloud voranbringen
        $http->pump();
        foreach ($robots as $r) {
            $r->tick($now);
            if (($e = $r->erkanntNeu()) !== null) {
                $erkanntCache[$r->did] = $e;
                Util::jsonSave($paths->data . '/protokoll.json', $erkanntCache);
            }
            if ($mqttOk) {
                foreach ($r->takeAcks() as [$befehl, $text]) {
                    $mqtt->publish(T . '/befehl/' . $r->name . '/' . $befehl . '/quittung', $text);
                }
            }
        }

        // Geaenderte Werte hoechstens einmal pro Sekunde senden
        if ($now !== $lastPublish) {
            $lastPublish = $now;
            $results = [];
            foreach ($robots as $n => $r) {
                $results[$n] = $r->status($now);
                if ($mqttOk) {
                    $r->veroeffentlichen($mqtt, $now);
                }
            }
            $ok = ($authStatus === 'ok' && $results && !array_filter($results, fn($x) => !$x['ok'])) ? 1 : 0;
        }
        if ($mqttOk && $now - $lastStatusTopics >= 10) {     // Lebenszeichen alle 10 s
            $lastStatusTopics = $now;
            if (!$robots && $authStatus === 'ok' && $gefunden) {
                $log->warn('Kein Roboter aktiv (Reiter „Geräte“)', 'no-robot');
            }
            $zaehler = ($zaehler + 1) % 1000;
            $mqtt->publish(T . '/status/ok', (string)$ok);
            $mqtt->publish(T . '/status/zaehler', (string)$zaehler);
            $mqtt->publish(T . '/status/ts', (string)time());
            $log->rotateCheck();
        }

        if ($now !== $lastStatus) {      // Status hoechstens einmal pro Sekunde (liegt im RAM, /dev/shm); nie Token oder Passwoerter
            $lastStatus = $now;
            Util::jsonSave($paths->statusFile(), [
                'service' => 'running', 'time' => $now, 'started' => $started,
                'ok' => $ok, 'zaehler' => $zaehler, 'ts' => $lastStatusTopics,
                'mqtt' => $mqttOk ? 1 : 0, 'mqtt_error' => $mqttErr, 'mqtt_seit' => $mqttSince,
                'cloud_mqtt' => $ecoMqttOk ? 1 : 0, 'cloud_mqtt_error' => $ecoMqttErr, 'cloud_mqtt_seit' => $ecoMqttSince,
                'anmeldung' => ['status' => $authStatus, 'text' => $authText, 'seit' => $authSeit, 'gueltig_bis' => $tok['gueltig_bis'] ?? 0],
                'roboter' => $results, 'version' => AppVersion::CURRENT,
            ]);
        }
    } catch (Throwable $e) {
        $log->error('Fehler in der Hauptschleife: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'loop');
    }
    usleep(100000);
}

if ($mqtt !== null && $mqtt->connected()) {
    $mqtt->publish(T . '/status/ok', '0');
    $mqtt->close();
}
if ($ecoMqtt !== null) {
    $ecoMqtt->close();
}
$http->abbrechen();
foreach ($robots as $r) {
    $r->close();
}
$log->info('Ecovacs-Dienst beendet');
$log->end();
@unlink($paths->pidFile());
