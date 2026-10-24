<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Recurrence
{
    private const MAX_OCCURRENCES = 5000;
    private const ZONE = 'Europe/Berlin';

    public static function parseLocalDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone(self::ZONE));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Bitte gib ein gültiges Datum und eine gültige Uhrzeit an.');
        }

        return $date;
    }

    public static function parseDateTime(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone(self::ZONE));
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Bitte gib ein gültiges Datum und eine gültige Uhrzeit an.', 0, $exception);
        }
    }

    public static function toDatabase(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function fromDatabase(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function generate(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?array $rule
    ): array {
        if ($end <= $start) {
            throw new InvalidArgumentException('Das Ende muss nach dem Beginn liegen.');
        }

        if ($rule === null || ($rule['frequency'] ?? 'none') === 'none') {
            return [['start' => $start, 'end' => $end]];
        }

        $frequency = strtolower((string) ($rule['frequency'] ?? ''));
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Die Wiederholungsart ist ungültig.');
        }

        $interval = filter_var($rule['interval'] ?? 1, FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 1 || $interval > 52) {
            throw new InvalidArgumentException('Das Wiederholungsintervall muss zwischen 1 und 52 liegen.');
        }

        $limitType = $rule['limit_type'] ?? '';
        $count = null;
        $until = null;
        if ($limitType === 'count') {
            $count = filter_var($rule['count'] ?? null, FILTER_VALIDATE_INT);
            if ($count === false || $count < 1 || $count > self::MAX_OCCURRENCES) {
                throw new InvalidArgumentException('Eine Serie muss zwischen 1 und 5000 Termine enthalten.');
            }
        } elseif ($limitType === 'until') {
            $untilValue = (string) ($rule['until'] ?? '');
            $until = DateTimeImmutable::createFromFormat('!Y-m-d', $untilValue, $start->getTimezone());
            $errors = DateTimeImmutable::getLastErrors();
            if ($until === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new InvalidArgumentException('Bitte gib ein gültiges Serien-Enddatum an.');
            }
            if ($until < $start->setTime(0, 0)) {
                throw new InvalidArgumentException('Das Serien-Enddatum liegt vor dem ersten Termin.');
            }
        } else {
            throw new InvalidArgumentException('Bitte begrenze die Serie mit einem Enddatum oder einer Anzahl.');
        }

        $weekdays = [];
        if ($frequency === 'weekly') {
            $allowed = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
            $weekdays = array_values(array_unique(array_intersect(
                $allowed,
                array_map('strtoupper', $rule['weekdays'] ?? [])
            )));
            if ($weekdays === []) {
                $weekdays[] = self::weekdayCode((int) $start->format('N'));
            }
        }

        $results = [];
        $startWeek = $start->modify('monday this week')->setTime(0, 0);
        $duration = $end->getTimestamp() - $start->getTimestamp();

        for ($days = 0; $days <= 366 * 100 && count($results) < self::MAX_OCCURRENCES; $days++) {
            $candidate = $start->modify("+{$days} days");
            if ($until !== null && $candidate->setTime(0, 0) > $until) {
                break;
            }

            if (self::isOccurrence($candidate, $start, $startWeek, $frequency, $interval, $weekdays)) {
                $results[] = [
                    'start' => $candidate,
                    'end' => $candidate->modify('+' . $duration . ' seconds'),
                ];
                if ($count !== null && count($results) >= $count) {
                    break;
                }
            }

        }

        if ($results === []) {
            throw new InvalidArgumentException('Für diese Wiederholungsregel wurden keine Termine erzeugt.');
        }
        if ($count !== null && count($results) !== $count) {
            throw new InvalidArgumentException('Die Serie überschreitet den unterstützten Zeitraum von 100 Jahren.');
        }
        if ($count === null && count($results) >= self::MAX_OCCURRENCES) {
            throw new InvalidArgumentException('Die Serie enthält mehr als 5000 Termine.');
        }

        return $results;
    }

    private static function isOccurrence(
        DateTimeImmutable $candidate,
        DateTimeImmutable $start,
        DateTimeImmutable $startWeek,
        string $frequency,
        int $interval,
        array $weekdays
    ): bool {
        if ($candidate == $start) {
            return true;
        }

        return match ($frequency) {
            'daily' => ((int) $start->diff($candidate)->format('%a')) % $interval === 0,
            'weekly' => self::isWeeklyOccurrence($candidate, $startWeek, $interval, $weekdays),
            'monthly' => self::isMonthlyOccurrence($candidate, $start, $interval),
            'yearly' => self::isYearlyOccurrence($candidate, $start, $interval),
        };
    }

    private static function isWeeklyOccurrence(
        DateTimeImmutable $candidate,
        DateTimeImmutable $startWeek,
        int $interval,
        array $weekdays
    ): bool {
        $candidateWeek = $candidate->modify('monday this week')->setTime(0, 0);
        $days = (int) $startWeek->diff($candidateWeek)->format('%a');
        $weeks = intdiv($days, 7);

        return $weeks >= 0
            && $weeks % $interval === 0
            && in_array(self::weekdayCode((int) $candidate->format('N')), $weekdays, true);
    }

    private static function isMonthlyOccurrence(
        DateTimeImmutable $candidate,
        DateTimeImmutable $start,
        int $interval
    ): bool {
        $monthDifference = ((int) $candidate->format('Y') - (int) $start->format('Y')) * 12
            + (int) $candidate->format('n') - (int) $start->format('n');

        return $monthDifference > 0
            && $monthDifference % $interval === 0
            && $candidate->format('j') === $start->format('j');
    }

    private static function isYearlyOccurrence(
        DateTimeImmutable $candidate,
        DateTimeImmutable $start,
        int $interval
    ): bool {
        $yearDifference = (int) $candidate->format('Y') - (int) $start->format('Y');

        return $yearDifference > 0
            && $yearDifference % $interval === 0
            && $candidate->format('m-d') === $start->format('m-d');
    }

    private static function weekdayCode(int $weekday): string
    {
        return ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'][$weekday - 1];
    }
}
