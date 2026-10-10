<?php
declare(strict_types=1);

define('JOBREADY_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $prefix = 'JobReady\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = JOBREADY_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
