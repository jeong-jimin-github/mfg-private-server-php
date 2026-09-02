<?php

declare(strict_types=1);

namespace Mfg;

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
        $xml = match ($name) {
            'keep_alive' => $this->xmlResponse('<keep_alive><server_time>' . time() . '</server_time></keep_alive>'),
            'appli_boot' => $this->xmlResponse('<server_setting><mask_ac_link_scene>0</mask_ac_link_scene></server_setting>'),
            'logout' => $this->logout($form),
            'login' => $this->login($form),
            'create_player' => $this->createPlayer($form),
            'client_state_read' => $this->clientStateRead($form),
            'client_state_write' => $this->clientStateWrite($form),
            default => $this->xmlResponse(),
        };
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        echo $xml;
    }

    private function login(array $form): string
    {
        $refid = trim((string)($form['refid'] ?? $form['dataid'] ?? $form['card_id'] ?? ''));
        if ($refid === '') $refid = 'GUEST';
        $profile = $this->db->ensureProfile($refid, $refid === 'GUEST' ? 'GUEST' : 'PLAYER');
        $session = bin2hex(random_bytes(16));
        $this->db->saveSession($session, $refid);
        return $this->xmlResponse('<login><result>0</result><pcuid>' . $this->x($session) . '</pcuid><mid>' . (int)$profile['player_id'] . '</mid><name>' . $this->x((string)$profile['name']) . '</name></login>');
    }

    private function logout(array $form): string
    {
        $session = (string)($form['pcuid'] ?? '');
        if ($session !== '') $this->db->deleteSession($session);
        return $this->xmlResponse('<logout><result>0</result></logout>');
    }

    private function createPlayer(array $form): string
    {
        $session = (string)($form['pcuid'] ?? '');
        $s = $this->db->getSession($session);
        $refid = $s['refid'] ?? 'GUEST';
        $name = trim((string)($form['name'] ?? 'PLAYER')) ?: 'PLAYER';
        $profile = $this->db->ensureProfile($refid, $name);
        $payload = $profile['payload'];
        $payload['created'] = true;
        $this->db->saveProfilePayload($refid, $payload);
        return $this->xmlResponse('<create_player><result>0</result><mid>' . (int)$profile['player_id'] . '</mid></create_player>');
    }

    private function clientStateRead(array $form): string
    {
        $mid = (int)($form['mid'] ?? 0);
        $kind = (string)($form['kind'] ?? $form['state_kind'] ?? '');
        $profile = $this->db->getProfileByPlayerId($mid);
        $raw = $profile['payload']['states'][$kind] ?? '';
        if (!is_string($raw)) $raw = json_encode($raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '';
        $b64 = $raw === '' ? '' : base64_encode($raw);
        return $this->xmlResponse('<client_state><kind>' . $this->x($kind) . '</kind><data>' . $this->x($b64) . '</data></client_state>');
    }

    private function clientStateWrite(array $form): string
    {
        $mid = (int)($form['mid'] ?? 0);
        $kind = (string)($form['kind'] ?? $form['state_kind'] ?? '');
        $data = (string)($form['data'] ?? '');
        $profile = $this->db->getProfileByPlayerId($mid);
        if ($profile) {
            $decoded = base64_decode($data, true);
            $payload = $profile['payload'];
            $payload['states'][$kind] = $decoded === false ? $data : $decoded;
            $this->db->saveProfilePayload((string)$profile['refid'], $payload);
        }
        return $this->xmlResponse('<client_state><result>0</result></client_state>');
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

        $dispatcher = new EamuseDispatcher($this->db, $this->baseUrl());
        $response = $dispatcher->dispatch($model, $module, $method, $root === false ? null : $root);
        $this->sendBinary(EamuseProtocol::encodeTransport($response, $info, $compress), $info, $compress);
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

    private function x(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
}
