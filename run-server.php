<?php

include __DIR__ . "/vendor/autoload.php";

$server = new \Monyxie\FtpDemo\Server\Server(null, json_decode(file_get_contents(__DIR__ . '/server-options.json'), true));
$server->run($argv[1] ?: null);
