<?php
declare(strict_types=1);

use App\Application;
use App\AuthService;
use App\BookingService;
use App\Config;
use App\Database;
use App\DatabaseSetup;
use App\Mailer;
use App\Security;
use App\UserService;

require dirname(__DIR__) . '/www/vendor/autoload.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = Config::fromEnvironment()->withOverrides([
    'APP_URL' => 'https://email-links.example.test',
    'MAIL_FROM' => 'mailer@example.test',
    'MAIL_FROM_NAME' => 'Email Link Test',
]);
expect(preg_match('/\Avflbuchung_email_links_[a-f0-9]{32}\z/', $config->get('DB_NAME')) === 1, 'Run email link tests only in an isolated email link test database.');
$mode = $argv[1] ?? '';
$directory = (string) getenv('VFL_MAIL_TEST_DIRECTORY');

if (PHP_SAPI === 'cli-server') {
    Security::startSession();
    (new Application($config, Database::connect($config)))->run();
    return;
}

function runChild(string $mode, array $environment = []): string
{
    global $directory;
    $capture = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/mail-setup.php') . ' --capture';
    $process = proc_open([
        PHP_BINARY, '-d', 'sendmail_path=' . $capture, '-d', 'session.save_path=' . $directory,
        __FILE__, $mode,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(
        getenv(),
        ['VFL_MAIL_TEST_DIRECTORY' => $directory, 'VFL_MAIL_TEST_FAIL' => '0'],
        $environment
    ));
    expect(is_resource($process), 'Could not start the email link test.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process) === 0, 'Email link scenario failed: '
        . preg_replace('/\b[a-f0-9]{64}\b/', '<REDACTED>', $errors . $output));
    return (string) $output;
}

if ($mode === '--registration-route') {
    $input = json_decode((string) getenv('REGISTRATION_ROUTE'), true, 512, JSON_THROW_ON_ERROR);
    if ($input['disable_mail'] ?? false) {
        $config = $config->withOverrides(['MAIL_FROM' => '']);
    }
    if (!empty($input['session_id'])) {
        $_COOKIE['verein_session'] = $input['session_id'];
    }
    Security::startSession();
    if ($input['user_id'] !== null) {
        $_SESSION['user_id'] = $input['user_id'];
    }
    $_GET = $input['method'] === 'POST' ? ['action' => $input['target']] : ['page' => $input['target']];
    $_SERVER['REQUEST_METHOD'] = $input['method'];
    $_POST = array_merge($input['data'], ['_csrf' => Security::csrfToken()]);
    ob_start();
    register_shutdown_function(static function () use ($input): void {
        $html = (string) ob_get_clean();
        echo json_encode([
            'status' => http_response_code(),
            'html' => $html,
            'flash' => $_SESSION['_flash'] ?? null,
            'session_id' => session_id(),
        ], JSON_THROW_ON_ERROR);
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($input['keep_session'] ?? false) {
                session_write_close();
            } else {
                session_destroy();
            }
        }
    });
    (new Application($config, Database::connect($config)))->run();
    exit;
}

if ($mode === '--route') {
    $input = json_decode((string) getenv('EMAIL_LINK_ROUTE'), true, 512, JSON_THROW_ON_ERROR);
    Security::startSession();
    if ($input['current_user'] !== null) {
        $_SESSION['user_id'] = $input['current_user'];
    }
    $_SESSION['wrong_account_state'] = 'must not survive account switching';
    $oldSession = session_id();
    $oldCsrf = Security::csrfToken();
    $_GET = ['page' => $input['page'], 'token' => $input['token']];
    $_SERVER['REQUEST_METHOD'] = $input['action'] === null ? 'GET' : 'POST';
    if ($input['action'] !== null) {
        $_GET = ['action' => $input['action']];
        $_POST = ['_csrf' => $oldCsrf, 'token' => $input['token'], 'password' => $input['password']];
        if ($input['confirmation'] !== null) {
            $_POST['password_confirmation'] = $input['confirmation'];
        }
    }
    $auth = new AuthService(Database::connect($config));
    ob_start();
    register_shutdown_function(static function () use ($auth, $oldSession, $oldCsrf): void {
        $html = (string) ob_get_clean();
        $user = $auth->currentUser();
        $hasForm = preg_match('/name="_csrf" value="([^"]+)"/', $html, $csrf) === 1;
        echo json_encode([
            'user_id' => $user === null ? null : (int) $user['id'],
            'role' => $user['role'] ?? null,
            'old_state' => isset($_SESSION['wrong_account_state']),
            'session_changed' => session_id() !== $oldSession,
            'csrf_changed' => ($_SESSION['csrf_token'] ?? '') !== $oldCsrf,
            'valid_form_csrf' => $hasForm && hash_equals(Security::csrfToken(), $csrf[1]),
            'confirmation_field' => str_contains($html, 'name="password_confirmation"'),
            'error' => ($_SESSION['_flash']['type'] ?? '') === 'danger',
        ], JSON_THROW_ON_ERROR);
        session_destroy();
    });
    (new Application($config, Database::connect($config)))->run();
    exit;
}

