<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Horarios de autobús · Reus</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #172033; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        main { width: min(860px, calc(100% - 32px)); margin: 48px auto; }
        header { margin-bottom: 24px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.7rem, 4vw, 2.5rem); }
        p { color: #5b6473; }
        .card { background: white; border: 1px solid #dde2e8; border-radius: 14px; padding: 24px; box-shadow: 0 8px 24px rgb(23 32 51 / 7%); margin-bottom: 20px; }
        .filters { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)) auto; gap: 16px; align-items: end; }
        label { display: grid; gap: 7px; font-weight: 650; }
        select, button { width: 100%; min-height: 44px; border-radius: 9px; border: 1px solid #bdc6d1; padding: 9px 12px; font: inherit; background: white; }
        select:focus { outline: 3px solid rgb(36 99 235 / 18%); border-color: #2463eb; }
        button { border-color: #2463eb; background: #2463eb; color: white; font-weight: 700; cursor: pointer; padding-inline: 22px; }
        button:hover { background: #174fc7; }
        .notice { border-left: 4px solid #d5a000; background: #fff9df; padding: 14px 16px; border-radius: 7px; }
        .error { border-left-color: #c73535; background: #fff0f0; color: #8b1d1d; }
        .section + .section { margin-top: 22px; }
        .section h2 { font-size: 1.05rem; margin: 0 0 12px; }
        .times { display: flex; flex-wrap: wrap; gap: 8px; }
        .time { background: #edf3ff; color: #174fae; border-radius: 999px; padding: 7px 11px; font-variant-numeric: tabular-nums; font-weight: 650; }
        .safe-text { white-space: pre-wrap; line-height: 1.55; color: #455064; }
        @media (max-width: 680px) { main { margin-block: 24px; } .filters { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <h1>Horarios de autobús</h1>
        <p>Selecciona una línea y una parada válida para consultar el horario programado.</p>
    </header>

    <section class="card">
        @if ($lines->isEmpty())
            <div class="notice">La red todavía no está sincronizada. Ejecuta <code>php artisan reus:sync-network</code>.</div>
        @else
            <form method="GET" action="{{ route('schedules.index') }}" class="filters">
                <label>
                    Línea
                    <select name="line" onchange="this.form.stop.value = ''; this.form.submit()">
                        <option value="">Selecciona una línea</option>
                        @foreach ($lines as $line)
                            <option value="{{ $line->external_id }}" @selected($selectedLine === $line->external_id)>{{ $line->public_name }}{{ $line->name ? ' · '.$line->name : '' }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Parada
                    <select name="stop" @disabled(!$selectedLine)>
                        <option value="">Selecciona una parada</option>
                        @foreach ($stops as $stop)
                            <option value="{{ $stop->external_id }}" @selected($selectedStop === $stop->external_id)>{{ $stop->name }} · {{ $stop->external_id }}</option>
                        @endforeach
                    </select>
                </label>

                <button type="submit">Ver horario</button>
            </form>
        @endif
    </section>

    @if ($errors->any())
        <div class="card notice error">{{ $errors->first() }}</div>
    @elseif ($error)
        <div class="card notice error">{{ $error }}</div>
    @elseif ($schedule)
        <section class="card" aria-live="polite">
            <h2>Línea {{ $schedule['line'] }} · Parada {{ $schedule['stop'] }}</h2>
            @foreach ($schedule['sections'] as $section)
                <div class="section">
                    <h2>{{ $section['title'] }}</h2>
                    @if ($section['message'])
                        <div class="notice">{{ $section['message'] }}</div>
                    @endif
                    @if ($section['times'])
                        <div class="times">
                            @foreach ($section['times'] as $time)
                                <span class="time">{{ $time }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
            @if (empty($schedule['sections'][0]['times']) && empty($schedule['sections'][0]['message']))
                <p class="safe-text">{{ $schedule['safe_text'] }}</p>
            @endif
        </section>
    @endif
</main>
</body>
</html>
