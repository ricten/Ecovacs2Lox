<?php
/**
 * Kleiner XML-Leser und -Schreiber fuer das alte Protokoll der Deebot-Modelle (<ctl id='..' ret='ok'><battery power='099'/></ctl>).
 * Die Nachrichten sind flach (ein Wurzelelement, wenige Kindelemente mit Attributen, kein Text). Ein eigener Leser vermeidet die
 * Abhaengigkeit von PHP-XML-Erweiterungen und ist gegen DTD/Entity-Tricks (XXE) unempfindlich: es gibt nichts zu laden.
 */
final class EcoXml
{
    private const TAG = '/<(\/?)([A-Za-z_][\w:.-]*)((?:\s+[\w:.-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/';

    /**
     * Wurzelelement samt Kindern lesen.
     * @return array{tag:string,attrs:array<string,string>,kinder:list<array>}|null  null bei Unlesbarem
     */
    public static function parse(string $xml): ?array
    {
        $xml = trim((string)preg_replace('/<\?xml[^>]*\?>/i', '', $xml));
        if ($xml === '' || $xml[0] !== '<' || strlen($xml) > 65536) {
            return null;
        }
        if (!preg_match_all(self::TAG, $xml, $tags, PREG_SET_ORDER)) {
            return null;
        }
        $stack = [];
        $root = null;
        foreach ($tags as $m) {
            if ($root !== null) {
                return null;                       // Text oder Elemente nach dem Wurzelelement
            }
            if ($m[1] === '/') {                  // schliessendes Element
                $knoten = array_pop($stack);
                if ($knoten === null || $knoten['tag'] !== $m[2]) {
                    return null;
                }
                if ($stack) {
                    $stack[count($stack) - 1]['kinder'][] = $knoten;
                } else {
                    $root = $knoten;
                }
                continue;
            }
            $knoten = ['tag' => $m[2], 'attrs' => self::attribute($m[3]), 'kinder' => []];
            if ($m[4] === '/') {                  // selbstschliessend
                if ($stack) {
                    $stack[count($stack) - 1]['kinder'][] = $knoten;
                } else {
                    $root = $knoten;
                }
            } else {
                $stack[] = $knoten;
            }
        }
        return $stack ? null : $root;
    }

    /** @return array<string,string> */
    private static function attribute(string $s): array
    {
        $out = [];
        if (preg_match_all('/([\w:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) {
                $out[$a[1]] = self::entities(isset($a[3]) && $a[3] !== '' ? $a[3] : $a[2]);
            }
        }
        return $out;
    }

    private static function entities(string $s): string
    {
        return str_replace(['&quot;', '&apos;', '&lt;', '&gt;', '&amp;'], ['"', "'", '<', '>', '&'], $s);
    }

    private static function esc(string $s): string
    {
        return str_replace(['&', "'", '<', '>'], ['&amp;', '&apos;', '&lt;', '&gt;'], $s);
    }

    /**
     * Befehl als XML-Text: <ctl id='..' a='..'><kind b='..'/></ctl>. Der Befehlsname (td) gehoert nicht in den Text, er geht als cmdName an die Cloud.
     * @param array<string,string|int> $attrs Attribute des Wurzelelements
     * @param array{0:string,1:array<string,string|int>}|null $kind Kindelement [Name, Attribute]
     */
    public static function bauen(array $attrs, ?array $kind = null): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' ' . $k . "='" . self::esc((string)$v) . "'";
        }
        if ($kind === null) {
            return '<ctl' . $a . '/>';
        }
        $b = '';
        foreach ($kind[1] as $k => $v) {
            $b .= ' ' . $k . "='" . self::esc((string)$v) . "'";
        }
        return '<ctl' . $a . '><' . $kind[0] . $b . '/></ctl>';
    }
}
