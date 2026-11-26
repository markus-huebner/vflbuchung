<?php
declare(strict_types=1);

require dirname(__DIR__) . '/www/app/Security.php';
require dirname(__DIR__) . '/www/app/AuthService.php';
require dirname(__DIR__) . '/www/app/Views.php';

use App\Views;

function checkRegistrationView(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$requests = [
    ['id' => 17, 'first_name' => 'Anna', 'last_name' => '<Test>', 'email' => 'anna@example.test',
        'username' => 'anna', 'requested_role' => 'manager'],
    ['id' => 18, 'first_name' => 'Ben', 'last_name' => '', 'email' => 'ben@example.test',
        'username' => 'ben', 'requested_role' => 'trainer'],
];

$html = Views::registrations($requests, 'csrf-token', 'manager');
checkRegistrationView(substr_count($html, '<table ') === 1, 'All registrations must share one table for aligned columns.');
checkRegistrationView(str_contains($html, '<div class="table-responsive">'), 'The table must remain usable on narrow screens.');
foreach (['Vorname', 'Nachname', 'E-Mail', 'Benutzername', 'Benutzerrolle', 'Aktionen'] as $label) {
    checkRegistrationView(substr_count($html, '<th scope="col">' . $label . '</th>') === 1, "$label must head a shared column.");
}
checkRegistrationView(str_contains($html, '<tr><td data-label="Vorname">Anna</td><td data-label="Nachname">&lt;Test&gt;</td><td data-label="E-Mail">anna@example.test</td><td data-label="Benutzername">anna</td>'), 'First registration must appear in one row with mobile labels.');
checkRegistrationView(str_contains($html, '<tr><td data-label="Vorname">Ben</td><td data-label="Nachname"></td><td data-label="E-Mail">ben@example.test</td><td data-label="Benutzername">ben</td>'), 'Second registration must appear in one row with mobile labels.');
checkRegistrationView(str_contains($html, '&lt;Test&gt;') && !str_contains($html, '<Test>'), 'Registration data must be escaped.');
checkRegistrationView(!str_contains($html, 'Beantragt:'), 'The requested role must not be displayed.');
checkRegistrationView(!str_contains($html, '<option value="admin"'), 'Managers must not be able to select the admin role.');
foreach ([17, 18] as $id) {
    checkRegistrationView(
        str_contains($html, 'name="role" form="registration-approve-' . $id . '"')
            && str_contains($html, 'action="/?action=registration-approve" id="registration-approve-' . $id . '"'),
        'The role selection must belong to the correct approval form.'
    );
}
checkRegistrationView(str_contains($html, '<option value="manager" selected>Verwalter</option>'), 'The original role selection must be preserved.');
checkRegistrationView(substr_count($html, '</select></td><td class="registration-actions-cell" data-label="Aktionen"><div class="registration-actions"><form') === 2, 'The role selection and actions must be in separate cells.');
checkRegistrationView(str_contains($html, '<label class="visually-hidden" for="registration-role-17">Benutzerrolle für anna</label>'), 'Each role selector must have an accessible label identifying the registration.');
checkRegistrationView(substr_count($html, 'class="btn btn-success" type="submit">Freigeben') === 2, 'Approval must be green.');
checkRegistrationView(substr_count($html, 'class="btn btn-outline-danger" type="submit">Ablehnen') === 2, 'Rejection must be red.');
checkRegistrationView(substr_count($html, 'name="_csrf" value="csrf-token"') === 4, 'Both forms must retain CSRF protection.');
checkRegistrationView(str_contains(Views::registrations($requests, 'csrf-token', 'admin'), '<option value="admin">Admin</option>'), 'Admins must be able to select the admin role.');
checkRegistrationView(str_contains(Views::registrations([], 'csrf-token', 'admin'), 'Keine offenen Registrierungen.'), 'The empty state must remain available.');

echo "Registration view checks passed.\n";
