<?php

namespace Tests\Feature;

use App\Models\BusLine;
use App\Models\Stop;
use App\Services\ReusTransport\CombinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReusTransportApiTest extends TestCase
{
    use RefreshDatabase;

    private function network(): array
    {
        $line = BusLine::create(['external_id' => 'L60', 'public_name' => '60', 'active' => true]);
        $stop = Stop::create(['external_id' => '93', 'name' => 'Oques 3', 'latitude' => 41.1545, 'longitude' => 1.1087, 'active' => true]);
        $line->stops()->attach($stop, ['stop_sequence' => 1, 'verified_at' => now()]);

        return [$line, $stop];
    }

    public function test_normalizes_and_validates_combinations(): void
    {
        $this->network();
        $service = app(CombinationService::class);
        $this->assertSame('L60', $service->normalizeLine('60'));
        $this->assertSame('L60', $service->normalizeLine('l60'));
        $this->assertTrue($service->combinationExists('L60', '93'));
        $this->assertFalse($service->combinationExists('L60', '125'));
    }

    public function test_arrival_handles_minutes_and_schedule_message(): void
    {
        $this->network();
        Http::fake(['*/timetoarrival_real_time/L60/93' => Http::sequence()->push(['timetoarrival' => '5 min'])->push(['timetoarrival' => 'CONSULTEU HORARI'])]);
        $this->getJson('/api/v1/reus/arrivals?line=60&stop=93')->assertOk()->assertJsonPath('data.timetoarrival', '5 min')->assertJsonPath('data.has_realtime_prediction', true);
        cache()->flush();
        $this->getJson('/api/v1/reus/arrivals?line=L60&stop=93')->assertOk()->assertJsonPath('data.has_realtime_prediction', false);
    }

    public function test_invalid_combination_never_calls_provider(): void
    {
        $this->network();
        Http::fake();
        $this->getJson('/api/v1/reus/arrivals?line=L60&stop=125')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_stop_search_and_nearby(): void
    {
        $this->network();
        $this->getJson('/api/v1/reus/stops?search=Oques')->assertOk()->assertJsonPath('data.0.external_id', '93');
        $this->getJson('/api/v1/reus/stops/nearby?latitude=41.1545&longitude=1.1087&radius=1')->assertOk()->assertJsonPath('data.0.external_id', '93');
    }

    public function test_map_stops_returns_coordinates_and_valid_lines(): void
    {
        $this->network();

        $this->getJson('/api/v1/reus/map-stops')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.code', '93')
            ->assertJsonPath('data.0.lines.0.id', 'L60');
    }

    public function test_direct_route_respects_stop_sequence(): void
    {
        [$line] = $this->network();
        $destination = Stop::create(['external_id' => '198', 'name' => 'Hospital 1', 'latitude' => 41.16, 'longitude' => 1.12, 'active' => true]);
        $line->stops()->attach($destination, ['stop_sequence' => 2, 'verified_at' => now()]);

        $this->getJson('/api/v1/reus/routes/direct?origin=93&destination=198')
            ->assertOk()
            ->assertJsonPath('meta.geometry', 'approximate_stop_polyline')
            ->assertJsonPath('data.0.line.id', 'L60')
            ->assertJsonPath('data.0.stops.0.id', '93')
            ->assertJsonPath('data.0.stops.1.id', '198');

        $this->getJson('/api/v1/reus/routes/direct?origin=198&destination=93')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Recorrido circular 13 → 52 → 97 → 120 → 13, como las líneas L10 y L11. */
    private function circularLine(): BusLine
    {
        $line = BusLine::create(['external_id' => 'L10', 'public_name' => '10', 'active' => true]);
        foreach (['13', '52', '97', '120', '13'] as $index => $code) {
            $stop = Stop::firstOrCreate(['external_id' => $code], ['name' => "Parada $code", 'active' => true]);
            $line->stops()->attach($stop, ['stop_sequence' => $index + 1, 'verified_at' => now()]);
        }

        return $line;
    }

    public function test_direct_route_can_end_at_the_terminus_of_a_circular_line(): void
    {
        $this->circularLine();

        $this->getJson('/api/v1/reus/routes/direct?origin=97&destination=13')
            ->assertOk()
            ->assertJsonPath('data.0.circular', true)
            ->assertJsonPath('data.0.crosses_terminus', false)
            ->assertJsonPath('data.0.stop_count', 3)
            ->assertJsonPath('data.0.stops.2.sequence', 5);
    }

    public function test_direct_route_continues_past_the_terminus_of_a_circular_line(): void
    {
        $this->circularLine();

        $response = $this->getJson('/api/v1/reus/routes/direct?origin=120&destination=52')
            ->assertOk()
            ->assertJsonPath('data.0.crosses_terminus', true)
            ->assertJsonPath('data.0.stop_count', 3);
        $this->assertSame(['120', '13', '52'], array_column($response->json('data.0.stops'), 'id'));
    }

    public function test_connections_only_list_stops_reachable_in_the_direction_of_travel(): void
    {
        [$line] = $this->network();
        $next = Stop::create(['external_id' => '198', 'name' => 'Hospital 1', 'active' => true]);
        $line->stops()->attach($next, ['stop_sequence' => 2]);

        $this->getJson('/api/v1/reus/stops/93/connections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '198')
            ->assertJsonPath('data.0.lines', ['60']);
        $this->getJson('/api/v1/reus/stops/198/connections')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reus/stops/198/connections?direction=to')
            ->assertOk()
            ->assertJsonPath('data.0.code', '93');
        $this->getJson('/api/v1/reus/stops/93/connections?direction=sideways')->assertUnprocessable();
    }

    public function test_every_connection_has_a_direct_route(): void
    {
        $this->circularLine();
        [$line] = $this->network();
        $line->stops()->attach(Stop::where('external_id', '52')->first(), ['stop_sequence' => 2]);

        foreach (['13', '52', '97', '120', '93'] as $origin) {
            foreach ($this->getJson("/api/v1/reus/stops/$origin/connections")->json('data') as $connection) {
                $this->getJson("/api/v1/reus/routes/direct?origin=$origin&destination={$connection['code']}")
                    ->assertOk()
                    ->assertJsonPath('meta.count', fn (int $count) => $count > 0);
            }
        }
        // En la circular, desde 120 también se llega a 52 pasando por la cabecera.
        $this->assertContains('52', array_column($this->getJson('/api/v1/reus/stops/120/connections')->json('data'), 'code'));
    }

    public function test_stop_on_circular_line_lists_the_line_once(): void
    {
        $this->circularLine();

        $this->getJson('/api/v1/reus/stops/13/lines')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reus/stops/13')->assertOk()->assertJsonCount(1, 'data.bus_lines');
        $this->getJson('/api/v1/reus/lines/L10/stops')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_stop_arrivals_query_lines_concurrently_and_report_failures(): void
    {
        [, $stop] = $this->network();
        BusLine::create(['external_id' => 'L10', 'public_name' => '10', 'active' => true])->stops()->attach($stop, ['stop_sequence' => 1]);
        Http::fake([
            '*/timetoarrival_real_time/L60/93' => Http::response(['timetoarrival' => '3 min']),
            '*/timetoarrival_real_time/L10/93' => Http::response('', 500),
        ]);

        $this->getJson('/api/v1/reus/stops/93/arrivals')
            ->assertOk()
            ->assertJsonPath('data.0.line.id', 'L10')
            ->assertJsonPath('data.0.available', false)
            ->assertJsonPath('data.1.line.id', 'L60')
            ->assertJsonPath('data.1.timetoarrival', '3 min');
        Http::assertSentCount(2);

        $this->getJson('/api/v1/reus/arrivals?line=L60&stop=93')->assertOk()->assertJsonPath('data.timetoarrival', '3 min');
        Http::assertSentCount(2);
    }

    public function test_inactive_line_or_stop_is_not_a_valid_combination(): void
    {
        [$line, $stop] = $this->network();
        Http::fake();
        $stop->update(['active' => false]);
        $this->getJson('/api/v1/reus/arrivals?line=L60&stop=93')->assertStatus(422);
        $stop->update(['active' => true]);
        $line->update(['active' => false]);
        $this->getJson('/api/v1/reus/schedules?line=L60&stop=93')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_errors_use_the_api_envelope(): void
    {
        $this->getJson('/api/v1/reus/stops/999')->assertNotFound()->assertJsonPath('success', false);
        $this->getJson('/api/v1/reus/arrivals')->assertUnprocessable()->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['line', 'stop']]);
    }

    public function test_lines_are_sorted_numerically_and_search_escapes_wildcards(): void
    {
        foreach (['60', '10', '2'] as $number) {
            BusLine::create(['external_id' => "L$number", 'public_name' => $number, 'active' => true]);
        }
        Stop::create(['external_id' => '1', 'name' => 'Plaça 50% descompte', 'active' => true]);
        Stop::create(['external_id' => '2', 'name' => 'Plaça 500', 'active' => true]);

        $this->assertSame(['2', '10', '60'], array_column($this->getJson('/api/v1/reus/lines')->json('data'), 'public_name'));
        $this->getJson('/api/v1/reus/stops?search='.urlencode('50%'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', '1');
    }
}
