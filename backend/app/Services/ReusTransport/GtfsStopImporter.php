<?php

namespace App\Services\ReusTransport;

use App\Models\Stop;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GtfsStopImporter
{
    /** @return array{rows:int,matched:int,updated:int,unchanged:int,not_in_local:list<string>,local_without_gtfs:list<string>} */
    public function import(string $csv, bool $dryRun = false): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new InvalidArgumentException('No se pudo abrir el contenido de stops.txt.');
        }
        fwrite($stream, $csv);
        rewind($stream);

        $headers = fgetcsv($stream, escape: '');
        if (! is_array($headers)) {
            throw new InvalidArgumentException('stops.txt está vacío.');
        }
        $headers = array_map(fn ($header) => trim((string) $header, "\xEF\xBB\xBF \t\n\r\0\x0B"), $headers);
        foreach (['stop_id', 'stop_lat', 'stop_lon'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new InvalidArgumentException("stops.txt no contiene la columna obligatoria $required.");
            }
        }

        $rows = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $values);
            $id = trim((string) $row['stop_id']);
            $latitude = filter_var($row['stop_lat'], FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($row['stop_lon'], FILTER_VALIDATE_FLOAT);
            if ($id === '' || $latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }
            $rows[$id] = ['latitude' => $latitude, 'longitude' => $longitude];
        }
        fclose($stream);

        $local = Stop::whereIn('external_id', array_keys($rows))->get()->keyBy('external_id');
        $updated = 0;
        $unchanged = 0;
        DB::transaction(function () use ($rows, $local, $dryRun, &$updated, &$unchanged): void {
            foreach ($local as $id => $stop) {
                $coordinates = $rows[$id];
                $changed = (float) $stop->latitude !== (float) $coordinates['latitude'] || (float) $stop->longitude !== (float) $coordinates['longitude'];
                if (! $changed) {
                    $unchanged++;

                    continue;
                }
                $updated++;
                if (! $dryRun) {
                    $stop->update($coordinates);
                }
            }
        });

        $localIds = Stop::pluck('external_id')->all();

        return [
            'rows' => count($rows),
            'matched' => $local->count(),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'not_in_local' => array_map('strval', array_values(array_diff(array_keys($rows), $localIds))),
            'local_without_gtfs' => array_map('strval', array_values(array_diff($localIds, array_keys($rows)))),
        ];
    }
}