if ($mode !== '--exercise') {
    expect(PHP_OS_FAMILY !== 'Windows', 'Run email link tests in the Linux webcontainer.');
    $directory = sys_get_temp_dir() . '/vflbuchung-email-links-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700), 'Could not create the isolated email capture directory.');
    try {
        echo runChild('--exercise');
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
    exit;
}

$pdo = Database::connect($config);
(new DatabaseSetup($pdo))->initialize(false);
$pdo->exec('DROP TABLE registration_requests');
$pdo->exec('DROP TABLE registration_access_tokens');
$setup = new DatabaseSetup($pdo);
$setup->migrate();
expect($setup->status()['schema_complete'], 'Migration must create the registration table for existing installations.');
$setup->migrate();
expect($setup->status()['schema_complete'], 'Registration migration must be repeatable.');
$auth = new AuthService($pdo);
$mailer = new Mailer($config);
$users = new UserService($pdo, $mailer, $config);
$bookings = new BookingService($pdo, $mailer, $config);
$actors = [];
foreach (['admin', 'manager', 'trainer'] as $role) {
    $pdo->prepare(
        "INSERT INTO users (username, email, first_name, last_name, password_hash, role, status)
         VALUES (:username, :email, :first_name, 'Nachname', :password_hash, :role, 'active')"
    )->execute([
        'username' => 'email-link-' . $role,
        'email' => $role . '@example.test',
        'first_name' => ucfirst($role),
        'password_hash' => password_hash('OriginalPassword123!', PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    $actors[$role] = ['id' => (int) $pdo->lastInsertId(), 'role' => $role];
}

$pdo->exec("INSERT INTO users (username, email, first_name, last_name, role, status)
    VALUES ('delete-second-admin', 'delete-second@example.test', 'Zweiter', 'Admin', 'admin', 'active')");
$secondAdminId = (int) $pdo->lastInsertId();
$userRoute = static function (string $method, string $target, int $userId, array $data = []): array {
    return json_decode(runChild('--registration-route', ['REGISTRATION_ROUTE' => json_encode([
        'method' => $method, 'target' => $target, 'user_id' => $userId, 'data' => $data,
    ], JSON_THROW_ON_ERROR)]), true, 512, JSON_THROW_ON_ERROR);
};
$userPage = $userRoute('GET', 'users', $actors['admin']['id']);
$userDocument = new DOMDocument();
expect($userDocument->loadHTML('<?xml encoding="UTF-8">' . $userPage['html'], LIBXML_NOERROR | LIBXML_NOWARNING), 'The user page must render valid HTML.');
$userXPath = new DOMXPath($userDocument);
expect($userXPath->query('//button[@data-delete-user and @data-user-id="' . $actors['admin']['id'] . '"]')->length === 0,
    'The signed-in user must not have a delete button.');
expect($userXPath->query('//button[@data-delete-user and @data-user-id="' . $secondAdminId . '"]')->length === 1,
    'The signed-in administrator must still be able to delete another user.');
expect($userXPath->query('//button[@data-edit-user and @data-user-id="' . $actors['admin']['id'] . '" and @data-user-self="true"]')->length === 1
    && $userXPath->query('//button[@data-edit-user and @data-user-id="' . $secondAdminId . '" and @data-user-self="false"]')->length === 1,
    'User editing must distinguish the signed-in account from other accounts.');
$selfRoleUpdate = $userRoute('POST', 'user-update', $actors['admin']['id'], [
    'id' => $actors['admin']['id'], 'email' => 'admin@example.test', 'first_name' => 'Changed',
    'last_name' => 'Nachname', 'role' => 'manager', 'status' => 'active',
]);
$selfRoleAction = $userRoute('POST', 'user-role', $actors['admin']['id'], ['id' => $actors['admin']['id'], 'role' => 'trainer']);
foreach ([$selfRoleUpdate, $selfRoleAction] as $attempt) {
    expect(($attempt['flash']['type'] ?? '') === 'danger'
        && str_contains($attempt['flash']['message'], 'eigene Administratorrolle nicht ändern'),
        'Each self-demotion route must reject the signed-in administrator even when another admin exists.');
}
expect($pdo->query("SELECT role FROM users WHERE username = 'email-link-admin'")->fetchColumn() === 'admin',
    'Rejected self-demotion must leave the administrator role unchanged.');
$selfUpdate = $userRoute('POST', 'user-update', $actors['admin']['id'], [
    'id' => $actors['admin']['id'], 'email' => 'admin@example.test', 'first_name' => 'Changed',
    'last_name' => 'Nachname', 'role' => 'admin', 'status' => 'inactive',
]);
$selfDeactivate = $userRoute('POST', 'user-deactivate', $actors['admin']['id'], ['id' => $actors['admin']['id']]);
foreach ([$selfUpdate, $selfDeactivate] as $attempt) {
    expect(($attempt['flash']['type'] ?? '') === 'danger'
        && str_contains($attempt['flash']['message'], 'eigenes Benutzerkonto nicht deaktivieren'),
        'Each self-deactivation route must reject the signed-in user.');
}
$adminState = $pdo->query("SELECT status, first_name FROM users WHERE username = 'email-link-admin'")->fetch();
expect($adminState['status'] === 'active' && $adminState['first_name'] === 'Admin',
    'Rejected self-deactivation must not change any account details.');
$ownEdit = $userRoute('POST', 'user-update', $actors['admin']['id'], [
    'id' => $actors['admin']['id'], 'email' => 'admin@example.test', 'first_name' => 'Admin',
    'last_name' => 'Nachname', 'role' => 'admin', 'status' => 'active',
]);
expect(($ownEdit['flash']['type'] ?? '') === 'success', 'Other edits to the signed-in account must remain possible.');
$otherRoleUpdate = $userRoute('POST', 'user-role', $actors['admin']['id'], ['id' => $actors['trainer']['id'], 'role' => 'manager']);
expect(($otherRoleUpdate['flash']['type'] ?? '') === 'success'
    && $pdo->query("SELECT role FROM users WHERE username = 'email-link-trainer'")->fetchColumn() === 'manager',
    'An administrator must still be able to change another user role.');
$userRoute('POST', 'user-role', $actors['admin']['id'], ['id' => $actors['trainer']['id'], 'role' => 'trainer']);
$otherDeactivate = $userRoute('POST', 'user-deactivate', $actors['admin']['id'], ['id' => $actors['trainer']['id']]);
expect(($otherDeactivate['flash']['type'] ?? '') === 'success'
    && $pdo->query("SELECT status FROM users WHERE username = 'email-link-trainer'")->fetchColumn() === 'inactive',
    'An administrator must still be able to deactivate another account.');
$otherReactivate = $userRoute('POST', 'user-update', $actors['admin']['id'], [
    'id' => $actors['trainer']['id'], 'email' => 'trainer@example.test', 'first_name' => 'Trainer',
    'last_name' => 'Nachname', 'role' => 'trainer', 'status' => 'active',
]);
expect(($otherReactivate['flash']['type'] ?? '') === 'success', 'Other accounts must still be reactivatable.');
$otherUpdateDeactivate = $userRoute('POST', 'user-update', $actors['admin']['id'], [
    'id' => $actors['trainer']['id'], 'email' => 'trainer@example.test', 'first_name' => 'Trainer',
    'last_name' => 'Nachname', 'role' => 'trainer', 'status' => 'inactive',
]);
expect(($otherUpdateDeactivate['flash']['type'] ?? '') === 'success'
    && $pdo->query("SELECT status FROM users WHERE username = 'email-link-trainer'")->fetchColumn() === 'inactive',
    'Deactivation through user editing must remain available for other users.');
$userRoute('POST', 'user-update', $actors['admin']['id'], [
    'id' => $actors['trainer']['id'], 'email' => 'trainer@example.test', 'first_name' => 'Trainer',
    'last_name' => 'Nachname', 'role' => 'trainer', 'status' => 'active',
]);
$selfDelete = $userRoute('POST', 'user-delete', $actors['admin']['id'], ['id' => $actors['admin']['id']]);
expect(($selfDelete['flash']['type'] ?? '') === 'danger'
    && str_contains($selfDelete['flash']['message'], 'eigenes Benutzerkonto nicht löschen'),
    'A forged self-delete request must be rejected even when another administrator exists.');
expect((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'email-link-admin'")->fetchColumn() === 1,
    'A rejected self-delete must leave the account intact.');
$deleteOther = $userRoute('POST', 'user-delete', $actors['admin']['id'], ['id' => $secondAdminId]);
expect(($deleteOther['flash']['type'] ?? '') === 'success'
    && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'delete-second-admin'")->fetchColumn() === 0,
    'Deleting another administrator must remain possible.');
$lastAdminDeletion = $userRoute('POST', 'user-delete', $actors['manager']['id'], ['id' => $actors['admin']['id']]);
expect(($lastAdminDeletion['flash']['type'] ?? '') === 'danger'
    && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'email-link-admin'")->fetchColumn() === 1,
    'A non-administrator must not be able to delete an administrator.');
try {
    $users->delete($actors['admin']['id'], $secondAdminId);
    throw new LogicException('The last active administrator must not be deleted.');
} catch (RuntimeException $exception) {
    expect(str_contains($exception->getMessage(), 'letzte aktive Administrator'), 'The last administrator must remain protected.');
}
foreach ([
    static fn() => $users->deactivate($actors['admin']['id'], $secondAdminId),
    static fn() => $users->changeRole($actors['admin']['id'], 'trainer', $secondAdminId),
    static fn() => $users->updateDetails($actors['admin']['id'], 'admin@example.test', 'Admin', 'Nachname', 'manager', 'active', $secondAdminId),
] as $attempt) {
    try {
        $attempt();
        throw new LogicException('The last active administrator must not be deactivated or demoted.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'letzten aktiven Administrator') || str_contains($exception->getMessage(), 'letzte aktive Administrator'),
            'The last active administrator must be protected by every service operation.');
    }
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
expect(is_resource($socket), 'Could not reserve a port for registration HTTP tests.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $directory, '-S', $address, __FILE__],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/http-server.log', 'a'], 2 => ['file', $directory . '/http-server.log', 'a']],
    $pipes
);
expect(is_resource($server), 'Could not start the registration HTTP test server.');
fclose($pipes[0]);
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    expect($ready, 'The registration HTTP test server did not start.');
    $request = static function (string $path, string &$cookie, ?array $post = null) use ($address): array {
        $headers = ['Cookie: ' . $cookie];
        $options = ['method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0];
        if ($post !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($post);
        }
        $options['header'] = implode("\r\n", $headers);
        $body = file_get_contents('http://' . $address . $path, false, stream_context_create(['http' => $options]));
        expect($body !== false, 'The registration HTTP request failed.');
        $status = 0;
        $location = null;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $match)) {
                $status = (int) $match[1];
            } elseif (preg_match('/^Location:\s*(.*)$/i', $header, $match)) {
                $location = trim($match[1]);
            } elseif (preg_match('/^Set-Cookie:\s*(verein_session=[^;]+)/i', $header, $match)) {
                $cookie = $match[1];
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => $body];
    };
    $login = static function (string &$cookie, string $role, string $password) use ($request): array {
        $page = $request('/?page=login', $cookie);
        expect(preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $token) === 1, 'Login must provide a CSRF token.');
        return $request('/?action=login', $cookie, [
            '_csrf' => $token[1], 'identifier' => 'email-link-' . $role, 'password' => $password,
        ]);
    };
    $cookie = '';
    $response = $request('/?page=registrations&return=https://example.com', $cookie);
    expect($response['location'] === '/?page=login', 'Guests must be redirected to login.');
    $response = $login($cookie, 'manager', 'wrong-password');
    expect($response['location'] === '/?page=login', 'Failed login must return to login.');
    $response = $login($cookie, 'manager', 'OriginalPassword123!');
    expect($response['location'] === '/?page=registrations', 'Successful login must return to registrations, not an external URL.');
    $response = $request('/?page=registrations', $cookie);
    expect($response['status'] === 200, 'A manager must be able to access registrations after login.');
    $response = $login($cookie, 'manager', 'OriginalPassword123!');
    expect($response['location'] === '/', 'The registration destination must be consumed after login.');
    $cookie = '';
    $response = $login($cookie, 'admin', 'OriginalPassword123!');
    expect($response['location'] === '/', 'Normal login must return to the home page.');
    $cookie = '';
    $request('/?page=registrations', $cookie);
    $response = $login($cookie, 'trainer', 'OriginalPassword123!');
    expect($response['location'] === '/?page=registrations', 'The registration destination must survive login for any role.');
    $response = $request('/?page=registrations', $cookie);
    expect($response['status'] === 403, 'The registration destination must not bypass role checks.');
} finally {
    proc_terminate($server);
    proc_close($server);
}

$route = static function (string $page, string $token, ?int $currentUser, ?string $action = null, string $password = 'UpdatedPassword123!', ?string $confirmation = 'UpdatedPassword123!'): array {
    return json_decode(runChild('--route', ['EMAIL_LINK_ROUTE' => json_encode([
        'page' => $page, 'token' => $token, 'current_user' => $currentUser,
        'action' => $action, 'password' => $password, 'confirmation' => $confirmation,
    ], JSON_THROW_ON_ERROR)]), true, 512, JSON_THROW_ON_ERROR);
};
$assertLogin = static function (array $result, int $userId, string $role): void {
    expect($result['user_id'] === $userId && $result['role'] === $role, 'An email link must log in its own recipient with the database role.');
    expect(!$result['old_state'] && $result['session_changed'] && $result['csrf_changed'], 'Account switching must clear old state and rotate session and CSRF tokens.');
};
$capture = static function (callable $send): array {
    global $directory;
    $before = glob($directory . '/*.eml');
    $result = $send();
    if (is_array($result)) {
        expect(($result['mail_errors'] ?? []) === [], 'The notification could not be sent.');
    }
    $paths = array_values(array_diff(glob($directory . '/*.eml'), $before));
    expect($paths !== [], 'The operation did not send its expected emails.');
    return array_map(static function (string $path): string {
        $message = (string) file_get_contents($path);
        $decoded = quoted_printable_decode($message);
        expect(str_contains($decoded, 'Platz- und Busbelegung - VfL Kaufering')
            && !str_contains($decoded, 'Vereins-Buchungssystem'), 'Every notification must use the correct application name.');
        expect(str_contains($decoded, 'src="cid:vfl-fussball-logo"')
            && str_contains($decoded, 'alt="VfL Kaufering Fußball 1948"')
            && str_contains($decoded, 'width="285"')
            && str_contains($decoded, 'text-transform:uppercase">Platz- und Busbelegung</div>')
            && str_contains($decoded, 'Automatische Benachrichtigung von Platz- und Busbelegung - VfL Kaufering</td>')
            && str_contains($decoded, 'background:#141210')
            && str_contains($decoded, 'border-bottom:3px solid #e1141b'),
            'Every notification must use the football website logo and header.');
        expect(preg_match('/Content-Type:\s*image\/png[^\r\n]*\r?\n(.*?\r?\n)\r?\n(.*?)(?=\r?\n--[^\r\n]+)/is', $message, $image) === 1
            && str_contains($image[1], 'Content-ID: <vfl-fussball-logo>')
            && str_contains($image[1], 'Content-Disposition: inline')
            && base64_decode($image[2], true) === file_get_contents(dirname(__DIR__) . '/www/assets/images/vfl-fussball-logo-email.png'),
            'Every notification must embed the actual logo as an inline PNG.');
        return $message;
    }, $paths);
};
$link = static function (string $message, string $page): string {
    $parts = [];
    preg_match_all('/Content-Type:\s*text\/(plain|html)([^\r\n]*(?:\r?\n[^\r\n]+)*)\r?\n\r?\n(.*?)(?=\r?\n--[^\r\n]+)/is', $message, $matches, PREG_SET_ORDER);
    foreach ($matches as $part) {
        $parts[strtolower($part[1])] = stripos($part[2], 'Content-Transfer-Encoding: quoted-printable') !== false
            ? quoted_printable_decode($part[3])
            : (stripos($part[2], 'Content-Transfer-Encoding: base64') !== false
                ? base64_decode($part[3], true)
                : $part[3]);
    }
    expect(isset($parts['html'], $parts['plain']), 'The notification must include HTML and plain text.');
    expect(preg_match_all('/<a\b[^>]*href="([^"]+)"/', $parts['html'], $links) > 0, 'The notification has no HTML link.');
    $token = null;
    foreach ($links[1] as $href) {
        $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect(($query['page'] ?? '') === $page && preg_match('/\A[a-f0-9]{64}\z/', $query['token'] ?? '') === 1, 'Every notification link must contain its recipient-bound token.');
        expect(str_contains($parts['plain'], $url), 'The plain text email must use the same authenticated link.');
        $token = $query['token'];
    }
    return $token;
};
$assertExpiry = static function (string $table, string $column, string $token) use ($pdo): void {
    $statement = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), $column) FROM $table WHERE "
        . ($table === 'users' ? 'invite_token_hash' : 'token_hash') . ' = :hash');
    $statement->execute(['hash' => Security::tokenHash($token)]);
    $seconds = $statement->fetchColumn();
    expect($seconds !== false && (int) $seconds >= 5 * 86400 - 30 && (int) $seconds <= 5 * 86400,
        "$table link must expire five days after creation.");
};
$assertBookingMail = static function (string $message, int $userId, string $role, int $otherUser) use ($pdo, $link, $route, $assertLogin): string {
    $token = $link($message, 'booking-access');
    $statement = $pdo->prepare('SELECT user_id FROM booking_access_tokens WHERE token_hash = :hash');
    $statement->execute(['hash' => Security::tokenHash($token)]);
    expect((int) $statement->fetchColumn() === $userId, 'The mailed token belongs to the wrong account.');
    $assertLogin($route('booking-access', $token, $otherUser), $userId, $role);
    return $token;
};

