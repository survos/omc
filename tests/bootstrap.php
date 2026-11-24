<?php

declare(strict_types=1);

$autoload = is_file(dirname(__DIR__).'/vendor/autoload.php')
    ? dirname(__DIR__).'/vendor/autoload.php'
    : dirname(__DIR__, 3).'/vendor/autoload.php';
require $autoload;
spl_autoload_register(static function (string $class): void {
    $prefix = 'Survos\\Omc\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
