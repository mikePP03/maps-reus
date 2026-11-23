<?php

namespace App\Services\ReusTransport;

use App\Models\BusLine;
use App\Models\RouteSegment;
use App\Models\Stop;
use Illuminate\Support\Collection;

/**
 * Planifica viajes «de A a B»: camina hasta una parada cercana, toma una línea (o dos, con un
 * transbordo en la misma parada) y camina hasta el destino.
 *
 * No conoce horarios: los tiempos son estimaciones a partir de la distancia a pie y del número
 * de paradas. Sigue las mismas reglas de sentido que DirectRouteService (incluidas las circulares).
 */
class JourneyPlanner
{
    /** Distancia real a pie respecto a la línea recta. */
    private const WALK_DETOUR = 1.3;

    /** Metros por minuto a pie (4,5 km/h). */
    private const WALK_SPEED = 75;

    /** Minutos estimados entre dos paradas consecutivas. */
    private const MINUTES_PER_HOP = 1.5;

    /** Penalización por transbordo: espera media y cambio de andén. */
    private const TRANSFER_MINUTES = 6;

    private const CANDIDATE_STOPS = 6;

    private const MAX_RESULTS = 5;

    /** Por debajo de esta distancia también se propone ir andando. */
    private const WALK_ONLY_METERS = 1500;

    /** @var array<string,array{line:BusLine,stops:Collection<int,Stop>,ids:list<string>,positions:array<string,list<int>>,circular:bool}> */
    private array $lines = [];

    /** @var array<string,list<string>> código de parada => líneas que pasan por ella */
    private array $linesByStop = [];

    /** @var array<string,list<array{0:float,1:float}>> "idParada:idSiguiente" => trazado por calles */
    private array $segments = [];

    /**
     * @param  array{stop?:string,lat?:float,lng?:float,label?:string}  $from
     * @param  array{stop?:string,lat?:float,lng?:float,label?:string}  $to
     * @return array{journeys:list<array<string,mixed>>,origin:array<string,mixed>,destination:array<string,mixed>}
     */
    public function plan(array $from, array $to, int $walkRadius = 600): array
    {
        $this->loadNetwork();
        $origin = $this->endpoint($from, 'Origen');
        $destination = $this->endpoint($to, 'Destino');
        $origins = $this->candidates($origin, $walkRadius);
        $destinations = $this->candidates($destination, $walkRadius);

        $journeys = [];
        foreach ($origins as $board) {
            foreach ($destinations as $alight) {
                if ($board['stop']->external_id === $alight['stop']->external_id) {
                    continue;
                }
                foreach ($this->linesByStop[$board['stop']->external_id] ?? [] as $lineId) {
                    $direct = $this->segment($lineId, $board['stop']->external_id, $alight['stop']->external_id);
                    if ($direct) {
                        $journeys[] = $this->journey($origin, $destination, $board, $alight, [$direct]);
                    }
                }
            }
            $journeys = [...$journeys, ...$this->transferJourneys($origin, $destination, $board, $destinations)];
        }

        $walkMeters = $this->walkMeters($origin, $destination);
        if ($walkMeters !== null && $walkMeters <= self::WALK_ONLY_METERS) {
            $journeys[] = $this->walkOnly($origin, $destination, $walkMeters);
        }

        return [
            'journeys' => $this->best($journeys),
            'origin' => $this->publicPoint($origin),
            'destination' => $this->publicPoint($destination),
        ];
    }

    private function loadNetwork(): void
    {
        if ($this->lines !== []) {
            return;
        }
        foreach (RouteSegment::all(['from_stop_id', 'to_stop_id', 'geometry']) as $segment) {
            $this->segments["{$segment->from_stop_id}:{$segment->to_stop_id}"] = $segment->geometry;
        }
        $lines = BusLine::where('active', true)->with(['stops' => fn ($query) => $query->where('stops.active', true)])->get();
        foreach ($lines as $line) {
            $stops = $line->stops->values();
            $ids = $stops->pluck('external_id')->all();
            $positions = [];
            foreach ($ids as $index => $id) {
                $positions[$id][] = $index;
            }
            $this->lines[$line->external_id] = [
                'line' => $line,
                'stops' => $stops,
                'ids' => $ids,
                'positions' => $positions,
                'circular' => count($ids) > 2 && $ids[0] === $ids[count($ids) - 1],
            ];
            foreach (array_keys($positions) as $id) {
                $this->linesByStop[$id][] = $line->external_id;
            }
        }
    }

    /**
     * @param  array{stop?:string,lat?:float,lng?:float,label?:string}  $input
     * @return array{name:string,lat:?float,lng:?float,stop:?Stop}
     */
    private function endpoint(array $input, string $fallback): array
    {
        if (isset($input['stop'])) {
            $stop = Stop::where('external_id', $input['stop'])->where('active', true)->firstOrFail();

            return ['name' => $input['label'] ?? $stop->name, 'lat' => $this->coordinate($stop->latitude), 'lng' => $this->coordinate($stop->longitude), 'stop' => $stop];
        }

        return ['name' => $input['label'] ?? $fallback, 'lat' => (float) $input['lat'], 'lng' => (float) $input['lng'], 'stop' => null];
    }

