<?php
// Weboberflaeche Ecovacs2Lox: Konto · Geräte · MQTT · Einbindung in Loxone · Test · Logdateien
// Achtung: LBWeb::lbheader() und mqtt_connectiondetails() ueberschreiben die globale Variable $cfg -> eigene Konfiguration heisst $eco.
// Diese Seite enthaelt bewusst kein JavaScript (nur Formulare).

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once LBPBINDIR . '/lib/bootstrap.php';

session_start();
if (empty($_SESSION['eco_token'])) {
    $_SESSION['eco_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['eco_token'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Schalter im Stil des LoxBerry Design Systems (Checkbox mit lb-toggle); $text darf feste HTML-Auszeichnung enthalten. */
function schalter(string $name, bool $an, string $text = ''): string
{
    return '<label class="lb-toggle"><input type="checkbox" name="' . h($name) . '" value="1"' . ($an ? ' checked' : '') . '><span class="lb-toggle-slider"></span></label>'
        . ($text !== '' ? ' <span class="eco-schalter-text">' . $text . '</span>' : '');
}

$paths = Paths::detect();
$eco = EcoConfig::load($paths->config);
$status = Util::jsonLoad($paths->statusFile());
$alter = isset($status['time']) ? time() - (int)$status['time'] : null;
$laeuft = $alter !== null && $alter <= 10;
$anm = is_array($status['anmeldung'] ?? null) ? $status['anmeldung'] : [];
$anmStatus = $laeuft ? (string)($anm['status'] ?? '') : '';
$tab = $_GET['tab'] ?? '';
if (!in_array($tab, ['konto', 'geraete', 'mqtt', 'loxone', 'test', 'logs'], true)) {
    $tab = ($anmStatus === 'ok') ? 'geraete' : 'konto';
}
$meldungen = [];

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Ungueltiges Formular-Token, Seite neu laden.');
    }
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {
        $neu = EcoConfig::fromPost($eco, $_POST);
        if (Util::jsonSave($paths->config, $neu)) {
            $meldungen[] = 'Einstellungen gespeichert.';
        } else {
            $meldungen[] = 'FEHLER: Einstellungen konnten nicht gespeichert werden.';
        }
        $eco = $neu;
    } elseif ($aktion === 'neustart') {
        exec(escapeshellarg(LBPBINDIR . '/stop.sh') . ' 2>&1');
        exec(escapeshellarg(LBPBINDIR . '/start.sh') . ' 2>&1');
        $meldungen[] = 'Dienst neu gestartet.';
    } elseif ($aktion === 'neu_anmelden') {
        // Der Dienst liest die Konfiguration neu, wenn sich die Datei aendert: danach meldet er sich erneut an.
        if (is_file($paths->config) ? @touch($paths->config) : Util::jsonSave($paths->config, $eco)) {
            $meldungen[] = 'Der Dienst meldet sich in wenigen Sekunden neu an.';
        } else {
            $meldungen[] = 'FEHLER: Konfigurationsdatei nicht beschreibbar.';
        }
    } elseif ($aktion === 'code_anfordern' || $aktion === 'code_pruefen') {
        if (!EcoConfig::kontoVollstaendig($eco)) {
            $meldungen[] = 'FEHLER: Zuerst E-Mail und Passwort des Ecovacs-Kontos eintragen und speichern.';
        } else {
            if ($eco['geraete_id'] === '') {
                $eco['geraete_id'] = EcoConfig::neueGeraeteId();
                Util::jsonSave($paths->config, $eco);
            }
            $auth = new EcoAuth(new EcoHttp(), $eco['konto']['land'], $eco['konto']['email'], $eco['konto']['passwort'], $eco['geraete_id']);
            if ($aktion === 'code_anfordern') {
                $r = $auth->codeAnfordern();
                $meldungen[] = ($r['ok'] ? '' : 'FEHLER: ') . $r['text'] . ($r['ok'] ? ' (an die Adresse des Ecovacs-Kontos; manchmal im Spam-Ordner).' : '.');
            } else {
                $r = $auth->codePruefen((string)($_POST['code'] ?? ''));
                if ($r['status'] === 'ok') {
                    EcoAuth::tokenSpeichern($paths->tokenFile(), $r['token']);
                    $meldungen[] = 'Die Verifizierung hat geklappt. Der Dienst übernimmt die Anmeldung in wenigen Sekunden.';
                } else {
                    $meldungen[] = 'FEHLER: ' . $r['text'] . '.';
                }
            }
        }
    }
    // Nach einer Aktion die Statusdatei neu lesen
    $status = Util::jsonLoad($paths->statusFile());
}

