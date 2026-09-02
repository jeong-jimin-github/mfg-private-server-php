<?php

declare(strict_types=1);

namespace Mfg;

use Mfg\Protocol\EamuseProtocol;
use Mfg\Storage\Database;

final class App
{
    public function __construct(private Database $db)
    {
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $body = file_get_contents('php://input') ?: '';

        if (str_starts_with($path, '/aog')) {
            $this->handleAog($path, $body);
            return;
        }

        $this->handleEamuse($body);
    }

    private function handleAog(string $path, string $body): void
    {
        parse_str($body, $form);
        $name = trim(substr($path, strlen('/aog')), '/');
        if ($name === '') {
            $name = trim((string)($_GET['f'] ?? ''), '/');
        }

        $xml = match ($name) {
            'keep_alive' => $this->xmlResponse('<keep_alive><server_time>' . time() . '</server_time></keep_alive>'),
            'appli_boot' => $this->xmlResponse('<appli_boot><result>0</result></appli_boot>'),
            'logout' => $this->xmlResponse('<logout><result>0</result></logout>'),
            default => $this->xmlResponse(),
        };

        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        echo $xml;
    }

    private function handleEamuse(string $wireBody): void
    {
        $info = EamuseProtocol::parseEamuseInfo($_SERVER['HTTP_X_EAMUSE_INFO'] ?? null);
        $compress = $_SERVER['HTTP_X_COMPRESS'] ?? 'none';
        $decoded = EamuseProtocol::decodeTransport($wireBody, $info, $compress);

        // Plain XML path is functional now. kbin decoding is added in the next codec layer.
        if (!str_starts_with(ltrim($decoded), '<')) {
            $this->sendBinary(EamuseProtocol::encodeTransport($this->eamuseWrap('eamuse', ''), $info, $compress), $info, $compress);
            return;
        }

        $module = '';
        $method = '';
        $model = '';
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($decoded);
        if ($xml !== false) {
            $model = (string)($xml['model'] ?? '');
            $children = $xml->children();
            foreach ($children as $child) {
                $module = $child->getName();
                $method = (string)($child['method'] ?? '');
                break;
            }
        }

        $response = $this->dispatchEamuse($model, $module, $method);
        $this->sendBinary(EamuseProtocol::encodeTransport($response, $info, $compress), $info, $compress);
    }

    private function dispatchEamuse(string $model, string $module, string $method): string
    {
        if ($module === 'services' && $method === 'get') {
            $base = $this->baseUrl();
            $names = ['cardmng','vfgcard','eacoin','facility','local','local2','message','netlog','package','pcbevent','pcbtracker','pkglist','posevent','sidmgr','userdata','userid','eventlog'];
            $items = '';
            foreach ($names as $name) {
                $items .= '<item name="' . htmlspecialchars($name, ENT_XML1) . '" url="' . htmlspecialchars($base, ENT_XML1) . '"/>';
            }
            return '<?xml version="1.0" encoding="UTF-8"?><response><services expire="10800" method="get" mode="operation" status="0">' . $items . '</services></response>';
        }
        if ($module === 'pcbtracker') {
            return '<?xml version="1.0" encoding="UTF-8"?><response><pcbtracker expire="1200" status="0" ecenable="1" eclimit="0" limit="0" time="' . time() . '" /></response>';
        }
        if ($module === 'message') {
            return '<?xml version="1.0" encoding="UTF-8"?><response><message expire="300" status="0" /></response>';
        }
        if ($module === 'facility') {
            return '<?xml version="1.0" encoding="UTF-8"?><response><facility status="0"><location><id __type="str">VFG00001</id><country __type="str">JP</country><region __type="str">13</region><name __type="str">LOCAL TEST</name></location></facility></response>';
        }
        return $this->eamuseWrap($module !== '' ? $module : 'eamuse', '');
    }

    private function xmlResponse(string $inner = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><response>' . $inner . '</response>';
    }

    private function eamuseWrap(string $module, string $inner, string $status = '0'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><response><' . $module . ' status="' . $status . '">' . $inner . '</' . $module . '></response>';
    }

    private function sendBinary(string $payload, ?string $info, ?string $compress): void
    {
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('X-Compress: ' . (in_array($compress, ['lz77','none'], true) ? $compress : 'none'));
        if ($info !== null) {
            header('X-Eamuse-Info: ' . $info);
        }
        header('Content-Length: ' . strlen($payload));
        echo $payload;
    }

    private function baseUrl(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        return $scheme . '://' . $host;
    }
}