    /**
     * Paradas desde las que empezar (o en las que terminar). Si no hay ninguna dentro del radio,
     * se usan igualmente las más cercanas para no dejar al usuario sin respuesta.
     *
     * @return list<array{stop:Stop,meters:int}>
     */
    private function candidates(array $point, int $walkRadius): array
    {
        // Sin coordenadas no se puede saber qué hay cerca: solo esa parada.
        if ($point['lat'] === null || $point['lng'] === null) {
            return $point['stop'] ? [['stop' => $point['stop'], 'meters' => 0]] : [];
        }

        // Una parada elegida cuenta como punto de partida: también valen las cercanas a pie,
        // que a menudo ahorran dar media vuelta en la misma línea.
        $nearby = Stop::where('active', true)->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->filter(fn (Stop $stop) => isset($this->linesByStop[$stop->external_id]))
            ->map(fn (Stop $stop) => ['stop' => $stop, 'meters' => $stop->external_id === $point['stop']?->external_id
                ? 0
                : $this->walkBetween($point['lat'], $point['lng'], (float) $stop->latitude, (float) $stop->longitude)])
            ->sortBy('meters')
            ->values();
        $within = $nearby->filter(fn ($candidate) => $candidate['meters'] <= $walkRadius);

        return ($within->isNotEmpty() ? $within : $nearby)->take(self::CANDIDATE_STOPS)->values()->all();
    }