$pitch = (int) $pdo->query("SELECT r.id FROM resources r JOIN resource_categories c ON c.id = r.category_id WHERE c.code = 'pitch' LIMIT 1")->fetchColumn();
$created = null;
$messages = $capture(static function () use ($bookings, $actors, $pitch, &$created): array {
    return $created = $bookings->create([
        'event_type' => 'training', 'resource_mode' => 'pitch', 'resource_ids' => [$pitch],
        'title' => 'Recipient switching test', 'starts_at' => '2038-01-10T10:00',
        'ends_at' => '2038-01-10T11:30', 'frequency' => 'none',
        'private_notes' => "Interne <Notiz>\nzweite Zeile",
    ], $actors['trainer']);
});
expect(count($messages) === 2, 'A trainer request must notify both the admin and manager.');
$approverTokens = [];
foreach ($messages as $message) {
    $role = str_contains($message, 'To: admin@example.test') ? 'admin' : 'manager';
    $approverTokens[$role] = $assertBookingMail($message, $actors[$role]['id'], $role, $actors['trainer']['id']);
    $decoded = str_replace("\r\n", "\n", quoted_printable_decode($message));
    $assertExpiry('booking_access_tokens', 'expires_at', $approverTokens[$role]);
    expect(str_contains($decoded, '5 Tage gültig'), 'Booking links must state their five-day lifetime.');
    $name = ucfirst($role);
    expect(str_contains($decoded, "Hallo {$name},") && !str_contains($decoded, "Hallo {$name} Nachname,")
        && str_contains($decoded, "Hallo {$name},\n\neine neue Buchungsanfrage wartet auf Bearbeitung.")
        && str_contains($decoded, '</p><p style="color:#514c48;margin:0 0 24px">eine neue Buchungsanfrage')
        && str_contains($decoded, 'aria-label="Platzbelegung"') && str_contains($decoded, 'Interne &lt;Notiz&gt;')
        && str_contains($decoded, 'Interne <Notiz>') && str_contains($decoded, "Datum: 10.01.2038\nUhrzeit: 10:00 – 11:30 Uhr")
        && preg_match('/>Datum<\/td><td>10\.01\.2038<\/td><\/tr><tr><td[^>]*>Uhrzeit<\/td><td>10:00 – 11:30 Uhr<\/td>/', $decoded) === 1,
        'Approver emails must include the pitch icon, escaped multiline notes and the actual end date.');
}
$assertLogin($route('booking-access', $approverTokens['manager'], $actors['manager']['id']), $actors['manager']['id'], 'manager');
$assertLogin($route('booking-access', $approverTokens['manager'], null), $actors['manager']['id'], 'manager');
$assignment = $pdo->prepare('SELECT id FROM booking_resource_assignments WHERE occurrence_id = :id');
$assignment->execute(['id' => $created['occurrence_id']]);
$assignmentId = (int) $assignment->fetchColumn();
$messages = $capture(static fn(): array => $bookings->approve([$assignmentId], $actors['manager']));
expect(count($messages) === 1, 'Approval must notify the requester.');
$trainerToken = $assertBookingMail($messages[0], $actors['trainer']['id'], 'trainer', $actors['admin']['id']);
expect(str_contains(quoted_printable_decode($messages[0]), 'Interne &lt;Notiz&gt;'), 'Authenticated requesters must receive internal notes.');
$approvalMail = str_replace("\r\n", "\n", quoted_printable_decode($messages[0]));
expect(str_contains($approvalMail, "Hallo Trainer,\n\ndeine Buchungsanfrage wurde freigegeben.")
    && !str_contains($approvalMail, 'Hallo Trainer Nachname,')
    && str_contains($approvalMail, "Datum: 10.01.2038\nUhrzeit: 10:00 – 11:30 Uhr"),
    'Requester notifications must use the first name, lowercase introduction and separate date and time.');
