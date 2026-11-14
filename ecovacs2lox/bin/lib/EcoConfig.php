<?php
/**
 * Konfiguration des Plugins (ecovacs.json, Rechte 0600).
 * Enthaelt das Passwort des Ecovacs-Kontos und ggf. das Passwort eines eigenen MQTT-Brokers: nie anzeigen, nie loggen, nie per MQTT senden.
 *
 * Die Geraete selbst kommen aus der Cloud (Geraeteliste des Kontos); hier stehen nur die Einstellungen je Geraet, gespeichert
 * unter der Geraetekennung (did): aktiv, Name im Topic, Protokoll und die freigegebenen Befehlsstufen.
 */
final class EcoConfig
{
    public const MAX_GERAETE = 5;
    /** Stufen der Befehlsfreigabe je Roboter. */
    public const STUFEN = [
        'steuern' => 'Start, Stopp, Pause, Weiter, zurück zur Station',
        'einstellen' => 'Saugstufe, Wasserstufe',
        'finden' => 'Ton abspielen',
    ];
    /** Protokoll je Roboter: automatisch erkennen oder fest (xml = alte Modelle wie Deebot 600/700/900, json = neuere Modelle). */
    public const PROTOKOLLE = ['auto' => 'automatisch erkennen', 'xml' => 'XML (ältere Modelle)', 'json' => 'JSON (neuere Modelle)'];

    /** Unterstuetzte Laender: Kuerzel => [Name, Kontinent der Cloud]. China hat eine eigene Cloud und wird nicht unterstuetzt. */
    public const LAENDER = [
        'at' => ['Österreich', 'eu'], 'be' => ['Belgien', 'eu'], 'hr' => ['Kroatien', 'eu'], 'cz' => ['Tschechien', 'eu'],
        'dk' => ['Dänemark', 'eu'], 'fi' => ['Finnland', 'eu'], 'fr' => ['Frankreich', 'eu'], 'de' => ['Deutschland', 'eu'],
        'gb' => ['Vereinigtes Königreich', 'eu'], 'hu' => ['Ungarn', 'eu'], 'ie' => ['Irland', 'eu'], 'it' => ['Italien', 'eu'],
        'xk' => ['Kosovo', 'eu'], 'lu' => ['Luxemburg', 'eu'], 'nl' => ['Niederlande', 'eu'], 'no' => ['Norwegen', 'eu'],
        'pl' => ['Polen', 'eu'], 'pt' => ['Portugal', 'eu'], 'ro' => ['Rumänien', 'eu'], 'ru' => ['Russland', 'eu'],
        'rs' => ['Serbien', 'eu'], 'si' => ['Slowenien', 'eu'], 'sk' => ['Slowakei', 'eu'], 'es' => ['Spanien', 'eu'],
        'se' => ['Schweden', 'eu'], 'ch' => ['Schweiz', 'eu'], 'ua' => ['Ukraine', 'eu'],
        'us' => ['USA', 'na'],
        'id' => ['Indonesien', 'as'], 'il' => ['Israel', 'as'], 'jp' => ['Japan', 'as'], 'kr' => ['Südkorea', 'as'],
        'sg' => ['Singapur', 'as'], 'th' => ['Thailand', 'as'], 'tr' => ['Türkei', 'as'], 'tw' => ['Taiwan', 'as'], 'vn' => ['Vietnam', 'as'],
    ];

    public static function defaultGeraet(): array
    {
        return ['aktiv' => true, 'name' => '', 'protokoll' => 'auto', 'befehle' => ['steuern' => false, 'einstellen' => false, 'finden' => false]];
    }

    public static function defaults(): array
    {
        return [
            'konto' => ['email' => '', 'passwort' => '', 'land' => 'de'],
            'geraete_id' => '',          // Kennung dieser Installation (wird einmal erzeugt; eine neue verlangt eine neue Verifizierung per E-Mail)
            'abfrage_abstand' => 300,    // Sekunden: alle Werte auch ohne Push-Meldung neu abfragen
            'mqtt' => ['eigener_broker' => false, 'host' => '', 'port' => 1883, 'benutzer' => '', 'passwort' => ''],
            'geraete' => [],             // did => Einstellungen
        ];
    }