$basis = '?tab=';
$tabs = ['konto' => 'Konto', 'geraete' => 'Geräte', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => $basis . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// UDP-Port des Gateways für Befehle aus Loxone (einstellbar, steht in den MQTT-Verbindungsdaten)
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());
$geraeteDatei = Util::jsonLoad($paths->geraeteFile());
$gefunden = is_array($geraeteDatei['geraete'] ?? null) ? $geraeteDatei['geraete'] : [];
$anmStatus = $laeuft ? (string)(($status['anmeldung']['status'] ?? '')) : '';

// 4. Parameter true: LoxBerry Design System (lb-*-Klassen) statt jQuery Mobile
LBWeb::lbheader('Ecovacs2Lox ' . AppVersion::CURRENT, '', '', true);
?>
<style>
 .eco pre { background:var(--lb-gray-100,#f4f4f4); color:var(--lb-text,#222); padding:8px; overflow:auto; max-height:420px; border-radius:var(--lb-radius-sm,4px); }
 .eco .ok { color:var(--lb-success,#2a8a2a); font-weight:bold; } .eco .fehler { color:var(--lb-danger,#c0392b); font-weight:bold; }
 .eco .hinweis { color:var(--lb-text-muted,#666); font-size:90%; }
 .eco .lb-card { margin:12px 0; padding:4px 16px 12px 16px; }
 .eco .eco-opt { margin:6px 0; display:flex; align-items:flex-start; gap:8px; }
 .eco .eco-opt .lb-toggle { flex:none; }
 .eco .lb-table td .lb-input, .eco .lb-table td .lb-select { width:auto; max-width:100%; }
 .eco .eco-schmal { width:90px !important; } .eco .eco-mittel { width:170px !important; } .eco .eco-breit { width:280px !important; }
 .eco-led { display:inline-block; width:18px; height:18px; border-radius:3px; vertical-align:middle; border:1px solid rgba(0,0,0,.35); }
 .eco-warn { color:var(--lb-warning,#b9770e); }
 .eco-led-ok { background:var(--lb-success,#2ecc40); } .eco-led-fehler { background:var(--lb-danger,#e74c3c); } .eco-led-aus { background:var(--lb-gray-400,#bbb); }
</style>
<div class="lb-content eco">
<?php foreach ($meldungen as $m) { echo '<div class="lb-callout' . (str_starts_with($m, 'FEHLER') ? ' lb-callout-warning' : '') . '"><span class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</span></div>'; } ?>

<?php if ($tab === 'konto') { ?>
<form method="post" action="<?= $basis ?>konto">
<input type="hidden" name="token" value="<?= h($token) ?>">
<div class="lb-card">
<h3>Ecovacs-Konto</h3>
<p class="hinweis">Das Plugin meldet sich wie die Ecovacs-App bei der Ecovacs-Cloud an und liest darüber Ihre Roboter. Es gibt keine lokale Schnittstelle der Roboter: ohne Internet und ohne laufende Cloud geht nichts. Das ist eine <b>inoffizielle</b> Schnittstelle, Ecovacs kann sie ändern oder sperren. Ein zweites Konto, mit dem der Roboter geteilt ist, ist sicherer als das Hauptkonto.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>E-Mail (oder Ecovacs-ID)</th><td><input class="lb-input eco-breit" type="text" name="konto[email]" value="<?= h($eco['konto']['email']) ?>" autocomplete="off"></td></tr>
<tr><th>Passwort</th><td><input class="lb-input eco-mittel" type="password" name="konto[passwort]" value="" autocomplete="new-password" placeholder="<?= $eco['konto']['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
<tr><th>Land des Kontos</th><td><select class="lb-select" name="konto[land]">
<?php foreach (EcoConfig::LAENDER as $code => [$name]) { echo '<option value="' . h($code) . '"' . ($eco['konto']['land'] === $code ? ' selected' : '') . '>' . h($name) . '</option>'; } ?>
</select></td></tr>
</table></div>
<p class="hinweis">Das Passwort wird nie angezeigt; ein leeres Feld behält das gespeicherte Passwort. Es liegt in der Konfigurationsdatei des Plugins (nur für den Systembenutzer lesbar) und geht nur als MD5-Hash an Ecovacs. China hat eine eigene Cloud und wird nicht unterstützt.</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</div>
</form>

<div class="lb-card">
<h3>Anmeldung</h3>
<?php
    $texte = ['keine_daten' => ['aus', 'E-Mail und Passwort fehlen'], 'anmelden' => ['aus', 'Anmeldung läuft'], 'ok' => ['ok', 'angemeldet'],
        'verifizierung' => ['fehler', 'Verifizierung nötig'], 'falsch' => ['fehler', 'Konto oder Passwort falsch'], 'fehler' => ['fehler', 'Anmeldung fehlgeschlagen']];
    [$led, $tx] = $laeuft ? ($texte[$anmStatus] ?? ['aus', 'unbekannt']) : ['aus', 'der Dienst läuft nicht'];
?>
<p><span class="eco-led eco-led-<?= $led ?>"></span> <b><?= h($tx) ?></b>
<?php if ($laeuft && $anmStatus !== 'ok' && !empty($status['anmeldung']['text'])) { echo ' – ' . h($status['anmeldung']['text']); } ?>
<?php if ($anmStatus === 'ok' && !empty($status['anmeldung']['gueltig_bis'])) { echo '<br><span class="hinweis">Der Zugriffstoken gilt bis etwa ' . h(date('d.m.Y H:i', (int)$status['anmeldung']['gueltig_bis'])) . '; der Dienst erneuert ihn selbst.</span>'; } ?></p>

<?php if ($anmStatus === 'verifizierung') { ?>
<div class="lb-callout"><span class="lb-callout-title">Verifizierung dieser Installation</span>
<p>Ecovacs kennt diese Installation noch nicht und verlangt einmal einen Code, den es per E-Mail an Ihr Konto schickt. Danach ist die Kennung dieser Installation bei Ecovacs bekannt und der Code wird nicht mehr gebraucht (solange Sie das Plugin nicht komplett löschen oder das Konto ändern).</p>
<form method="post" action="<?= $basis ?>konto" style="display:inline"><input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn" type="submit" name="aktion" value="code_anfordern">1. Code per E-Mail anfordern</button></form>
<form method="post" action="<?= $basis ?>konto" style="display:inline"><input type="hidden" name="token" value="<?= h($token) ?>">
 &nbsp; 2. Code: <input class="lb-input eco-schmal" type="text" name="code" maxlength="12" autocomplete="off" placeholder="123456">
<button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="code_pruefen">Code bestätigen</button></form>
</div>
<?php } elseif ($laeuft && in_array($anmStatus, ['falsch', 'fehler'], true)) { ?>
<form method="post" action="<?= $basis ?>konto"><input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn" type="submit" name="aktion" value="neu_anmelden">Anmeldung erneut versuchen</button></form>
<p class="hinweis">Bei „Konto oder Passwort falsch“ versucht der Dienst es nicht von selbst wieder (damit Ecovacs das Konto nicht sperrt). Nach Korrektur der Eingaben speichern, dann hier erneut versuchen.</p>
<?php } ?>
</div>

<?php } elseif ($tab === 'geraete') { ?>
<form method="post" action="<?= $basis ?>geraete">
<input type="hidden" name="token" value="<?= h($token) ?>">
<div class="lb-card">
<h3>Allgemein</h3>
<div class="lb-form-row"><label class="lb-form-label" for="abfrage_abstand">Alle Werte alle … Sekunden neu abfragen (60–3600)</label>
<div class="lb-form-field"><input class="lb-input eco-schmal" type="number" id="abfrage_abstand" name="abfrage_abstand" min="60" max="3600" value="<?= (int)$eco['abfrage_abstand'] ?>"></div></div>
<p class="hinweis">Änderungen meldet der Roboter selbst per Push; dazu fragt das Plugin zusätzlich alle 20 Sekunden (bei Reinigung) bzw. alle 2 Minuten (sonst) den Zustand ab und alle Werte in diesem Abstand.</p>
</div>

<div class="lb-card">
<h3>Roboter</h3>
<div class="lb-callout"><span class="lb-callout-title">Geprüft und nicht geprüft</span>
<p><b>Ältere Modelle</b> (Protokoll „XML“: Deebot 500, 600, 700, 900 u. a.) sind am <b>Deebot 605</b> geprüft: Anmeldung, Abfragen, alle Werte. Die Steuerbefehle sind dort noch nicht am Roboter ausprobiert.<br>
<b>Neuere Modelle</b> (Protokoll „JSON“: OZMO 920/950, T-, N- und X-Reihen) sind <b>nach der Protokollbeschreibung gebaut und ungeprüft</b>, es stand kein solcher Roboter zum Testen zur Verfügung. Rückmeldungen sind willkommen.</p></div>
<?php if (!$gefunden) { ?>
<p class="hinweis">Noch keine Roboter gefunden. Erst im Reiter „Konto“ anmelden; dann erscheinen die Roboter Ihres Kontos hier.</p>
<?php } else { ?>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Status</th><th>Aktiv</th><th>Roboter</th><th>Name im Topic</th><th>Protokoll</th><th>Befehle freigeben</th></tr>
<?php
$namenNachDid = [];
foreach (EcoConfig::roboter($eco, $gefunden) as $r) {
    $namenNachDid[$r['did']] = $r['name'];
}
foreach ($gefunden as $d) {
    $did = EcoConfig::cleanDid((string)$d['did']);
    $c = array_merge(EcoConfig::defaultGeraet(), $eco['geraete'][$did] ?? []);
    $name = $namenNachDid[$did] ?? null;
    $r = ($name !== null && $laeuft) ? ($status['roboter'][$name] ?? null) : null;
    if (empty($c['aktiv'])) {
        [$led, $tip] = ['aus', 'nicht aktiv'];
    } elseif ($r === null) {
        [$led, $tip] = ['aus', $laeuft ? 'noch keine Meldung des Dienstes' : 'der Dienst läuft nicht'];
    } elseif (!empty($r['aus'])) {
        [$led, $tip] = ['aus', 'nicht erreichbar (ausgeschaltet oder ohne WLAN) – kein Fehler'];
    } elseif (empty($r['ok'])) {
        [$led, $tip] = ['fehler', (string)($r['error'] ?? 'Fehler')];
    } else {
        [$led, $tip] = ['ok', (!empty($r['status']) ? $r['status'] : 'verbunden') . (isset($r['akku']) ? ', Akku ' . (int)$r['akku'] . ' %' : '') . (!empty($r['protokoll']) ? ', Protokoll ' . $r['protokoll'] : ', Protokoll wird erkannt')];
    }
    $i = h($did);
    ?>
<tr>
 <td><span class="eco-led eco-led-<?= $led ?>" title="<?= h($tip) ?>"></span> <span class="hinweis"><?= h($tip) ?></span></td>
 <td><?= schalter('geraete[' . $did . '][aktiv]', !empty($c['aktiv'])) ?></td>
 <td><b><?= h($d['nick'] !== '' ? $d['nick'] : 'Roboter') ?></b><br><span class="hinweis"><?= h($d['modell'] !== '' ? $d['modell'] : $d['class']) ?></span></td>
 <td><input class="lb-input eco-mittel" type="text" name="geraete[<?= $i ?>][name]" value="<?= h($c['name']) ?>" placeholder="<?= h($name ?? Util::name((string)$d['nick'])) ?>"></td>
 <td><select class="lb-select" name="geraete[<?= $i ?>][protokoll]">
<?php foreach (EcoConfig::PROTOKOLLE as $k => $t) { echo '<option value="' . h($k) . '"' . ($c['protokoll'] === $k ? ' selected' : '') . '>' . h($t) . '</option>'; } ?>
 </select></td>
 <td>
<?php foreach (EcoConfig::STUFEN as $k => $t) { ?>
  <div class="eco-opt"><?= schalter('geraete[' . $did . '][befehle][' . $k . ']', !empty($c['befehle'][$k]), h($t)) ?></div>
<?php } ?>
 </td>
</tr>
<?php } ?>
</table></div>
<p class="hinweis">Bis zu fünf Roboter. Der Name steckt im Topic (<code>ecovacs/&lt;name&gt;/…</code>; nur a–z, 0–9, _ und -), leer = Name aus der Ecovacs-App. „Automatisch erkennen“ probiert beim ersten Mal zuerst das alte, dann das neue Protokoll und merkt sich das Ergebnis. Ein ausgeschalteter Roboter ist kein Fehler (<code>ecovacs/status/ok</code> bleibt 1, <code>verbunden</code> = 0).</p>
<p class="hinweis"><b>Befehle:</b> ab Werk gesperrt. Die Roboter fahren los, sobald der Befehl „Start“ kommt; geben Sie nur frei, was Sie wirklich aus Loxone auslösen wollen. Die Quittung steht unter <code>…/quittung</code>.</p>
<?php } ?>
</div>
<?php if ($gefunden) { ?><p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p><?php } ?>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = EcoConfig::ownBroker($eco);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('ecovacs/#', GatewayInfo::general());
    ?>
<h3>MQTT</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th><?= $own !== null ? 'Broker (eigener, aus den Plugin-Einstellungen)' : 'Broker (aus der LoxBerry-Konfiguration)' ?></th><td><?= h(($d['brokerhost'] ?? '?') . ':' . ($d['brokerport'] ?? '?')) ?></td></tr>
<tr><th>Gateway-Abo</th><td><?= GatewayInfo::feldHtml($gw, 'ecovacs/#') ?></td></tr>
<tr><th>Dienst</th><td><?= $laeuft ? '<span class="ok">läuft</span> (Version ' . h($status['version'] ?? '?') . ')' : '<span class="fehler">läuft nicht</span> (der Wächter startet ihn spätestens nach einer Minute)' ?></td></tr>
<tr><th>Broker-Verbindung des Dienstes</th><td><?= !empty($status['mqtt']) ? '<span class="ok">verbunden</span>' : '<span class="fehler">getrennt</span> ' . h($status['mqtt_error'] ?? '') ?></td></tr>
<tr><th>MQTT der Ecovacs-Cloud (Push-Meldungen)</th><td><?= !empty($status['cloud_mqtt']) ? '<span class="ok">verbunden</span>' : ($anmStatus === 'ok' ? '<span class="eco-warn">getrennt</span> ' . h($status['cloud_mqtt_error'] ?? '') . ' (die Abfragen per HTTPS laufen trotzdem)' : '-') ?></td></tr>
<tr><th>status/ok</th><td><?= isset($status['ok']) ? (int)$status['ok'] : '-' ?></td></tr>
<tr><th>status/zaehler</th><td><?= isset($status['zaehler']) ? (int)$status['zaehler'] : '-' ?></td></tr>
<tr><th>status/ts</th><td><?= !empty($status['ts']) ? h(date('d.m.Y H:i:s', (int)$status['ts'])) : '-' ?></td></tr>
</table></div>
<form method="post" action="<?= $basis ?>mqtt">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $eco['mqtt']; ?>
<div class="eco-opt"><?= schalter('mqtt[eigener_broker]', !empty($mq['eigener_broker']), 'Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)') ?></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Host/IP</th><td><input class="lb-input" type="text" name="mqtt[host]" value="<?= h($mq['host']) ?>"></td>
<th>Port</th><td><input class="lb-input eco-schmal" type="number" name="mqtt[port]" value="<?= (int)$mq['port'] ?>"></td></tr>
<tr><th>Benutzer</th><td><input class="lb-input eco-mittel" type="text" name="mqtt[benutzer]" value="<?= h($mq['benutzer']) ?>" autocomplete="off"></td>
<th>Passwort</th><td><input class="lb-input eco-mittel" type="password" name="mqtt[passwort]" value="" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
</table></div>
<p class="hinweis">Ohne Haken verwendet das Plugin den MQTT-Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry). Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort. Dieser Broker ist <i>nicht</i> der der Ecovacs-Cloud.</p>
<div class="lb-callout"><span class="lb-callout-title">Zertifikat der Ecovacs-Cloud</span>
<p>Die Push-Meldungen kommen vom MQTT-Server der Ecovacs-Cloud (TLS, Port 8883). Dessen Zertifikat stammt von einer <b>eigenen Ecovacs-Zertifizierungsstelle</b> und lässt sich mit den öffentlichen Zertifizierungsstellen des Systems nicht prüfen. Das Plugin prüft es deshalb (wie die App-Bibliotheken anderer Projekte) nicht; die Verbindung ist verschlüsselt, der Server aber nicht beglaubigt. Die Anmeldung und alle Befehle gehen dagegen per HTTPS mit geprüftem Zertifikat.</p></div>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'loxone') {
    $namen = array_map(fn($g) => $g['name'], EcoConfig::roboter($eco, $gefunden)) ?: ['roboter1'];
    // [Topic (relativ zu ecovacs/<name>/), Einheit, Bedeutung, Gruppe]
    $werte = [
        ['status', 'Text', 'Zusammengefasster Zustand: Lädt, In der Station (voll geladen), Reinigt (Auto/Kante/Punkt), Pausiert, Fährt zur Ladestation, Ruhezustand, Bereit, Fehler: …', 'Status'],
        ['modus', 'Text', 'Reinigungsart bzw. -zustand: auto, edge, spot, spot_area, custom_area, pause, stop, returning, idle', 'Status'],
        ['reinigt | laedt | zurueck', '0/1', 'reinigt gerade | lädt in der Station | fährt zur Ladestation', 'Status'],
        ['verbunden | online', '0/1', 'verbunden: Antworten kommen an; online: 0 = Roboter nicht erreichbar (ausgeschaltet oder ohne WLAN, kein Fehler)', 'Status'],
        ['akku | akku_schwach', '%, 0/1', 'Akkustand und Warnung (bis 15 %)', 'Akku'],
        ['saugstufe', '1–4', 'Saugstufe (ältere Modelle: 2 = normal, 3 = stark)', 'Einstellungen'],
        ['wasserstufe | wassertank', '1–4, 0/1', 'Wasserstufe und ob der Wassertank eingesetzt ist (Modelle mit Wischfunktion)', 'Einstellungen'],
        ['schlaf', '0/1', 'Ruhezustand des Roboters', 'Status'],
        ['flaeche_m2 | dauer_s', 'm², s', 'Fläche und Dauer der laufenden oder letzten Reinigung (Einheiten nicht bestätigt)', 'Reinigung'],
        ['summe_flaeche_m2 | summe_dauer_s | summe_anzahl', 'm², s, -', 'Summen über alle Reinigungen', 'Reinigung'],
        ['verschleiss_hauptbuerste | verschleiss_seitenbuerste | verschleiss_filter', '%', 'Restlebensdauer der Verschleißteile (100 = neu)', 'Verschleiß'],
        ['fehler_code | fehler_text', '-, Text', 'Fehler des Roboters (0 = kein Fehler) und Klartext', 'Fehler'],
        ['protokoll | modell', 'Text', 'Erkanntes Protokoll (xml oder json) und Modellname aus der Cloud', 'Info'],
    ];
    ?>
<h3>Einbindung in Loxone</h3>
<p>Der Eingangsname ist das Topic mit _ statt /. Die Werte kommen über das MQTT-Gateway (Abo ecovacs/#) als virtuelle Eingänge, z. B. <code>ecovacs_<?= h($namen[0]) ?>_akku</code>.
Werte, die der Roboter nicht liefert, werden nicht gesendet (z. B. Wasserstufe bei Modellen ohne Wischfunktion). Gesendet werden nur Änderungen und alle 5 Minuten alle Werte. Texte (<code>status</code>, <code>modus</code>, <code>fehler_text</code>) als virtuelle Texteingänge anlegen.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Gruppe</th><th>Topic</th><th>Eingangsname</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php
$einzel = fn(string $t) => !preg_match('/[|<]/', $t);     // genau ein festes Topic?
foreach ($namen as $a) {
    foreach ($werte as $w) {
        $topic = 'ecovacs/' . $a . '/' . $w[0];
        $name = $einzel($w[0]) ? str_replace('/', '_', $topic) : '(Topic mit _ statt /)';
        echo '<tr><td>' . h($w[3]) . '</td><td>' . h($topic) . '</td><td>' . h($name) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
    }
}
foreach ([['ecovacs/status/ok', '0/1', 'Anmeldung und alle Roboter in Ordnung (ein ausgeschalteter Roboter ist kein Fehler; No Cache)'], ['ecovacs/status/zaehler', '0–999', 'Lebenszeichen, zählt alle 10 s (No Cache)'],
    ['ecovacs/status/ts', 's', 'Unix-Zeitstempel des letzten Lebenszeichens']] as $w) {
    echo '<tr><td>Status</td><td>' . h($w[0]) . '</td><td>' . h(str_replace('/', '_', $w[0])) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
}
?>
</table></div>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker ecovacs/status/ok per Letzter-Wille-Nachricht auf 0 (retained).</p>

<h3>Befehle aus Loxone</h3>
<p>Gesperrt, bis sie je Roboter im Reiter „Geräte“ freigegeben sind. Virtueller Ausgang: Adresse <code>/dev/udp/&lt;LoxBerry-IP&gt;/<?= (int)$udp ?></code>,
Befehl bei EIN <code>publish ecovacs/befehl/&lt;name&gt;/&lt;befehl&gt; &lt;wert&gt;</code> (ohne HTTP-Header/Body, Trennzeichen leer). Die Quittung steht unter <code>…/&lt;befehl&gt;/quittung</code>:
<code>angenommen</code>, dann <code>ausgefuehrt</code> oder <code>abgelehnt: &lt;grund&gt;</code> (nie retained). Höchstens ein Befehl je Sekunde und Roboter. Der Roboter fährt bei <b>start</b> los.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Topic</th><th>Wert</th><th>Stufe</th><th>Wirkung</th></tr>
<?php
foreach ($namen as $a) {
    foreach (EcoCommands::LISTE as $k => [$stufe, , $text]) {
        $w = $k === 'start' ? 'auto | kante | spot (oder 1)' : (in_array($k, ['saugstufe', 'wasserstufe'], true) ? '1–4' : '1');
        echo '<tr><td>ecovacs/befehl/' . h($a) . '/' . h($k) . '</td><td>' . h($w) . '</td><td>' . h(EcoConfig::STUFEN[$stufe]) . '</td><td>' . h($text) . '</td></tr>';
    }
}
?>
</table></div>
<p class="hinweis">Ältere Modelle kennen nur zwei Saugstufen (1 und 2 = normal, 3 und 4 = stark). Nie vorgesehen: Karten löschen, Zeitpläne, Firmware, Werksreset, Konto- und Netzwerkeinstellungen.</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="selbst">Selbstprüfung</button>
<button class="lb-btn lb-btn-danger" type="submit" name="aktion" value="neustart">Dienst neu starten</button>
</form>
<?php
    if ((string)($_POST['aktion'] ?? '') === 'selbst') {
        $zeile = function (string $name, bool $ok, string $text): void {
            echo '<tr><td>' . h($name) . '</td><td class="' . ($ok ? 'ok' : 'fehler') . '">' . ($ok ? 'OK' : 'Fehler') . '</td><td>' . h($text) . '</td></tr>';
        };
        echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
        $zeile('PHP', PHP_VERSION_ID >= 70400 && extension_loaded('curl') && extension_loaded('openssl'), 'PHP ' . PHP_VERSION . ', curl ' . (extension_loaded('curl') ? 'vorhanden' : 'FEHLT') . ', openssl ' . (extension_loaded('openssl') ? 'vorhanden' : 'FEHLT'));
        $zeile('Konto eingetragen', EcoConfig::kontoVollstaendig($eco), EcoConfig::kontoVollstaendig($eco) ? 'E-Mail und Passwort sind eingetragen' : 'E-Mail oder Passwort fehlen');
        $zeile('Dienst', $laeuft, $laeuft ? 'läuft, letzte Statusmeldung vor ' . $alter . ' s' : 'keine aktuelle Statusmeldung');
        $zeile('Anmeldung bei Ecovacs', $anmStatus === 'ok', $laeuft ? ($anmStatus === 'ok' ? 'angemeldet' : ((string)($status['anmeldung']['text'] ?? '') ?: 'nicht angemeldet')) : 'Dienst läuft nicht');
        $zeile('Roboter im Konto', count($gefunden) > 0, count($gefunden) . ' gefunden');
        foreach (($status['roboter'] ?? []) as $n => $r) {
            $zeile('Roboter ' . $n, !empty($r['ok']), !empty($r['aus']) ? 'nicht erreichbar (ausgeschaltet?) – kein Fehler' : (!empty($r['ok'])
                ? (string)($r['status'] ?? '') . ', Protokoll ' . ((string)($r['protokoll'] ?? '') ?: 'wird erkannt') . ', ' . (int)($r['antworten'] ?? 0) . ' Antworten' : (string)($r['error'] ?? 'Fehler')));
        }
        $own = EcoConfig::ownBroker($eco);
        $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1], 'brokeruser' => $own[2], 'brokerpass' => $own[3]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
        $mq = new MqttClient((string)($d['brokerhost'] ?? 'localhost'), (int)($d['brokerport'] ?? 1883), (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? ''), 'ecovacs_test_' . $paths->folder, 'ecovacs/status/test');
        $okm = $mq->connect();
        $zeile('Broker', $okm, $okm ? 'Verbindung möglich' : $mq->lastError());
        $mq->close();
        $gw = GatewayInfo::subscription('ecovacs/#', GatewayInfo::general());
        $zeile('Gateway-Abo', $gw['state'] === 'ok', $gw['text']);
        echo '</table></div>';
    }
} elseif ($tab === 'logs') {
    echo '<h3>Logdateien</h3>';
    $dateien = glob(LBPLOGDIR . '/*.log') ?: [];
    if (!$dateien) {
        echo '<p>Noch keine Logdatei vorhanden.</p>';
    }
    foreach ($dateien as $f) {
        $size = (int)filesize($f);
        $text = (string)@file_get_contents($f, false, null, max(0, $size - 20000));   // nur das Ende lesen
        $zeilen = array_slice(explode("\n", $text), -200);
        // Beim allerersten Start meldet die LoxBerry-Logbibliothek, dass die neue Logdatei noch nicht in ihrer Datenbank steht. Harmlos, nicht anzeigen.
        $zeilen = array_filter($zeilen, fn($z) => !str_contains($z, 'log_db_queryid: Could not find database entry'));
        echo '<h4>' . h(basename($f)) . ' (letzte Zeilen)</h4><pre>' . h(implode("\n", $zeilen)) . '</pre>';
    }
}
?>
</div>
<?php LBWeb::lbfooter(); ?>
