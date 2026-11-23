<?php

namespace App\Services\ReusTransport;

use App\Models\BusLine;
use App\Models\Stop;
use Illuminate\Support\Collection;

class DirectRouteService
{
    /** @return list<array<string, mixed>> */
    public function find(string $origin, string $destination): array
    {
        $servesStop = fn (string $id) => fn ($query) => $query->where('stops.external_id', $id)->where('stops.active', true);

        return BusLine::query()
            ->where('active', true)
            ->whereHas('stops', $servesStop($origin))
            ->whereHas('stops', $servesStop($destination))
            ->with(['stops' => fn ($query) => $query->where('stops.active', true)])
            ->get()
            ->map(function (BusLine $line) use ($origin, $destination) {
                $circular = $this->isCircular($line->stops);
                $segment = $this->shortestSegment($line->stops, $origin, $destination, $circular);
                if ($segment === null) {
                    return null;
                }

                return [
                    'line' => [
                        'id' => $line->external_id,
                        'name' => $line->public_name,
                        'description' => $line->name,
                    ],
                    'circular' => $circular,
                    'crosses_terminus' => $segment['crosses_terminus'],
                    'stop_count' => $segment['stops']->count(),
                    'intermediate_stop_count' => max(0, $segment['stops']->count() - 2),
                    'stops' => $segment['stops']->map(fn (Stop $stop) => [
                        'id' => $stop->external_id,
                        'name' => $stop->name,
                        'latitude' => $stop->latitude === null ? null : (float) $stop->latitude,
                        'longitude' => $stop->longitude === null ? null : (float) $stop->longitude,
                        'sequence' => (int) $stop->pivot->stop_sequence,
                    ])->all(),
                ];
            })
            ->filter()
            ->sortBy('stop_count')
            ->values()
            ->all();
    }

    /**
     * Paradas unidas a $stop por una línea directa: con $forward, las que se alcanzan saliendo de ella;
     * sin él, desde las que se llega a ella. Sigue las mismas reglas que find(), así que cualquier
     * pareja devuelta tiene al menos una ruta directa.
     *
     * @return list<array{code:string,name:string,has_coordinates:bool,lines:list<string>}>
     */
    public function connections(string $stop, bool $forward = true): array
    {
        $found = [];
        $lines = BusLine::query()
            ->where('active', true)
            ->whereHas('stops', fn ($query) => $query->where('stops.external_id', $stop)->where('stops.active', true))
            ->with(['stops' => fn ($query) => $query->where('stops.active', true)])
            ->get()
            ->sortBy('public_name', SORT_NATURAL);

        foreach ($lines as $line) {
            $stops = $line->stops->values();
            $positions = $stops->keys()->filter(fn (int $index) => $stops[$index]->external_id === $stop);
            // En una circular se puede seguir tras la cabecera, así que toda la vuelta es alcanzable.
            $reachable = $this->isCircular($stops)
                ? $stops
                : $stops->filter(fn (Stop $candidate, int $index) => $forward
                    ? $index > $positions->min()
                    : $index < $positions->max());

            foreach ($reachable as $candidate) {
                if ($candidate->external_id === $stop) {
                    continue;
                }
                $found[$candidate->external_id] ??= [
                    'code' => $candidate->external_id,
                    'name' => $candidate->name,
                    'has_coordinates' => $candidate->latitude !== null && $candidate->longitude !== null,
                    'lines' => [],
                ];
                if (! in_array($line->public_name, $found[$candidate->external_id]['lines'], true)) {
                    $found[$candidate->external_id]['lines'][] = $line->public_name;
                }
            }
        }

        return collect($found)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Una línea es circular cuando su recorrido termina en la misma parada en la que empieza. */
    private function isCircular(Collection $stops): bool
    {
        return $stops->count() > 2 && $stops->first()->external_id === $stops->last()->external_id;
    }

    /**
     * Elige el tramo más corto en sentido de marcha entre cualquier paso por el origen y cualquier
     * paso posterior por el destino. En una circular también vale seguir tras la cabecera y volver
     * a empezar la vuelta, ya que el último elemento y el primero son la misma parada.
     *
     * @param  Collection<int,Stop>  $stops
     * @return array{stops:Collection<int,Stop>,crosses_terminus:bool}|null
     */
    private function shortestSegment(Collection $stops, string $origin, string $destination, bool $circular): ?array
    {
        $ids = $stops->pluck('external_id')->all();
        $last = count($ids) - 1;
        $origins = array_keys($ids, $origin, true);
        $destinations = array_keys($ids, $destination, true);
        $best = null;

        foreach ($origins as $from) {
            foreach ($destinations as $to) {
                if ($to > $from) {
                    $length = $to - $from;
                    $candidate = ['length' => $length, 'stops' => $stops->slice($from, $length + 1)->values(), 'crosses_terminus' => false];
                } elseif ($circular && $from < $last && $to > 0) {
                    $length = ($last - $from) + $to;
                    $candidate = ['length' => $length, 'stops' => $stops->slice($from)->concat($stops->slice(1, $to))->values(), 'crosses_terminus' => true];
                } else {
                    continue;
                }
                if ($best === null || $candidate['length'] < $best['length']) {
                    $best = $candidate;
                }
            }
        }

        return $best === null ? null : ['stops' => $best['stops'], 'crosses_terminus' => $best['crosses_terminus']];
    }
}
