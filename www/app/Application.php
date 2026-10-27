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
    private BookingTypeService $bookingTypes;
    private BookingService $bookings;
    private DatabaseSetup $databaseSetup;
    private string $csrf;

    public function __construct(
        private readonly Config $config,
        private readonly \PDO $pdo
    ) {
        $this->databaseSetup = new DatabaseSetup($pdo);
        $this->databaseSetup->migrate();
        $mailer = new Mailer($config);
        $this->auth = new AuthService($pdo);
        $this->users = new UserService($pdo, $mailer, $config);
        $this->resources = new ResourceService($pdo);
        $this->bookingTypes = new BookingTypeService($pdo);
        $this->bookings = new BookingService($pdo, $mailer, $config, $this->bookingTypes);
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
            $setupRequired = !$this->databaseSetup->status()['schema_complete']
                || !$this->initialAdminExists();
            if ($setupRequired) {
                if (isset($_GET['api'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(503);
                    echo json_encode(['error' => 'Die Ersteinrichtung muss zuerst abgeschlossen werden.'], JSON_THROW_ON_ERROR);
                    return;
                }
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                    if ((string) ($_GET['action'] ?? '') !== 'setup') {
                        $this->redirect('/?page=setup');
                    }
                } elseif ((string) ($_GET['page'] ?? '') !== 'setup') {
                    $this->redirect('/?page=setup');
                }
            }

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
                'profile-update' => '/?page=profile',
                'user-password', 'user-role', 'user-deactivate', 'user-delete' => '/?page=users',
                'resource-save' => trim((string) ($_POST['id'] ?? '')) === ''
                    ? '/?page=resources&form=create'
                    : '/?page=resources',
                'resource-active', 'resource-delete' => '/?page=resources',
                'booking-type-save', 'booking-type-status', 'booking-type-remove' => '/?page=booking-types',
                'booking-approve', 'booking-reject', 'booking-delete', 'request-edit' => '/?page=dashboard',
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
            $includeClosedRequests = ($_POST['show_all_requests'] ?? '') === '1';
            echo json_encode([
                'pending' => $actor['role'] === 'trainer',
                'occurrence_id' => $result['occurrence_id'],
                'request_page' => $actor['role'] === 'trainer'
                    ? $this->bookings->requestPageForOccurrence(
                        $result['occurrence_id'],
                        $actor,
                        $includeClosedRequests
                    )
                    : null,
                'occurrence_count' => $result['occurrence_count'],
                'mail_errors' => $result['mail_errors'],
            ], JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'request-edit' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf($_POST['_csrf'] ?? null);
            $actor = $this->requireRole(['admin', 'manager', 'trainer']);
            $result = $this->bookings->editPendingRequest(
                $this->positiveId($_POST['occurrence_id'] ?? null),
                $_POST,
                $actor,
                ($_POST['approve'] ?? '') === '1'
            );
            echo json_encode($result, JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'request-dashboard' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $actor = $this->requireRole(['admin', 'manager', 'trainer']);
            $includeClosedRequests = ($_GET['requests'] ?? '') === 'all';
            $requestPage = filter_var($_GET['requests_page'] ?? 1, FILTER_VALIDATE_INT);
            $requestPage = $requestPage === false || $requestPage < 1 ? 1 : $requestPage;
            $requestListing = $this->bookings->requests($actor, $includeClosedRequests, $requestPage);
            $requestBaseUrl = ($_GET['page'] ?? '') === 'dashboard' ? '/?page=dashboard' : '/';
            echo json_encode([
                'html' => Views::dashboard(
                    $actor,
                    $requestListing['items'],
                    $this->csrf,
                    '',
                    true,
                    $includeClosedRequests,
                    $requestListing['page'],
                    $requestListing['total'],
                    $requestListing['page_count'],
                    $requestListing['page_size'],
                    $requestBaseUrl
                ),
            ], JSON_THROW_ON_ERROR);
            return;
        }

        if ($api === 'request-details' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $actor = $this->requireRole(['admin', 'manager', 'trainer']);
            echo json_encode($this->bookings->editablePendingRequest(
                $this->positiveId($_GET['occurrence_id'] ?? null), $actor
            ), JSON_THROW_ON_ERROR);
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
                $this->redirect('/');

            case 'logout':
                $this->auth->logout();
                $this->redirect('/');

            case 'setup':
                $status = $this->databaseSetup->status();
                $adminExists = $this->initialAdminExists();
                if ($adminExists) {
                    if ($status['schema_complete']) {
                        throw new RuntimeException('Der erste Administrator wurde bereits eingerichtet.');
                    }
                    if ((string) ($_POST['schema_action'] ?? '') !== 'keep') {
                        throw new RuntimeException('Bei einem vorhandenen Administratorkonto dürfen Tabellen nur ergänzt, nicht neu angelegt werden.');
                    }

                    $this->databaseSetup->initialize(false);
                    $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Das Datenbankschema wurde ergänzt. Melde dich mit deinem Administratorkonto an.'];
                    $this->redirect('/?page=login');
                }

                AuthService::normalizeEmail((string) ($_POST['email'] ?? ''));
                AuthService::normalizeUsername((string) ($_POST['username'] ?? ''));
                AuthService::validateFirstName((string) ($_POST['first_name'] ?? ''));
                AuthService::validateLastName((string) ($_POST['last_name'] ?? ''));
                AuthService::validatePassword((string) ($_POST['password'] ?? ''));

                $schemaAction = (string) ($_POST['schema_action'] ?? '');
                if (!in_array($schemaAction, ['keep', 'recreate'], true)) {
                    throw new RuntimeException('Die gewählte Datenbankaktion ist ungültig.');
                }
                if ($schemaAction === 'recreate') {
                    if ($status['existing_table_count'] === 0) {
                        throw new RuntimeException('Es wurden keine vorhandenen Anwendungstabellen gefunden. Bitte lade die Seite neu.');
                    }
                    if ((string) ($_POST['confirm_recreate'] ?? '') !== '1') {
                        throw new RuntimeException('Bitte bestätige den Neuaufbau der Anwendungstabellen.');
                    }
                }
                if (!$status['schema_complete'] || $schemaAction === 'recreate') {
                    $this->databaseSetup->initialize($schemaAction === 'recreate');
                }

                $this->auth->createInitialAdmin(
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['username'] ?? ''),
                    (string) ($_POST['first_name'] ?? ''),
                    (string) ($_POST['last_name'] ?? ''),
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
                    (string) ($_POST['first_name'] ?? ''),
                    (string) ($_POST['last_name'] ?? ''),
                    (string) ($_POST['role'] ?? '')
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Die Einladung wurde versendet.'];
                $this->redirect('/?page=users');

            case 'user-create':
                $this->requireRole(['admin']);
                $this->users->createWithoutEmail(
                    (string) ($_POST['username'] ?? ''),
                    (string) ($_POST['first_name'] ?? ''),
                    (string) ($_POST['last_name'] ?? ''),
                    (string) ($_POST['role'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['password_confirm'] ?? '')
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Der Benutzer ohne E-Mail-Adresse wurde angelegt.'];
                $this->redirect('/?page=users');

            case 'profile-update':
                $actor = $this->requireUser();
                $this->users->updateOwnProfile(
                    (int) $actor['id'],
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['first_name'] ?? ''),
                    (string) ($_POST['last_name'] ?? '')
                );
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Deine Daten wurden gespeichert.'];
                $this->redirect('/?page=profile');

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

            case 'booking-type-save':
                $this->requireRole(['admin']);
                $code = trim((string) ($_POST['code'] ?? ''));
                $trainerRequestable = $_POST['trainer_requestable'] ?? '0';
                if (!is_string($trainerRequestable) || !in_array($trainerRequestable, ['0', '1'], true)) {
                    throw new RuntimeException('Die Trainerfreigabe ist ungültig.');
                }
                $this->bookingTypes->save(
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['color'] ?? ''),
                    $code === '' ? null : $code,
                    $trainerRequestable === '1'
                );
                $_SESSION['_flash'] = [
                    'type' => 'success',
                    'message' => $code === '' ? 'Die Buchungsart wurde angelegt.' : 'Die Buchungsart wurde gespeichert.',
                ];
                $this->redirect('/?page=booking-types');

            case 'booking-type-status':
                $this->requireRole(['admin']);
                $active = (string) ($_POST['active'] ?? '');
                if (!in_array($active, ['0', '1'], true)) {
                    throw new RuntimeException('Der Buchungsartenstatus ist ungültig.');
                }
                $this->bookingTypes->setActive(
                    (string) ($_POST['code'] ?? ''),
                    $active === '1'
                );
                $_SESSION['_flash'] = [
                    'type' => 'success',
                    'message' => $active === '1'
                        ? 'Die Buchungsart wurde aktiviert.'
                        : 'Die Buchungsart wurde archiviert.',
                ];
                $this->redirect('/?page=booking-types');

            case 'booking-type-remove':
                $this->requireRole(['admin']);
                $deleted = $this->bookingTypes->remove((string) ($_POST['code'] ?? ''));
                $_SESSION['_flash'] = [
                    'type' => 'success',
                    'message' => $deleted
                        ? 'Die Buchungsart wurde gelöscht.'
                        : 'Die Buchungsart wird für bestehende Termine archiviert.',
                ];
                $this->redirect('/?page=booking-types');

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
                $result = $this->bookings->approve($this->postedAssignmentIds(), $actor);
                $_SESSION['_flash'] = [
                    'type' => $result['mail_errors'] !== [] ? 'warning' : 'success',
                    'message' => 'Die Anfrage wurde freigegeben.'
                        . ($result['mail_errors'] !== [] ? ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.' : ''),
                ];
                $this->redirect('/?page=dashboard');

            case 'booking-reject':
                $actor = $this->requireRole(['admin', 'manager']);
                $mailErrors = $this->bookings->reject(
                    $this->postedAssignmentIds(),
                    $actor,
                    (string) ($_POST['reason'] ?? '')
                );
                $_SESSION['_flash'] = [
                    'type' => $mailErrors !== [] ? 'warning' : 'success',
                    'message' => 'Die Anfrage wurde abgelehnt.'
                        . ($mailErrors !== [] ? ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.' : ''),
                ];
                $this->redirect('/?page=dashboard');

            case 'booking-delete':
                $actor = $this->requireRole(['trainer']);
                $this->bookings->deletePendingRequests($this->postedAssignmentIds(), $actor);
                $_SESSION['_flash'] = ['type' => 'success', 'message' => 'Die offene Anfrage wurde gelöscht.'];
                $this->redirect('/?page=dashboard');

            case 'request-edit':
                $actor = $this->requireRole(['admin', 'manager', 'trainer']);
                $result = $this->bookings->editPendingRequest(
                    $this->positiveId($_POST['occurrence_id'] ?? null),
                    $_POST,
                    $actor,
                    ($_POST['approve'] ?? '') === '1'
                );
                $message = $result['approved'] > 0
                    ? 'Die Anfrage wurde freigegeben.'
                    : 'Die Anfrage wurde geändert.';
                if ($result['mail_errors'] !== []) {
                    $message .= ' Die Benachrichtigungs-E-Mail konnte nicht versendet werden.';
                }
                $_SESSION['_flash'] = [
                    'type' => $result['mail_errors'] !== [] ? 'warning' : 'success',
                    'message' => $message,
                ];
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
                    ? 'Die Buchung wurde gelöscht.'
                    : $result['cancelled'] . ' Buchungen wurden gelöscht.';
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
                    'message' => 'Die Kabinenbelegung wurde gelöscht.'
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
        $databaseStatus = $this->databaseSetup->status();
        $user = $databaseStatus['schema_complete'] ? $this->auth->currentUser() : null;
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        $content = '';
        $clientData = [];

        switch ($page) {
            case 'calendar':
            case 'dashboard':
                if ($page === 'dashboard') {
                    $user = $this->requireRole(['admin', 'manager', 'trainer']);
                }
                $resources = $this->resources->all();
                $bookingTypes = $this->bookingTypes->all();
                $includeClosedRequests = ($_GET['requests'] ?? '') === 'all';
                $requestPage = filter_var($_GET['requests_page'] ?? 1, FILTER_VALIDATE_INT);
                $requestPage = $requestPage === false || $requestPage < 1 ? 1 : $requestPage;
                $requestListing = $user !== null && in_array($user['role'], ['admin', 'manager', 'trainer'], true)
                    ? $this->bookings->requests($user, $includeClosedRequests, $requestPage)
                    : ['items' => [], 'total' => 0, 'page' => 1, 'page_count' => 1, 'page_size' => 10];
                $requestBaseUrl = $page === 'dashboard' ? '/?page=dashboard' : '/';
                $content = Views::calendar(
                    $user,
                    $this->csrf,
                    $resources,
                    $requestListing['items'],
                    $includeClosedRequests,
                    $requestListing['page'],
                    $requestListing['total'],
                    $requestListing['page_count'],
                    $requestListing['page_size'],
                    $requestBaseUrl,
                    $bookingTypes
                );
                $clientData = [
                    'showAllRequests' => $includeClosedRequests,
                    'bookingTypes' => $bookingTypes,
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
                $adminExists = $this->initialAdminExists();
                if ($databaseStatus['schema_complete'] && $adminExists) {
                    http_response_code(404);
                    $content = '<section class="panel-card p-4"><h1 class="h3">Setup abgeschlossen</h1><p>Der Einrichtungsassistent ist bereits gesperrt.</p></section>';
                } else {
                    $content = Views::setup($this->csrf, '', $databaseStatus, !$adminExists);
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

            case 'users':
                $this->requireRole(['admin']);
                $content = Views::users(
                    $this->users->all(),
                    $this->csrf,
                    '',
                    (string) ($_GET['form'] ?? '')
                );
                break;

            case 'profile':
                $user = $this->requireUser();
                $content = Views::profile($user, $this->csrf);
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

            case 'booking-types':
                $this->requireRole(['admin']);
                $content = Views::bookingTypes($this->bookingTypes->all(), $this->csrf);
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
        if ($this->databaseSetup->hasTable('users')) {
            $adminCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin'"
            )->fetchColumn();
            if ($adminCount > 0) {
                return true;
            }
        }

        if (!$this->databaseSetup->hasTable('app_settings')) {
            return false;
        }
        $statement = $this->pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'initial_admin_created'"
        );
        return $statement->fetchColumn() === '1';
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

    private function postedAssignmentIds(): array
    {
        $values = $_POST['assignment_ids'] ?? [];
        if (!is_array($values)) {
            throw new RuntimeException('Die Auswahl der Buchungsplätze ist ungültig.');
        }
        if ($values === [] && isset($_POST['assignment_id'])) {
            $values[] = $_POST['assignment_id'];
        }

        return array_values(array_unique(array_map(
            fn(mixed $value): int => $this->positiveId($value),
            $values
        )));
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
            'profile' => 'Mein Profil',
            'dashboard' => 'Belegungsplan',
            'users' => 'Benutzerverwaltung',
            'resources' => 'Ressourcen',
            'booking-types' => 'Buchungsarten',
            default => 'Belegungsplan',
        };
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }
}
