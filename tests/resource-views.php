<?php
declare(strict_types=1);

require dirname(__DIR__) . '/www/app/Security.php';
require dirname(__DIR__) . '/www/app/Views.php';

use App\Views;

function checkResourceView(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$categories = [
    ['code' => 'pitch', 'name' => 'Platz'],
    ['code' => 'bus', 'name' => 'Fahrzeug'],
    ['code' => 'cabin', 'name' => 'Kabine'],
];
$resources = [
    ['id' => 1, 'type' => 'pitch', 'name' => 'Platz A', 'is_active' => 1],
    ['id' => 2, 'type' => 'bus', 'name' => 'Bus A', 'is_active' => 0],
];
$html = Views::resources($resources, $categories, 'csrf-token');

foreach (['pitch' => 'Plätze', 'bus' => 'Fahrzeuge', 'cabin' => 'Kabinen'] as $code => $heading) {
    checkResourceView(
        preg_match('~<section[^>]*data-resource-order-area[^>]*><h2[^>]*>' . $heading . '</h2>.*?'
            . '<tbody data-resource-order-group="' . $code . '" data-csrf-token="csrf-token">(.*?)</tbody>~s', $html, $matches) === 1,
        "Missing separate section and ordering group for $heading."
    );
    if ($code === 'pitch') {
        checkResourceView(str_contains($matches[1], 'Platz A') && !str_contains($matches[1], 'Bus A'), 'Pitch section has incorrect resources.');
        checkResourceView(str_contains($matches[1], 'data-resource-edit') && str_contains($matches[1], 'data-resource-delete'), 'Resource actions are missing.');
    } elseif ($code === 'bus') {
        checkResourceView(str_contains($matches[1], 'Bus A') && str_contains($matches[1], 'Deaktiviert'), 'Vehicle section has incorrect resources.');
    } else {
        checkResourceView(str_contains($matches[1], 'noch keine Ressourcen'), 'Empty categories need a message.');
    }
}

checkResourceView(
    str_contains($html, 'href="/?page=resources&amp;form=create" aria-label="Ressource anlegen"')
        && str_contains($html, '<span aria-hidden="true">+</span>'),
    'The accessible plus button must link directly to resource creation.'
);
checkResourceView(!str_contains($html, 'Ressourcenaktionen') && !str_contains($html, 'resource-category-filter'), 'The old menu and filter must be removed.');
checkResourceView(str_contains($html, 'id="resource-edit-dialog"') && str_contains($html, 'id="resource-delete-dialog"'), 'Resource dialogs must remain available.');

$create = Views::resources([], $categories, 'csrf-token', '', 'create');
checkResourceView(str_contains($create, '<h2 class="h5 mb-0">Ressource anlegen</h2>'), 'The general creation form needs a generic heading.');
foreach ($categories as $category) {
    checkResourceView(str_contains($create, '<option value="' . $category['code'] . '">' . $category['name'] . '</option>'), 'The creation form must retain every category.');
}

foreach (['pitch' => ['Plätze', 'Platz anlegen'], 'bus' => ['Fahrzeuge', 'Fahrzeug anlegen'], 'cabin' => ['Kabinen', 'Kabine anlegen']] as $code => [$heading, $createLabel]) {
    $section = Views::resources($resources, $categories, 'csrf-token', '', '', $code);
    checkResourceView(str_contains($section, '<h1 class="h3 mb-0">' . $heading . '</h1>'), 'The category page must have the correct heading.');
    checkResourceView(substr_count($section, 'data-resource-order-group=') === 1 && str_contains($section, 'data-resource-order-group="' . $code . '"'), 'Only the selected category must be shown.');
    checkResourceView(str_contains($section, 'href="/?page=resources&amp;category=' . $code . '&amp;form=create"'), 'The plus button must preserve the category.');
    checkResourceView(str_contains($section, 'aria-label="' . $createLabel . '" title="' . $createLabel . '"'), 'The plus button must use the category-specific label.');
    checkResourceView(substr_count($section, 'name="return_category" value="' . $code . '"') >= 2, 'Actions must preserve the category.');
    $categoryCreate = Views::resources($resources, $categories, 'csrf-token', '', 'create', $code);
    checkResourceView(str_contains($categoryCreate, '<h2 class="h5 mb-0">' . $createLabel . '</h2>'), 'The creation form must have a category-specific heading.');
    checkResourceView(str_contains($categoryCreate, 'name="type" value="' . $code . '"'), 'Creation must use the selected category.');
    checkResourceView(str_contains($categoryCreate, 'href="/?page=resources&amp;category=' . $code . '"'), 'Back must return to the selected category.');
}

echo "Resource view checks passed.\n";
