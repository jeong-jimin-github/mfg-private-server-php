<?php

declare(strict_types=1);

namespace Mfg\Aog;

/** Mirrors the Python server's log-only AOG endpoints. */
final class TelemetryLog
{
    /** @param array<string,mixed> $form */
    public static function write(array $form,string $label): void
    {
        $raw=(string)($form['log']??'');
        if($raw==='')return;

        // The Python reference applies unquote_plus() to the parsed form value
        // before base64 decoding. Keep the same permissive behavior here.
        $encoded=urldecode($raw);
        $decoded=base64_decode($encoded,true);
        if($decoded===false)return;

        // Python's .decode('utf-8') drops the log on invalid UTF-8.
        if(preg_match('//u',$decoded)!==1)return;
        error_log('[MFG]['.$label.'] '.$decoded);
    }
}
