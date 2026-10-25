<?php
declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

final class Application
{
    private AuthService $auth;
    private UserService $users;
    private ResourceService $resources;
    private BookingService $bookings;
    private string $csrf;

    public function __construct(
        private readonly Config $config,
        private readonly \PDO $pdo
    ) {
        $mailer = new Mailer($config);
        $this->auth = new AuthService($pdo, $config);
        $this->users = new UserService($pdo, $mailer, $config);
        $this->resources = new ResourceService($pdo);
        $this->bookings = new BookingService($pdo, $mailer, $config);
        $this->csrf = Security::csrfToken();
    }

    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');

        try {
            if (isset($_GET['api'])) {
                $this->handleApi((string) $_GET['api']);
                return;
            }

            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $this->handleAction((string) ($_GET['action'] ?? ''));
                return;
            }

            $this->renderPage((string) ($_GET['page'] ?? 'calendar'));
        } catch (Throwable $exception) {
            if (isset($_GET['api'])) {
                $this->jsonError($exception);
                return;
            }

            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                if (!$exception instanceof RuntimeException && !$exception instanceof \InvalidArgumentException) {
                    error_log('Application page failed: ' . $exception->getMessage());
                    http_response_code(500);
                    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Fehler</title><body><h1>Die Seite konnte nicht geladen werden.</h1></body></html>';
                    return;
                }
                $currentUser = $this->auth->currentUser();
                if ($currentUser === null) {
                    $this->redirect('/?page=login');
                }
                http_response_code(403);
                echo Views::layout(
                    'Zugriff verweigert',
                    '<section class="panel-card p-4"><h1 class="h3">Zugriff verweigert</h1><p>Für diese Funktion fehlen die Rechte.</p></section>',
                    $currentUser,
                    $this->csrf,
                    $this->config->get('APP_URL')
                );
                return;
            }

            if ($exception instanceof RuntimeException || $exception instanceof \InvalidArgumentException) {
                $_SESSION['_flash'] = ['type' => 'danger', 'message' => $exception->getMessage()];
            } else {
                error_log('Application request failed: ' . $exception->getMessage());
                $_SESSION['_flash'] = ['type' => 'danger', 'message' => 'Die Anfrage konnte nicht verarbeitet werden.'];
            }

            $target = match ((string) ($_GET['action'] ?? '')) {
                'login' => '/?page=login',
                'setup' => '/?page=setup',
                'invite-accept' => '/?page=invite&token=' . rawurlencode((string) ($_POST['token'] ?? '')),
                'password-request' => '/?page=forgot',
                'password-reset' => '/?page=reset&token=' . rawurlencode((string) ($_POST['token'] ?? '')),
                'user-invite' => '/?page=users&form=invite',
                'user-create' => '/?page=users&form=create',
                'user-password', 'user-role', 'user-deactivate', 'user-delete' => '/?page=users',
                'resource-save' => trim((string) ($_POST['id'] ?? '')) === ''
                    ? '/?page=resources&form=create'
                    : '/?page=resources',
                'resource-active', 'resource-delete' => '/?page=resources',
                'booking-approve', 'booking-reject', 'booking-withdraw', 'request-edit' => '/?page=dashboard',
                default => '/',
            };
            $this->redirect($target);
        }
    }

    private function handleApi(string $api): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($api === 'events' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $user = $this->auth->currentUser();
            $includePrivate = $user !== null && in_array($user['role'], ['admin', 'manager'], true);
            echo json_encode(
                $this->bookings->events(
                    (string) ($_GET['start'] ?? ''),
                    (string) ($_GET['end'] ?? ''),
                    (string) ($_GET['type'] ?? ''),
                    $includePrivate
                ),
                JSON_THROW_ON_ERROR
            );
            return;
        }

        if ($api === 'resources' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $resources = array_values(array_filter(
                $this->resources->all(),
                static fn(array $resource): bool => in_array($resource['type'], ['pitch', 'bus', 'cabin'], true)
            ));
            echo json_encode([
                'resources' => array_map(static fn(array $resource): array => [
                    'id' => (int) $resource['id'],
                    'type' => $resource['type'],
                    'category_name' => $resource['category_name'],
                    'name' => $resource['name'],
                ], $resources),
            ], JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'resource-reorder' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf($_POST['_csrf'] ?? null);
            $this->requireRole(['admin']);
            $resourceIds = $_POST['resource_ids'] ?? null;
            if (!is_array($resourceIds)) {
                throw new RuntimeException('Die Ressourcenreihenfolge ist ungültig.');
            }
            $this->resources->reorder((string) ($_POST['category'] ?? ''), $resourceIds);
            echo json_encode(['success' => true], JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'booking-create' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf($_POST['_csrf'] ?? null);
            $actor = $this->requireUser();
            $result = $this->bookings->create($_POST, $actor);
            echo json_encode([
                'pending' => $actor['role'] === 'trainer',
                'occurrence_count' => $result['occurrence_count'],
                'mail_errors' => $result['mail_errors'],
            ], JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'booking-details' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $actor = $this->requireRole(['admin', 'manager']);
            echo json_encode($this->bookings->editableOccurrence(
                $this->positiveId($_GET['occurrence_id'] ?? null), $actor
            ), JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'booking-edit' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf($_POST['_csrf'] ?? null);
            $actor = $this->requireRole(['admin', 'manager']);
            $result = $this->bookings->editOccurrence(
                $this->positiveId($_POST['occurrence_id'] ?? null),
                $_POST,
                $actor
            );
            echo json_encode($result, JSON_THROW_ON_ERROR);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'API-Endpunkt nicht gefunden.'], JSON_THROW_ON_ERROR);
    }

    private function handleAction(string $action): void
    {
        Security::verifyCsrf($_POST['_csrf'] ?? null);

        switch ($action) {
            case 'login':
                if (!$this->auth->authenticate((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                    throw new RuntimeException('Benutzername, E-Mail-Adresse oder Passwort ist nicht korrekt.');
                }
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Du bist angemeldet.'];
                $this->redirect('/');

            case 'logout':
                $this->auth->logout();
                $this->redirect('/');

            case 'setup':
                $this->auth->createInitialAdmin(
                    (string) ($_POST['setup_token'] ?? ''),
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['username'] ?? ''),
                    (string) ($_POST['display_name'] ?? ''),
                    (string) ($_POST['password'] ?? '')
                );
                $this->auth->loginAfterSetup((string) $_POST['email']);
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Das Administratorkonto wurde eingerichtet.'];
                $this->redirect('/');

            case 'invite-accept':
                $this->users->acceptInvitation((string) ($_POST['token'] ?? ''), (string) ($_POST['password'] ?? ''));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Dein Konto ist eingerichtet. Du kannst dich jetzt anmelden.'];
                $this->redirect('/?page=login');

            case 'password-request':
                $this->users->requestPasswordReset((string) ($_POST['email'] ?? ''));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Falls ein aktives Konto zu dieser Adresse existiert, wurde ein Link versendet.'];
                $this->redirect('/?page=forgot');

            case 'password-reset':
                $this->users->resetPassword((string) ($_POST['token'] ?? ''), (string) ($_POST['password'] ?? ''));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Das Passwort wurde geändert. Bitte melde dich an.'];
                $this->redirect('/?page=login');

            case 'user-invite':
                $this->requireRole(['admin']);
                $this->users->invite(
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['username'] ?? ''),
                    (string) ($_POST['display_name'] ?? ''),
                    (string) ($_POST['role'] ?? '')
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Die Einladung wurde versendet.'];
                $this->redirect('/?page=users');

            case 'user-create':
                $this->requireRole(['admin']);
                $this->users->createWithoutEmail(
                    (string) ($_POST['username'] ?? ''),
                    (string) ($_POST['display_name'] ?? ''),
                    (string) ($_POST['role'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['password_confirm'] ?? '')
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Der Benutzer ohne E-Mail-Adresse wurde angelegt.'];
                $this->redirect('/?page=users');

            case 'user-password':
                $this->requireRole(['admin']);
                $this->users->setPassword(
                    $this->positiveId($_POST['id'] ?? null),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['password_confirm'] ?? '')
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Das Passwort wurde geändert.'];
                $this->redirect('/?page=users');

            case 'user-role':
                $this->requireRole(['admin']);
                $this->users->changeRole($this->positiveId($_POST['id'] ?? null), (string) ($_POST['role'] ?? ''));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Die Rolle wurde geändert.'];
                $this->redirect('/?page=users');

            case 'user-deactivate':
                $this->requireRole(['admin']);
                $this->users->deactivate($this->positiveId($_POST['id'] ?? null));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Das Konto wurde deaktiviert.'];
                $this->redirect('/?page=users');

            case 'user-delete':
                $this->requireRole(['admin']);
                $this->users->delete($this->positiveId($_POST['id'] ?? null));
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Der Benutzer wurde gelöscht. Zugehörige Reservierungen bleiben erhalten.'];
                $this->redirect('/?page=users');

            case 'resource-save':
                $this->requireRole(['admin']);
                $resourceId = trim((string) ($_POST['id'] ?? ''));
                $this->resources->save(
                    (string) ($_POST['type'] ?? ''),
                    (string) ($_POST['name'] ?? ''),
                    $resourceId === '' ? null : $this->positiveId($resourceId)
                );
                $_SESSION['_flash'] = [
                    'type' => 'success',
                    'message' => $resourceId === '' ? 'Die Ressource wurde angelegt.' : 'Die Ressource wurde geändert.',
                ];
                $this->redirect('/?page=resources');

            case 'resource-active':
                $this->requireRole(['admin']);
                $active = (string) ($_POST['active'] ?? '');
                if (!in_array($active, ['0', '1'], true)) {
                    throw new RuntimeException('Der Ressourcenstatus ist ungültig.');
                }
                $this->resources->setActive($this->positiveId($_POST['id'] ?? null), $active === '1');
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Der Ressourcenstatus wurde geändert.'];
                $this->redirect('/?page=resources');

            case 'resource-delete':
                $this->requireRole(['admin']);
                $deletedBookings = $this->resources->delete($this->positiveId($_POST['id'] ?? null));
                $message = $deletedBookings === 0
                    ? 'Die Ressource wurde gelöscht. Es waren keine Buchungen verknüpft.'
                    : 'Die Ressource und ' . $deletedBookings . ' zugehörige '
                        . ($deletedBookings === 1 ? 'Buchung' : 'Buchungen')
                        . ' wurden gelöscht. Es wurden keine E-Mails versendet.';
                $_SESSION['_flash'] = [
                    'type' => 'success',
                    'message' => $message,
                ];
                $this->redirect('/?page=resources');

            case 'booking-approve':
                $actor = $this->requireRole(['admin', 'manager']);
                $id = $this->positiveId($_POST['assignment_id'] ?? null);
                $result = $this->bookings->approve([$id], $actor);
                $_SESSION['_flash'] = [
                    'type' => $result['mail_errors'] !== [] ? 'warning' : 'success',
                    'message' => 'Die Anfrage wurde freigegeben.'
                        . ($result['mail_errors'] !== [] ? ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.' : ''),
                ];
                $this->redirect('/?page=dashboard');

            case 'booking-reject':
                $actor = $this->requireRole(['admin', 'manager']);
                $mailErrors = $this->bookings->reject(
                    [$this->positiveId($_POST['assignment_id'] ?? null)],
                    $actor,
                    (string) ($_POST['reason'] ?? '')
                );
                $_SESSION['_flash'] = [
                    'type' => $mailErrors !== [] ? 'warning' : 'success',
                    'message' => 'Die Anfrage wurde abgelehnt.'
                        . ($mailErrors !== [] ? ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.' : ''),
                ];
                $this->redirect('/?page=dashboard');

            case 'booking-withdraw':
                $actor = $this->requireRole(['trainer']);
                $this->bookings->withdraw([$this->positiveId($_POST['assignment_id'] ?? null)], $actor);
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Die Anfrage wurde zurückgezogen.'];
                $this->redirect('/?page=dashboard');

            case 'request-edit':
                $actor = $this->requireRole(['trainer']);
                $this->bookings->editPendingRequest(
                    $this->positiveId($_POST['assignment_id'] ?? null),
                    $_POST,
                    $actor
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Die Anfrage wurde geändert.'];
                $this->redirect('/?page=dashboard');

            case 'booking-cancel':
                $actor = $this->requireRole(['admin', 'manager']);
                $scope = (string) ($_POST['scope'] ?? 'one');
                $result = $this->bookings->cancel(
                    $this->positiveId($_POST['assignment_id'] ?? null),
                    $actor,
                    $scope
                );
                $cancelledMessage = $result['cancelled'] === 1
                    ? 'Die Buchung wurde storniert.'
                    : $result['cancelled'] . ' Buchungen wurden storniert.';
                $_SESSION['_flash'] = [
                    'type' => $result['mail_errors'] !== [] ? 'warning' : 'success',
                    'message' => $cancelledMessage
                        . ($result['mail_errors'] !== [] ? ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.' : ''),
                ];
                $this->redirect('/');

            case 'booking-cabin-cancel':
                $actor = $this->requireRole(['admin', 'manager']);
                $result = $this->bookings->cancelCabin(
                    $this->positiveId($_POST['occurrence_id'] ?? null),
                    $this->positiveId($_POST['cabin_id'] ?? null),
                    $actor,
                    (string) ($_POST['scope'] ?? 'one')
                );
                $_SESSION['_flash'] = [
                    'type' => $result['mail_errors'] !== [] ? 'warning' : 'success',
                    'message' => 'Die Kabinenbelegung wurde storniert.'
                        . ($result['mail_errors'] !== [] ? ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.' : ''),
                ];
                $this->redirect('/');

            default:
                http_response_code(404);
                echo 'Aktion nicht gefunden.';
        }
    }

    private function renderPage(string $page): void
    {
        $user = $this->auth->currentUser();
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        $content = '';
        $clientData = [];

        switch ($page) {
            case 'calendar':
                $resources = $this->resources->all();
                $content = Views::calendar($user, $this->csrf, $resources);
                $clientData = [
                    'resources' => array_values(array_map(
                        static fn(array $resource): array => [
                            'id' => (int) $resource['id'],
                            'type' => $resource['type'],
                            'name' => $resource['name'],
                        ],
                        array_filter($resources, static fn(array $resource): bool => in_array($resource['type'], ['pitch', 'bus', 'cabin'], true))
                    )),
                ];
                if (!$this->initialAdminExists()) {
                    $content = '<div class="alert alert-info">Das System ist noch nicht eingerichtet. <a href="/?page=setup">Ersten Administrator einrichten</a></div>' . $content;
                }
                break;

            case 'login':
                $content = Views::login($this->csrf);
                break;

            case 'setup':
                if ($this->initialAdminExists()) {
                    http_response_code(404);
                    $content = '<section class="panel-card p-4"><h1 class="h3">Setup abgeschlossen</h1><p>Der Einrichtungsassistent ist bereits gesperrt.</p></section>';
                } else {
                    $content = Views::setup($this->csrf);
                }
                break;

            case 'invite':
                $content = Views::invite((string) ($_GET['token'] ?? ''), $this->csrf);
                break;

            case 'forgot':
                $content = Views::forgot($this->csrf);
                break;

            case 'reset':
                $content = Views::reset((string) ($_GET['token'] ?? ''), $this->csrf);
                break;

            case 'dashboard':
                $user = $this->requireRole(['admin', 'manager', 'trainer']);
                $content = Views::dashboard($user, $this->bookings->requests($user), $this->csrf);
                break;

            case 'users':
                $this->requireRole(['admin']);
                $content = Views::users(
                    $this->users->all(),
                    $this->csrf,
                    '',
                    (string) ($_GET['form'] ?? '')
                );
                break;

            case 'resources':
                $this->requireRole(['admin']);
                $content = Views::resources(
                    $this->resources->all(true),
                    $this->resources->categories(),
                    $this->csrf,
                    '',
                    (string) ($_GET['form'] ?? '')
                );
                break;

            default:
                http_response_code(404);
                $content = '<section class="panel-card p-4"><h1 class="h3">Seite nicht gefunden</h1></section>';
        }

        echo Views::layout(
            $this->pageTitle($page),
            $content,
            $user,
            $this->csrf,
            $this->config->get('APP_URL'),
            is_array($flash) ? $flash : null,
            $clientData
        );
    }

    private function initialAdminExists(): bool
    {
        $statement = $this->pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'initial_admin_created'"
        );
        $setting = $statement->fetchColumn();
        if ($setting === '1') {
            return true;
        }

        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    }

    private function requireUser(): array
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            throw new RuntimeException('Bitte melde dich an.');
        }

        return $user;
    }

    private function requireRole(array $roles): array
    {
        $user = $this->requireUser();
        if (!in_array($user['role'], $roles, true)) {
            throw new RuntimeException('Für diese Funktion fehlen die Rechte.');
        }

        return $user;
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new RuntimeException('Eine angegebene ID ist ungültig.');
        }

        return $id;
    }

    private function jsonError(Throwable $exception): void
    {
        if ($exception instanceof RuntimeException || $exception instanceof \InvalidArgumentException) {
            http_response_code(400);
            $message = $exception->getMessage();
        } else {
            error_log('API request failed: ' . $exception->getMessage());
            http_response_code(500);
            $message = 'Die Anfrage konnte nicht verarbeitet werden.';
        }
        echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    }

    private function pageTitle(string $page): string
    {
        return match ($page) {
            'login' => 'Anmelden',
            'setup' => 'Ersteinrichtung',
            'invite' => 'Konto einrichten',
            'forgot', 'reset' => 'Passwort',
            'dashboard' => 'Dashboard',
            'users' => 'Benutzerverwaltung',
            'resources' => 'Ressourcen',
            default => 'Belegungsplan',
        };
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }
}
