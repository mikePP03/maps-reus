<?php

namespace App\Services\ReusTransport;

use App\Exceptions\InvalidLineStopCombination;
use App\Models\BusLine;
use App\Models\Stop;

class CombinationService
{
    public function normalizeLine(string $line): string
    {
        $line = strtoupper(trim($line));

        return str_starts_with($line, 'L') ? $line : 'L'.$line;
    }

    public function combinationExists(string $line, string $stop): bool
    {
        return BusLine::where('external_id', $this->normalizeLine($line))->where('active', true)->whereHas('stops', fn ($query) => $query->where('stops.external_id', trim($stop))->where('stops.active', true))->exists();
    }

    /** @return array{0:BusLine,1:Stop} */
    public function require(string $line, string $stop): array
    {
        $id = $this->normalizeLine($line);
        $busLine = BusLine::where('external_id', $id)->where('active', true)->first();
        $found = $busLine?->stops()->where('stops.external_id', trim($stop))->where('stops.active', true)->first();
        if (! $busLine || ! $found) {
            throw new InvalidLineStopCombination("La línea $id no pasa por la parada ".trim($stop).'.');
        }

        return [$busLine, $found];
    }
}
