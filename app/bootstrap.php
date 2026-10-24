<?php
declare(strict_types=1);

use App\Config;
use App\Database;
use App\Security;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Berlin');

/** @var Config $config */
$config = Config::fromEnvironment();
ini_set('display_errors', $config->isLocal() ? '1' : '0');
ini_set('log_errors', '1');
Security::startSession();

$pdo = Database::connect($config);
