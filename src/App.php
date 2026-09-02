<?php

declare(strict_types=1);

namespace Mfg;

use Mfg\Aog\Dispatcher as AogDispatcher;
use Mfg\Eamuse\Dispatcher as EamuseDispatcher;
use Mfg\Protocol\EamuseProtocol;
use Mfg\Storage\Database;

final class App
{
    public function __construct(private Database $db) {}

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $body = file_get_contents('php://input') ?: '';

        if ($path === '/healthz') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'service'=>'mfg-private-server-php','time'=>time()], JSON_UNESCAPED_SLASHES);
            return;
        }
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
        if ($name === '') $name = trim((string)($_GET['f'] ?? ''), '/');
        $xml = (new AogDispatcher($this->db))->dispatch($name, is_array($form) ? $form : []);
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        echo $xml;
    }

    private function handleEamuse(string $wireBody): void
    {
        $info = EamuseProtocol::parseEamuseInfo($_SERVER['HTTP_X_EAMUSE_INFO'] ?? null);
        $compress = $_SERVER['HTTP_X_COMPRESS'] ?? 'none';
        $decoded = EamuseProtocol::decodeTransport($wireBody, $info, $compress);

        if (!str_starts_with(ltrim($decoded), '<')) {
            $this->sendBinary(EamuseProtocol::encodeTransport($this->eamuseWrap('eamuse', ''), $info, $compress), $info, $compress);
            return;
        }

        libxml_use_internal_errors(true);
        $root = simplexml_load_string($decoded);
        $module = '';
        $method = '';
        $model = '';
        if ($root !== false) {
            $model = (string)($root['model'] ?? '');
            foreach ($root->children() as $child) {
                $module = $child->getName();
                $method = (string)($child['method'] ?? '');
                break;
            }
        }
        if ($module === '') $module = trim((string)($_GET['module'] ?? ''));
        if ($method === '') $method = trim((string)($_GET['method'] ?? $_GET['f'] ?? ''));
        if (str_contains($method, '.')) {
            [$qModule, $qMethod] = array_pad(explode('.', $method, 2), 2, '');
            if ($module === '') $module = $qModule;
            $method = $qMethod;
        }

        $response = (new EamuseDispatcher($this->db, $this->baseUrl()))
            ->dispatch($model, $module, $method, $root === false ? null : $root);
        $this->sendBinary(EamuseProtocol::encodeTransport($response, $info, $compress), $info, $compress);
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
        if ($info !== null) header('X-Eamuse-Info: ' . $info);
        header('Content-Length: ' . strlen($payload));
        echo $payload;
    }

    private function baseUrl(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    }
}