    /**
     * Tramo más corto en sentido de marcha de $from a $to en una línea.
     *
     * @return array{line:string,from:int,to:int,hops:int,wrap:bool}|null
     */
    private function segment(string $lineId, string $from, string $to): ?array
    {
        $line = $this->lines[$lineId];
        $last = count($line['ids']) - 1;
        $best = null;
        foreach ($line['positions'][$from] ?? [] as $i) {
            foreach ($line['positions'][$to] ?? [] as $j) {
                if ($j > $i) {
                    $candidate = ['line' => $lineId, 'from' => $i, 'to' => $j, 'hops' => $j - $i, 'wrap' => false];
                } elseif ($line['circular'] && $i < $last && $j > 0) {
                    $candidate = ['line' => $lineId, 'from' => $i, 'to' => $j, 'hops' => ($last - $i) + $j, 'wrap' => true];
                } else {
                    continue;
                }
                if ($best === null || $candidate['hops'] < $best['hops']) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * Combinaciones con un transbordo en la misma parada: línea A hasta T, línea B desde T.
     *
     * @param  array{stop:Stop,meters:int}  $board
     * @param  list<array{stop:Stop,meters:int}>  $destinations
     * @return list<array<string,mixed>>
     */
    private function transferJourneys(array $origin, array $destination, array $board, array $destinations): array
    {
        $journeys = [];
        $boardId = $board['stop']->external_id;
        foreach ($this->linesByStop[$boardId] ?? [] as $firstLine) {
            foreach (array_keys($this->lines[$firstLine]['positions']) as $transfer) {
                if ($transfer === $boardId || ! ($first = $this->segment($firstLine, $boardId, $transfer))) {
                    continue;
                }
                foreach ($this->linesByStop[$transfer] as $secondLine) {
                    if ($secondLine === $firstLine) {
                        continue;
                    }
                    foreach ($destinations as $alight) {
                        $alightId = $alight['stop']->external_id;
                        if ($alightId === $transfer || $alightId === $boardId) {
                            continue;
                        }
                        if ($second = $this->segment($secondLine, $transfer, $alightId)) {
                            $journeys[] = $this->journey($origin, $destination, $board, $alight, [$first, $second]);
                        }
                    }
                }
            }
        }

        return $journeys;
    }

    /**
     * @param  list<array{line:string,from:int,to:int,hops:int,wrap:bool}>  $segments
     * @return array<string,mixed>
     */
    private function journey(array $origin, array $destination, array $board, array $alight, array $segments): array
    {
        $legs = [];
        if ($board['meters'] > 0) {
            $legs[] = $this->walkLeg($this->publicPoint($origin), $this->stopPoint($board['stop']), $board['meters']);
        }
        foreach ($segments as $segment) {
            $legs[] = $this->busLeg($segment);
        }
        if ($alight['meters'] > 0) {
            $legs[] = $this->walkLeg($this->stopPoint($alight['stop']), $this->publicPoint($destination), $alight['meters']);
        }

        $walk = $board['meters'] + $alight['meters'];
        $hops = array_sum(array_column($segments, 'hops'));
        $transfers = count($segments) - 1;

        return [
            'lines' => array_map(fn ($segment) => $this->lines[$segment['line']]['line']->public_name, $segments),
            'minutes' => (int) ceil($walk / self::WALK_SPEED + $hops * self::MINUTES_PER_HOP + $transfers * self::TRANSFER_MINUTES),
            'walk_meters' => $walk,
            'transfers' => $transfers,
            'bus_stops' => $hops,
            'legs' => $legs,
            // Clave para quedarse con la mejor variante de cada combinación de líneas.
            '_key' => implode('>', array_map(fn ($segment) => $segment['line'], $segments)),
        ];
    }

    /** @return array<string,mixed> */
    private function walkOnly(array $origin, array $destination, int $meters): array
    {
        return [
            'lines' => [],
            'minutes' => (int) ceil($meters / self::WALK_SPEED),
            'walk_meters' => $meters,
            'transfers' => 0,
            'bus_stops' => 0,
            'legs' => [$this->walkLeg($this->publicPoint($origin), $this->publicPoint($destination), $meters)],
            '_key' => 'walk',
        ];
    }

    /** @return array<string,mixed> */
    private function walkLeg(array $from, array $to, int $meters): array
    {
        return ['type' => 'walk', 'from' => $from, 'to' => $to, 'meters' => $meters, 'minutes' => (int) ceil($meters / self::WALK_SPEED)];
    }

    /** @param array{line:string,from:int,to:int,hops:int,wrap:bool} $segment */
    private function busLeg(array $segment): array
    {
        $line = $this->lines[$segment['line']];
        $models = ($segment['wrap']
            ? $line['stops']->slice($segment['from'])->concat($line['stops']->slice(1, $segment['to']))
            : $line['stops']->slice($segment['from'], $segment['hops'] + 1))->values();
        $stops = $models->map(fn (Stop $stop) => $this->stopPoint($stop));

        return [
            'type' => 'bus',
            'line' => ['id' => $line['line']->external_id, 'name' => $line['line']->public_name],
            'from' => $stops->first(),
            'to' => $stops->last(),
            'stops' => $stops->all(),
            'geometry' => $this->geometry($models->all()),
            'hops' => $segment['hops'],
            'minutes' => (int) ceil($segment['hops'] * self::MINUTES_PER_HOP),
            'crosses_terminus' => $segment['wrap'],
        ];
    }

    /**
     * Trazado por calles del tramo, uniendo los segmentos guardados por `reus:build-geometry`.
     * Donde falta un segmento se une en línea recta; las paradas sin coordenadas se saltan.
     *
     * @param  list<Stop>  $stops
     * @return list<array{0:float,1:float}>
     */
    private function geometry(array $stops): array
    {
        $located = array_values(array_filter($stops, fn (Stop $stop) => $stop->latitude !== null && $stop->longitude !== null));
        $path = [];
        foreach ($located as $index => $stop) {
            $next = $located[$index + 1] ?? null;
            $points = $next === null
                ? [[(float) $stop->latitude, (float) $stop->longitude]]
                : ($this->segments["{$stop->id}:{$next->id}"] ?? [[(float) $stop->latitude, (float) $stop->longitude]]);
            foreach ($points as $point) {
                if ($path === [] || end($path) !== $point) {
                    $path[] = $point;
                }
            }
        }

        return $path;
    }

    /**
     * Ordena por tiempo estimado, deja una sola variante por combinación de líneas y descarta los
     * transbordos que no compensan: si otra opción con menos transbordos no tarda más, sobran.
     *
     * @param  list<array<string,mixed>>  $journeys
     * @return list<array<string,mixed>>
     */
    private function best(array $journeys): array
    {
        $sorted = collect($journeys)
            ->sortBy([['minutes', 'asc'], ['transfers', 'asc'], ['walk_meters', 'asc']])
            ->unique('_key')
            ->values();

        return $sorted
            ->reject(fn ($journey) => $sorted->contains(
                fn ($other) => $other['transfers'] < $journey['transfers'] && $other['minutes'] <= $journey['minutes'],
            ))
            // Si ir andando no tarda más, el autobús (que además hay que esperar) sobra.
            ->reject(fn ($journey) => $journey['lines'] !== [] && $sorted->contains(
                fn ($other) => $other['lines'] === [] && $other['minutes'] <= $journey['minutes'],
            ))
            ->take(self::MAX_RESULTS)
            ->map(fn ($journey) => collect($journey)->except('_key')->all())
            ->values()
            ->all();
    }

    /** @return array{code:string,name:string,lat:?float,lng:?float} */
    private function stopPoint(Stop $stop): array
    {
        return ['code' => $stop->external_id, 'name' => $stop->name, 'lat' => $this->coordinate($stop->latitude), 'lng' => $this->coordinate($stop->longitude)];
    }

    /** @return array{code:?string,name:string,lat:?float,lng:?float} */
    private function publicPoint(array $point): array
    {
        return ['code' => $point['stop']?->external_id, 'name' => $point['name'], 'lat' => $point['lat'], 'lng' => $point['lng']];
    }

    private function walkMeters(array $a, array $b): ?int
    {
        if ($a['lat'] === null || $a['lng'] === null || $b['lat'] === null || $b['lng'] === null) {
            return null;
        }

        return $this->walkBetween($a['lat'], $a['lng'], $b['lat'], $b['lng']);
    }

    private function walkBetween(float $latA, float $lngA, float $latB, float $lngB): int
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return (int) round(6371000 * 2 * asin(min(1, sqrt($h))) * self::WALK_DETOUR);
    }

    private function coordinate(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
