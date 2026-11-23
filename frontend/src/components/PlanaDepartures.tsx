import { useQuery } from "@tanstack/react-query";
import { fetchPlanaDepartures, formatDay, type PlanaLine } from "@/lib/plana-api";
import Spinner from "@/components/Spinner";

/** Chapa de línea con su color oficial (si el GTFS lo trae) o el de la app. */
export function PlanaLineBadge({
  line,
}: {
  line: Pick<PlanaLine, "name" | "color" | "text_color">;
}) {
  return (
    <span
      className="shrink-0 rounded bg-primary px-1.5 py-0.5 text-[0.7rem] font-bold text-primary-foreground"
      style={
        line.color
          ? { backgroundColor: `#${line.color}`, color: `#${line.text_color ?? "FFFFFF"}` }
          : undefined
      }
    >
      {line.name}
    </span>
  );
}

/** Próximas salidas de una parada según el horario oficial del día (sin tiempo real). */
export default function PlanaDepartures({
  stop,
  onShowTimetable,
}: {
  stop: string;
  /** Si se pasa, cada salida ofrece abrir el horario completo de su línea. */
  onShowTimetable?: (lineId: string) => void;
}) {
  const departures = useQuery({
    queryKey: ["plana", "departures", stop],
    queryFn: () => fetchPlanaDepartures(stop),
    refetchInterval: 60_000,
    staleTime: 30_000,
    retry: 1,
  });

  if (departures.isPending) return <Spinner label="Consultando el horario…" className="text-xs" />;
  if (departures.isError) {
    return <p className="text-xs text-destructive">{departures.error.message}</p>;
  }
  if (departures.data.length === 0) {
    return (
      <p className="text-xs text-muted-foreground">
        No hay salidas desde esta parada en los próximos 7 días.
      </p>
    );
  }

  const first = departures.data[0];

  return (
    <div className="space-y-1.5">
      {first && first.day_offset > 0 && (
        <p className="rounded-md border border-primary/40 bg-primary/10 px-2 py-1.5 text-xs">
          Hoy ya no hay más salidas. La siguiente es{" "}
          <strong>
            {first.day_offset === 1 ? "mañana" : `el ${formatDay(first.date)}`} a las {first.time}
          </strong>
          .
        </p>
      )}
      <ul className="ui-stagger space-y-1.5">
        {departures.data.map((departure, index) => (
          <li
            key={`${departure.line.id}-${departure.time}-${index}`}
            className="rounded-md border border-border bg-background/60 px-2 py-1.5"
          >
            <div className="flex items-center justify-between gap-2">
              <div className="flex min-w-0 items-center gap-2">
                <PlanaLineBadge line={departure.line} />
                <span className="truncate text-xs text-muted-foreground">
                  {departure.headsign ?? departure.line.description}
                </span>
              </div>
              <span className="shrink-0 text-right text-xs font-semibold leading-tight">
                {departure.day_offset === 1
                  ? "Mañana "
                  : departure.day_offset > 1
                    ? `${formatDay(departure.date)} `
                    : ""}
                {departure.time}
                {!departure.next_day && (
                  <span className="block text-[0.65rem] font-normal text-muted-foreground">
                    {departure.in_minutes <= 0 ? "ahora" : `en ${formatIn(departure.in_minutes)}`}
                  </span>
                )}
              </span>
            </div>
            {departure.note && <p className="mt-1 text-[0.65rem] text-primary">{departure.note}</p>}
            {onShowTimetable && (
              <button
                type="button"
                onClick={() => onShowTimetable(departure.line.id)}
                className="mt-1 text-[0.7rem] font-medium text-primary hover:underline"
              >
                Horario de la línea {departure.line.name}
              </button>
            )}
          </li>
        ))}
      </ul>
      <p className="text-[0.65rem] text-muted-foreground">
        Horario oficial de la Generalitat. Sin información en tiempo real.
      </p>
    </div>
  );
}

function formatIn(minutes: number) {
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}