    public static function cleanBefehle($b): array
    {
        $b = is_array($b) ? $b : [];
        $out = [];
        foreach (array_keys(self::STUFEN) as $k) {
            $out[$k] = !empty($b[$k]);
        }
        return $out;
    }

    /** Geraetekennung: nur Zeichen, die in Topics der Cloud vorkommen. */
    public static function cleanDid(string $s): string
    {
        return (string)preg_replace('/[^A-Za-z0-9_.-]/', '', trim($s));
    }

    public static function cleanProtokoll($p): string
    {
        return isset(self::PROTOKOLLE[(string)$p]) ? (string)$p : 'auto';
    }

    public static function cleanLand($l): string
    {
        $l = strtolower(trim((string)$l));
        return isset(self::LAENDER[$l]) ? $l : 'de';
    }

    public static function neueGeraeteId(): string
    {
        return md5(bin2hex(random_bytes(16)));
    }

    public static function load(string $file): array
    {
        $def = self::defaults();
        $c = Util::jsonLoad($file);
        $out = array_merge($def, $c);
        $out['konto'] = array_merge($def['konto'], is_array($c['konto'] ?? null) ? $c['konto'] : []);
        $out['konto']['land'] = self::cleanLand($out['konto']['land']);
        $out['geraete_id'] = preg_match('/^[0-9a-f]{32}$/', (string)$out['geraete_id']) ? (string)$out['geraete_id'] : '';
        $out['abfrage_abstand'] = max(getenv('ECO_DEV_DIR') ? 1 : 60, min(3600, (int)$out['abfrage_abstand']));
        $out['mqtt'] = array_merge($def['mqtt'], is_array($c['mqtt'] ?? null) ? $c['mqtt'] : []);
        $g = [];
        foreach (is_array($c['geraete'] ?? null) ? $c['geraete'] : [] as $did => $x) {
            $did = self::cleanDid((string)$did);
            if ($did === '' || !is_array($x)) {
                continue;
            }
            $x = array_merge(self::defaultGeraet(), $x);
            $g[$did] = ['aktiv' => !empty($x['aktiv']), 'name' => Util::name((string)$x['name']), 'protokoll' => self::cleanProtokoll($x['protokoll']),
                'befehle' => self::cleanBefehle($x['befehle'])];
        }
        $out['geraete'] = $g;
        return $out;
    }

