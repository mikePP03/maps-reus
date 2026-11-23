<?php

namespace Tests\Feature;

use App\Models\BusLine;
use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchedulePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_explains_when_the_network_has_not_been_synchronized(): void
    {
        $this->get('/horarios')
            ->assertOk()
            ->assertSee('La red todavía no está sincronizada');
    }

    public function test_it_filters_valid_stops_and_displays_a_schedule(): void
    {
        cache()->flush();
        $line = BusLine::create(['external_id' => 'L60', 'public_name' => '60', 'active' => true]);
        $validStop = Stop::create(['external_id' => '93', 'name' => 'Oques 3', 'active' => true]);
        Stop::create(['external_id' => '125', 'name' => 'Parada no relacionada', 'active' => true]);
        $line->stops()->attach($validStop, ['stop_sequence' => 1, 'verified_at' => now()]);

        Http::fake(['*/scheduler/L60/93' => Http::response('<main><h2>Laborables</h2><p>09:15</p></main>')]);

        $this->get('/horarios?line=L60&stop=93')
            ->assertOk()
            ->assertSee('Oques 3')
            ->assertDontSee('Parada no relacionada')
            ->assertSee('09:15');
    }
}
