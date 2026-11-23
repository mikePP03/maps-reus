<?php

namespace App\Services\Plana;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Planificador por horario de la red Plana con el algoritmo Connection Scan (CSA): recorre en
 * orden de hora las conexiones entre paradas consecutivas de todas las expediciones del día y
 * obtiene la llegada más temprana, con transbordos y tramos a pie. Después busca alternativas
 * saliendo un poco más tarde.
 */
class PlanaJourneyPlanner
{
    /** Margen para cambiar de autobús en una misma parada. */
    private const TRANSFER_SECONDS = 60;

    private const CANDIDATE_STOPS = 8;

    /** Distancia máxima a pie hasta la parada más cercana; más lejos, el punto queda fuera de la red. */
    public const MAX_FALLBACK_WALK_METERS = 2500;

    private const MAX_BUS_LEGS = 3;

    private const ALTERNATIVES = 3;

    /** Ventana de búsqueda: los interurbanos pueden pasar con horas de diferencia. */
    private const HORIZON_SECONDS = 8 * 3600;

    private const WALK_ONLY_METERS = 2000;

    /** Un transbordo más solo compensa si se llega al menos 3 minutos antes. */
    private const TRANSFER_MIN_GAIN_SECONDS = 180;

    /** En la lista del día, un transbordo con más espera que esto no se ofrece. */
    private const MAX_TRANSFER_WAIT_SECONDS = 40 * 60;

    /** @var list<array{0:string,1:string,2:int,3:int,4:string}> [desde, hasta, salida, llegada, expedición] */
    private array $connections = [];

    /** @var array<string,list<int>> expedición → índices de sus conexiones en orden */
    private array $tripConnections = [];

    /** @var array<string,array<string,int>> parada → [parada vecina → metros a pie] */
    private array $footpaths = [];

    /** @var array<string,object> */
    private array $stops = [];

    public function __construct(private PlanaNetwork $network) {}

    /**
     * @param  array{stop?:string,lat?:float,lng?:float,label?:string}  $from
     * @param  array{stop?:string,lat?:float,lng?:float,label?:string}  $to
     * @return array{journeys:list<array<string,mixed>>,origin:array<string,mixed>,destination:array<string,mixed>}
     */
    public function plan(array $from, array $to, CarbonImmutable $departure, int $walkRadius = 1200, int $horizon = self::HORIZON_SECONDS): array
    {
        $this->stops = DB::table('plana_stops')->get()->keyBy('id')->all();
        $origin = $this->endpoint($from, 'Origen');
        $destination = $this->endpoint($to, 'Destino');
        $start = $departure->secondsSinceMidnight();
        $this->loadConnections($departure, $start, $horizon);
        $this->loadFootpaths();

        $origins = $this->candidates($origin, $walkRadius);
        $destinations = $this->candidates($destination, $walkRadius);

        $journeys = [];
        $seen = [];
        $add = function (?array $journey) use (&$journeys, &$seen): bool {
            if ($journey === null) {
                return false;
            }
            $key = implode('|', array_map(fn ($leg) => $leg['type'] === 'bus' ? $leg['trip_id'] : 'w', $journey['legs']));
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            $journeys[] = $journey;

            return true;
        };

        if ($origins !== [] && $destinations !== []) {
            // Criterios a la vez: directo, con 1 y con 2 transbordos. Una opción con más
            // transbordos solo entra si llega antes que todas las que tienen menos.
            $fastest = null;
            for ($legs = 1; $legs <= self::MAX_BUS_LEGS; $legs++) {
                $journey = $this->scan($origin, $destination, $origins, $destinations, $start, $legs);
                if ($journey && (! $fastest || $journey['arrives_seconds'] <= $fastest['arrives_seconds'] - self::TRANSFER_MIN_GAIN_SECONDS)) {
                    $add($journey);
                    $fastest = $journey;
                }
            }
            // Alternativas más tardías de la opción más rápida, por si se pierde el autobús, con
            // los mismos autobuses como mucho: si la mejor es directa, la siguiente directa.
            $legsOfFastest = $fastest ? count(array_filter($fastest['legs'], fn ($leg) => $leg['type'] === 'bus')) : 0;
            for ($i = 0; $fastest && $i < self::ALTERNATIVES - 1; $i++) {
                $firstBus = collect($fastest['legs'])->firstWhere('type', 'bus');
                $later = $this->scan($origin, $destination, $origins, $destinations, $firstBus['departs_seconds'] - $this->walkToFirstBus($fastest) + 60, max(1, $legsOfFastest));
                if (! $later || ! $add($later)) {
                    break;
                }
                $fastest = $later;
            }
        }

        $walk = $this->walkMeters($origin, $destination);
        if ($walk !== null && $walk <= self::WALK_ONLY_METERS) {
            $seconds = Geo::walkSeconds($walk);
            $journeys[] = $this->walkJourney($origin, $destination, $walk, $start, $start + $seconds);
        }

        // A igual hora de llegada, primero la que tiene menos autobuses.
        usort($journeys, fn ($a, $b) => [$a['arrives_seconds'], $this->busLegs($a)] <=> [$b['arrives_seconds'], $this->busLegs($b)]);

        return [
            'journeys' => array_map(fn ($journey) => $this->publicJourney($journey, $start), array_slice($journeys, 0, self::ALTERNATIVES + 1)),
            'origin' => $this->publicPoint($origin),
            'destination' => $this->publicPoint($destination),
        ];
    }

