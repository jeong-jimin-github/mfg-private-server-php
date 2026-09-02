<?php

declare(strict_types=1);

function sh_ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$root=dirname(__DIR__);
$ht=(string)file_get_contents($root.'/.htaccess');
$gi=(string)file_get_contents($root.'/.gitignore');

sh_ok(str_contains($ht,'(?:src|data|tests|\\.github)'),'private directory deny rule missing');
sh_ok(str_contains($ht,'[F,L,NC]'),'deny rule must stop with HTTP forbidden');
foreach(['config(?:\\.example|\\.local)?\\.php','\\.env','\\.gitignore','README'] as $needle){
    sh_ok(str_contains($ht,$needle),'sensitive file deny missing: '.$needle);
}
sh_ok(str_contains($ht,'RewriteCond %{REQUEST_FILENAME} !-f'),'front-controller file guard missing');
sh_ok(str_contains($ht,'RewriteCond %{REQUEST_FILENAME} !-d'),'front-controller directory guard missing');
sh_ok(str_contains($ht,'RewriteRule ^ index.php [QSA,L]'),'front-controller rewrite missing');

foreach(['/data/*.sqlite','/data/*.sqlite-*','/config.local.php','.env'] as $needle){
    sh_ok(str_contains($gi,$needle),'gitignore missing: '.$needle);
}

echo "shared-hosting security config OK\n";
