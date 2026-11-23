<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidLineStopCombination;
use App\Exceptions\ProviderException;
use App\Models\BusLine;
use App\Services\ReusTransport\ArrivalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SchedulePageController extends Controller
{
    public function __invoke(Request $request, ArrivalService $arrivals): View
    {
        $validated = $request->validate([
            'line' => ['nullable', 'string', 'regex:/^[Ll]?\d+$/', 'max:20'],
            'stop' => ['nullable', 'string', 'regex:/^[\pL\pN_-]+$/u', 'max:50'],
        ]);

        $selectedLine = isset($validated['line']) ? 'L'.ltrim(strtoupper($validated['line']), 'L') : null;
        $selectedStop = $validated['stop'] ?? null;
        $lines = BusLine::where('active', true)->get()->sortBy('public_name', SORT_NATURAL)->values();
        $line = $selectedLine ? $lines->firstWhere('external_id', $selectedLine) : null;
        $stops = $line?->stops()->where('stops.active', true)->get() ?? collect();
        $schedule = null;
        $error = null;

        if ($selectedLine && $selectedStop) {
            try {
                $schedule = $arrivals->schedule($selectedLine, $selectedStop);
            } catch (InvalidLineStopCombination|ProviderException $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('schedules.index', compact(
            'lines',
            'stops',
            'selectedLine',
            'selectedStop',
            'schedule',
            'error',
        ));
    }
}
