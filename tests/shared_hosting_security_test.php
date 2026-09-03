<?php

declare(strict_types=1);

function sh_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$root=dirname(__DIR__);
$ht=(string)file_get_contents($root.'/.htaccess');
$publicHt=(string)file_get_contents($root.'/public/.htaccess');
$gi=(string)file_get_contents($root.'/.gitignore');

// Fallback deployment where the whole project is the document root: protect
// source/runtime state first, then route application URLs to root index.php.
sh_ok(str_contains($ht,'(?:src|data|tests|\\.github)'),'private directory deny rule missing');
sh_ok(str_contains($ht,'[F,L,NC]'),'deny rule must stop with HTTP forbidden');
foreach(['config(?:\\.example|\\.local)?\\.php','\\.env','\\.gitignore','README'] as $needle){
    sh_ok(str_contains($ht,$needle),'sensitive file deny missing: '.$needle);
}
sh_ok(str_contains($ht,'RewriteCond %{REQUEST_FILENAME} !-f'),'root front-controller file guard missing');
sh_ok(str_contains($ht,'RewriteCond %{REQUEST_FILENAME} !-d'),'root front-controller directory guard missing');
sh_ok(str_contains($ht,'RewriteRule ^ index.php [QSA,L]'),'root front-controller rewrite missing');

// Recommended deployment points DocumentRoot directly at public/. It needs its
// own rewrite because the project-root .htaccess is not a reliable front
// controller once public/ is the server's document root.
sh_ok(str_contains($publicHt,'RewriteEngine On'),'public rewrite engine missing');
sh_ok(str_contains($publicHt,'RewriteCond %{REQUEST_FILENAME} !-f'),'public front-controller file guard missing');
sh_ok(str_contains($publicHt,'RewriteCond %{REQUEST_FILENAME} !-d'),'public front-controller directory guard missing');
sh_ok(str_contains($publicHt,'RewriteRule ^ index.php [QSA,L]'),'public front-controller rewrite missing');

foreach(['/data/*.sqlite','/data/*.sqlite-*','/config.local.php','.env'] as $needle){
    sh_ok(str_contains($gi,$needle),'gitignore missing: '.$needle);
}

echo "shared-hosting root/public front-controller security OK\n";
