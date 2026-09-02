<?php

declare(strict_types=1);

namespace Mfg\Eamuse;

use Mfg\Storage\Database;
use SimpleXMLElement;

final class Dispatcher
{
    private const DEFAULT_CARDID = 'E0047CC78DFBA459';
    private const PASELI_BALANCE = 57300;

    public function __construct(private Database $db, private string $baseUrl)
    {
    }

    public function dispatch(string $model, string $module, string $method, ?SimpleXMLElement $root): string
    {
        $srcid = $root ? (string)($root['srcid'] ?? '') : '';
        if ($srcid !== '') {
            $this->db->touchCabinet($srcid, $model);
        }

        return match (true) {
            $module === 'services' && $method === 'get' => $this->services(),
            $module === 'pcbtracker' && in_array($method, ['alive','keepalive'], true) => '<?xml version="1.0" encoding="UTF-8"?><response><pcbtracker expire="1200" status="0" ecenable="1" eclimit="0" limit="0" time="' . time() . '" /></response>',
            $module === 'message' && $method === 'get' => '<?xml version="1.0" encoding="UTF-8"?><response><message expire="300" status="0" /></response>',
            $module === 'facility' && $method === 'get' => $this->facility(),
            $module === 'package' && $method === 'list' => '<?xml version="1.0" encoding="UTF-8"?><response><package expire="600" status="0" /></response>',
            $module === 'pcbevent' && $method === 'put' => '<?xml version="1.0" encoding="UTF-8"?><response><pcbevent status="0" /></response>',
            $module === 'eventlog' && $method === 'write' => $this->wrap('eventlog', $this->kitem('gamesession','s64',1) . $this->kitem('logsendflg','s32',0) . $this->kitem('logerrlevel','s32',0) . $this->kitem('evtidnosendflg','s32',0)),
            in_array($module, ['cardmng','vfgcard'], true) => $this->cardmng($module, $method, $root),
            $module === 'eacoin' => $this->eacoin($method, $root),
            $module === 'vfgac' => $this->vfgac($method),
            $module === 'vfglog' => $this->vfglog($method, $root),
            in_array($module, ['posevent','pkglist','userdata','userid','sidmgr','netlog','local','local2'], true) => $this->wrap($module, ''),
            default => $this->wrap($module !== '' ? $module : 'eamuse', ''),
        };
    }

    private function services(): string
    {
        $names = ['cardmng','vfgcard','eacoin','facility','local','local2','message','netlog','package','pcbevent','pcbtracker','pkglist','posevent','sidmgr','userdata','userid','eventlog'];
        $items = '';
        foreach ($names as $name) {
            $items .= '<item name="' . $this->x($name) . '" url="' . $this->x($this->baseUrl) . '"/>';
        }
        $keepalive = $this->baseUrl . '/core/keepalive?pa=127.0.0.1&amp;ia=127.0.0.1&amp;ga=127.0.0.1&amp;ma=127.0.0.1&amp;t1=2&amp;t2=10';
        return '<?xml version="1.0" encoding="UTF-8"?><response><services expire="10800" method="get" mode="operation" status="0">' . $items . '<item name="ntp" url="ntp.nict.jp"/><item name="keepalive" url="' . $keepalive . '"/></services></response>';
    }

