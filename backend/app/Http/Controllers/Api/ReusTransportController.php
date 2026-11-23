<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArrivalRequest;
use App\Http\Requests\DirectRouteRequest;
use App\Http\Requests\JourneyRequest;
use App\Models\BusLine;
use App\Models\Stop;
use App\Services\ReusTransport\ArrivalService;
use App\Services\ReusTransport\CombinationService;
use App\Services\ReusTransport\DirectRouteService;
use App\Services\ReusTransport\JourneyEta;
use App\Services\ReusTransport\JourneyPlanner;
use App\Services\ReusTransport\PlaceSearch;
use App\Services\ReusTransport\StreetRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReusTransportController extends Controller
{
    public function __construct(private ArrivalService $arrivals, private CombinationService $combinations) {}

    public function lines(): JsonResponse
    {
        return $this->ok(Cache::remember('reus:lines', 300, fn () => BusLine::where('active', true)->get()->sortBy('public_name', SORT_NATURAL)->values()));
    }

    public function line(string $line): JsonResponse
    {
        return $this->ok(BusLine::where('external_id', $this->combinations->normalizeLine($line))->where('active', true)->firstOrFail());
    }

    public function lineStops(string $line): JsonResponse
    {
        $model = BusLine::where('external_id', $this->combinations->normalizeLine($line))->where('active', true)->firstOrFail();

        return $this->ok($model->stops()->where('stops.active', true)->get());
    }

    public function stops(Request $request): JsonResponse
    {
        $v = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Stop::where('active', true);
        if ($search = $v['search'] ?? null) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("external_id like ? escape '!'", [$like])->orWhereRaw("name like ? escape '!'", [$like]));
        }
        $page = $query->orderBy('name')->paginate($v['per_page'] ?? 20);

        return $this->ok($page->items(), ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function mapStops(): JsonResponse
    {
        $data = Cache::remember('reus:map-stops', 300, fn () => Stop::query()
            ->where('active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['busLines' => fn ($query) => $query
                ->where('bus_lines.active', true)
                ->select(['bus_lines.id', 'external_id', 'public_name', 'name'])])
            ->orderBy('name')
            ->get()
            ->map(fn (Stop $stop) => [
                'id' => (string) $stop->id,
                'code' => $stop->external_id,
                'name' => $stop->name,
                'latitude' => (float) $stop->latitude,
                'longitude' => (float) $stop->longitude,
                'lines' => $stop->busLines->map(fn (BusLine $line) => [
                    'id' => $line->external_id,
                    'name' => $line->public_name,
                    'description' => $line->name,
                ])->values(),
            ])->values());

        return $this->ok($data, ['count' => $data->count()]);
    }

    public function directRoutes(DirectRouteRequest $request, DirectRouteService $routes): JsonResponse
    {
        $data = $routes->find($request->string('origin'), $request->string('destination'));

        return $this->ok($data, [
            'origin' => $request->string('origin')->toString(),
            'destination' => $request->string('destination')->toString(),
            'count' => count($data),
            'geometry' => 'approximate_stop_polyline',
        ]);
    }

    public function journeys(JourneyRequest $request, JourneyPlanner $planner): JsonResponse
    {
        $result = $planner->plan($request->point('from'), $request->point('to'), (int) ($request->input('walk_radius') ?? 600));

        return $this->ok($result['journeys'], [
            'origin' => $result['origin'],
            'destination' => $result['destination'],
            'count' => count($result['journeys']),
            'estimates' => 'Tiempos aproximados: no tienen en cuenta horarios ni esperas.',
        ]);
    }

    /**
     * Horas reales de un viaje: `legs[i][line]`, `legs[i][stop]` (parada donde se sube) y
     * `legs[i][ride]` (minutos en el bus), más los minutos a pie antes y después.
     */
    public function journeyEta(Request $request, JourneyEta $eta): JsonResponse
    {
        $v = $request->validate([
            'legs' => ['required', 'array', 'min:1', 'max:3'],
            'legs.*.line' => ['required', 'string', 'regex:/^[Ll]?\d+$/', 'max:20'],
            'legs.*.stop' => ['required', 'string', 'regex:/^[\pL\pN_-]+$/u', 'max:50'],
            'legs.*.ride' => ['required', 'integer', 'min:0', 'max:240'],
            'walk_before' => ['nullable', 'integer', 'min:0', 'max:120'],
            'walk_after' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);
        $legs = array_map(fn ($leg) => ['line' => $leg['line'], 'stop' => $leg['stop'], 'ride' => (int) $leg['ride']], $v['legs']);

        return $this->ok($eta->calculate($legs, (int) ($v['walk_before'] ?? 0), (int) ($v['walk_after'] ?? 0)));
    }

    /** Camino a pie por calles entre dos puntos de Reus; null si el servicio de rutas no responde. */
    public function walkPath(Request $request, StreetRouter $router): JsonResponse
    {
        // Limitado a Cataluña (Reus y la red Plana) para no servir de proxy general del servicio de rutas.
        $lat = ['required', 'numeric', 'between:40.5,42.9'];
        $lng = ['required', 'numeric', 'between:0.15,3.35'];
        $v = $request->validate(['from_lat' => $lat, 'from_lng' => $lng, 'to_lat' => $lat, 'to_lng' => $lng]);

        return $this->ok($router->walk((float) $v['from_lat'], (float) $v['from_lng'], (float) $v['to_lat'], (float) $v['to_lng']));
    }

    public function places(Request $request, PlaceSearch $places): JsonResponse
    {
        $v = $request->validate(['q' => ['required', 'string', 'min:3', 'max:100'], 'area' => ['nullable', 'in:reus,catalunya']]);

        return $this->ok($places->search($v['q'], $v['area'] ?? 'reus'));
    }

    public function stopConnections(Request $request, string $stop, DirectRouteService $routes): JsonResponse
    {
        $v = $request->validate(['direction' => ['nullable', 'in:from,to']]);
        $model = Stop::where('external_id', $stop)->where('active', true)->firstOrFail();
        $direction = $v['direction'] ?? 'from';
        $data = Cache::remember("reus:connections:{$model->external_id}:$direction", 300, fn () => $routes->connections($model->external_id, $direction === 'from'));

        return $this->ok($data, ['stop' => $model->external_id, 'direction' => $direction, 'count' => count($data)]);
    }

    public function stop(string $stop): JsonResponse
    {
        return $this->ok(Stop::where('external_id', $stop)->where('active', true)->with('busLines')->firstOrFail());
    }

    public function stopLines(string $stop): JsonResponse
    {
        $model = Stop::where('external_id', $stop)->where('active', true)->firstOrFail();

        return $this->ok($model->busLines()->where('bus_lines.active', true)->get());
    }

    public function arrival(ArrivalRequest $request): JsonResponse
    {
        return $this->ok($this->arrivals->getArrival($request->string('line'), $request->string('stop')));
    }

    public function schedule(ArrivalRequest $request): JsonResponse
    {
        return $this->ok($this->arrivals->schedule($request->string('line'), $request->string('stop')));
    }

    public function stopArrivals(string $stop): JsonResponse
    {
        return $this->ok($this->arrivals->forStop($stop));
    }

    public function nearby(Request $request): JsonResponse
    {
        $v = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'radius' => ['nullable', 'numeric', 'min:0.05', 'max:20']]);
        $lat = (float) $v['latitude'];
        $lon = (float) $v['longitude'];
        $radius = (float) ($v['radius'] ?? 1);
        $latDelta = $radius / 111.32;
        $lonDelta = $radius / max(1.0, 111.32 * cos(deg2rad($lat)));
        $data = Stop::where('active', true)->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])->whereBetween('longitude', [$lon - $lonDelta, $lon + $lonDelta])->get()->map(function (Stop $stop) use ($lat, $lon) {
            $latDistance = deg2rad((float) $stop->latitude - $lat);
            $lonDistance = deg2rad((float) $stop->longitude - $lon);
            $a = sin($latDistance / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $stop->latitude)) * sin($lonDistance / 2) ** 2;
            $stop->setAttribute('distance_km', 6371 * 2 * asin(min(1, sqrt($a))));

            return $stop;
        })->filter(fn (Stop $stop) => $stop->getAttribute('distance_km') <= $radius)->sortBy('distance_km')->take(100)->values();

        return $this->ok($data, ['radius_km' => $radius]);
    }

    private function ok(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => (object) $meta]);
    }
}