$messages = $capture(static fn(): array => $bookings->cancel($assignmentId, $actors['manager']));
$assertBookingMail($messages[0], $actors['trainer']['id'], 'trainer', $actors['admin']['id']);
expect(str_contains(quoted_printable_decode($messages[0]), 'Interne &lt;Notiz&gt;')
    && str_contains(quoted_printable_decode($messages[0]), 'Ressource wurde aus deiner Buchung entfernt'),
    'Cancellation emails must include the saved details and describe the removed resource.');
$assertLogin($route('booking-access', $approverTokens['admin'], $actors['manager']['id']), $actors['admin']['id'], 'admin');

$pending = null;
$capture(static function () use ($bookings, $actors, $pitch, &$pending): array {
    return $pending = $bookings->create([
        'event_type' => 'training', 'resource_mode' => 'pitch', 'resource_ids' => [$pitch],
        'title' => 'Rejection switching test', 'starts_at' => '2038-01-11T10:00',
        'ends_at' => '2038-01-11T11:30', 'frequency' => 'none',
    ], $actors['trainer']);
});
$assignment->execute(['id' => $pending['occurrence_id']]);
$messages = $capture(static fn(): array => $bookings->reject([(int) $assignment->fetchColumn()], $actors['manager'], 'Test rejection'));
$assertBookingMail($messages[0], $actors['trainer']['id'], 'trainer', $actors['admin']['id']);

