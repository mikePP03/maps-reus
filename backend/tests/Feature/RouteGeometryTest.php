<?php

namespace Tests\Feature;

use App\Models\BusLine;
use App\Models\RouteSegment;
use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteGeometryTest extends TestCase
{
    use RefreshDatabase;

    /** Respuesta OSRM con un tramo por cada par de puntos y la distancia indicada. */
    private function osrm(array $legs): array
    {
        return ['code' => 'Ok', 'routes' => [['legs' => array_map(fn ($leg) => [
            'distance' => $leg['meters'],
            'steps' => [['geometry' => ['coordinates' => array_map(fn ($point) => [$point[1], $point[0]], $leg['points'])]]],
        ], $legs)]]];
    }

    private function line(): void
    {
        $line = BusLine::create(['external_id' => 'L60', 'public_name' => '60', 'active' => true]);
        foreach (['93' => [41.1500, 1.1000], '123' => [41.1510, 1.1000], '198' => [41.1520, 1.1000]] as $code => [$lat, $lng]) {
            $stop = Stop::create(['external_id' => $code, 'name' => "Parada $code", 'latitude' => $lat, 'longitude' => $lng, 'active' => true]);
            $line->stops()->attach($stop, ['stop_sequence' => $line->stops()->count() + 1]);
        }
    }

    public function test_builds_street_geometry_and_discards_implausible_detours(): void
    {
        $this->line();
        Http::fake(['*/routed-car/*' => Http::response($this->osrm([
            // 93 → 123: ~111 m en recta, 130 m por calle con un punto intermedio.
            ['meters' => 130, 'points' => [[41.1500, 1.1000], [41.1505, 1.1003], [41.1510, 1.1000]]],
            // 123 → 198: 2 km para ~111 m en recta: rodeo descartado.
            ['meters' => 2000, 'points' => [[41.1510, 1.1000], [41.1700, 1.1300], [41.1520, 1.1000]]],
        ]))]);

        $this->artisan('reus:build-geometry')->assertSuccessful();

        $ids = Stop::pluck('id', 'external_id');
        $good = RouteSegment::where('from_stop_id', $ids['93'])->where('to_stop_id', $ids['123'])->firstOrFail();
        $this->assertCount(3, $good->geometry);
        $detour = RouteSegment::where('from_stop_id', $ids['123'])->where('to_stop_id', $ids['198'])->firstOrFail();
        $this->assertCount(2, $detour->geometry);

        // Ya guardado: no vuelve a consultar el servicio.
        $this->artisan('reus:build-geometry')->assertSuccessful();
        Http::assertSentCount(1);

        $legs = $this->getJson('/api/v1/reus/journeys?from_stop=93&to_stop=198')->assertOk()->json('data.0.legs');
        $this->assertSame([[41.15, 1.1], [41.1505, 1.1003], [41.151, 1.1], [41.152, 1.1]], $legs[0]['geometry']);
    }

    public function test_journey_geometry_falls_back_to_straight_lines_without_stored_segments(): void
    {
        $this->line();

        $this->getJson('/api/v1/reus/journeys?from_stop=93&to_stop=198')
            ->assertOk()
            ->assertJsonPath('data.0.legs.0.geometry', [[41.15, 1.1], [41.151, 1.1], [41.152, 1.1]]);
    }

    public function test_walk_path_follows_streets_is_cached_and_limited_to_reus(): void
    {
        Http::fake(['*/routed-foot/*' => Http::response($this->osrm([
            ['meters' => 450, 'points' => [[41.1557, 1.1065], [41.1540, 1.1070], [41.1527, 1.1084]]],
        ]))]);
        $url = '/api/v1/reus/walk-path?from_lat=41.1557&from_lng=1.1065&to_lat=41.1527&to_lng=1.1084';

        $this->getJson($url)->assertOk()->assertJsonPath('data.meters', 450)->assertJsonCount(3, 'data.geometry');
        $this->getJson($url)->assertOk();
        Http::assertSentCount(1);

        $this->getJson('/api/v1/reus/walk-path?from_lat=40.4&from_lng=-3.7&to_lat=40.41&to_lng=-3.71')->assertUnprocessable();
    }

    public function test_walk_path_returns_null_when_the_routing_service_fails(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->getJson('/api/v1/reus/walk-path?from_lat=41.1557&from_lng=1.1065&to_lat=41.1527&to_lng=1.1084')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
