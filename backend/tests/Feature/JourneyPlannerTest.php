<?php

namespace Tests\Feature;

use App\Models\BusLine;
use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JourneyPlannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L60: 93 → 123 → 198 y L31: 198 → 5, con paradas separadas ~1 km entre sí en línea recta.
     */
    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        $stops = [
            '93' => [41.1500, 1.1000],
            '123' => [41.1590, 1.1000],
            '198' => [41.1680, 1.1000],
            '5' => [41.1680, 1.0880],
        ];
        foreach ($stops as $code => [$lat, $lng]) {
            Stop::create(['external_id' => $code, 'name' => "Parada $code", 'latitude' => $lat, 'longitude' => $lng, 'active' => true]);
        }
        foreach (['L60' => ['93', '123', '198'], 'L31' => ['198', '5']] as $id => $codes) {
            $line = BusLine::create(['external_id' => $id, 'public_name' => substr($id, 1), 'active' => true]);
            foreach ($codes as $index => $code) {
                $line->stops()->attach(Stop::where('external_id', $code)->first(), ['stop_sequence' => $index + 1]);
            }
        }
    }

    public function test_direct_journey_between_stops(): void
    {
        $this->getJson('/api/v1/reus/journeys?from_stop=93&to_stop=198')
            ->assertOk()
            ->assertJsonPath('data.0.lines', ['60'])
            ->assertJsonPath('data.0.transfers', 0)
            ->assertJsonPath('data.0.bus_stops', 2)
            ->assertJsonCount(1, 'data.0.legs');
    }

    public function test_journey_with_one_transfer(): void
    {
        $this->getJson('/api/v1/reus/journeys?from_stop=93&to_stop=5')
            ->assertOk()
            ->assertJsonPath('data.0.lines', ['60', '31'])
            ->assertJsonPath('data.0.transfers', 1)
            ->assertJsonPath('data.0.legs.0.to.code', '198')
            ->assertJsonPath('data.0.legs.1.from.code', '198');
    }

    public function test_never_travels_against_the_direction_of_the_line(): void
    {
        $this->getJson('/api/v1/reus/journeys?from_stop=5&to_stop=93')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_from_coordinates_walks_to_the_nearest_stop_and_from_the_last_one(): void
    {
        $response = $this->getJson('/api/v1/reus/journeys?from_lat=41.1502&from_lng=1.1002&from_label=Casa&to_lat=41.1682&to_lng=1.0878&to_label=Pol%C3%ADgono')
            ->assertOk()
            ->assertJsonPath('meta.origin.name', 'Casa')
            ->assertJsonPath('data.0.lines', ['60', '31']);

        $legs = $response->json('data.0.legs');
        $this->assertSame(['walk', 'bus', 'bus', 'walk'], array_column($legs, 'type'));
        $this->assertSame('Casa', $legs[0]['from']['name']);
        $this->assertSame('93', $legs[0]['to']['code']);
        $this->assertSame('Polígono', $legs[3]['to']['name']);
        $this->assertGreaterThan(0, $response->json('data.0.walk_meters'));
    }

    public function test_short_trips_suggest_walking_instead_of_a_slower_bus(): void
    {
        $this->getJson('/api/v1/reus/journeys?from_lat=41.1500&from_lng=1.1000&to_lat=41.1505&to_lng=1.1000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lines', [])
            ->assertJsonPath('data.0.legs.0.type', 'walk');
    }

    public function test_requires_both_ends(): void
    {
        $this->getJson('/api/v1/reus/journeys?from_stop=93')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['to_stop', 'to_lat', 'to_lng']]);
    }

    public function test_place_search_is_limited_to_reus_and_cached(): void
    {
        Http::fake(['*/search*' => Http::response([[
            'name' => 'Polígon Industrial Agro-Reus',
            'display_name' => 'Polígon Industrial Agro-Reus, Reus, Baix Camp, Tarragona, Catalunya, España',
            'lat' => '41.1555', 'lon' => '1.0815',
        ]])]);

        $this->getJson('/api/v1/reus/places?q=agro%20reus')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Polígon Industrial Agro-Reus')
            ->assertJsonPath('data.0.label', 'Reus, Baix Camp')
            ->assertJsonPath('data.0.lat', 41.1555);
        $this->getJson('/api/v1/reus/places?q=Agro%20Reus')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['bounded'] === 1 && str_contains($request->url(), 'viewbox='));
    }
}
