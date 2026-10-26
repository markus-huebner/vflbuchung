<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if ($config->isSetupRequired()) {
    (new App\DatabaseConnectionWizard($config, null, true))->run();
    exit;
}

if ($pdo === null) {
    (new App\DatabaseConnectionWizard($config, $databaseConnectionError))->run();
    exit;
}

(new App\Application($config, $pdo))->run();
