<?php

declare(strict_types=1);

namespace Mfg;

use Mfg\Aog\Dispatcher as AogDispatcher;
use Mfg\Aog\FeatureDispatcher;
use Mfg\Aog\MiscDispatcher;
use Mfg\Debug\CaptureStore;
use Mfg\Eamuse\Dispatcher as EamuseDispatcher;
use Mfg\Protocol\EamuseProtocol;
use Mfg\Protocol\KBinXml;
use Mfg\Storage\Database;

final class App
{
    public function __construct(private Database $db) {}

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = file_get_contents('php://input') ?: '';

        if ($path === '/healthz') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'service'=>'mfg-private-server-php','time'=>time()], JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($method === 'GET' && in_array($path, ['/', '/health', '/status'], true)) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            $base = $this->baseUrl();
            echo "VFG local server ok\n";
            echo 'e-amuse: ' . $base . "\n";
            echo 'game:    ' . rtrim($base, '/') . "/aog\n";
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
        $form = is_array($form) ? $form : [];
        $name = trim(substr($path, strlen('/aog')), '/');
        if ($name === '') $name = trim((string)($_GET['f'] ?? ''), '/');
        $captureName='aog_'.($name!==''?$name:'root');
        $pairs=[];
        foreach($form as $k=>$v){
            $pairs[]=(string)$k.'='.(is_scalar($v)||(is_object($v)&&method_exists($v,'__toString'))?(string)$v:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        $this->captureText('requests',$captureName,'PATH '.$path."\n".implode('&',$pairs));

        $feature = (new FeatureDispatcher($this->db))->dispatch($name, $form);
        $xml = $feature ?? (new AogDispatcher($this->db))->dispatch($name, $form);
        if (in_array($name, ['gget','gpost'], true) && !str_contains($xml, '<chat')) {
            $xml = $this->appendMatchChat($xml, $name, $form);
        }
        $this->captureText('responses',$captureName,$xml);
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        echo $xml;
    }

    /** @param array<string,mixed> $form */
    private function appendMatchChat(string $xml, string $name, array $form): string
    {
        $raw = (string)($form['must'] ?? '');
        $parts = $raw === '' ? [] : (preg_split('#[/,]#', $raw) ?: []);
        $tid = max(1, (int)($parts[2] ?? $form['tid'] ?? 1));
        $since = $name === 'gget' ? (int)($parts[6] ?? 0) : 1000000000;
        $chat = (new MiscDispatcher($this->db))->stampXml('chat', $tid, $since);
        $at = strrpos($xml, '</root>');
        if ($at === false) return $xml;
        return substr($xml, 0, $at) . $chat . substr($xml, $at);
    }

    private function handleEamuse(string $wireBody): void
    {
        $info = EamuseProtocol::parseEamuseInfo($_SERVER['HTTP_X_EAMUSE_INFO'] ?? null);
        $compress = $_SERVER['HTTP_X_COMPRESS'] ?? 'none';
        $decoded = EamuseProtocol::decodeTransport($wireBody, $info, $compress);
        $decodedTransport=$decoded;

        $usedKbin = false;
        $kbinEncoding = 'UTF-8';
        $kbinCompressed = true;
        if (KBinXml::isBinary($decoded)) {
            try {
                $meta = KBinXml::decode($decoded);
                $decoded = $meta['xml'];
                $usedKbin = true;
                $kbinEncoding = $meta['encoding'];
                $kbinCompressed = $meta['compressed'];
            } catch (\Throwable $e) {
                error_log('[MFG] kbin decode failed: ' . $e->getMessage());
            }
        }

        if (!str_starts_with(ltrim($decoded), '<')) {
            $this->captureBinary('transport','eamuse_decode_failed',$decodedTransport);
            $this->captureJson('transport','eamuse_decode_failed',[
                'x_eamuse_info'=>$info,'x_compress'=>$compress,'used_kbin'=>$usedKbin,
                'kbin_encoding'=>$kbinEncoding,'kbin_compressed'=>$kbinCompressed,
                'wire_bytes'=>strlen($wireBody),'decoded_bytes'=>strlen($decodedTransport),
            ]);
            $fallback = $this->eamuseWrap('eamuse', '');
            $this->captureText('requests','unknown',$decoded,'xml');
            $this->captureText('responses','unknown',$fallback,'xml');
            if ($usedKbin) {
                try { $fallback = KBinXml::encode($fallback, $kbinEncoding, $kbinCompressed); } catch (\Throwable) {}
            }
            $this->sendBinary(EamuseProtocol::encodeTransport($fallback, $info, $compress), $info, $compress);
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

        $reqName=trim($module.'_'.$method,'_');if($reqName==='')$reqName='unknown';
        $this->captureText('requests',$reqName,$decoded);
        $this->captureBinary('transport',$reqName,$decodedTransport);
        $this->captureJson('transport',$reqName,[
            'x_eamuse_info'=>$info,'x_compress'=>$compress,'used_kbin'=>$usedKbin,
            'kbin_encoding'=>$kbinEncoding,'kbin_compressed'=>$kbinCompressed,
            'wire_bytes'=>strlen($wireBody),'decoded_bytes'=>strlen($decodedTransport),
            'model'=>$model,'module'=>$module,'method'=>$method,
        ]);

        $responseXml = (new EamuseDispatcher($this->db, $this->baseUrl()))
            ->dispatch($model, $module, $method, $root === false ? null : $root);
        $this->captureText('responses',$reqName,$responseXml);
        $response=$responseXml;

        if ($usedKbin) {
            try {
                $response = KBinXml::encode($response, $kbinEncoding, $kbinCompressed);
            } catch (\Throwable $e) {
                error_log('[MFG] kbin encode failed, returning XML: ' . $e->getMessage());
            }
        }
        $this->sendBinary(EamuseProtocol::encodeTransport($response, $info, $compress), $info, $compress);
    }

    private function captureText(string $kind,string $name,string $body,string $ext='xml'): void
    {
        try{(new CaptureStore())->saveText($kind,$name,$body,$ext);}catch(\Throwable $e){error_log('[MFG] capture write failed: '.$e->getMessage());}
    }

    private function captureBinary(string $kind,string $name,string $body): void
    {
        try{(new CaptureStore())->saveBinary($kind,$name,$body);}catch(\Throwable $e){error_log('[MFG] capture write failed: '.$e->getMessage());}
    }

    /** @param array<string,mixed> $meta */
    private function captureJson(string $kind,string $name,array $meta): void
    {
        try{(new CaptureStore())->saveJson($kind,$name,$meta);}catch(\Throwable $e){error_log('[MFG] capture write failed: '.$e->getMessage());}
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
