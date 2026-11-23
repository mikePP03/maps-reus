<?php

namespace Tests\Feature;

use App\Models\BusLine;
use App\Models\Stop;
use App\Services\ReusTransport\NetworkSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetworkSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $stops): string
    {
        return '<body>'.implode('', array_map(fn ($stop) => "<article><h4>{$stop[1]}</h4><h5>Parada: {$stop[0]}</h5></article>", $stops)).'</body>';
    }

    /** @var array<string,list<array{0:string,1:string}>> */
    private array $network = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '/';
            if (preg_match('#^/lines/(\d+)$#', $path, $match)) {
                return Http::response($this->page($this->network[$match[1]] ?? []));
            }

            return Http::response('<body>'.implode('', array_map(fn ($number) => "<a href=\"/lines/$number\">$number</a>", array_keys($this->network))).'</body>');
        });
    }

    private function fakeProvider(array $lines): void
    {
        $this->network = $lines;
    }

    private function sync(): array
    {
        return app(NetworkSynchronizer::class)->sync(pauseMs: 0);
    }

    public function test_circular_line_stores_terminus_at_both_ends(): void
    {
        $this->fakeProvider(['10' => [['13', 'Pl. Prim'], ['52', 'Hospital'], ['97', 'Estació'], ['13', 'Pl. Prim']]]);

        $stats = $this->sync();

        $this->assertSame([], $stats['errors']);
        $line = BusLine::where('external_id', 'L10')->firstOrFail();
        $this->assertSame(['13', '52', '97', '13'], $line->stops->pluck('external_id')->all());
        $this->assertSame(3, Stop::count());
        $this->assertSame(['L10'], Stop::where('external_id', '13')->firstOrFail()->busLines->pluck('external_id')->all());
    }

    public function test_resync_replaces_order_and_deactivates_what_disappeared(): void
    {
        $this->fakeProvider(['10' => [['13', 'Pl. Prim'], ['52', 'Hospital']], '60' => [['93', 'Oques 3'], ['198', 'Hospital 1']]]);
        $this->sync();

        $this->fakeProvider(['10' => [['52', 'Hospital'], ['13', 'Pl. Prim']]]);
        $stats = $this->sync();

        $this->assertSame(1, $stats['lines_deactivated']);
        $this->assertSame(2, $stats['stops_deactivated']);
        $this->assertFalse(BusLine::where('external_id', 'L60')->value('active'));
        $this->assertFalse(Stop::where('external_id', '93')->value('active'));
        $this->assertTrue(Stop::where('external_id', '13')->value('active'));
        $this->assertSame(['52', '13'], BusLine::where('external_id', 'L10')->firstOrFail()->stops->pluck('external_id')->all());

        $this->getJson('/api/v1/reus/arrivals?line=L60&stop=93')->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_failed_line_keeps_its_stops_active(): void
    {
        $this->fakeProvider(['10' => [['13', 'Pl. Prim'], ['52', 'Hospital']]]);
        $this->sync();

        $this->fakeProvider(['10' => []]);
        $stats = $this->sync();

        $this->assertCount(1, $stats['errors']);
        $this->assertSame(0, $stats['stops_deactivated']);
        $this->assertTrue(Stop::where('external_id', '52')->value('active'));
    }
}