$cabin = (int) $pdo->query("SELECT r.id FROM resources r JOIN resource_categories c ON c.id = r.category_id WHERE c.code = 'cabin' LIMIT 1")->fetchColumn();
$bus = (int) $pdo->query("SELECT r.id FROM resources r JOIN resource_categories c ON c.id = r.category_id WHERE c.code = 'bus' LIMIT 1")->fetchColumn();
expect($cabin > 0 && $bus > 0, 'Cabin and vehicle fixtures must be available.');
$cabinRequest = null;
$capture(static function () use ($bookings, $actors, $pitch, &$cabinRequest): array {
    return $cabinRequest = $bookings->create([
        'event_type' => 'training', 'resource_mode' => 'pitch', 'resource_ids' => [$pitch],
        'title' => 'Cabin notification', 'starts_at' => '2038-02-01T10:00',
        'ends_at' => '2038-02-01T11:00', 'frequency' => 'weekly', 'interval' => 1,
        'limit_type' => 'count', 'count' => 2, 'private_notes' => 'Kabinenhinweis',
    ], $actors['trainer']);
});
$assignment->execute(['id' => $cabinRequest['occurrence_id']]);
$cabinAssignment = (int) $assignment->fetchColumn();
$messages = $capture(static fn(): array => $bookings->approve([$cabinAssignment], $actors['manager'], [$cabin]));
$decoded = quoted_printable_decode($messages[0]);
expect(str_contains($decoded, 'Kabinen') && str_contains($decoded, 'Kabinenhinweis')
    && str_contains($decoded, 'Wiederholung') && str_contains($decoded, '2 Termine')
    && str_contains($decoded, 'Datum (erster betroffener Termin): 01.02.2038')
    && str_contains($decoded, 'Uhrzeit: 10:00 – 11:00 Uhr'),
    'Approval emails must include cabins, internal notes and recurrence details.');
$messages = $capture(static fn(): array => $bookings->cancelCabin((int) $cabinRequest['occurrence_id'], $cabin, $actors['manager']));
$decoded = quoted_printable_decode($messages[0]);
expect(str_contains($decoded, 'aria-label="Kabinenbelegung"') && str_contains($decoded, 'Kabinenhinweis')
    && str_contains($decoded, 'Kabine wurde aus deiner Buchung entfernt'), 'Cabin cancellation must identify the cabin and its booking.');

$busRequest = null;
$messages = $capture(static function () use ($bookings, $actors, $bus, &$busRequest): array {
    return $busRequest = $bookings->create([
        'event_type' => 'bus', 'resource_ids' => [$bus], 'title' => 'Bus notification',
        'starts_at' => '2038-02-02T10:00', 'ends_at' => '2038-02-02T11:00',
        'frequency' => 'none', 'private_notes' => 'Fahrzeughinweis',
    ], $actors['trainer']);
});
expect(str_contains(quoted_printable_decode($messages[0]), 'aria-label="Fahrzeugbuchung"')
    && str_contains(quoted_printable_decode($messages[0]), 'Fahrzeughinweis'),
    'Vehicle notifications must include a vehicle icon and internal notes.');
$assignment->execute(['id' => $busRequest['occurrence_id']]);
$busAssignment = (int) $assignment->fetchColumn();
$messages = $capture(static fn(): array => $bookings->approve([$busAssignment], $actors['manager']));
expect(str_contains(quoted_printable_decode($messages[0]), 'aria-label="Fahrzeugbuchung"'), 'Vehicle approval must identify the booking type.');
$messages = $capture(static fn(): array => $bookings->cancel($busAssignment, $actors['manager']));
expect(str_contains(quoted_printable_decode($messages[0]), 'aria-label="Fahrzeugbuchung"')
    && str_contains(quoted_printable_decode($messages[0]), 'Fahrzeughinweis'), 'Vehicle cancellation must preserve type and details.');

$assertInvitationLayout = static function (string $message, string $heading): void {
    $decoded = str_replace("\r\n", "\n", quoted_printable_decode($message));
    expect(preg_match('/^Subject: ([^\r\n]*(?:\r?\n[ \t]+[^\r\n]*)*)/m', $message, $subject) === 1, 'The invitation must have a subject.');
    $expectedSubject = $heading === 'Einladung' ? 'Einladung' : 'Aktualisierte Einladung zur Platz- und Busbelegung - VfL Kaufering';
    expect(iconv_mime_decode(trim($subject[1]), 0, 'UTF-8') === $expectedSubject,
        'The invitation subject must match its intended wording.');
    expect(str_contains($decoded, 'Content-Type: text/html') && str_contains($decoded, 'Content-Type: text/plain')
        && str_contains($decoded, 'aria-label="Einladung"') && str_contains($decoded, 'background:#141210')
        && str_contains($decoded, 'background:#e1141b') && str_contains($decoded, '</span> ' . $heading . '</h1>')
        && str_contains($decoded, '&lt;Invitee&gt;') && str_contains($decoded, '<Invitee>')
        && str_contains($decoded, "Hallo <Invitee>,\n\nrichte dein Konto")
        && str_contains($decoded, 'Hallo &lt;Invitee&gt;,</p><p style="color:#514c48;margin:0 0 24px">richte')
        && str_contains($decoded, 'email-link-invitee') && str_contains($decoded, '5 Tage'),
        'Invitations must include the styled layout, escaped recipient, username and link expiry in both formats.');
};
$messages = $capture(static fn() => $users->invite('invitee@example.test', 'email-link-invitee', '<Invitee>', '', 'trainer'));
$assertInvitationLayout($messages[0], 'Einladung');
$inviteToken = $link($messages[0], 'invite');
$assertExpiry('users', 'invite_expires_at', $inviteToken);
$invitee = (int) $pdo->query("SELECT id FROM users WHERE username = 'email-link-invitee'")->fetchColumn();
$messages = $capture(static fn() => $users->updateDetails($invitee, 'updated-invitee@example.test', '<Invitee>', '', 'trainer', 'invited', $actors['admin']['id']));
$assertInvitationLayout($messages[0], 'Aktualisierte Einladung');
$oldInvitation = $route('invite', $inviteToken, $actors['admin']['id']);
expect($oldInvitation['user_id'] === $actors['admin']['id'] && $oldInvitation['error'], 'An invitation replaced by an email change must not switch accounts.');
$inviteToken = $link($messages[0], 'invite');
$assertExpiry('users', 'invite_expires_at', $inviteToken);
$prepared = $route('invite', $inviteToken, $actors['admin']['id']);
expect($prepared['user_id'] === null && !$prepared['old_state'] && $prepared['valid_form_csrf']
    && $prepared['confirmation_field'], 'A valid invitation must sign out the old account and render a password form with confirmation.');
foreach (['DifferentPassword123!', null] as $confirmation) {
    $mismatch = $route('invite', $inviteToken, null, 'invite-accept', 'UpdatedPassword123!', $confirmation);
    expect($mismatch['error'] && $mismatch['user_id'] === null, 'Mismatched or missing confirmation must reject activation without logging in.');
    $inviteeState = $pdo->query("SELECT status, password_hash FROM users WHERE username = 'email-link-invitee'")->fetch();
    expect($inviteeState['status'] === 'invited' && $inviteeState['password_hash'] === null,
        'A failed confirmation must not activate the account or set its password.');
}
$assertLogin($route('invite', $inviteToken, $actors['admin']['id'], 'invite-accept'), $invitee, 'trainer');
$replayed = $route('invite', $inviteToken, $actors['admin']['id']);
expect($replayed['user_id'] === $actors['admin']['id'] && $replayed['error'] && !$replayed['session_changed'], 'A used invitation must not switch the current account.');

