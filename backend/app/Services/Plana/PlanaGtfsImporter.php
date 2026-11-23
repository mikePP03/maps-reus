<?php

namespace App\Services\Plana;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Importa del GTFS de autobuses interurbanos de la Generalitat las líneas de una zona (todas las
 * que tienen al menos una parada dentro del rectángulo; se importan enteras, aunque salgan de él)
 * y, opcionalmente, solo de los operadores cuyo nombre contiene un texto. Lee el ZIP en streaming
 * con phar:// (sin la extensión zip de PHP) y reemplaza de una vez el contenido de las tablas plana_*.
 */
class PlanaGtfsImporter
{
    /** Distancia máxima en línea recta para proponer un transbordo a pie entre paradas. */
    private const FOOTPATH_METERS = 350;

    private const CHUNK = 500;

    /**
     * @param  string|null  $agencyPattern  texto del nombre del operador; null o '' = todos
     * @param  array{0:float,1:float,2:float,3:float}|null  $area  [latMín, latMáx, lngMín, lngMáx]; null = sin límite
     * @return array<string,int|string|null>
     */
    public function import(string $zipPath, ?string $agencyPattern = null, ?array $area = null, ?string $sourceUrl = null): array
    {
        if (! is_file($zipPath)) {
            throw new InvalidArgumentException("No existe el archivo $zipPath.");
        }
        $base = 'phar://'.str_replace('\\', '/', realpath($zipPath));

        $agencies = [];
        foreach ($this->rows("$base/agency.txt") as $agency) {
            if (! $agencyPattern || stripos($agency['agency_name'], $agencyPattern) !== false) {
                $agencies[$agency['agency_id']] = $agency['agency_name'];
            }
        }
        if ($agencies === []) {
            throw new InvalidArgumentException("Ningún operador del GTFS contiene «{$agencyPattern}».");
        }

        $inArea = $area ? $this->routesInArea($base, $area) : null;

        $routes = [];
        foreach ($this->rows("$base/routes.txt") as $route) {
            if (isset($agencies[$route['agency_id'] ?? '']) && ($inArea === null || isset($inArea[$route['route_id']]))) {
                $routes[$route['route_id']] = [
                    'id' => $route['route_id'],
                    'agency_name' => $agencies[$route['agency_id']],
                    'short_name' => trim($route['route_short_name'] ?? '') ?: $route['route_id'],
                    'long_name' => trim($route['route_long_name'] ?? ''),
                    'color' => $this->color($route['route_color'] ?? null),
                    'text_color' => $this->color($route['route_text_color'] ?? null),
                ];
            }
        }

        $notes = [];
        if ($this->exists("$base/nota.txt")) {
            foreach ($this->rows("$base/nota.txt") as $note) {
                $text = $this->utf8(trim($note['nota'] ?? ''));
                if ($text !== '') {
                    $notes[$note['trip_id']] = $text;
                }
            }
        }

        $trips = [];
        foreach ($this->rows("$base/trips.txt") as $trip) {
            if (isset($routes[$trip['route_id']])) {
                $trips[$trip['trip_id']] = [
                    'id' => $trip['trip_id'],
                    'route_id' => $trip['route_id'],
                    'service_id' => $trip['service_id'],
                    'headsign' => trim($trip['trip_headsign'] ?? '') ?: null,
                    'direction_id' => ($trip['direction_id'] ?? '') === '' ? null : (int) $trip['direction_id'],
                    'shape_id' => trim($trip['shape_id'] ?? '') ?: null,
                    'note' => $notes[$trip['trip_id']] ?? null,
                ];
            }
        }

        $stopTimes = [];
        $stopIds = [];
        foreach ($this->rows("$base/stop_times.txt") as $time) {
            if (! isset($trips[$time['trip_id']])) {
                continue;
            }
            $arrival = $this->seconds($time['arrival_time'] ?: $time['departure_time']);
            $departure = $this->seconds($time['departure_time'] ?: $time['arrival_time']);
            if ($arrival === null || $departure === null) {
                continue;
            }
            $stopTimes[] = ['trip_id' => $time['trip_id'], 'stop_id' => $time['stop_id'], 'sequence' => (int) $time['stop_sequence'], 'arrival' => $arrival, 'departure' => $departure];
            $stopIds[$time['stop_id']] = true;
        }

        $stops = [];
        foreach ($this->rows("$base/stops.txt") as $stop) {
            if (isset($stopIds[$stop['stop_id']]) && is_numeric($stop['stop_lat']) && is_numeric($stop['stop_lon'])) {
                $stops[$stop['stop_id']] = [
                    'id' => $stop['stop_id'],
                    'code' => trim($stop['stop_code'] ?? '') ?: null,
                    'name' => trim($stop['stop_name']),
                    'lat' => round((float) $stop['stop_lat'], 7),
                    'lng' => round((float) $stop['stop_lon'], 7),
                ];
            }
        }
        // Un paso por una parada sin coordenadas no se podría dibujar ni usar para caminar.
        $stopTimes = array_values(array_filter($stopTimes, fn ($time) => isset($stops[$time['stop_id']])));

        $services = array_fill_keys(array_column($trips, 'service_id'), true);
        $dates = $this->serviceDates($base, $services);

        $shapeIds = array_fill_keys(array_filter(array_column($trips, 'shape_id')), true);
        $shapes = $this->shapes("$base/shapes.txt", $shapeIds);

        $footpaths = $this->footpaths($stops);

        $allDates = array_merge(...array_values(array_map('array_keys', $dates)) ?: [[]]);
        sort($allDates);

        DB::transaction(function () use ($routes, $stops, $trips, $stopTimes, $dates, $shapes, $footpaths, $allDates, $sourceUrl, &$stats): void {
            foreach (['plana_footpaths', 'plana_shapes', 'plana_service_dates', 'plana_stop_times', 'plana_trips', 'plana_stops', 'plana_routes'] as $table) {
                DB::table($table)->delete();
            }
            $this->insert('plana_routes', array_values($routes));
            $this->insert('plana_stops', array_values($stops));
            $this->insert('plana_trips', array_values($trips));
            $this->insert('plana_stop_times', $stopTimes);
            $rows = [];
            foreach ($dates as $service => $serviceDates) {
                foreach (array_keys($serviceDates) as $date) {
                    $rows[] = ['service_id' => $service, 'date' => $date];
                }
            }
            $this->insert('plana_service_dates', $rows);
            $this->insert('plana_shapes', array_map(fn ($id, $points) => ['id' => $id, 'points' => json_encode($points)], array_keys($shapes), $shapes));
            $this->insert('plana_footpaths', $footpaths);

            $stats = [
                'agencies' => count(array_unique(array_column($routes, 'agency_name'))),
                'routes' => count($routes),
                'trips' => count($trips),
                'stops' => count($stops),
                'stop_times' => count($stopTimes),
                'service_dates' => count($rows),
                'shapes' => count($shapes),
                'footpaths' => count($footpaths),
                'valid_from' => $allDates[0] ?? null,
                'valid_to' => $allDates[count($allDates) - 1] ?? null,
            ];
            DB::table('plana_feed')->insert([
                'imported_at' => now(),
                'valid_from' => $stats['valid_from'],
                'valid_to' => $stats['valid_to'],
                'source_url' => $sourceUrl,
                'stats' => json_encode($stats),
            ]);
        });

        return $stats;
    }

