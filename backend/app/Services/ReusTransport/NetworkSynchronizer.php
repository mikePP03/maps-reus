<?php

namespace App\Services\ReusTransport;

use App\Models\BusLine;
use App\Models\Stop;
use Illuminate\Support\Facades\DB;

class NetworkSynchronizer
{
    public function __construct(private AmersamClient $client, private HtmlParser $parser) {}

    /**
     * Con $prune, una sincronización completa desactiva las líneas que ya no publica el proveedor
     * y las paradas que han quedado sin ninguna línea activa.
     *
     * @return array<string,int|list<string>>
     */
    public function sync(?string $onlyLine = null, bool $dryRun = false, bool $prune = true, int $pauseMs = 350): array
    {
        $lines = $onlyLine ? [['external_id' => 'L'.ltrim(strtoupper($onlyLine), 'L'), 'public_name' => ltrim(strtoupper($onlyLine), 'L')]] : $this->parser->lines($this->client->home());
        $stats = ['lines_discovered' => count($lines), 'lines_synced' => 0, 'stops_created' => 0, 'stops_updated' => 0, 'relations_created' => 0, 'relations_removed' => 0, 'lines_deactivated' => 0, 'stops_deactivated' => 0, 'errors' => []];
        foreach ($lines as $index => $data) {
            try {
                $stops = $this->parser->stops($this->client->line($data['public_name']));
                if (! $dryRun) {
                    DB::transaction(function () use ($data, $stops, &$stats): void {
                        $this->storeLine($data, $stops, $stats);
                    });
                }
                $stats['lines_synced']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = $data['external_id'].': '.$e->getMessage();
            }
            if ($index < count($lines) - 1 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        if ($prune && ! $dryRun && ! $onlyLine && $lines !== []) {
            $this->deactivateMissing(array_column($lines, 'external_id'), $stats);
        }

        return $stats;
    }

    /**
     * El orden publicado es la fuente de verdad: se reemplaza el recorrido completo de la línea.
     *
     * @param  array{external_id:string,public_name:string}  $data
     * @param  list<array{external_id:string,name:string,stop_sequence:int}>  $stops
     */
    private function storeLine(array $data, array $stops, array &$stats): void
    {
        $now = now();
        $line = BusLine::updateOrCreate(['external_id' => $data['external_id']], ['public_name' => $data['public_name'], 'source_url' => config('services.reus_transport.base_url').'/lines/'.$data['public_name'], 'active' => true, 'last_synced_at' => $now]);
        $rows = [];
        $seen = [];
        foreach ($stops as $item) {
            if (! isset($seen[$item['external_id']])) {
                $stop = Stop::firstOrNew(['external_id' => $item['external_id']]);
                $stop->exists ? $stats['stops_updated']++ : $stats['stops_created']++;
                $stop->fill(['name' => $item['name'], 'active' => true, 'last_synced_at' => $now])->save();
                $seen[$item['external_id']] = $stop->id;
            }
            $rows[] = ['bus_line_id' => $line->id, 'stop_id' => $seen[$item['external_id']], 'stop_sequence' => $item['stop_sequence'], 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }

        $key = fn ($row) => $row['stop_sequence'].':'.$row['stop_id'];
        $existing = DB::table('bus_line_stop')->where('bus_line_id', $line->id)->get(['stop_sequence', 'stop_id'])->map(fn ($row) => $key((array) $row))->all();
        $incoming = array_map($key, $rows);
        $stats['relations_created'] += count(array_diff($incoming, $existing));
        $stats['relations_removed'] += count(array_diff($existing, $incoming));

        DB::table('bus_line_stop')->where('bus_line_id', $line->id)->delete();
        DB::table('bus_line_stop')->insert($rows);
    }

    /** @param list<string> $discovered */
    private function deactivateMissing(array $discovered, array &$stats): void
    {
        DB::transaction(function () use ($discovered, &$stats): void {
            $stats['lines_deactivated'] = BusLine::where('active', true)->whereNotIn('external_id', $discovered)->update(['active' => false]);
            $stats['stops_deactivated'] = Stop::where('active', true)->whereDoesntHave('busLines', fn ($query) => $query->where('bus_lines.active', true))->update(['active' => false]);
        });
    }
}
