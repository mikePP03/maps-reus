<?php

namespace Tests\Unit;

use App\Exceptions\ProviderException;
use App\Services\ReusTransport\HtmlParser;
use PHPUnit\Framework\TestCase;

class HtmlParserTest extends TestCase
{
    public function test_discovers_deduplicates_and_naturally_sorts_strict_line_links(): void
    {
        $lines = (new HtmlParser)->lines(file_get_contents(__DIR__.'/../Fixtures/home.html'));
        $this->assertSame(['L10', 'L60'], array_column($lines, 'external_id'));
    }

    public function test_extracts_stops_in_order_and_preserves_catalan_characters(): void
    {
        $stops = (new HtmlParser)->stops(file_get_contents(__DIR__.'/../Fixtures/line.html'));
        $this->assertSame(['198', '123', '93'], array_column($stops, 'external_id'));
        $this->assertSame('Raval de Martí Folguera', $stops[1]['name']);
        $this->assertSame('Parcel·les', $stops[2]['name']);
    }

    public function test_parent_containing_multiple_stops_is_not_a_false_positive(): void
    {
        $stops = (new HtmlParser)->stops('<body><section><article>A — Parada: 1</article><article>B — Parada: 2</article></section></body>');
        $this->assertSame(['1', '2'], array_column($stops, 'external_id'));
    }

    public function test_extracts_name_from_heading_preceding_the_stop_code(): void
    {
        $html = '<body><article><h4><a>Oques 3</a></h4><h5>Parada: 93</h5></article><article><h4>Raval de Martí Folguera</h4><h5>Parada: 123</h5></article></body>';
        $stops = (new HtmlParser)->stops($html);

        $this->assertSame(['Oques 3', 'Raval de Martí Folguera'], array_column($stops, 'name'));
    }

    public function test_circular_line_keeps_repeated_terminus(): void
    {
        $html = '<body><article><h4>Pl. Prim</h4><h5>Parada: 13</h5></article><article><h4>Hospital</h4><h5>Parada: 52</h5></article><article><h4>Pl. Prim</h4><h5>Parada: 13</h5></article></body>';
        $stops = (new HtmlParser)->stops($html);

        $this->assertSame(['13', '52', '13'], array_column($stops, 'external_id'));
        $this->assertSame([1, 2, 3], array_column($stops, 'stop_sequence'));
    }

    public function test_empty_parse_throws_and_cannot_be_destructively_synced(): void
    {
        $this->expectException(ProviderException::class);
        (new HtmlParser)->stops('<body>Sin datos</body>');
    }
}
