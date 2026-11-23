<?php

namespace App\Services\Plana;

use App\Services\ReusTransport\PlaceSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Consultas de la red Plana: líneas, paradas y próximas salidas según el horario oficial. */
class PlanaNetwork
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('services.reus_transport.timezone', 'Europe/Madrid'));
    }

    /** @return array<string,mixed>|null */
    public function feed(): ?array
    {
        $feed = DB::table('plana_feed')->latest('id')->first();

        return $feed ? [
            'imported_at' => $feed->imported_at,
            'valid_from' => $feed->valid_from,
            'valid_to' => $feed->valid_to,
            'source' => 'Generalitat de Catalunya · Autobusos interurbans (GTFS)',
            'source_url' => $feed->source_url,
            'stats' => json_decode($feed->stats, true),
        ] : null;
    }

    /** @return list<array<string,mixed>> */
    public function lines(): array
    {
        return Cache::remember('plana:lines', 3600, fn () => DB::table('plana_routes')->get()
            ->map(fn ($route) => $this->linePayload($route))
            ->sortBy(fn ($line) => $line['name'], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /** Recorridos de una línea (un trazado por sentido) y sus paradas en orden. */
    public function line(string $id): ?array
    {
        $route = DB::table('plana_routes')->where('id', $id)->first();
        if (! $route) {
            return null;
        }
        $patterns = [];
        foreach ([0, 1, null] as $direction) {
            // La expedición con más paradas de cada sentido representa su recorrido completo.
            $trip = DB::table('plana_trips as t')
                ->where('t.route_id', $id)
                ->when($direction === null, fn ($q) => $q->whereNull('t.direction_id'), fn ($q) => $q->where('t.direction_id', $direction))
                ->select('t.id', 't.headsign', 't.shape_id')
                ->selectSub(DB::table('plana_stop_times')->whereColumn('trip_id', 't.id')->selectRaw('count(*)'), 'stops_count')
                ->orderByDesc('stops_count')
                ->first();
            if (! $trip) {
                continue;
            }
            $stops = DB::table('plana_stop_times as st')
                ->join('plana_stops as s', 's.id', '=', 'st.stop_id')
                ->where('st.trip_id', $trip->id)
                ->orderBy('st.sequence')
                ->get(['s.id', 's.name', 's.lat', 's.lng']);
            $shape = $trip->shape_id ? DB::table('plana_shapes')->where('id', $trip->shape_id)->value('points') : null;
            $patterns[] = [
                'direction' => $direction,
                'headsign' => $trip->headsign ?? $stops->last()?->name,
                'stops' => $stops->map(fn ($stop) => $this->stopPayload($stop))->all(),
                'geometry' => $shape ? json_decode($shape, true) : $stops->map(fn ($stop) => [(float) $stop->lat, (float) $stop->lng])->all(),
            ];
        }

        return $this->linePayload($route) + ['patterns' => $patterns];
    }

    /**
     * Horario completo de una línea en un día: por cada sentido, las paradas del recorrido más
     * largo (filas) y cada expedición con su hora de paso en ellas (columnas), en orden de salida.
     *
     * @return array<string,mixed>|null
     */
    public function timetable(string $routeId, string $date): ?array
    {
        $route = DB::table('plana_routes')->where('id', $routeId)->first();
        if (! $route) {
            return null;
        }
        $services = $this->activeServices($date);
        $trips = DB::table('plana_trips')->where('route_id', $routeId)->whereIn('service_id', $services)->get()->keyBy('id');
        $times = DB::table('plana_stop_times')->whereIn('trip_id', $trips->keys())->orderBy('trip_id')->orderBy('sequence')
            ->get(['trip_id', 'stop_id', 'departure'])->groupBy('trip_id');
        $stopRows = DB::table('plana_stops')->whereIn('id', $times->flatten()->pluck('stop_id')->unique())->get()->keyBy('id');
        $names = $stopRows->map(fn ($stop) => $stop->name);

        $directions = [];
        foreach ($trips->groupBy(fn ($trip) => $trip->direction_id ?? 'x') as $group) {
            // El recorrido más largo del sentido marca las filas; el resto se encaja en orden.
            $pattern = $group->map(fn ($trip) => $times[$trip->id] ?? collect())->sortByDesc(fn ($stops) => $stops->count())->first()->pluck('stop_id')->all();
            $rows = [];
            foreach ($group as $trip) {
                $column = array_fill(0, count($pattern), null);
                $position = 0;
                foreach ($times[$trip->id] ?? [] as $stop) {
                    for ($i = $position; $i < count($pattern); $i++) {
                        if ($pattern[$i] === $stop->stop_id) {
                            $column[$i] = self::clock($stop->departure);
                            $position = $i + 1;
                            break;
                        }
                    }
                }
                $first = ($times[$trip->id] ?? collect())->first();
                $rows[] = [
                    'id' => $trip->id,
                    'headsign' => $trip->headsign,
                    'note' => $trip->note,
                    'starts' => $first ? $first->departure : 0,
                    'times' => $column,
                ];
            }
            usort($rows, fn ($a, $b) => $a['starts'] <=> $b['starts']);
            // El GTFS trae a veces expediciones duplicadas (mismas horas en todas las paradas).
            $rows = collect($rows)->unique(fn ($row) => implode(',', array_map('strval', $row['times'])))->values()->all();
            $headsigns = array_count_values(array_filter(array_column($rows, 'headsign')));
            arsort($headsigns);
            $ends = [$stopRows[$pattern[0]] ?? null, $stopRows[end($pattern)] ?? null];
            $directions[] = [
                'direction' => $group->first()->direction_id,
                // «Tarragona → Reus»: sentido con nombres de población, para no depender de la parada final.
                'label' => $this->directionLabel(self::lineName($route->short_name, $route->long_name)['description'], ...$ends),
                'headsign' => array_key_first($headsigns) ?? ($names[end($pattern)] ?? null),
                'stops' => array_map(fn ($id) => ['code' => $id, 'name' => $names[$id] ?? $id], $pattern),
                'trips' => array_map(fn ($row) => array_diff_key($row, ['starts' => true]), $rows),
            ];
        }
        usort($directions, fn ($a, $b) => ($a['direction'] ?? 9) <=> ($b['direction'] ?? 9));

        // Si ese día no circula, el próximo día con servicio de la línea.
        $nextDate = $directions === []
            ? DB::table('plana_service_dates as sd')->join('plana_trips as t', 't.service_id', '=', 'sd.service_id')
                ->where('t.route_id', $routeId)->where('sd.date', '>', $date)->min('sd.date')
            : null;

        return $this->linePayload($route) + ['date' => $date, 'next_service_date' => $nextDate, 'directions' => $directions];
    }

    /**
     * «Origen → Destino» con las poblaciones de la descripción de la línea («Tarragona - Reus»):
     * el origen es la población más cercana a la primera parada y el destino, la más cercana a la
     * última. Se hace por geografía y no por nombre porque hay calles que se llaman como otro
     * pueblo («C. Ciutat de Reus», en Salou). Null si no se puede deducir.
     */
    private function directionLabel(string $description, ?object $first, ?object $last): ?string
    {
        // Separadores con espacio a algún lado: «Vila-seca» no se parte.
        $towns = array_values(array_filter(array_map(
            // Sin aclaraciones entre paréntesis ni el recorrido («BM Cambrils per La Pineda»).
            fn ($part) => trim(preg_replace(['/\(.*?\)/u', '/\s+(per|via|pel|per la)\s+.*$/iu'], '', $part) ?? ''),
            preg_split('/\s+-\s*|\s*-\s+/u', $description) ?: [],
        )));
        if (count($towns) < 2 || ! $first || ! $last) {
            return null;
        }
        $places = [];
        foreach (array_unique($towns) as $town) {
            if ($point = $this->townPoint($town)) {
                $places[$town] = $point;
            }
        }
        if (count($places) < 2) {
            return null;
        }
        $nearest = fn (object $stop, ?string $except = null) => collect($places)
            ->except(array_filter([$except]))
            ->map(fn ($point) => Geo::meters((float) $stop->lat, (float) $stop->lng, $point[0], $point[1]))
            ->sort()
            ->keys()
            ->first();
        $to = $nearest($last);
        $from = $nearest($first, $to);

        return $from && $to ? "$from → $to" : null;
    }

    /**
     * Coordenadas de una población de Cataluña con el buscador de OpenStreetMap, guardadas sin
     * caducidad (los pueblos no se mueven). Null si no se encuentra o el servicio falla.
     *
     * @return array{0:float,1:float}|null
     */
    private function townPoint(string $town): ?array
    {
        $key = 'plana:town:'.mb_strtolower($town);
        if (Cache::has($key)) {
            return Cache::get($key);
        }
        try {
            // «BM Cambrils» (Badia Mar) no lo conoce el buscador: se busca «Cambrils».
            $place = app(PlaceSearch::class)->search(preg_replace('/^BM\s+/u', '', $town) ?? $town, 'catalunya')[0] ?? null;
        } catch (Throwable) {
            return null;
        }
        $point = $place ? [$place['lat'], $place['lng']] : null;
        Cache::forever($key, $point);

        return $point;
    }

    /** Todas las paradas con las líneas que pasan por cada una (para el mapa). */
    public function mapStops(): array
    {
        return Cache::remember('plana:map-stops', 3600, function () {
            $lines = DB::table('plana_stop_times as st')
                ->join('plana_trips as t', 't.id', '=', 'st.trip_id')
                ->join('plana_routes as r', 'r.id', '=', 't.route_id')
                ->distinct()
                ->get(['st.stop_id', 'r.id', 'r.short_name', 'r.long_name'])
                ->groupBy('stop_id');

            return DB::table('plana_stops')->orderBy('name')->get()->map(fn ($stop) => [
                'id' => $stop->id,
                'code' => $stop->id,
                'name' => $stop->name,
                'latitude' => (float) $stop->lat,
                'longitude' => (float) $stop->lng,
                'lines' => ($lines[$stop->id] ?? collect())
                    ->sortBy('short_name', SORT_NATURAL)
                    ->map(fn ($line) => ['id' => $line->id, 'name' => self::lineName($line->short_name, $line->long_name)['name'], 'description' => null])
                    ->values()
                    ->all(),
            ])->all();
        });
    }

    /** Servicios que circulan en una fecha (Y-m-d). @return list<string> */
    public function activeServices(string $date): array
    {
        return DB::table('plana_service_dates')->where('date', $date)->pluck('service_id')->all();
    }

    /**
     * Próximas salidas desde una parada a partir de $from. Incluye expediciones del día anterior
     * que pasan de la medianoche y, si hoy quedan pocas, las primeras de mañana.
     *
     * @return list<array<string,mixed>>
     */
    public function departures(string $stopId, CarbonImmutable $from, int $limit = 12): array
    {
        $seconds = $from->secondsSinceMidnight();
        $today = $from->format('Y-m-d');
        $results = collect()
            ->concat($this->departuresOn($stopId, $from->subDay()->format('Y-m-d'), $seconds + 86400, -86400, $limit))
            ->concat($this->departuresOn($stopId, $today, $seconds, 0, $limit))
            ->sortBy('seconds')
            ->take($limit)
            ->values();
        // Si hoy quedan pocas, se completan con las de los días siguientes; si hoy no queda
        // ninguna, se sigue buscando hasta el próximo día con servicio (como mucho una semana).
        for ($days = 1; $days <= 7 && $results->count() < 3; $days++) {
            $more = $this->departuresOn($stopId, $from->addDays($days)->format('Y-m-d'), 0, 86400 * $days, $limit - $results->count());
            $results = $results->concat($more);
            if ($days >= 1 && $results->isNotEmpty() && $more->isEmpty()) {
                break;
            }
        }

        return $results->map(function ($departure) use ($seconds) {
            $departure['in_minutes'] = (int) floor(($departure['seconds'] - $seconds) / 60);
            unset($departure['seconds']);

            return $departure;
        })->all();
    }

    /** @return Collection<int,array<string,mixed>> */
    private function departuresOn(string $stopId, string $date, int $fromSeconds, int $offset, int $limit): Collection
    {
        $services = $this->activeServices($date);
        if ($services === [] || $limit <= 0) {
            return collect();
        }

        return DB::table('plana_stop_times as st')
            ->join('plana_trips as t', 't.id', '=', 'st.trip_id')
            ->join('plana_routes as r', 'r.id', '=', 't.route_id')
            ->where('st.stop_id', $stopId)
            ->where('st.departure', '>=', $fromSeconds)
            ->whereIn('t.service_id', $services)
            // En la última parada de la expedición ya no se puede subir.
            ->whereExists(fn ($q) => $q->from('plana_stop_times as nx')->whereColumn('nx.trip_id', 'st.trip_id')->whereColumn('nx.sequence', '>', 'st.sequence'))
            ->orderBy('st.departure')
            ->limit($limit)
            ->get(['st.departure', 't.id as trip_id', 't.headsign', 't.note', 'r.id as route_id', 'r.short_name', 'r.long_name', 'r.color', 'r.text_color'])
            ->map(fn ($row) => [
                'seconds' => $row->departure + $offset,
                'time' => self::clock($row->departure),
                'next_day' => $offset > 0,
                // Días desde hoy (0 = hoy, 1 = mañana…) y fecha, para «jueves 29/10».
                'day_offset' => max(0, intdiv($offset, 86400)),
                'date' => $date,
                'line' => ['id' => $row->route_id] + self::lineName($row->short_name, $row->long_name) + ['color' => $row->color, 'text_color' => $row->text_color],
                'headsign' => $row->headsign ?? $this->lastStopName($row->trip_id),
                'note' => $row->note,
            ]);
    }

    public function lastStopName(string $tripId): ?string
    {
        return DB::table('plana_stop_times as st')->join('plana_stops as s', 's.id', '=', 'st.stop_id')
            ->where('st.trip_id', $tripId)->orderByDesc('st.sequence')->value('s.name');
    }

    /**
     * Nombre visible de una línea: el código comercial entre paréntesis de la descripción
     * («(e5) Reus - Salou» → «e5») o, si no tiene, el código oficial («L0592»).
     *
     * @return array{name:string,description:string}
     */
    public static function lineName(string $shortName, string $longName): array
    {
        if (preg_match('/^\s*\(([^)]{1,8})\)\s*(.*)$/u', $longName, $match)) {
            return ['name' => $match[1], 'description' => $match[2]];
        }

        return ['name' => $shortName, 'description' => $longName];
    }

    /** Segundos desde medianoche → «HH:MM» (las horas de más de 24 vuelven a empezar). */
    public static function clock(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60));
    }

    /** @return array<string,mixed> */
    private function linePayload(object $route): array
    {
        return ['id' => $route->id] + self::lineName($route->short_name, $route->long_name) + [
            'operator' => $route->agency_name,
            'color' => $route->color,
            'text_color' => $route->text_color,
        ];
    }

    /** @return array<string,mixed> */
    private function stopPayload(object $stop): array
    {
        return ['code' => $stop->id, 'name' => $stop->name, 'lat' => (float) $stop->lat, 'lng' => (float) $stop->lng];
    }
}