    /**
     * Como plan(), pero si no hay ningún autobús en la ventana habitual busca el siguiente
     * disponible: primero en el resto del día y después en los días siguientes (hasta $maxDays).
     *
     * @return array{journeys:list<array<string,mixed>>,origin:array<string,mixed>,destination:array<string,mixed>,date:CarbonImmutable,next_available:bool}
     */
    public function planOrNext(array $from, array $to, CarbonImmutable $departure, int $walkRadius = 1200, int $maxDays = 7): array
    {
        $result = $this->plan($from, $to, $departure, $walkRadius);
        if ($this->hasBus($result['journeys'])) {
            return $result + ['date' => $departure, 'next_available' => false];
        }

        // Resto del día, incluidas las expediciones que pasan de la medianoche.
        $rest = $this->plan($from, $to, $departure, $walkRadius, max(self::HORIZON_SECONDS, 30 * 3600 - $departure->secondsSinceMidnight()));
        if ($this->hasBus($rest['journeys'])) {
            return $rest + ['date' => $departure, 'next_available' => true];
        }

        for ($days = 1; $days <= $maxDays; $days++) {
            $day = $departure->addDays($days)->startOfDay();
            $next = $this->plan($from, $to, $day, $walkRadius, 30 * 3600);
            if ($this->hasBus($next['journeys'])) {
                // Ir andando no es una alternativa «para otro día».
                $next['journeys'] = array_values(array_filter($next['journeys'], fn ($journey) => $journey['lines'] !== []));

                return $next + ['date' => $day, 'next_available' => true];
            }
        }

        return $result + ['date' => $departure, 'next_available' => false];
    }

    /**
     * Todas las salidas posibles de un trayecto a lo largo del día (desde $departure hasta el
     * final del servicio): carga los horarios una sola vez y encadena búsquedas, empezando cada
     * una justo después del primer autobús de la anterior. Se quitan las opciones que no
     * compensan (salir antes para llegar a la misma hora o más tarde que una salida posterior).
     *
     * @return array{journeys:list<array<string,mixed>>,origin:array<string,mixed>,destination:array<string,mixed>,date:CarbonImmutable,next_available:bool}
     */
    public function planDay(array $from, array $to, CarbonImmutable $departure, int $walkRadius = 1200, int $maxResults = 250, int $maxDays = 7): array
    {
        for ($days = 0; $days <= $maxDays; $days++) {
            $day = $days === 0 ? $departure : $departure->addDays($days)->startOfDay();
            $result = $this->dayJourneys($from, $to, $day, $walkRadius, $maxResults);
            if ($this->hasBus($result['journeys'])) {
                return $result + ['date' => $day, 'next_available' => $days > 0];
            }
        }

        return $result + ['date' => $departure, 'next_available' => false];
    }