$messages = $capture(static fn() => $users->requestPasswordReset('trainer@example.test'));
expect(str_contains(quoted_printable_decode($messages[0]), 'aria-label="Passwort"'), 'Password reset emails must show a password icon.');
$resetMail = str_replace("\r\n", "\n", quoted_printable_decode($messages[0]));
expect(str_contains($resetMail, 'background:#141210') && str_contains($resetMail, 'background:#e1141b')
    && str_contains($resetMail, "Hallo Trainer,\n\nsetze dein Passwort")
    && str_contains($resetMail, 'Hallo Trainer,</p><p style="color:#514c48;margin:0 0 24px">über')
    && !str_contains($resetMail, 'Hallo Trainer Nachname,')
    && str_contains($resetMail, '5 Tage gültig') && str_contains($resetMail, 'einmal verwendbar'),
    'Password reset emails must use the booking layout and describe the link lifetime in both alternatives.');
$resetToken = $link($messages[0], 'reset');
$assertExpiry('password_resets', 'expires_at', $resetToken);
$prepared = $route('reset', $resetToken, $actors['manager']['id']);
expect($prepared['user_id'] === null && !$prepared['old_state'] && $prepared['valid_form_csrf']
    && $prepared['confirmation_field'], 'A valid reset link must render a usable password form with confirmation.');
$originalHash = $pdo->query("SELECT password_hash FROM users WHERE username = 'email-link-trainer'")->fetchColumn();
foreach (['DifferentPassword123!', null] as $confirmation) {
    $mismatch = $route('reset', $resetToken, null, 'password-reset', 'UpdatedPassword123!', $confirmation);
    expect($mismatch['error'] && $mismatch['user_id'] === null, 'Mismatched or missing confirmation must reject reset without logging in.');
    expect($pdo->query("SELECT password_hash FROM users WHERE username = 'email-link-trainer'")->fetchColumn() === $originalHash,
        'A failed confirmation must not change the password.');
}
$assertLogin($route('reset', $resetToken, $actors['admin']['id'], 'password-reset'), $actors['trainer']['id'], 'trainer');
$hash = $pdo->query("SELECT password_hash FROM users WHERE username = 'email-link-trainer'")->fetchColumn();
expect(password_verify('UpdatedPassword123!', $hash), 'Reset must change the recipient password.');
$replayed = $route('reset', $resetToken, $actors['admin']['id']);
expect($replayed['user_id'] === $actors['admin']['id'] && $replayed['error'], 'A used reset link must not switch the current account.');
$oldAdminHash = $pdo->query("SELECT password_hash FROM users WHERE username = 'email-link-admin'")->fetchColumn();
expect(password_verify('OriginalPassword123!', $oldAdminHash), 'An email action must not change the previous account password.');
$messages = $capture(static fn() => $users->requestPasswordReset('trainer@example.test'));
$expiredReset = $link($messages[0], 'reset');
$pdo->prepare('UPDATE password_resets SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE token_hash = :hash')
    ->execute(['hash' => Security::tokenHash($expiredReset)]);
$expired = $route('reset', $expiredReset, $actors['admin']['id'], 'password-reset');
expect($expired['user_id'] === $actors['admin']['id'] && $expired['error'], 'An expired password reset must not switch accounts.');

$pdo->prepare('UPDATE booking_access_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE token_hash = :hash')
    ->execute(['hash' => Security::tokenHash($trainerToken)]);
$expired = $route('booking-access', $trainerToken, $actors['admin']['id']);
expect($expired['user_id'] === $actors['admin']['id'] && $expired['error'], 'An expired link must not switch the current account.');
$inactiveToken = $auth->createBookingAccessToken($actors['trainer']['id']);
$pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :id")->execute(['id' => $actors['trainer']['id']]);
$inactive = $route('booking-access', $inactiveToken, $actors['admin']['id']);
expect($inactive['user_id'] === $actors['admin']['id'] && $inactive['error'], 'A link for a deactivated account must not authenticate it.');
$unknown = $route('booking-access', Security::randomToken(), $actors['admin']['id']);
expect($unknown['user_id'] === $actors['admin']['id'] && $unknown['error'], 'An unknown email token must not switch accounts.');

$pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute(['id' => $actors['trainer']['id']]);
$pdo->exec("INSERT INTO users (username, email, first_name, role, status)
    VALUES ('notify-second-admin', 'notify-second@example.test', 'Second', 'admin', 'active'),
           ('notify-inactive-admin', 'notify-inactive@example.test', 'Inactive', 'admin', 'inactive')");
$messages = $capture(static fn() => $users->register('new-manager@example.test', 'new-manager', '<New>', 'Manager & Team'));
expect(count($messages) === 2, 'Each active admin with email must receive a registration notification.');
expect(count(array_filter($messages, static fn(string $message): bool => str_contains($message, 'To: admin@example.test'))) === 1, 'The first administrator must be notified.');
expect(count(array_filter($messages, static fn(string $message): bool => str_contains($message, 'To: notify-second@example.test'))) === 1, 'The second administrator must be notified.');
foreach ($messages as $message) {
    $decoded = str_replace("\r\n", "\n", quoted_printable_decode($message));
    $adminFirstName = str_contains($message, 'To: admin@example.test') ? 'Admin' : 'Second';
    expect(str_contains($decoded, "Hallo {$adminFirstName},\n\neine neue Registrierungsanfrage")
        && str_contains($decoded, "Hallo {$adminFirstName},</p><p style=\"color:#514c48;margin:0 0 24px\">eine neue Registrierungsanfrage")
        && !str_contains($decoded, "Hallo {$adminFirstName} Nachname,"),
        'Registration notifications must address administrators by first name and continue lowercase.');
    expect(str_contains($decoded, '&lt;New&gt; Manager &amp; Team') && str_contains($decoded, 'new-manager@example.test')
        && str_contains($decoded, 'new-manager') && str_contains($decoded, '?page=registration-access')
        && str_contains($decoded, '5 Tage gültig'),
        'Each notification must contain escaped registration details and the review link.');
    $registrationToken = $link($message, 'registration-access');
    $assertExpiry('registration_access_tokens', 'expires_at', $registrationToken);
    $guestRegistrationLink = $route('registration-access', $registrationToken, null);
    expect(!$guestRegistrationLink['error'] && $guestRegistrationLink['user_id'] === null,
        'A valid registration link must not automatically log in its recipient.');
    $existingRegistrationLink = $route('registration-access', $registrationToken, $actors['manager']['id']);
    expect(!$existingRegistrationLink['error'] && $existingRegistrationLink['user_id'] === $actors['manager']['id']
        && !$existingRegistrationLink['session_changed'], 'A registration link must preserve an existing login.');
    $pdo->prepare('UPDATE registration_access_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE token_hash = :hash')
        ->execute(['hash' => Security::tokenHash($registrationToken)]);
    $expiredRegistrationLink = $route('registration-access', $registrationToken, null);
    expect($expiredRegistrationLink['error'] && $expiredRegistrationLink['user_id'] === null,
        'Expired registration links must be rejected without logging in.');
    expect(str_contains($decoded, 'Content-Type: text/plain') && str_contains($decoded, 'Content-Type: text/html')
        && str_contains($decoded, 'background:#141210') && str_contains($decoded, 'background:#e1141b')
        && str_contains($decoded, 'Registrierungen bearbeiten') && str_contains($decoded, 'aria-label="Registrierung"'),
        'Each notification must contain both MIME parts and the booking email styling.');
    expect(!str_contains($decoded, 'notify-inactive@example.test'), 'Inactive administrators must not be notified.');
}
$pdo->exec("DELETE FROM users WHERE username IN ('notify-second-admin', 'notify-inactive-admin')");
$requests = $users->registrations();
expect(count($requests) === 1 && $requests[0]['requested_role'] === 'trainer', 'Registrations must default to trainer.');
$registrationId = (int) $requests[0]['id'];
$registrationRoute = static function (string $method, string $target, ?int $userId, array $data = [], ?string $sessionId = null, bool $keepSession = false, bool $disableMail = false): array {
    return json_decode(runChild('--registration-route', ['REGISTRATION_ROUTE' => json_encode([
        'method' => $method, 'target' => $target, 'user_id' => $userId, 'data' => $data,
        'session_id' => $sessionId, 'keep_session' => $keepSession, 'disable_mail' => $disableMail,
    ], JSON_THROW_ON_ERROR)]), true, 512, JSON_THROW_ON_ERROR);
};
$users->register('taken-suffix@example.test', 'new-manager1', 'Taken', '');
$collision = $registrationRoute('POST', 'register', null, [
    'email' => 'retry@example.test', 'username' => 'New-Manager', 'first_name' => '<Neuer>',
    'last_name' => 'Name & Co', 'role' => 'manager',
], null, true);
expect(($collision['flash']['type'] ?? '') === 'danger', 'A duplicate username must report an error.');
$retry = $registrationRoute('GET', 'register', null, [], $collision['session_id'], true);
$document = new DOMDocument();
@$document->loadHTML($retry['html']);
$xpath = new DOMXPath($document);
expect($xpath->evaluate('string(//*[@id="register-first-name"]/@value)') === '<Neuer>', 'The first name must survive and be escaped.');
expect($xpath->evaluate('string(//*[@id="register-last-name"]/@value)') === 'Name & Co', 'The last name must survive.');
expect($xpath->evaluate('string(//*[@id="register-email"]/@value)') === 'retry@example.test', 'The email must survive.');
expect($xpath->evaluate('string(//*[@id="register-username"]/@value)') === 'New-Manager', 'The username must survive.');
expect($xpath->query('//*[@name="role"]')->length === 0, 'Registration must not offer a role selection.');
$suggested = [];
foreach ($xpath->query('//*[@data-username-suggestion]') as $button) {
    $suggested[] = $button->getAttribute('data-username-suggestion');
}
expect($suggested === ['new-manager2', 'new-manager3', 'new-manager4'], 'Suggestions must skip pending usernames and match normalized names.');
$resubmit = $registrationRoute('POST', 'register', null, [
    'email' => 'retry@example.test', 'username' => $suggested[0], 'first_name' => '<Neuer>',
    'last_name' => 'Name & Co', 'role' => 'manager',
], $collision['session_id']);
expect(($resubmit['flash']['type'] ?? '') === 'info', 'A suggested username must allow registration.');
foreach ($users->registrations() as $request) {
    if (in_array($request['username'], ['new-manager1', 'new-manager2'], true)) {
        $users->rejectRegistration((int) $request['id']);
    }
}
$emailCollision = $registrationRoute('POST', 'register', null, [
    'email' => 'admin@example.test', 'username' => 'unique-retry', 'first_name' => 'Email',
    'last_name' => 'Taken', 'role' => 'trainer',
], null, true);
$emailRetry = $registrationRoute('GET', 'register', null, [], $emailCollision['session_id']);
expect(str_contains($emailRetry['html'], 'value="admin@example.test"') && str_contains($emailRetry['html'], 'value="unique-retry"'), 'Email collisions must retain the entered fields.');
expect(!str_contains($emailRetry['html'], 'data-username-suggestion'), 'Available usernames must not produce unnecessary suggestions.');
expect($users->registrationUsernameSuggestions('email-link-admin') === ['email-link-admin1', 'email-link-admin2', 'email-link-admin3'], 'Suggestions must account for existing user accounts.');
$longName = str_repeat('a', 80);
$users->register('long-name@example.test', $longName, 'Long', '');
foreach ($users->registrationUsernameSuggestions($longName) as $suggestion) {
    expect(strlen($suggestion) === 80 && AuthService::normalizeUsername($suggestion) === $suggestion, 'Suggestions must obey the username length limit.');
}
foreach ($users->registrations() as $request) {
    if ($request['username'] === $longName) {
        $users->rejectRegistration((int) $request['id']);
    }
}
$managerPage = $registrationRoute('GET', 'registrations', $actors['manager']['id']);
expect(str_contains($managerPage['html'], 'new-manager@example.test'), 'Managers must be able to review registrations.');
expect(!str_contains($managerPage['html'], '<option value="admin"'), 'Managers must not be offered the admin role.');
$adminPage = $registrationRoute('GET', 'registrations', $actors['admin']['id']);
expect(str_contains($adminPage['html'], '<option value="admin"'), 'Admins must be offered the admin role.');
$trainerPage = $registrationRoute('GET', 'registrations', $actors['trainer']['id']);
expect($trainerPage['status'] === 403 && !str_contains($trainerPage['html'], 'new-manager@example.test'), 'Trainers must not see registration data.');
$unauthorizedPost = $registrationRoute('POST', 'registration-approve', $actors['trainer']['id'], ['id' => $registrationId, 'role' => 'trainer']);
expect(($unauthorizedPost['flash']['type'] ?? '') === 'danger', 'Trainer approval requests must be rejected.');
$escalationPost = $registrationRoute('POST', 'registration-approve', $actors['manager']['id'], ['id' => $registrationId, 'role' => 'admin']);
expect(($escalationPost['flash']['type'] ?? '') === 'danger', 'Managers must not approve admin through a crafted request.');
expect(count($users->registrations()) === 1, 'Unauthorized HTTP requests must leave the registration pending.');
expect(!$auth->authenticate('new-manager', 'SomePassword123!'), 'A registration must not authenticate before approval.');
foreach ([['trainer', 'trainer'], ['manager', 'admin']] as [$actorRole, $requestedRole]) {
    try {
        $users->approveRegistration($registrationId, $requestedRole, $actors[$actorRole]);
        throw new LogicException('Unauthorized approval was accepted.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'nicht freigeben'), 'The wrong approval error was returned.');
    }
}
try {
    $users->register('another@example.test', 'new-manager', 'Duplicate', '');
    throw new LogicException('A duplicate registration was accepted.');
} catch (RuntimeException $exception) {
    expect(str_contains($exception->getMessage(), 'bereits'), 'The duplicate registration error was missing.');
}
$noMailConfig = $config->withOverrides(['MAIL_FROM' => '']);
$noMailUsers = new UserService($pdo, new Mailer($noMailConfig), $noMailConfig);
try {
    $noMailUsers->approveRegistration($registrationId, 'manager', $actors['manager']);
    throw new LogicException('An approval without mail should fail.');
} catch (RuntimeException $exception) {
    expect(str_contains($exception->getMessage(), 'E-Mail-Versand'), 'The mail failure was not surfaced.');
}
expect(count($users->registrations()) === 1, 'Failed email delivery must leave the registration pending.');
expect((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'new-manager'")->fetchColumn() === 0, 'Failed approval must not create an account.');
$messages = $capture(static fn() => $users->approveRegistration($registrationId, 'manager', $actors['manager']));
expect(count($users->registrations()) === 0, 'Approved registration must be removed from the queue.');
$registeredId = (int) $pdo->query("SELECT id FROM users WHERE username = 'new-manager' AND status = 'invited'")->fetchColumn();
expect($registeredId > 0 && !$auth->authenticate('new-manager', 'SomePassword123!'), 'The invited account must not authenticate before accepting its invitation.');
$token = $link($messages[0], 'invite');
$assertLogin($route('invite', $token, null, 'invite-accept'), $registeredId, 'manager');
try {
    $users->approveRegistration($registrationId, 'trainer', $actors['admin']);
    throw new LogicException('A registration was approved twice.');
} catch (RuntimeException $exception) {
    expect(str_contains($exception->getMessage(), 'bereits bearbeitet'), 'Repeated approvals must fail.');
}
$users->register('new-trainer@example.test', 'new-trainer', 'New', 'Trainer');
$trainerRegistration = (int) $users->registrations()[0]['id'];
$messages = $capture(static fn() => $users->approveRegistration($trainerRegistration, 'trainer', $actors['manager']));
$newTrainer = (int) $pdo->query("SELECT id FROM users WHERE username = 'new-trainer' AND role = 'trainer' AND status = 'invited'")->fetchColumn();
expect($newTrainer > 0, 'Approvers must be able to select the trainer role.');
$assertLogin($route('invite', $link($messages[0], 'invite'), null, 'invite-accept'), $newTrainer, 'trainer');
$users->register('new-admin@example.test', 'new-admin', 'New', 'Admin');
$adminRegistration = (int) $users->registrations()[0]['id'];
$messages = $capture(static fn() => $users->approveRegistration($adminRegistration, 'admin', $actors['admin']));
$newAdmin = (int) $pdo->query("SELECT id FROM users WHERE username = 'new-admin' AND role = 'admin' AND status = 'invited'")->fetchColumn();
expect($newAdmin > 0, 'Admins must be able to approve an administrator.');
$assertLogin($route('invite', $link($messages[0], 'invite'), null, 'invite-accept'), $newAdmin, 'admin');
$users->register('rejected@example.test', 'rejected-user', '<Rejected>', '');
$rejectedId = (int) $users->registrations()[0]['id'];
$unauthorizedRejection = $registrationRoute('POST', 'registration-reject', $actors['trainer']['id'], ['id' => $rejectedId]);
expect(($unauthorizedRejection['flash']['type'] ?? '') === 'danger' && count($users->registrations()) === 1,
    'Trainers must not be able to reject a registration.');
$failedRejection = $registrationRoute('POST', 'registration-reject', $actors['manager']['id'], ['id' => $rejectedId], null, false, true);
expect(($failedRejection['flash']['type'] ?? '') === 'danger'
    && str_contains($failedRejection['flash']['message'], 'E-Mail-Versand')
    && count($users->registrations()) === 1, 'A failed rejection email must leave the request pending and report the error.');
$messages = $capture(static function () use ($registrationRoute, $actors, $rejectedId): void {
    $result = $registrationRoute('POST', 'registration-reject', $actors['manager']['id'], ['id' => $rejectedId]);
    expect(($result['flash']['type'] ?? '') === 'info' && str_contains($result['flash']['message'], 'Benachrichtigung versendet'),
        'Successful rejection must confirm the notification.');
});
expect(count($messages) === 1 && str_contains($messages[0], 'To: rejected@example.test'), 'The applicant must receive exactly one rejection notification.');
$message = quoted_printable_decode($messages[0]);
expect(str_contains($message, 'Content-Type: text/plain') && str_contains($message, 'Content-Type: text/html')
    && str_contains($message, '&lt;Rejected&gt;') && str_contains($message, '<Rejected>')
    && substr_count($message, 'deine Registrierungsanfrage wurde leider abgelehnt') === 2
    && str_contains($message, 'background:#141210') && str_contains($message, 'aria-label="Registrierung"'), 'The rejection notification must contain escaped HTML and matching plain text.');
expect(count($users->registrations()) === 0, 'Rejected registrations must be removed.');
expect((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'rejected-user'")->fetchColumn() === 0, 'Rejected registrations must not create accounts.');
$duplicateRejection = $registrationRoute('POST', 'registration-reject', $actors['admin']['id'], ['id' => $rejectedId]);
expect(($duplicateRejection['flash']['type'] ?? '') === 'danger', 'An already rejected registration must not be processed again.');
$guestPage = $registrationRoute('GET', 'register', null);
expect(!str_contains($guestPage['html'], 'name="role"') && str_contains($guestPage['html'], '>Registrieren</button>'), 'Guests must be able to register without selecting a role.');
$guestPost = $registrationRoute('POST', 'register', null, [
    'email' => 'route@example.test', 'username' => 'route-register', 'first_name' => 'Route', 'role' => 'admin',
]);
expect(($guestPost['flash']['type'] ?? '') === 'info' && count($users->registrations()) === 1, 'Guest registration must create a pending request and give feedback.');
expect($users->registrations()[0]['requested_role'] === 'trainer', 'A submitted role must not change the default registration role.');
$users->rejectRegistration((int) $users->registrations()[0]['id']);
$failedMail = $registrationRoute('POST', 'register', null, [
    'email' => 'mail-failure@example.test', 'username' => 'mail-failure', 'first_name' => 'Mail',
], null, false, true);
expect(($failedMail['flash']['type'] ?? '') === 'warning'
    && str_contains($failedMail['flash']['message'], 'Nicht alle Administratoren'), 'Mail delivery failures must be reported to the applicant.');
expect(count($users->registrations()) === 1 && $users->registrations()[0]['username'] === 'mail-failure', 'Mail failure must not discard the registration.');
$users->rejectRegistration((int) $users->registrations()[0]['id']);
$signedInPost = $registrationRoute('POST', 'register', $actors['manager']['id'], [
    'email' => 'signed@example.test', 'username' => 'signed-register', 'first_name' => 'Signed', 'role' => 'trainer',
]);
expect(($signedInPost['flash']['type'] ?? '') === 'danger' && count($users->registrations()) === 0, 'Signed-in users must not submit public registrations.');
echo "Recipient-bound email link tests passed.\n";
