<?php

namespace App\Services\ReusTransport;

use App\Exceptions\ProviderException;
use DOMElement;
use DOMXPath;
use Symfony\Component\DomCrawler\Crawler;

class HtmlParser
{
    /** @return list<array{external_id:string,public_name:string}> */
    public function lines(string $html): array
    {
        $found = [];
        (new Crawler($html))->filter('a[href]')->each(function (Crawler $link) use (&$found): void {
            $path = parse_url(trim((string) $link->attr('href')), PHP_URL_PATH);
            if (is_string($path) && preg_match('#^/lines/(\d+)/?$#', $path, $match)) {
                $found[$match[1]] = ['external_id' => 'L'.$match[1], 'public_name' => $match[1]];
            }
        });
        uksort($found, 'strnatcmp');

        return array_values($found);
    }

    /** @return list<array{external_id:string,name:string,stop_sequence:int}> */
    public function stops(string $html): array
    {
        $crawler = new Crawler($html);
        $found = [];
        $crawler->filter('body *')->each(function (Crawler $node) use (&$found): void {
            $text = $this->text($node->text(''));
            if (! preg_match('/\bParada\s*:\s*([\pL\pN_-]+)/iu', $text, $match)) {
                return;
            }
            if (preg_match_all('/\bParada\s*:/iu', $text) !== 1 || $node->children()->reduce(fn (Crawler $child) => preg_match('/\bParada\s*:/iu', $this->text($child->text(''))) === 1)->count() > 0) {
                return;
            }
            $code = $match[1];
            // Una parada puede repetirse en el recorrido (líneas circulares), pero no dos veces seguidas.
            if ($found !== [] && $found[array_key_last($found)]['external_id'] === $code) {
                return;
            }
            $name = $this->extractName($node, $text, $match[0]);
            if ($name !== '') {
                $found[] = ['external_id' => $code, 'name' => $name, 'stop_sequence' => count($found) + 1];
            }
        });
        if ($found === []) {
            throw new ProviderException('No se pudo extraer ninguna parada de la página de línea.');
        }

        return array_values($found);
    }

    /** @return array{sections:list<array{title:string,times:list<string>,message:?string}>,safe_text:string} */
    public function schedule(string $html): array
    {
        $crawler = new Crawler($html);
        $crawler->filter('script,style,iframe,object')->each(fn (Crawler $node) => $node->getNode(0)?->parentNode?->removeChild($node->getNode(0)));
        $root = $crawler->filter('body')->count() ? $crawler->filter('body') : $crawler;
        $text = $this->text($root->text(''));
        // Se separan las etiquetas con espacios: text() pega "<p>09:50</p><p>10:50</p>" en "09:5010:50".
        $spaced = $this->text(strip_tags(str_replace('<', ' <', $root->html(''))));
        preg_match_all('/\b(?:[01]?\d|2[0-3]):[0-5]\d\b/', $spaced, $matches);

        return ['sections' => [['title' => 'Horarios', 'times' => array_values(array_unique($matches[0])), 'message' => preg_match('/sense servei/i', $text) ? 'Sense servei' : null]], 'safe_text' => $text];
    }

    private function extractName(Crawler $node, string $text, string $label): string
    {
        $element = $node->getNode(0);
        if ($element instanceof DOMElement) {
            $xpath = new DOMXPath($element->ownerDocument);
            $headings = $xpath->query('(preceding::h1 | preceding::h2 | preceding::h3 | preceding::h4)[last()]', $element);
            $heading = $headings?->item(0);
            if ($heading && ($name = $this->text($heading->textContent)) !== '' && ! preg_match('/\bParada\s*:/iu', $name)) {
                return $name;
            }
        }

        foreach (['h1', 'h2', 'h3', 'h4', 'h5', '.name', '.title', 'strong'] as $selector) {
            if ($node->filter($selector)->count() && ($name = $this->text($node->filter($selector)->first()->text(''))) !== '' && ! preg_match('/\bParada\s*:/iu', $name)) {
                return $name;
            }
        }

        return trim($this->text(str_replace($label, '', $text)), " -–—\t\n\r\0\x0B");
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