    /** @return array{journeys:list<array<string,mixed>>,origin:array<string,mixed>,destination:array<string,mixed>} */
    private function dayJourneys(array $from, array $to, CarbonImmutable $day, int $walkRadius, int $maxResults): array
    {
        $this->stops = DB::table('plana_stops')->get()->keyBy('id')->all();
        $origin = $this->endpoint($from, 'Origen');
        $destination = $this->endpoint($to, 'Destino');
        $start = $day->secondsSinceMidnight();
        // Hasta las 6:00 del día siguiente, para incluir los nocturnos.
        $this->loadConnections($day, $start, max(3600, 30 * 3600 - $start));
        $this->loadFootpaths();
        $origins = $this->candidates($origin, $walkRadius);
        $destinations = $this->candidates($destination, $walkRadius);

        $journeys = [];
        $seen = [];
        $time = $start;
        while ($origins !== [] && $destinations !== [] && count($journeys) < $maxResults) {
            // En cada hora de salida, la mejor opción: directa si existe y no se tarda más de
            // 3 minutos de más; si no, la que llega antes.
            $best = null;
            for ($legs = 1; $legs <= self::MAX_BUS_LEGS; $legs++) {
                $journey = $this->scan($origin, $destination, $origins, $destinations, $time, $legs);
                if ($journey && (! $best || $journey['arrives_seconds'] <= $best['arrives_seconds'] - self::TRANSFER_MIN_GAIN_SECONDS)) {
                    $best = $journey;
                }
            }
            if (! $best) {
                break;
            }
            $key = implode('|', array_map(fn ($leg) => $leg['type'] === 'bus' ? $leg['trip_id'] : 'w', $best['legs']));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $journeys[] = $best;
            }
            $firstBus = collect($best['legs'])->firstWhere('type', 'bus');
            $time = $firstBus['departs_seconds'] - $this->walkToFirstBus($best) + 60;
        }

        // Fuera las que nadie elegiría: esperar mucho en un transbordo (p. ej. el nocturno de las
        // 02:51 para esperar 3 horas el primer bus de la mañana) o tardar mucho más de lo habitual
        // en ese trayecto (la mediana: la más rápida puede ser un nocturno sin tráfico).
        $durations = array_map(fn ($journey) => $journey['arrives_seconds'] - $journey['departs_seconds'], $journeys);
        sort($durations);
        $typical = $durations[intdiv(count($durations), 2)] ?? 0;
        $journeys = array_values(array_filter($journeys, function (array $journey) use ($typical): bool {
            $duration = $journey['arrives_seconds'] - $journey['departs_seconds'];

            return $this->longestTransferWait($journey) <= self::MAX_TRANSFER_WAIT_SECONDS
                && $duration <= max(2 * $typical, $typical + 60 * 60);
        }));

        // Fuera las que salen antes pero no llegan antes que una salida posterior.
        usort($journeys, fn ($a, $b) => $a['departs_seconds'] <=> $b['departs_seconds']);
        $kept = [];
        for ($i = count($journeys) - 1, $earliest = PHP_INT_MAX; $i >= 0; $i--) {
            if ($journeys[$i]['arrives_seconds'] < $earliest) {
                $kept[] = $journeys[$i];
                $earliest = $journeys[$i]['arrives_seconds'];
            }
        }
        $kept = array_reverse($kept);

