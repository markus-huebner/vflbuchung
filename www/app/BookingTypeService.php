<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class BookingTypeService
{
    private const LEGACY_PALETTE = [
        'green' => ['label' => 'Grün', 'hex' => '#27834a'],
        'blue' => ['label' => 'Blau', 'hex' => '#2865a6'],
        'orange' => ['label' => 'Orange', 'hex' => '#bf6a18'],
        'purple' => ['label' => 'Violett', 'hex' => '#7350a0'],
        'teal' => ['label' => 'Türkis', 'hex' => '#187c80'],
        'red' => ['label' => 'Rot', 'hex' => '#b53b38'],
        'indigo' => ['label' => 'Indigo', 'hex' => '#5143a8'],
        'slate' => ['label' => 'Schiefergrau', 'hex' => '#536879'],
        'navy' => ['label' => 'Marineblau', 'hex' => '#1e3a5f'],
        'cyan' => ['label' => 'Dunkelcyan', 'hex' => '#006b78'],
        'emerald' => ['label' => 'Smaragdgrün', 'hex' => '#087a53'],
        'lime' => ['label' => 'Lindgrün', 'hex' => '#537a16'],
        'olive' => ['label' => 'Olivgrün', 'hex' => '#65722a'],
        'amber' => ['label' => 'Bernstein', 'hex' => '#8a5a00'],
        'gold' => ['label' => 'Gold', 'hex' => '#916c00'],
        'rust' => ['label' => 'Rostrot', 'hex' => '#99420f'],
        'coral' => ['label' => 'Koralle', 'hex' => '#a93c3c'],
        'crimson' => ['label' => 'Karmesinrot', 'hex' => '#922b3e'],
        'magenta' => ['label' => 'Magenta', 'hex' => '#96325f'],
        'pink' => ['label' => 'Pink', 'hex' => '#9d356f'],
        'plum' => ['label' => 'Pflaume', 'hex' => '#66336f'],
        'violet' => ['label' => 'Dunkelviolett', 'hex' => '#5b3e91'],
        'royal' => ['label' => 'Königsblau', 'hex' => '#344f9b'],
        'steel' => ['label' => 'Stahlblau', 'hex' => '#3e586d'],
        'brown' => ['label' => 'Braun', 'hex' => '#6b4932'],
        'charcoal' => ['label' => 'Anthrazit', 'hex' => '#3b4650'],
        'forest' => ['label' => 'Waldgrün', 'hex' => '#205b3b'],
        'moss' => ['label' => 'Moosgrün', 'hex' => '#4a6535'],
        'mint' => ['label' => 'Dunkelmint', 'hex' => '#146c61'],
        'turquoise' => ['label' => 'Petrol', 'hex' => '#08737c'],
        'cobalt' => ['label' => 'Kobaltblau', 'hex' => '#2454a2'],
        'brick' => ['label' => 'Ziegelrot', 'hex' => '#873b32'],
        'yellow' => ['label' => 'Gelb', 'hex' => '#f4d03f'],
        'lemon' => ['label' => 'Zitronengelb', 'hex' => '#e8e66a'],
        'pale-yellow' => ['label' => 'Pastellgelb', 'hex' => '#f9e79f'],
        'sunflower' => ['label' => 'Sonnengelb', 'hex' => '#f1c40f'],
        'apricot' => ['label' => 'Aprikose', 'hex' => '#f5b041'],
        'tangerine' => ['label' => 'Mandarine', 'hex' => '#f39c12'],
        'peach' => ['label' => 'Pfirsich', 'hex' => '#f5cba7'],
        'salmon' => ['label' => 'Lachs', 'hex' => '#ec7063'],
        'light-coral' => ['label' => 'Helles Koralle', 'hex' => '#f1948a'],
        'rose' => ['label' => 'Rosé', 'hex' => '#e8a0bf'],
        'blush' => ['label' => 'Zartrosa', 'hex' => '#f5b7d2'],
        'lavender' => ['label' => 'Lavendel', 'hex' => '#c39bd3'],
        'lilac' => ['label' => 'Flieder', 'hex' => '#d7bde2'],
        'mauve' => ['label' => 'Mauve', 'hex' => '#bb8fce'],
        'periwinkle' => ['label' => 'Hellindigo', 'hex' => '#aeb6e8'],
        'sky' => ['label' => 'Himmelblau', 'hex' => '#85c1e9'],
        'powder-blue' => ['label' => 'Puderblau', 'hex' => '#aed6f1'],
        'ice-blue' => ['label' => 'Eisblau', 'hex' => '#d6eaf8'],
        'aqua' => ['label' => 'Aqua', 'hex' => '#76d7c4'],
        'light-turquoise' => ['label' => 'Helltürkis', 'hex' => '#48c9b0'],
        'mint-light' => ['label' => 'Mint', 'hex' => '#a3e4d7'],
        'light-green' => ['label' => 'Hellgrün', 'hex' => '#7dcea0'],
        'pale-green' => ['label' => 'Pastellgrün', 'hex' => '#abebc6'],
        'spring-green' => ['label' => 'Frühlingsgrün', 'hex' => '#a3e635'],
        'chartreuse' => ['label' => 'Chartreuse', 'hex' => '#c5e85c'],
        'yellow-green' => ['label' => 'Gelbgrün', 'hex' => '#b6d957'],
        'sand' => ['label' => 'Sand', 'hex' => '#e9c46a'],
        'beige' => ['label' => 'Beige', 'hex' => '#d7c29e'],
        'tan' => ['label' => 'Hellbraun', 'hex' => '#d2b48c'],
        'silver' => ['label' => 'Silber', 'hex' => '#d5d8dc'],
        'light-gray' => ['label' => 'Hellgrau', 'hex' => '#bdc3c7'],
        'blue-gray' => ['label' => 'Blaugrau', 'hex' => '#afbdc9'],
    ];

    private const COLOR_FAMILIES = [
        'red' => [
            'label' => ['Dunkelrot', 'Rot', 'Hellrot', 'Pastellrot'],
            'hue' => 0,
            'saturation' => 72,
            'lightness' => [29, 45, 62, 80],
        ],
        'brown' => [
            'label' => ['Dunkelbraun', 'Braun', 'Hellbraun', 'Pastellbraun'],
            'hue' => 25,
            'saturation' => 58,
            'lightness' => [25, 39, 55, 75],
        ],
        'orange' => [
            'label' => ['Dunkelorange', 'Orange', 'Hellorange', 'Pastellorange'],
            'hue' => 32,
            'saturation' => 88,
            'lightness' => [29, 45, 62, 80],
        ],
        'yellow' => [
            'label' => ['Dunkelgelb', 'Gelb', 'Hellgelb', 'Pastellgelb'],
            'hue' => 53,
            'saturation' => 90,
            'lightness' => [29, 45, 62, 80],
        ],
        'green' => [
            'label' => ['Dunkelgrün', 'Grün', 'Hellgrün', 'Pastellgrün'],
            'hue' => 125,
            'saturation' => 58,
            'lightness' => [29, 45, 62, 80],
        ],
        'turquoise' => [
            'label' => ['Dunkeltürkis', 'Türkis', 'Helltürkis', 'Pastelltürkis'],
            'hue' => 175,
            'saturation' => 62,
            'lightness' => [29, 45, 62, 80],
        ],
        'blue' => [
            'label' => ['Dunkelblau', 'Blau', 'Hellblau', 'Pastellblau'],
            'hue' => 215,
            'saturation' => 72,
            'lightness' => [29, 45, 62, 80],
        ],
        'violet' => [
            'label' => ['Dunkelviolett', 'Violett', 'Hellviolett', 'Pastellviolett'],
            'hue' => 270,
            'saturation' => 58,
            'lightness' => [29, 45, 62, 80],
        ],
        'magenta' => [
            'label' => ['Dunkelmagenta', 'Magenta', 'Hellmagenta', 'Pastellmagenta'],
            'hue' => 320,
            'saturation' => 62,
            'lightness' => [29, 45, 62, 80],
        ],
        'black' => [
            'label' => ['Schwarz', 'Anthrazit', 'Grau', 'Hellgrau'],
            'hue' => 0,
            'saturation' => 0,
            'lightness' => [0, 25, 55, 82],
        ],
    ];

    private const COLOR_LEVELS = ['dark', 'medium', 'light', 'pale'];

    private const DEFAULTS = [
        ['code' => 'training', 'name' => 'Training', 'color' => 'green-medium', 'sort_order' => 10, 'trainer_requestable' => true],
        ['code' => 'match', 'name' => 'Spiel', 'color' => 'blue-medium', 'sort_order' => 20, 'trainer_requestable' => true],
        ['code' => 'external', 'name' => 'Externe Belegung', 'color' => 'orange-medium', 'sort_order' => 30, 'trainer_requestable' => false],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function palette(): array
    {
        $ordered = [];
        foreach (self::COLOR_LEVELS as $levelIndex => $level) {
            foreach (self::COLOR_FAMILIES as $familyCode => $family) {
                $hex = self::hslToHex(
                    (float) $family['hue'],
                    (float) $family['saturation'],
                    (float) $family['lightness'][$levelIndex]
                );
                $ordered[$familyCode . '-' . $level] = [
                    'label' => $family['label'][$levelIndex],
                    'hex' => $hex,
                    'text_hex' => self::textColor($hex),
                ];
            }
        }
        return $ordered;
    }

    public static function defaults(): array
    {
        $palette = self::palette();
        return array_map(
            static fn(array $type): array => $type + [
                'is_active' => true,
                'color_hex' => $palette[$type['color']]['hex'],
                'color_label' => $palette[$type['color']]['label'],
                'text_hex' => $palette[$type['color']]['text_hex'],
            ],
            self::DEFAULTS
        );
    }

    public function all(bool $includeInactive = true): array
    {
        $sql = 'SELECT code, name, color, sort_order, is_active, trainer_requestable FROM booking_types';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, name';
        $types = $this->pdo->query($sql)->fetchAll();
        $palette = self::palette();

        return array_map(static function (array $type) use ($palette): array {
            $type['color'] = self::normalizeColorCode((string) $type['color'], $palette) ?? 'black-light';
            $color = $palette[$type['color']];
            $type['is_active'] = (bool) $type['is_active'];
            $type['trainer_requestable'] = (bool) $type['trainer_requestable'];
            $type['color_hex'] = $color['hex'];
            $type['color_label'] = $color['label'];
            $type['text_hex'] = self::textColor($color['hex']);
            return $type;
        }, $types);
    }

    private static function textColor(string $hex): string
    {
        return self::luminance($hex) > 0.179 ? '#000000' : '#ffffff';
    }

    private static function luminance(string $hex): float
    {
        $channels = self::rgb($hex);
        $linear = array_map(
            static fn(float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels
        );
        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    private static function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
    }

    private static function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $saturation /= 100;
        $lightness /= 100;
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - $chroma / 2;
        [$red, $green, $blue] = match (true) {
            $hue < 60 => [$chroma, $x, 0],
            $hue < 120 => [$x, $chroma, 0],
            $hue < 180 => [0, $chroma, $x],
            $hue < 240 => [0, $x, $chroma],
            $hue < 300 => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($red + $match) * 255),
            (int) round(($green + $match) * 255),
            (int) round(($blue + $match) * 255)
        );
    }

    private static function normalizeColorCode(string $code, array $palette): ?string
    {
        if (isset($palette[$code])) {
            return $code;
        }

        $legacy = self::LEGACY_PALETTE[$code] ?? null;
        if ($legacy === null) {
            return null;
        }

        $target = self::rgb($legacy['hex']);
        $closestCode = null;
        $closestDistance = INF;
        foreach ($palette as $candidateCode => $candidate) {
            $channels = self::rgb($candidate['hex']);
            $distance = ($target[0] - $channels[0]) ** 2
                + ($target[1] - $channels[1]) ** 2
                + ($target[2] - $channels[2]) ** 2;
            if ($distance < $closestDistance) {
                $closestCode = $candidateCode;
                $closestDistance = $distance;
            }
        }

        return $closestCode;
    }

    public function save(
        string $name,
        string $color,
        ?string $code = null,
        bool $trainerRequestable = true
    ): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Der Name muss zwischen 1 und 80 Zeichen lang sein.');
        }
        $color = self::normalizeColorCode($color, self::palette()) ?? '';
        if ($color === '') {
            throw new RuntimeException('Die gewählte Kalenderfarbe ist ungültig.');
        }

        if ($code === null || $code === '') {
            $code = $this->newCode($name);
            $sortOrder = (int) $this->pdo->query(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM booking_types'
            )->fetchColumn();
            $statement = $this->pdo->prepare(
                'INSERT INTO booking_types (code, name, color, sort_order, is_active, trainer_requestable)
                 VALUES (:code, :name, :color, :sort_order, 1, :trainer_requestable)'
            );
            $statement->execute([
                'code' => $code,
                'name' => $name,
                'color' => $color,
                'sort_order' => $sortOrder,
                'trainer_requestable' => $trainerRequestable ? 1 : 0,
            ]);
            return $code;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $code) || $code === 'bus') {
            throw new RuntimeException('Die Buchungsart wurde nicht gefunden.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE booking_types
             SET name = :name, color = :color, trainer_requestable = :trainer_requestable
             WHERE code = :code'
        );
        $statement->execute([
            'name' => $name,
            'color' => $color,
            'trainer_requestable' => $trainerRequestable ? 1 : 0,
            'code' => $code,
        ]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT 1 FROM booking_types WHERE code = :code');
            $exists->execute(['code' => $code]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('Die Buchungsart wurde nicht gefunden.');
            }
        }

        return $code;
    }

    public function setActive(string $code, bool $active): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $code) || $code === 'bus') {
            throw new RuntimeException('Die Buchungsart wurde nicht gefunden.');
        }

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                'SELECT is_active FROM booking_types WHERE code = :code FOR UPDATE'
            );
            $lookup->execute(['code' => $code]);
            $current = $lookup->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Die Buchungsart wurde nicht gefunden.');
            }
            if (!$active && (bool) $current) {
                $activeCount = (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM booking_types WHERE is_active = 1'
                )->fetchColumn();
                if ($activeCount <= 1) {
                    throw new RuntimeException('Mindestens eine Buchungsart muss aktiv bleiben.');
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE booking_types SET is_active = :is_active WHERE code = :code'
            );
            $update->execute(['is_active' => $active ? 1 : 0, 'code' => $code]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function remove(string $code): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $code) || $code === 'bus') {
            throw new RuntimeException('Die Buchungsart wurde nicht gefunden.');
        }

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare(
                'SELECT is_active FROM booking_types WHERE code = :code FOR UPDATE'
            );
            $lookup->execute(['code' => $code]);
            $current = $lookup->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Die Buchungsart wurde nicht gefunden.');
            }

            $usage = $this->pdo->prepare(
                'SELECT 1 FROM booking_occurrences WHERE event_type = :code LIMIT 1'
            );
            $usage->execute(['code' => $code]);
            $isUsed = $usage->fetchColumn() !== false;
            if ($isUsed) {
                if ((bool) $current) {
                    $activeCount = (int) $this->pdo->query(
                        'SELECT COUNT(*) FROM booking_types WHERE is_active = 1'
                    )->fetchColumn();
                    if ($activeCount <= 1) {
                        throw new RuntimeException('Mindestens eine Buchungsart muss aktiv bleiben.');
                    }
                    $archive = $this->pdo->prepare(
                        'UPDATE booking_types SET is_active = 0 WHERE code = :code'
                    );
                    $archive->execute(['code' => $code]);
                }
                $this->pdo->commit();
                return false;
            }

            if ((bool) $current) {
                $activeCount = (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM booking_types WHERE is_active = 1'
                )->fetchColumn();
                if ($activeCount <= 1) {
                    throw new RuntimeException('Mindestens eine Buchungsart muss aktiv bleiben.');
                }
            }

            $delete = $this->pdo->prepare('DELETE FROM booking_types WHERE code = :code');
            $delete->execute(['code' => $code]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function isActive(string $code): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $code)) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT is_active FROM booking_types WHERE code = :code'
        );
        $statement->execute(['code' => $code]);
        $active = $statement->fetchColumn();
        return $active !== false && (int) $active === 1;
    }

    public function isTrainerRequestable(string $code): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $code)) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT trainer_requestable FROM booking_types WHERE code = :code'
        );
        $statement->execute(['code' => $code]);
        $requestable = $statement->fetchColumn();

        return $requestable !== false && (int) $requestable === 1;
    }

    private function newCode(string $name): string
    {
        $ascii = strtr(mb_strtolower($name, 'UTF-8'), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        $base = 'custom-' . substr($slug === '' ? 'typ' : $slug, 0, 31);
        $exists = $this->pdo->prepare('SELECT 1 FROM booking_types WHERE code = :code');
        for ($suffix = 1; ; $suffix++) {
            $candidate = $suffix === 1 ? $base : substr($base, 0, 37 - strlen((string) $suffix))
                . '-' . $suffix;
            $exists->execute(['code' => $candidate]);
            if ($exists->fetchColumn() === false) {
                return $candidate;
            }
        }
    }
}
