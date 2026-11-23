<?php

namespace App\Http\Requests;

/** Igual que JourneyRequest, pero las paradas son las de la red Plana. */
class PlanaJourneyRequest extends JourneyRequest
{
    protected function stopExistsRule(): string
    {
        return 'exists:plana_stops,id';
    }
}