        return [
            'journeys' => array_map(fn ($journey) => $this->publicJourney($journey, $start), $kept),
            'origin' => $this->publicPoint($origin),
            'destination' => $this->publicPoint($destination),
        ];
    }

    /** Mayor espera (en segundos) entre bajar de un autobús y subir al siguiente. */
    private function longestTransferWait(array $journey): int
    {
        $longest = 0;
        $readyAt = null;
        foreach ($journey['legs'] as $leg) {
            if ($leg['type'] === 'walk') {
                $readyAt = $readyAt === null ? null : $readyAt + Geo::walkSeconds($leg['meters']);

                continue;
            }
            if ($readyAt !== null) {
                $longest = max($longest, $leg['departs_seconds'] - $readyAt);
            }
            $readyAt = $leg['arrives_seconds'];
        }

        return $longest;
    }

    /**
     * Si un punto (lugar o ubicación) no tiene ninguna parada a una distancia razonable a pie.
     * Una parada nunca está fuera.
     *
     * @param  array{stop?:string,lat?:float,lng?:float}  $point
     */
    public function isOutOfArea(array $point): bool
    {
        if (isset($point['stop']) || ! isset($point['lat'], $point['lng'])) {
            return false;
        }
        $nearest = DB::table('plana_stops')->get(['lat', 'lng'])
            ->map(fn ($stop) => Geo::walkMeters((float) $point['lat'], (float) $point['lng'], (float) $stop->lat, (float) $stop->lng))
            ->min();

        return $nearest === null || $nearest > self::MAX_FALLBACK_WALK_METERS;
    }

    /** Primera conexión que sale a partir de $time (búsqueda binaria). */
    private function firstConnectionAt(int $time): int
    {
        [$low, $high] = [0, count($this->connections)];
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->connections[$middle][2] < $time) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    private function hasBus(array $journeys): bool
    {
        return collect($journeys)->contains(fn ($journey) => $journey['lines'] !== []);
    }

    /** Conexiones entre paradas consecutivas de las expediciones del día (y las nocturnas del anterior). */
    private function loadConnections(CarbonImmutable $day, int $start, int $horizon): void
    {
        $sources = [
            [$day->format('Y-m-d'), 0],
            [$day->subDay()->format('Y-m-d'), -86400],
        ];
        $rows = [];
        foreach ($sources as [$date, $offset]) {
            $services = $this->network->activeServices($date);
            if ($services === []) {
                continue;
            }
            $times = DB::table('plana_stop_times as st')
                ->join('plana_trips as t', 't.id', '=', 'st.trip_id')
                ->whereIn('t.service_id', $services)
                ->orderBy('st.trip_id')
                ->orderBy('st.sequence')
                ->get(['st.trip_id', 'st.stop_id', 'st.arrival', 'st.departure']);
            $previous = null;
            foreach ($times as $row) {
                if ($previous && $previous->trip_id === $row->trip_id) {
                    $departure = $previous->departure + $offset;
                    $arrival = $row->arrival + $offset;
                    if ($departure >= $start && $departure <= $start + $horizon) {
                        $rows[] = [$previous->stop_id, $row->stop_id, $departure, $arrival, ($offset ? 'y:' : '').$row->trip_id];
                    }
                }
                $previous = $row;
            }
        }
        usort($rows, fn ($a, $b) => $a[2] <=> $b[2] ?: $a[3] <=> $b[3]);
        $this->connections = $rows;
        $this->tripConnections = [];
        foreach ($rows as $index => $connection) {
            $this->tripConnections[$connection[4]][] = $index;
        }
    }

    private function loadFootpaths(): void
    {
        $this->footpaths = [];
        foreach (DB::table('plana_footpaths')->get() as $path) {
            $this->footpaths[$path->from_stop_id][$path->to_stop_id] = (int) $path->meters;
        }
    }

    /**
     * Una pasada de CSA desde $time usando como mucho $maxLegs autobuses. Devuelve el viaje que llega antes a cualquiera de las
     * paradas de destino (contando el tramo final a pie), o null.
     *
     * @param  list<array{stop:string,meters:int}>  $origins
     * @param  list<array{stop:string,meters:int}>  $destinations
     */
    private function scan(array $origin, array $destination, array $origins, array $destinations, int $time, int $maxLegs): ?array
    {
        $arrive = [];
        $ready = [];
        $via = [];
        foreach ($origins as $candidate) {
            $at = $time + Geo::walkSeconds($candidate['meters']);
            $arrive[$candidate['stop']] = $at;
            $ready[$candidate['stop']] = $at;
            $via[$candidate['stop']] = ['origin', $candidate['meters']];
        }
        $finalWalk = array_column($destinations, 'meters', 'stop');
        $best = PHP_INT_MAX;
        $bestStop = null;
        $boarded = [];
        $legsTo = [];

        // Las conexiones están ordenadas por hora: se empieza directamente en la primera útil.
        $count = count($this->connections);
        for ($index = $this->firstConnectionAt($time); $index < $count; $index++) {
            [$from, $to, $departure, $arrival, $trip] = $this->connections[$index];
            if ($departure >= $best) {
                break;
            }
            if (! isset($boarded[$trip])) {
                if (! isset($ready[$from]) || $ready[$from] > $departure) {
                    continue;
                }
                // Limita los transbordos: no se sube a un bus si ya lleva demasiados tramos.
                if (($legsTo[$from] ?? 0) >= $maxLegs) {
                    continue;
                }
                $boarded[$trip] = [$index, ($legsTo[$from] ?? 0) + 1];
            }
            if (isset($arrive[$to]) && $arrive[$to] <= $arrival) {
                continue;
            }
            [$boardIndex, $legs] = $boarded[$trip];
            $arrive[$to] = $arrival;
            $ready[$to] = $arrival + self::TRANSFER_SECONDS;
            $via[$to] = ['bus', $boardIndex, $index];
            $legsTo[$to] = $legs;
            if (isset($finalWalk[$to]) && $arrival + Geo::walkSeconds($finalWalk[$to]) < $best) {
                $best = $arrival + Geo::walkSeconds($finalWalk[$to]);
                $bestStop = $to;
            }
            foreach ($this->footpaths[$to] ?? [] as $neighbour => $meters) {
                $walked = $arrival + Geo::walkSeconds($meters);
                if (! isset($arrive[$neighbour]) || $walked < $arrive[$neighbour]) {
                    $arrive[$neighbour] = $walked;
                    $ready[$neighbour] = $walked;
                    $via[$neighbour] = ['walk', $to, $meters];
                    $legsTo[$neighbour] = $legs;
                    if (isset($finalWalk[$neighbour]) && $walked + Geo::walkSeconds($finalWalk[$neighbour]) < $best) {
                        $best = $walked + Geo::walkSeconds($finalWalk[$neighbour]);
                        $bestStop = $neighbour;
                    }
                }
            }
        }

        return $bestStop === null ? null : $this->rebuild($origin, $destination, $via, $bestStop, $finalWalk[$bestStop], $best);
    }

    /** Recorre los punteros de la pasada desde la parada final hasta el origen. */
    private function rebuild(array $origin, array $destination, array $via, string $stop, int $finalMeters, int $arrives): ?array
    {
        $legs = [];
        if ($finalMeters > 0) {
            $legs[] = ['type' => 'walk', 'from_stop' => $stop, 'to_point' => $destination, 'meters' => $finalMeters, 'arrives_seconds' => $arrives];
        }
        for ($guard = 0; $guard < 12; $guard++) {
            $step = $via[$stop] ?? null;
            if ($step === null) {
                return null;
            }
            if ($step[0] === 'origin') {
                if ($step[1] > 0) {
                    $legs[] = ['type' => 'walk', 'from_point' => $origin, 'to_stop' => $stop, 'meters' => $step[1]];
                }
                break;
            }
            if ($step[0] === 'walk') {
                $legs[] = ['type' => 'walk', 'from_stop' => $step[1], 'to_stop' => $stop, 'meters' => $step[2]];
                $stop = $step[1];

                continue;
            }
            [, $boardIndex, $alightIndex] = $step;
            $legs[] = $this->busLeg($boardIndex, $alightIndex);
            $stop = $this->connections[$boardIndex][0];
        }
        $legs = array_reverse($legs);

        // Horas de los tramos a pie: el inicial acaba al subir al primer bus; los demás, a continuación.
        $clock = null;
        foreach ($legs as $i => &$leg) {
            if ($leg['type'] === 'bus') {
                $clock = $leg['arrives_seconds'];

                continue;
            }
            $seconds = Geo::walkSeconds($leg['meters']);
            if ($clock === null) {
                $next = $legs[$i + 1] ?? null;
                $leg['arrives_seconds'] = $next ? $next['departs_seconds'] : $arrives;
                $leg['departs_seconds'] = $leg['arrives_seconds'] - $seconds;
            } else {
                $leg['departs_seconds'] = $clock;
                $leg['arrives_seconds'] = $clock + $seconds;
                $clock = $leg['arrives_seconds'];
            }
        }
        unset($leg);

        return ['legs' => $legs, 'arrives_seconds' => $arrives, 'departs_seconds' => $legs[0]['departs_seconds'] ?? $arrives];
    }

    private function busLeg(int $boardIndex, int $alightIndex): array
    {
        $trip = $this->connections[$boardIndex][4];
        $indexes = array_values(array_filter($this->tripConnections[$trip], fn ($i) => $i >= $boardIndex && $i <= $alightIndex));
        $stopIds = [$this->connections[$boardIndex][0]];
        $times = [$this->connections[$boardIndex][2]];
        foreach ($indexes as $i) {
            $stopIds[] = $this->connections[$i][1];
            $times[] = $this->connections[$i][3];
        }

        return [
            'type' => 'bus',
            'trip_id' => $trip,
            'stop_ids' => $stopIds,
            'stop_times' => $times,
            'departs_seconds' => $this->connections[$boardIndex][2],
            'arrives_seconds' => $this->connections[$alightIndex][3],
        ];
    }

    private function busLegs(array $journey): int
    {
        return count(array_filter($journey['legs'], fn ($leg) => $leg['type'] === 'bus'));
    }

    private function walkToFirstBus(array $journey): int
    {
        $first = $journey['legs'][0];

        return $first['type'] === 'walk' ? Geo::walkSeconds($first['meters']) : 0;
    }

    /** @return array<string,mixed> */
    private function walkJourney(array $origin, array $destination, int $meters, int $departs, int $arrives): array
    {
        return [
            'legs' => [['type' => 'walk', 'from_point' => $origin, 'to_point' => $destination, 'meters' => $meters, 'departs_seconds' => $departs, 'arrives_seconds' => $arrives]],
            'departs_seconds' => $departs,
            'arrives_seconds' => $arrives,
        ];
    }

    /** Convierte el resultado interno al formato de la API (compatible con el de Reus). */
    private function publicJourney(array $journey, int $start): array
    {
        $tripIds = array_map(fn ($leg) => preg_replace('/^y:/', '', $leg['trip_id']), array_filter($journey['legs'], fn ($leg) => $leg['type'] === 'bus'));
        $trips = DB::table('plana_trips as t')->join('plana_routes as r', 'r.id', '=', 't.route_id')
            ->whereIn('t.id', $tripIds)
            ->get(['t.id', 't.headsign', 't.note', 't.shape_id', 'r.id as route_id', 'r.short_name', 'r.long_name', 'r.color', 'r.text_color'])
            ->keyBy('id');

        $legs = [];
        $walkMeters = 0;
        $busStops = 0;
        foreach ($journey['legs'] as $leg) {
            if ($leg['type'] === 'walk') {
                $walkMeters += $leg['meters'];
                $from = isset($leg['from_point']) ? $this->publicPoint($leg['from_point']) : $this->stopPoint($leg['from_stop']);
                $to = isset($leg['to_point']) ? $this->publicPoint($leg['to_point']) : $this->stopPoint($leg['to_stop']);
                $legs[] = [
                    'type' => 'walk',
                    'from' => $from,
                    'to' => $to,
                    'meters' => $leg['meters'],
                    'minutes' => (int) ceil(Geo::walkSeconds($leg['meters']) / 60),
                    'departs_at' => PlanaNetwork::clock($leg['departs_seconds'] + 86400),
                    'arrives_at' => PlanaNetwork::clock($leg['arrives_seconds'] + 86400),
                ];

                continue;
            }
            $trip = $trips[preg_replace('/^y:/', '', $leg['trip_id'])];
            $stops = array_map(fn ($id, $seconds) => $this->stopPoint($id) + ['time' => PlanaNetwork::clock($seconds + 86400)], $leg['stop_ids'], $leg['stop_times']);
            $hops = count($stops) - 1;
            $busStops += $hops;
            $legs[] = [
                'type' => 'bus',
                'line' => ['id' => $trip->route_id] + PlanaNetwork::lineName($trip->short_name, $trip->long_name) + ['color' => $trip->color, 'text_color' => $trip->text_color],
                'headsign' => $trip->headsign ?? end($stops)['name'],
                'note' => $trip->note,
                'from' => $stops[0],
                'to' => $stops[$hops],
                'stops' => $stops,
                'hops' => $hops,
                'minutes' => (int) round(($leg['arrives_seconds'] - $leg['departs_seconds']) / 60),
                'departs_at' => $stops[0]['time'],
                'arrives_at' => $stops[$hops]['time'],
                'geometry' => $this->geometry($trip->shape_id, $stops),
                'crosses_terminus' => false,
            ];
        }
        $busLegs = array_values(array_filter($legs, fn ($leg) => $leg['type'] === 'bus'));

        return [
            'lines' => array_map(fn ($leg) => $leg['line']['name'], $busLegs),
            'minutes' => (int) round(($journey['arrives_seconds'] - $journey['departs_seconds']) / 60),
            'wait_minutes' => max(0, (int) round(($journey['departs_seconds'] - $start) / 60)),
            'walk_meters' => $walkMeters,
            'transfers' => max(0, count($busLegs) - 1),
            'bus_stops' => $busStops,
            'departs_at' => PlanaNetwork::clock($journey['departs_seconds'] + 86400),
            'arrives_at' => PlanaNetwork::clock($journey['arrives_seconds'] + 86400),
            'arrives_next_day' => $journey['arrives_seconds'] >= 86400,
            'arrives_seconds' => $journey['arrives_seconds'],
            'legs' => $legs,
        ];
    }

    /**
     * Tramo del trazado oficial entre la parada de subida y la de bajada; si la expedición no tiene
     * trazado, une las paradas en línea recta.
     *
     * @param  list<array{lat:float,lng:float}>  $stops
     * @return list<array{0:float,1:float}>
     */
    private function geometry(?string $shapeId, array $stops): array
    {
        $straight = array_map(fn ($stop) => [$stop['lat'], $stop['lng']], $stops);
        $points = $shapeId ? json_decode((string) DB::table('plana_shapes')->where('id', $shapeId)->value('points'), true) : null;
        if (! is_array($points) || count($points) < 2) {
            return $straight;
        }
        $start = $this->nearest($points, $stops[0], 0);
        $end = $this->nearest($points, end($stops), $start);
        if ($end <= $start) {
            return $straight;
        }

        return [[$stops[0]['lat'], $stops[0]['lng']], ...array_slice($points, $start, $end - $start + 1), [end($stops)['lat'], end($stops)['lng']]];
    }

    private function nearest(array $points, array $stop, int $from): int
    {
        $best = $from;
        $bestDistance = INF;
        for ($i = $from; $i < count($points); $i++) {
            $distance = ($points[$i][0] - $stop['lat']) ** 2 + (($points[$i][1] - $stop['lng']) * 0.75) ** 2;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $i;
            }
        }

        return $best;
    }

    /** @return array{name:string,lat:?float,lng:?float,stop:?string} */
    private function endpoint(array $input, string $fallback): array
    {
        if (isset($input['stop'])) {
            $stop = $this->stops[$input['stop']] ?? abort(404, 'Parada no encontrada.');

            return ['name' => $input['label'] ?? $stop->name, 'lat' => (float) $stop->lat, 'lng' => (float) $stop->lng, 'stop' => $stop->id];
        }

        return ['name' => $input['label'] ?? $fallback, 'lat' => (float) $input['lat'], 'lng' => (float) $input['lng'], 'stop' => null];
    }

    /**
     * Paradas cercanas al punto (la elegida, si es una parada, cuenta con 0 m). Si no hay ninguna
     * dentro del radio, se usan igualmente las más próximas.
     *
     * @return list<array{stop:string,meters:int}>
     */
    private function candidates(array $point, int $radius): array
    {
        $nearby = collect($this->stops)
            ->map(fn ($stop) => [
                'stop' => $stop->id,
                'meters' => $stop->id === $point['stop'] ? 0 : Geo::walkMeters($point['lat'], $point['lng'], (float) $stop->lat, (float) $stop->lng),
            ])
            ->sortBy('meters')
            ->values();
        $within = $nearby->filter(fn ($candidate) => $candidate['meters'] <= $radius);

        // Si no hay ninguna en el radio, las más próximas, pero nunca más lejos de lo razonable a pie:
        // un destino a 6 km de cualquier parada queda fuera de la red, no es «82 min andando».
        $fallback = $nearby->filter(fn ($candidate) => $candidate['meters'] <= self::MAX_FALLBACK_WALK_METERS)->take(3);

        return ($within->isNotEmpty() ? $within : $fallback)->take(self::CANDIDATE_STOPS)->values()->all();
    }

    private function walkMeters(array $a, array $b): ?int
    {
        return Geo::walkMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
    }

    /** @return array{code:?string,name:string,lat:?float,lng:?float} */
    private function publicPoint(array $point): array
    {
        return ['code' => $point['stop'], 'name' => $point['name'], 'lat' => $point['lat'], 'lng' => $point['lng']];
    }

    /** @return array{code:string,name:string,lat:float,lng:float} */
    private function stopPoint(string $id): array
    {
        $stop = $this->stops[$id];

        return ['code' => $stop->id, 'name' => $stop->name, 'lat' => (float) $stop->lat, 'lng' => (float) $stop->lng];
    }
}