    /**
     * Líneas con al menos una parada dentro del rectángulo. Recorre stop_times una vez más, pero
     * evita cargar en memoria los horarios de toda Cataluña.
     *
     * @param  array{0:float,1:float,2:float,3:float}  $area
     * @return array<string,bool>
     */
    private function routesInArea(string $base, array $area): array
    {
        [$minLat, $maxLat, $minLng, $maxLng] = $area;
        $stopsInArea = [];
        foreach ($this->rows("$base/stops.txt") as $stop) {
            $lat = (float) $stop['stop_lat'];
            $lng = (float) $stop['stop_lon'];
            if ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng) {
                $stopsInArea[$stop['stop_id']] = true;
            }
        }
        $tripRoute = [];
        foreach ($this->rows("$base/trips.txt") as $trip) {
            $tripRoute[$trip['trip_id']] = $trip['route_id'];
        }
        $routes = [];
        foreach ($this->rows("$base/stop_times.txt") as $time) {
            if (isset($stopsInArea[$time['stop_id']], $tripRoute[$time['trip_id']])) {
                $routes[$tripRoute[$time['trip_id']]] = true;
            }
        }

        return $routes;
    }

    /** @return Generator<int,array<string,string>> */
    private function rows(string $path): Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("No se pudo leer $path.");
        }
        $header = fgetcsv($handle, escape: '');
        if (! is_array($header)) {
            fclose($handle);

            return;
        }
        $header = array_map(fn ($column) => trim((string) $column, "\xEF\xBB\xBF \t\""), $header);
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if (count($values) === count($header)) {
                yield array_combine($header, $values);
            }
        }
        fclose($handle);
    }

    private function exists(string $path): bool
    {
        return @file_exists($path);
    }

    /** «25:10:00» → 90600. Admite horas de más de 24 (expediciones pasada la medianoche). */
    private function seconds(string $time): ?int
    {
        return preg_match('/^\s*(\d{1,2}):(\d{2}):(\d{2})\s*$/', $time, $m) ? $m[1] * 3600 + $m[2] * 60 + (int) $m[3] : null;
    }

    private function color(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[0-9A-F]{6}$/', $value) ? $value : null;
    }

    /** nota.txt no es GTFS estándar y a veces viene en otra codificación. */
    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /**
     * Días en que circula cada servicio: calendar.txt (rangos por día de la semana) más las
     * excepciones de calendar_dates.txt (1 = se añade, 2 = se quita).
     *
     * @param  array<string,bool>  $services
     * @return array<string,array<string,bool>>
     */
    private function serviceDates(string $base, array $services): array
    {
        $dates = [];
        $weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        if ($this->exists("$base/calendar.txt")) {
            foreach ($this->rows("$base/calendar.txt") as $calendar) {
                if (! isset($services[$calendar['service_id']]) || ! in_array('1', array_map(fn ($day) => $calendar[$day] ?? '0', $weekdays), true)) {
                    continue;
                }
                $day = CarbonImmutable::createFromFormat('Ymd', $calendar['start_date'])->startOfDay();
                $end = CarbonImmutable::createFromFormat('Ymd', $calendar['end_date'])->startOfDay();
                for (; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                    if (($calendar[$weekdays[$day->dayOfWeekIso - 1]] ?? '0') === '1') {
                        $dates[$calendar['service_id']][$day->format('Y-m-d')] = true;
                    }
                }
            }
        }
        if ($this->exists("$base/calendar_dates.txt")) {
            foreach ($this->rows("$base/calendar_dates.txt") as $exception) {
                if (! isset($services[$exception['service_id']])) {
                    continue;
                }
                $date = CarbonImmutable::createFromFormat('Ymd', $exception['date'])->format('Y-m-d');
                if ($exception['exception_type'] === '1') {
                    $dates[$exception['service_id']][$date] = true;
                } else {
                    unset($dates[$exception['service_id']][$date]);
                }
            }
        }

        return $dates;
    }

    /**
     * @param  array<string,bool>  $wanted
     * @return array<string,list<array{0:float,1:float}>>
     */
    private function shapes(string $path, array $wanted): array
    {
        if ($wanted === [] || ! $this->exists($path)) {
            return [];
        }
        $points = [];
        foreach ($this->rows($path) as $point) {
            if (isset($wanted[$point['shape_id']])) {
                $points[$point['shape_id']][(int) $point['shape_pt_sequence']] = [round((float) $point['shape_pt_lat'], 6), round((float) $point['shape_pt_lon'], 6)];
            }
        }
        foreach ($points as &$shape) {
            ksort($shape);
            $shape = array_values($shape);
        }

        return $points;
    }

    /**
     * Pares de paradas a menos de FOOTPATH_METERS (por ejemplo, las dos marquesinas de una misma
     * calle), en ambos sentidos, con la distancia a pie estimada.
     *
     * @param  array<string,array{id:string,lat:float,lng:float}>  $stops
     * @return list<array{from_stop_id:string,to_stop_id:string,meters:int}>
     */
    private function footpaths(array $stops): array
    {
        $list = array_values($stops);
        $paths = [];
        $latDelta = self::FOOTPATH_METERS / 111320;
        usort($list, fn ($a, $b) => $a['lat'] <=> $b['lat']);
        foreach ($list as $i => $a) {
            for ($j = $i + 1; $j < count($list) && $list[$j]['lat'] - $a['lat'] <= $latDelta; $j++) {
                $b = $list[$j];
                $meters = Geo::meters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
                if ($meters <= self::FOOTPATH_METERS) {
                    $walk = (int) round($meters * Geo::WALK_DETOUR);
                    $paths[] = ['from_stop_id' => $a['id'], 'to_stop_id' => $b['id'], 'meters' => $walk];
                    $paths[] = ['from_stop_id' => $b['id'], 'to_stop_id' => $a['id'], 'meters' => $walk];
                }
            }
        }

        return $paths;
    }

    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
