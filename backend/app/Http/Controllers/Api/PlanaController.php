<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanaJourneyRequest;
use App\Services\Plana\PlanaJourneyPlanner;
use App\Services\Plana\PlanaNetwork;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** API de la red interurbana de Plana (datos del GTFS de la Generalitat). */
class PlanaController extends Controller
{
    public function __construct(private PlanaNetwork $network) {}

    public function feed(): JsonResponse
    {
        return $this->ok($this->network->feed());
    }

    public function lines(): JsonResponse
    {
        return $this->ok($this->network->lines());
    }

    public function line(string $line): JsonResponse
    {
        return $this->ok($this->network->line($line) ?? abort(404));
    }

    /** Horario completo de la línea para un día (`date`, por defecto hoy). */
    public function timetable(Request $request, string $line): JsonResponse
    {
        $date = $this->when($request)->format('Y-m-d');

        return $this->ok($this->network->timetable($line, $date) ?? abort(404));
    }

    public function mapStops(): JsonResponse
    {
        $stops = $this->network->mapStops();

        return $this->ok($stops, ['count' => count($stops)]);
    }

    /** Catálogo para los selectores (mismo formato que /reus/stops). */
    public function stops(Request $request): JsonResponse
    {
        $v = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:2000']]);
        $stops = DB::table('plana_stops')->orderBy('name')->get(['id', 'name', 'lat', 'lng'])
            ->map(fn ($stop) => ['external_id' => $stop->id, 'name' => $stop->name, 'latitude' => (string) $stop->lat, 'longitude' => (string) $stop->lng]);

        return $this->ok($stops->take((int) ($v['per_page'] ?? 2000))->values(), ['current_page' => 1, 'last_page' => 1, 'total' => $stops->count()]);
    }

    public function departures(Request $request, string $stop): JsonResponse
    {
        abort_unless(DB::table('plana_stops')->where('id', $stop)->exists(), 404);

        return $this->ok($this->network->departures($stop, $this->when($request)));
    }

    public function journeys(PlanaJourneyRequest $request, PlanaJourneyPlanner $planner): JsonResponse
    {
        $when = $this->when($request);
        $result = $planner->planOrNext($request->point('from'), $request->point('to'), $when, (int) ($request->input('walk_radius') ?? 1200));

        return $this->ok($result['journeys'], [
            'origin' => $result['origin'],
            'destination' => $result['destination'],
            // Día de los resultados: puede ser posterior al pedido si ese día no quedaban autobuses.
            'date' => $result['date']->format('Y-m-d'),
            'requested_date' => $when->format('Y-m-d'),
            'time' => $when->format('H:i'),
            'next_available' => $result['next_available'],
            // Origen o destino sin ninguna parada a menos de 2,5 km a pie: fuera de la red.
            'out_of_area' => $result['journeys'] === [] ? [
                'origin' => $planner->isOutOfArea($request->point('from')),
                'destination' => $planner->isOutOfArea($request->point('to')),
            ] : null,
            'count' => count($result['journeys']),
            'source' => 'Horarios oficiales de la Generalitat de Catalunya (GTFS). Sin tiempo real.',
        ]);
    }

    /**
     * Todas las salidas que quedan en el día para un trayecto (o las del próximo día con
     * servicio), cada una con su itinerario completo.
     */
    public function dayJourneys(PlanaJourneyRequest $request, PlanaJourneyPlanner $planner): JsonResponse
    {
        $when = $this->when($request);
        $result = $planner->planDay($request->point('from'), $request->point('to'), $when, (int) ($request->input('walk_radius') ?? 1200));

        return $this->ok($result['journeys'], [
            'origin' => $result['origin'],
            'destination' => $result['destination'],
            'date' => $result['date']->format('Y-m-d'),
            'requested_date' => $when->format('Y-m-d'),
            'time' => $when->format('H:i'),
            'next_available' => $result['next_available'],
            // Origen o destino sin ninguna parada a menos de 2,5 km a pie: fuera de la red.
            'out_of_area' => $result['journeys'] === [] ? [
                'origin' => $planner->isOutOfArea($request->point('from')),
                'destination' => $planner->isOutOfArea($request->point('to')),
            ] : null,
            'count' => count($result['journeys']),
            'source' => 'Horarios oficiales de la Generalitat de Catalunya (GTFS). Sin tiempo real.',
        ]);
    }

    /** Fecha y hora de la consulta (`date` Y-m-d y `time` H:i), por defecto ahora en Reus. */
    private function when(Request $request): CarbonImmutable
    {
        $v = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
        ]);
        $now = $this->network->now();
        if (! isset($v['date']) && ! isset($v['time'])) {
            return $now;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', ($v['date'] ?? $now->format('Y-m-d')).' '.($v['time'] ?? $now->format('H:i')), $now->timezone);
    }

    private function ok(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => (object) $meta]);
    }
}
