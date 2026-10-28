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
$auth = new AuthService($pdo);
$mailer = new Mailer($config);
$users = new UserService($pdo, $mailer, $config);
$bookings = new BookingService($pdo, $mailer, $config);
$actors = [];
foreach (['admin', 'manager', 'trainer'] as $role) {
    $pdo->prepare(
        "INSERT INTO users (username, email, first_name, password_hash, role, status)
         VALUES (:username, :email, :first_name, :password_hash, :role, 'active')"
    )->execute([
        'username' => 'email-link-' . $role,
        'email' => $role . '@example.test',
        'first_name' => ucfirst($role),
        'password_hash' => password_hash('OriginalPassword123!', PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    $actors[$role] = ['id' => (int) $pdo->lastInsertId(), 'role' => $role];
}

$route = static function (string $page, string $token, ?int $currentUser, ?string $action = null, string $password = 'UpdatedPassword123!'): array {
    return json_decode(runChild('--route', ['EMAIL_LINK_ROUTE' => json_encode([
        'page' => $page, 'token' => $token, 'current_user' => $currentUser,
        'action' => $action, 'password' => $password,
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
    return array_map(static fn(string $path): string => (string) file_get_contents($path), $paths);
};
$link = static function (string $message, string $page): string {
    expect(preg_match('/boundary="([^"]+)"/i', $message, $boundary) === 1, 'The notification has no MIME boundary.');
    $parts = [];
    foreach (explode('--' . $boundary[1], $message) as $part) {
        $sections = preg_split('/\r?\n\r?\n/', ltrim($part, "\r\n"), 2);
        if (count($sections) !== 2 || !preg_match('/Content-Type:\s*text\/(plain|html)/i', $sections[0], $type)) {
            continue;
        }
        $parts[$type[1]] = stripos($sections[0], 'Content-Transfer-Encoding: quoted-printable') !== false
            ? quoted_printable_decode($sections[1])
            : (stripos($sections[0], 'Content-Transfer-Encoding: base64') !== false
                ? base64_decode($sections[1], true)
                : $sections[1]);
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
    ], $actors['trainer']);
});
expect(count($messages) === 2, 'A trainer request must notify both the admin and manager.');
$approverTokens = [];
foreach ($messages as $message) {
    $role = str_contains($message, 'To: admin@example.test') ? 'admin' : 'manager';
    $approverTokens[$role] = $assertBookingMail($message, $actors[$role]['id'], $role, $actors['trainer']['id']);
}
$assertLogin($route('booking-access', $approverTokens['manager'], $actors['manager']['id']), $actors['manager']['id'], 'manager');
$assertLogin($route('booking-access', $approverTokens['manager'], null), $actors['manager']['id'], 'manager');
$assignment = $pdo->prepare('SELECT id FROM booking_resource_assignments WHERE occurrence_id = :id');
$assignment->execute(['id' => $created['occurrence_id']]);
$assignmentId = (int) $assignment->fetchColumn();
$messages = $capture(static fn(): array => $bookings->approve([$assignmentId], $actors['manager']));
expect(count($messages) === 1, 'Approval must notify the requester.');
$trainerToken = $assertBookingMail($messages[0], $actors['trainer']['id'], 'trainer', $actors['admin']['id']);
$messages = $capture(static fn(): array => $bookings->cancel($assignmentId, $actors['manager']));
$assertBookingMail($messages[0], $actors['trainer']['id'], 'trainer', $actors['admin']['id']);
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

$messages = $capture(static fn() => $users->invite('invitee@example.test', 'email-link-invitee', 'Invitee', '', 'trainer'));
$inviteToken = $link($messages[0], 'invite');
$invitee = (int) $pdo->query("SELECT id FROM users WHERE username = 'email-link-invitee'")->fetchColumn();
$messages = $capture(static fn() => $users->updateDetails($invitee, 'updated-invitee@example.test', 'Invitee', '', 'trainer', 'invited'));
$oldInvitation = $route('invite', $inviteToken, $actors['admin']['id']);
expect($oldInvitation['user_id'] === $actors['admin']['id'] && $oldInvitation['error'], 'An invitation replaced by an email change must not switch accounts.');
$inviteToken = $link($messages[0], 'invite');
$prepared = $route('invite', $inviteToken, $actors['admin']['id']);
expect($prepared['user_id'] === null && !$prepared['old_state'] && $prepared['valid_form_csrf'], 'A valid invitation must sign out the old account and render a usable password form.');
$assertLogin($route('invite', $inviteToken, $actors['admin']['id'], 'invite-accept'), $invitee, 'trainer');
$replayed = $route('invite', $inviteToken, $actors['admin']['id']);
expect($replayed['user_id'] === $actors['admin']['id'] && $replayed['error'] && !$replayed['session_changed'], 'A used invitation must not switch the current account.');

$messages = $capture(static fn() => $users->requestPasswordReset('trainer@example.test'));
$resetToken = $link($messages[0], 'reset');
$prepared = $route('reset', $resetToken, $actors['manager']['id']);
expect($prepared['user_id'] === null && !$prepared['old_state'] && $prepared['valid_form_csrf'], 'A valid reset link must sign out the old account and render a usable password form.');
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
echo "Recipient-bound email link tests passed.\n";