    private function facility(): string
    {
        $parts = parse_url($this->baseUrl) ?: [];
        $host = (string)($parts['host'] ?? '127.0.0.1');
        $facilityIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $host : gethostbyname($host);
        if (filter_var($facilityIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) $facilityIp = '127.0.0.1';
        $port = (int)($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
        $inner = '<location>'
            . $this->kitem('id','str','VFG00001')
            . $this->kitem('country','str','JP')
            . $this->kitem('region','str','13')
            . $this->kitem('name','str','LOCAL TEST')
            . $this->kitem('type','u8',0)
            . $this->kitem('countryname','str','Japan')
            . $this->kitem('countryjname','str','日本')
            . $this->kitem('regionname','str','Tokyo')
            . $this->kitem('regionjname','str','東京都')
            . $this->kitem('customercode','str','VFG')
            . $this->kitem('companycode','str','00')
            . $this->kitem('latitude','s32',0)
            . $this->kitem('longitude','s32',0)
            . $this->kitem('accuracy','u8',0)
            . '</location><line>'
            . $this->kitem('id','str','0')
            . $this->kitem('class','u8',1)
            . '</line><portfw>'
            . $this->kitem('globalip','ip4',$facilityIp)
            . $this->kitem('globalport','u16',$port)
            . $this->kitem('privateport','u16',$port)
            . '</portfw><public>'
            . $this->kitem('flag','u8',1)
            . $this->kitem('name','str','LOCAL TEST')
            . $this->kitem('latitude','s32',0)
            . $this->kitem('longitude','s32',0)
            . '</public><share><eacoin>'
            . $this->kitem('notchamount','s32',0)
            . $this->kitem('notchcount','s32',0)
            . $this->kitem('supplylimit','s32',100000)
            . '</eacoin><url>'
            . $this->kitem('eapass','str',$this->baseUrl)
            . $this->kitem('arcadefan','str',$this->baseUrl)
            . $this->kitem('konaminetdx','str',$this->baseUrl)
            . $this->kitem('konamiid','str',$this->baseUrl)
            . $this->kitem('eagate','str',$this->baseUrl)
            . '</url></share>';
        return $this->wrap('facility', $inner);
    }

    private function vfgac(string $method): string
    {
        if ($method === 'service_list') {
            $url = rtrim($this->baseUrl, '/') . '/aog';
            $inner = $this->kitem('service_url','str',$url) . '<services><item service="front" mode="operation">' . $this->x($url) . '</item><item service="game" mode="operation">' . $this->x($url) . '</item></services>';
            return '<?xml version="1.0" encoding="UTF-8"?><response><vfgac status="0">' . $inner . '</vfgac>' . $inner . '</response>';
        }
        return $this->wrap('vfgac', '');
    }

    private function cardmng(string $wireNode, string $method, ?SimpleXMLElement $root): string
    {
        $node = $this->findModuleNode($root, $wireNode) ?? $this->findModuleNode($root, $wireNode === 'vfgcard' ? 'cardmng' : 'vfgcard') ?? $root;
        $rawCard = $node ? (string)($node['cardid'] ?? $node['card_id'] ?? '') : '';
        $refid = $node ? strtoupper(trim((string)($node['refid'] ?? ''))) : '';
        $normalized = preg_replace('/\s+/', '', $rawCard) ?? '';
        $canonical = (bool)preg_match('/^[0-9A-Fa-f]{16}$/', $normalized);
        $strict = strtolower(trim((string)(getenv('VFG_CARDMNG_MODE') ?: 'compat'))) === 'strict';
        $inquireMode = strtolower(trim((string)(getenv('VFG_CARDMNG_INQUIRE_MODE') ?: 'auto')));

        if ($rawCard === '' && in_array($method, ['inquire','getrefid'], true)) {
            return $this->cardXml($wireNode, $method === 'inquire' ? ' status="112"' : ' status="110"');
        }
        if (in_array($method, ['bindmodel','bindcard'], true) && $refid === '') {
            return $this->cardXml($wireNode, ' status="110"');
        }
        if (!$canonical && $rawCard !== '' && $strict && in_array($method, ['inquire','getrefid'], true)) {
            return $this->cardXml($wireNode, $method === 'inquire' ? ' status="112"' : ' status="110"');
        }

        $cardId = $canonical ? strtoupper($normalized) : self::DEFAULT_CARDID;
        if ($method === 'inquire') {
            if ($inquireMode === 'new') {
                return $this->cardXml($wireNode, ' status="112"');
            }
            $rec = $this->db->getCard($cardId);
            if (!$rec || !(int)$rec['issued']) {
                return $this->cardXml($wireNode, ' status="112"');
            }
            $attrs = ' binded="' . ((int)$rec['bound'] ? '1' : '0') . '" dataid="' . $this->x((string)$rec['refid']) . '" refid="' . $this->x((string)$rec['refid']) . '" newflag="' . ((int)$rec['bound'] ? '0' : '1') . '" expired="0" exflag="0" ecflag="1"';
            if ((int)$rec['bound']) {
                $attrs .= ' lastupdate="' . (int)($rec['updated_at'] ?: $rec['created_at'] ?: time()) . '"';
            }
            return $this->cardXml($wireNode, $attrs);
        }
        if ($method === 'getrefid') {
            $pin = $node ? trim((string)($node['passwd'] ?? '')) : '';
            $existing = $this->db->getCard($cardId);
            $existingPin = is_array($existing) ? (string)($existing['pin'] ?? '') : '';
            $safePin = preg_match('/^\d{4}$/', $pin) ? $pin : (preg_match('/^\d{4}$/', $existingPin) ? $existingPin : '0000');
            $rec = $this->db->issueCard($cardId, $safePin);
            return $this->cardXml($wireNode, ' refid="' . $this->x((string)$rec['refid']) . '" dataid="' . $this->x((string)$rec['refid']) . '"');
        }
        if ($method === 'authpass') {
            return $this->cardXml($wireNode, ' status="0"');
        }
        if (in_array($method, ['bindmodel','bindcard'], true)) {
            $rec = $this->db->bindCardByRefid($refid);
            return $rec ? $this->cardXml($wireNode, ' dataid="' . $this->x((string)$rec['refid']) . '"') : $this->cardXml($wireNode, ' status="110"');
        }
        if ($method === 'getdatalist') {
            return $this->cardXml($wireNode, '');
        }
        return $this->cardXml($wireNode, ' status="0"');
    }

    private function eacoin(string $method, ?SimpleXMLElement $root): string
    {
        if (in_array($method, ['checkin','opcheckin'], true)) {
            $sess = substr(bin2hex(random_bytes(8)), 0, 16);
            $this->db->setKv('eacoin', $sess, self::PASELI_BALANCE);
            if ($method === 'opcheckin') {
                return $this->wrap('eacoin', $this->kitem('sessid','str',$sess));
            }
            return $this->wrap('eacoin', $this->kitem('sequence','s16',0) . $this->kitem('acstatus','u8',0) . $this->kitem('acid','str','LOCAL') . $this->kitem('acname','str','LOCAL TEST') . $this->kitem('balance','s32',self::PASELI_BALANCE) . $this->kitem('sessid','str',$sess));
        }
        $sess = $this->childText($root, 'sessid');
        if ($method === 'consume') {
            $payment = (int)$this->childText($root, 'payment');
            $balance = max(0, (int)$this->db->getKv('eacoin', $sess, self::PASELI_BALANCE) - $payment);
            $this->db->setKv('eacoin', $sess, $balance);
            return $this->wrap('eacoin', $this->kitem('acstatus','u8',0) . $this->kitem('autocharge','u8',0) . $this->kitem('balance','s32',$balance));
        }
        if ($method === 'getbalance') {
            return $this->wrap('eacoin', $this->kitem('acstatus','u8',0) . $this->kitem('balance','s32',(int)$this->db->getKv('eacoin', $sess, self::PASELI_BALANCE)));
        }
        if ($method === 'checkout') {
            $this->db->deleteKv('eacoin', $sess);
            return $this->wrap('eacoin', '');
        }
        if (in_array($method, ['getlog','getoplog','getcampaign'], true)) {
            return $this->wrap('eacoin', '<topic><sumdate __type="str">0</sumdate></topic>');
        }
        return $this->wrap('eacoin', '');
    }

    private function vfglog(string $method, ?SimpleXMLElement $root): string
    {
        if ($method === 'put_msg' && $root) {
            $nodes = $root->xpath('//msg') ?: [];
            foreach ($nodes as $msg) {
                $label = trim((string)($msg['label'] ?? '?'));
                if ($label === '') $label = '?';
                $value = trim((string)$msg);
                if (strlen($value) > 500) $value = substr($value, 0, 500);
                if ($label === 'network_error') {
                    error_log('[MFG][client][ERROR] network_error: ' . $value);
                } else {
                    error_log('[MFG][client] ' . $label . ': ' . $value);
                }
            }
        }
        return $this->wrap('vfglog', '');
    }

    private function findModuleNode(?SimpleXMLElement $root, string $name): ?SimpleXMLElement
    {
        if (!$root) return null;
        if ($root->getName() === $name) return $root;
        $nodes = $root->xpath('//' . $name);
        return $nodes && isset($nodes[0]) ? $nodes[0] : null;
    }

    private function childText(?SimpleXMLElement $root, string $name): string
    {
        if (!$root) return '';
        $nodes = $root->xpath('//' . $name);
        return $nodes && isset($nodes[0]) ? trim((string)$nodes[0]) : '';
    }

    private function cardXml(string $node, string $attrs): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><response><' . $node . $attrs . ' /></response>';
    }

    private function kitem(string $tag, string $type, string|int $value = ''): string
    {
        if ($value === '') return '<' . $tag . ' __type="' . $type . '" />';
        return '<' . $tag . ' __type="' . $type . '">' . $this->x((string)$value) . '</' . $tag . '>';
    }

    private function wrap(string $module, string $inner, string $status = '0'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><response><' . $module . ' status="' . $status . '">' . $inner . '</' . $module . '></response>';
    }

    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
