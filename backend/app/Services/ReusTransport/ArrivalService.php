<?php

namespace App\Services\ReusTransport;

use App\Models\BusLine;
use App\Models\Stop;
use Illuminate\Support\Facades\Cache;

class ArrivalService
{
    private const ARRIVAL_TTL = 20;

    public function __construct(private AmersamClient $client, private CombinationService $combinations, private HtmlParser $parser) {}

    /** @return array<string,mixed> */
    public function getArrival(string $line, string $stop): array
    {
        [$busLine, $busStop] = $this->combinations->require($line, $stop);

        return Cache::remember($this->cacheKey($busLine, $busStop), self::ARRIVAL_TTL, fn () => $this->format($busLine, $busStop, $this->client->arrival($busLine->external_id, $busStop->external_id)));
    }

    /**
     * Las líneas que no están en caché se piden al proveedor en paralelo.
     *
     * @return list<array<string,mixed>>
     */
    public function forStop(string $stop): array
    {
        $model = Stop::where('external_id', trim($stop))->where('active', true)->with(['busLines' => fn ($q) => $q->where('active', true)])->firstOrFail();
        $lines = $model->busLines->sortBy('public_name', SORT_NATURAL)->values();

        $cached = $lines->mapWithKeys(fn (BusLine $line) => [$line->external_id => Cache::get($this->cacheKey($line, $model))])->filter();
        $missing = $lines->reject(fn (BusLine $line) => $cached->has($line->external_id));
        $fetched = $missing->isEmpty() ? [] : $this->client->arrivalsForStop($missing->pluck('external_id')->all(), $model->external_id);

        return $lines->map(function (BusLine $line) use ($model, $cached, $fetched) {
            $arrival = $cached->get($line->external_id);
            if ($arrival === null && ($json = $fetched[$line->external_id] ?? null) !== null) {
                $arrival = $this->format($line, $model, $json);
                Cache::put($this->cacheKey($line, $model), $arrival, self::ARRIVAL_TTL);
            }
            if ($arrival === null) {
                return ['line' => ['id' => $line->external_id, 'name' => $line->public_name], 'destination' => $line->name, 'timetoarrival' => null, 'available' => false, 'error' => 'Proveedor no disponible'];
            }

            return ['line' => $arrival['line'], 'destination' => $line->name, 'timetoarrival' => $arrival['timetoarrival'], 'available' => true, 'error' => null];
        })->all();
    }

    /** @return array<string,mixed> */
    public function schedule(string $line, string $stop): array
    {
        [$busLine, $busStop] = $this->combinations->require($line, $stop);

        return Cache::remember("reus:schedule:{$busLine->external_id}:{$busStop->external_id}", 21600, fn () => ['line' => $busLine->external_id, 'stop' => $busStop->external_id] + $this->parser->schedule($this->client->schedule($busLine->external_id, $busStop->external_id)));
    }

    private function cacheKey(BusLine $line, Stop $stop): string
    {
        return "reus:arrival:{$line->external_id}:{$stop->external_id}";
    }

    /**
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function format(BusLine $line, Stop $stop, array $json): array
    {
        $value = $json['timetoarrival'] ?? null;
        $value = is_scalar($value) ? (string) $value : null;

        return ['line' => ['id' => $line->external_id, 'name' => $line->public_name], 'stop' => ['id' => $stop->external_id, 'name' => $stop->name], 'timetoarrival' => $value, 'has_realtime_prediction' => $value !== null && ! preg_match('/CONSULTEU\s+HORARI/i', $value), 'fetched_at' => now()->toIso8601String()];
    }
}
