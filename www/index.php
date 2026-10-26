<?php
declare(strict_types=1);

$applicationRoot = is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ : dirname(__DIR__);
require $applicationRoot . '/app/bootstrap.php';

if ($pdo === null) {
    (new App\DatabaseConnectionWizard($config, $databaseConnectionError))->run();
    exit;
}

(new App\Application($config, $pdo))->run();
