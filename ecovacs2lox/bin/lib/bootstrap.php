<?php
/**
 * Gemeinsamer Start fuer Dienst und Weboberflaeche: Klassen, Zeitzone und Pfade.
 * Ausserhalb von LoxBerry (Entwicklung/Tests) greift ECO_DEV_DIR als Basisverzeichnis.
 * Util, Logger, MqttClient und GatewayInfo kommen aus ../common/php (build.sh kopiert sie nach bin/lib).
 */
date_default_timezone_set('Europe/Berlin');     // PHP auf dem LoxBerry laeuft auf UTC

foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo', 'EcoKonstanten', 'EcoConfig', 'EcoHttp', 'EcoAuth', 'EcoXml', 'EcoTexte',
    'EcoProtokoll', 'EcoProtoXml', 'EcoProtoJson', 'EcoCommands', 'EcoGeraet', 'AppVersion'] as $cls) {
    // im ZIP liegt alles in bin/lib; im Quellbaum kommen die gemeinsamen Klassen aus ../common/php
    $f = __DIR__ . '/' . $cls . '.php';
    require_once is_file($f) ? $f : __DIR__ . '/../../../../common/php/' . $cls . '.php';
}

final class Paths
{
    public string $folder;
    public string $config;     // ecovacs.json
    public string $logfile;
    public string $data;       // Datenordner (Anmeldetoken, Geraeteliste)
    public string $run;        // /dev/shm/<folder>
    public string $bin;

    public static function detect(): Paths
    {
        $p = new Paths();
        $dev = getenv('ECO_DEV_DIR');
        if ($dev) {
            $p->folder = getenv('ECO_DEV_FOLDER') ?: 'ecovacs2lox';
            $p->config = $dev . '/ecovacs.json';
            $p->logfile = $dev . '/ecovacs.log';
            $p->data = $dev . '/data';
            $p->run = getenv('ECO_DEV_RUN') ?: $dev . '/run';
            $p->bin = dirname(__DIR__);
        } else {
            require_once 'loxberry_system.php';
            $p->folder = LBPPLUGINDIR;
            $p->config = LBPCONFIGDIR . '/ecovacs.json';
            $p->logfile = LBPLOGDIR . '/ecovacs.log';
            $p->data = LBPDATADIR;
            $p->run = '/dev/shm/' . LBPPLUGINDIR;
            $p->bin = LBPBINDIR;
        }
        if (!is_dir($p->run)) {
            @mkdir($p->run, 0775, true);
        }
        if (!is_dir($p->data)) {
            @mkdir($p->data, 0775, true);
        }
        return $p;
    }

    public function statusFile(): string
    {
        return $this->run . '/status.json';
    }

    public function pidFile(): string
    {
        return $this->run . '/ecovacs.pid';
    }

    public function lockFile(): string
    {
        return $this->run . '/run.lock';
    }

    /** Anmeldetoken (Datenordner, 0600): ueberlebt Neustarts und Updates, damit nicht bei jedem Start neu angemeldet wird. */
    public function tokenFile(): string
    {
        return $this->data . '/token.json';
    }

    /** Vom Dienst gefundene Geraete des Kontos (Anzeige in der Weboberflaeche). */
    public function geraeteFile(): string
    {
        return $this->data . '/geraete.json';
    }
}