    /** Eingaben der Weboberflaeche pruefen und mit der bisherigen Konfiguration zusammenfuehren (jeder Reiter sendet nur seine Abschnitte). */
    public static function fromPost(array $old, array $post): array
    {
        $new = $old;
        if (is_array($post['konto'] ?? null)) {
            $k = $post['konto'];
            $pw = (string)($k['passwort'] ?? '');
            $email = trim((string)($k['email'] ?? ''));
            $land = self::cleanLand($k['land'] ?? 'de');
            $aenderung = $email !== (string)$old['konto']['email'] || $land !== (string)$old['konto']['land'] || $pw !== '';
            $new['konto'] = ['email' => $email, 'land' => $land, 'passwort' => $pw !== '' ? $pw : (string)$old['konto']['passwort']];
            if ($aenderung && $new['geraete_id'] === '') {
                $new['geraete_id'] = self::neueGeraeteId();
            }
        }
        if (isset($post['abfrage_abstand'])) {
            $new['abfrage_abstand'] = max(60, min(3600, (int)$post['abfrage_abstand']));
        }
        if (is_array($post['geraete'] ?? null)) {
            $names = [];
            foreach ($post['geraete'] as $did => $p) {
                $did = self::cleanDid((string)$did);
                if ($did === '' || !is_array($p)) {
                    continue;
                }
                // Leeres Feld bleibt leer: roboter() nimmt dann den Namen aus der Cloud (nicht fest "roboter" speichern, sonst aendern sich die Topics)
                $name = Util::name((string)($p['name'] ?? ''));
                if ($name !== '') {
                    $base = $name;
                    for ($n = 2; isset($names[$name]); $n++) {
                        $name = $base . '_' . $n;
                    }
                    $names[$name] = true;
                }
                $new['geraete'][$did] = ['aktiv' => !empty($p['aktiv']), 'name' => $name, 'protokoll' => self::cleanProtokoll($p['protokoll'] ?? 'auto'),
                    'befehle' => self::cleanBefehle($p['befehle'] ?? [])];
            }
        }
        if (is_array($post['mqtt'] ?? null)) {
            $m = $post['mqtt'];
            $mpw = (string)($m['passwort'] ?? '');
            $new['mqtt'] = [
                'eigener_broker' => !empty($m['eigener_broker']),
                'host' => (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($m['host'] ?? ''))),
                'port' => max(1, min(65535, (int)($m['port'] ?? 1883))),
                'benutzer' => trim((string)($m['benutzer'] ?? '')),
                'passwort' => $mpw !== '' ? $mpw : (string)$old['mqtt']['passwort'],
            ];
        }
        return $new;
    }

    /** Kontinent der Cloud zum Land. */
    public static function kontinent(string $land): string
    {
        return self::LAENDER[self::cleanLand($land)][1];
    }

    /** Sind E-Mail und Passwort eingetragen? */
    public static function kontoVollstaendig(array $cfg): bool
    {
        return trim((string)$cfg['konto']['email']) !== '' && (string)$cfg['konto']['passwort'] !== '';
    }

    /**
     * Abzufragende Roboter: gefundene Geraete des Kontos, die aktiv sind (Standard: aktiv). Der Name im Topic ist der gewaehlte Name,
     * sonst aus dem Namen in der Cloud (nick), sonst roboter<Nummer>; doppelte Namen bekommen eine Nummer.
     * @param list<array{did:string,class:string,nick?:string,company?:string,modell?:string,resource?:string}> $gefunden
     * @return list<array{did:string,class:string,resource:string,company:string,nick:string,name:string,protokoll:string,befehle:array}>
     */
    public static function roboter(array $cfg, array $gefunden): array
    {
        $out = [];
        $used = [];
        foreach ($gefunden as $i => $d) {
            $did = self::cleanDid((string)($d['did'] ?? ''));
            if ($did === '') {
                continue;
            }
            $c = array_merge(self::defaultGeraet(), $cfg['geraete'][$did] ?? []);
            if (empty($c['aktiv']) || count($out) >= self::MAX_GERAETE) {
                continue;
            }
            $name = Util::name((string)$c['name']);
            if ($name === '') {
                $name = Util::name((string)($d['nick'] ?? ''));
            }
            $name = $name !== '' ? $name : 'roboter' . ($i + 1);
            $base = $name;
            for ($n = 2; isset($used[$name]); $n++) {
                $name = $base . '_' . $n;
            }
            $used[$name] = true;
            $out[] = ['did' => $did, 'class' => (string)($d['class'] ?? ''), 'resource' => (string)($d['resource'] ?? ''), 'company' => (string)($d['company'] ?? ''),
                'nick' => (string)($d['nick'] ?? ''), 'modell' => (string)($d['modell'] ?? ''), 'name' => $name, 'protokoll' => self::cleanProtokoll($c['protokoll'] ?? 'auto'),
                'befehle' => self::cleanBefehle($c['befehle'] ?? [])];
        }
        return $out;
    }

    /**
     * Eigener MQTT-Broker aus der Konfiguration (statt dem LoxBerry-Broker).
     * @return array{0:string,1:int,2:string,3:string}|null  [host, port, user, pass]; null = LoxBerry-Broker verwenden
     */
    public static function ownBroker(array $cfg): ?array
    {
        $m = $cfg['mqtt'];
        if (empty($m['eigener_broker']) || trim((string)$m['host']) === '') {
            return null;
        }
        return [trim((string)$m['host']), (int)$m['port'] > 0 ? (int)$m['port'] : 1883, (string)$m['benutzer'], (string)$m['passwort']];
    }

    /** Alle Geheimnisse der Konfiguration (fuer die Maskierung im Log). @return list<string> */
    public static function secrets(array $cfg): array
    {
        $s = [(string)$cfg['konto']['passwort'], (string)$cfg['mqtt']['passwort']];
        return array_values(array_filter($s, fn($x) => strlen($x) >= 4));
    }
}
