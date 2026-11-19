<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\LocalProjection;
use App\SpongeCity\Geo\UtmPlanTransform;

/**
 * Reads what a SimStadt run printed and saved: its summary (the totals simstadtpy prints
 * as "  Key : value unit") and the result table, condensed to one row per building with
 * its position in plan millimetres, so the agent can tell which building is which.
 */
final readonly class SimStadtResults
{
    private const string SUMMARY_LINE = '/^\s{2}(\S.*?)\s*:\s*(\S.*)$/';
    private const string OUTPUT_FILES = 'Output files:';
    private const int MAX_BUILDINGS = 40;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    /**
     * @return array<string, string> e.g. "Yearly Heating demand" → "7205388 kWh / a"
     */
    public function summary(string $output): array
    {
        $summary = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with(trim($line), self::OUTPUT_FILES)) {
                break;
            }
            if (1 === preg_match(self::SUMMARY_LINE, $line, $m) && !str_contains($m[1], '/')) {
                $summary[$m[1]] = trim($m[2]);
            }
        }

        return $summary;
    }

    /**
     * @return list<array<string, int|float|string|null>> one row per building, largest first
     */
    public function buildings(string $csv, SimStadtTemplate $template, GeoPoint $centre): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', $csv) ?: [], static fn (string $l): bool => '' !== trim($l)));
        if (count($lines) < 2) {
            return [];
        }
        $header = str_getcsv(array_shift($lines), escape: '');
        $projection = new LocalProjection($centre);
        $transform = new UtmPlanTransform($centre);
        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line, escape: '');
            if (count($values) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $values);
            $id = $row[$template->idColumn()] ?? '';
            if ('' === $id) {
                continue;
            }
            $building = $rows[$id] ?? ['id' => $id, '_positions' => []];
            foreach ($template->columns() as $column => $key) {
                $value = $row[$column] ?? null;
                if (null === $value || '' === $value) {
                    continue;
                }
                if (is_numeric($value)) {
                    // roof surfaces of one building add up; a building's own row is taken as is
                    $building[$key] = $template->perSurface() ? ($building[$key] ?? 0) + (float) $value : (float) $value;
                } else {
                    $building[$key] ??= $value;
                }
            }
            $building['_positions'][] = self::position($row, $projection, $transform);
            $rows[$id] = $building;
        }

        $buildings = array_map(static function (array $b): array {
            $positions = array_filter($b['_positions']);
            unset($b['_positions']);
            if ([] !== $positions) {
                $b['x'] = (int) round(array_sum(array_column($positions, 0)) / count($positions) * self::MILLIMETRES_PER_METRE);
                $b['z'] = (int) round(array_sum(array_column($positions, 1)) / count($positions) * self::MILLIMETRES_PER_METRE);
            }

            return array_map(static fn (mixed $v): mixed => is_float($v) ? round($v, 1) : $v, $b);
        }, array_values($rows));
        $sortBy = $template->sortBy();
        usort($buildings, static fn (array $a, array $b): int => ($b[$sortBy] ?? 0) <=> ($a[$sortBy] ?? 0));

        return array_slice($buildings, 0, self::MAX_BUILDINGS);
    }

    /**
     * @param array<string, string> $row
     *
     * @return array{0: float, 1: float}|null plan metres
     */
    private static function position(array $row, LocalProjection $projection, UtmPlanTransform $transform): ?array
    {
        if (is_numeric($row['Latitude'] ?? null) && is_numeric($row['Longitude'] ?? null)) {
            [$x, $z] = $projection->toPlan((float) $row['Latitude'], (float) $row['Longitude']);

            return [$x / self::MILLIMETRES_PER_METRE, $z / self::MILLIMETRES_PER_METRE];
        }
        if (is_numeric($row['X-Coordinate'] ?? null) && is_numeric($row['Y-Coordinate'] ?? null)) {
            return $transform->toPlan((float) $row['X-Coordinate'], (float) $row['Y-Coordinate']);
        }

        return null;
    }
}
