import { useEffect, useMemo, useRef, useState, type RefObject } from "react";
import { useQuery } from "@tanstack/react-query";
import { fetchPlanaTimetable, formatDay } from "@/lib/plana-api";
import { PlanaLineBadge } from "@/components/PlanaDepartures";
import Spinner from "@/components/Spinner";

function today() {
  const now = new Date();
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** «2026-10-27» + 1 → «2026-10-28». */
function addDays(date: string, days: number) {
  const [year, month, day] = date.split("-").map(Number);
  const next = new Date(year!, month! - 1, day! + days);
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`;
}

type Trip = { id: string; note: string | null; times: Array<string | null> };

/** Fila de la parada donde sale el autobús (su primera hora) y donde llega (la última). */
function firstStopOf(trip: Trip) {
  return trip.times.findIndex((time) => time !== null);
}

function lastStopOf(trip: Trip) {
  for (let i = trip.times.length - 1; i >= 0; i--) if (trip.times[i] !== null) return i;
  return -1;
}

/** Minutos entre dos horas «HH:MM» (si pasa de la medianoche, suma un día). */
function minutesBetween(from: string, to: string) {
  const toMinutes = (time: string) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
  const diff = toMinutes(to) - toMinutes(from);
  return diff < 0 ? diff + 1440 : diff;
}

/**
 * Vista simple: una fila por autobús con a qué hora sale y de dónde, a qué hora llega y adónde,
 * y cuánto tarda. Es la que responde a «¿a qué hora sale y llega?» sin leer la tabla entera.
 */
function TripList({
  trips,
  stops,
  nextTrip,
  nextRef,
}: {
  trips: Trip[];
  stops: Array<{ code: string; name: string }>;
  nextTrip: number;
  nextRef: RefObject<HTMLTableCellElement | null>;
}) {
  return (
    <div className="overflow-auto rounded-lg border border-border bg-card">
      <table className="w-full border-collapse text-sm">
        <thead className="sticky top-0 bg-card text-left text-xs uppercase tracking-wider text-muted-foreground">
          <tr className="border-b border-border">
            <th className="px-3 py-2 font-medium">Sale</th>
            <th className="px-3 py-2 font-medium">Llega</th>
            <th className="px-3 py-2 text-right font-medium">Duración</th>
          </tr>
        </thead>
        <tbody>
          {trips.map((trip, index) => {
            const from = firstStopOf(trip);
            const to = lastStopOf(trip);
            const departs = trip.times[from];
            const arrives = trip.times[to];
            const isNext = index === nextTrip;
            return (
              <tr
                key={trip.id}
                className={`border-b border-border/60 last:border-b-0 ${isNext ? "bg-primary/15" : index % 2 ? "bg-secondary/20" : ""}`}
              >
                <td ref={isNext ? nextRef : undefined} className="px-3 py-2 align-top">
                  <span className="block font-mono text-base font-semibold tabular-nums">
                    {departs}
                    {isNext && (
                      <span className="ml-2 rounded bg-primary px-1.5 py-0.5 font-sans text-[0.65rem] font-semibold text-primary-foreground">
                        Próximo
                      </span>
                    )}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    desde {stops[from]?.name}
                  </span>
                </td>
                <td className="px-3 py-2 align-top">
                  <span className="block font-mono text-base font-semibold tabular-nums">
                    {arrives}
                  </span>
                  <span className="block text-xs text-muted-foreground">a {stops[to]?.name}</span>
                </td>
                <td className="whitespace-nowrap px-3 py-2 text-right align-top text-xs text-muted-foreground">
                  {departs && arrives ? `${minutesBetween(departs, arrives)} min` : ""}
                  {trip.note ? (
                    <sup className="text-primary" title={trip.note}>
                      *
                    </sup>
                  ) : null}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function nowClock() {
  const now = new Date();
  return `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`;
}

/**
 * Página a pantalla completa con el horario de una línea para un día: una tabla por sentido con
 * las paradas en filas y cada salida en columnas. Si es hoy, resalta y centra el próximo autobús.
 */
export default function PlanaTimetable({
  lineId,
  onClose,
}: {
  lineId: string;
  onClose: () => void;
}) {
  const [date, setDate] = useState(today);
  const [directionIndex, setDirectionIndex] = useState(0);
  // «Salidas y llegadas» (una fila por autobús) o la tabla con todas las paradas.
  const [view, setView] = useState<"trips" | "stops">("trips");
  const timetable = useQuery({
    queryKey: ["plana", "timetable", lineId, date],
    queryFn: () => fetchPlanaTimetable(lineId, date),
    staleTime: 60 * 60 * 1000,
  });
  const line = timetable.data;
  const direction = line?.directions[directionIndex] ?? line?.directions[0];

  // Próxima salida (solo si se mira el horario de hoy): la primera que sale a partir de ahora.
  const nextTrip = useMemo(() => {
    if (!direction || date !== today()) return -1;
    const now = nowClock();
    return direction.trips.findIndex((trip) => (trip.times.find((time) => time) ?? "") >= now);
  }, [direction, date]);

  const nextCell = useRef<HTMLTableCellElement | null>(null);
  useEffect(() => {
    nextCell.current?.scrollIntoView({ block: "nearest", inline: "center" });
  }, [nextTrip, direction]);

  // Cerrar con Escape, como cualquier vista a pantalla completa.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const notes = direction
    ? Array.from(
        new Set(direction.trips.map((trip) => trip.note).filter((note): note is string => !!note)),
      )
    : [];
  const firstTime = (index: number) => direction?.trips[index]?.times.find((time) => time);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="timetable-title"
      className="fixed inset-0 z-[1300] flex flex-col bg-background"
    >
      <header className="shrink-0 border-b border-border bg-card">
        <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
          <button
            type="button"
            onClick={onClose}
            className="shrink-0 rounded-full border border-border px-3 py-1.5 text-sm font-medium hover:bg-secondary"
          >
            ← Volver
          </button>
          {line ? (
            <div className="flex min-w-0 items-center gap-2">
              <PlanaLineBadge line={line} />
              <div className="min-w-0">
                <h1 id="timetable-title" className="truncate text-base font-semibold leading-tight">
                  {line.description}
                </h1>
                {line.operator && (
                  <p className="truncate text-xs text-muted-foreground">{line.operator}</p>
                )}
              </div>
            </div>
          ) : (
            <h1 id="timetable-title" className="text-base font-semibold">
              Horario de la línea
            </h1>
          )}
        </div>
      </header>

      <main className="min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto max-w-6xl space-y-4 px-4 py-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label className="block text-xs font-medium sm:w-48">
              Día
              <input
                type="date"
                value={date}
                onChange={(event) => {
                  if (event.target.value) setDate(event.target.value);
                }}
                className="mt-1 w-full rounded-md border border-input bg-background px-2 py-2 text-sm"
              />
            </label>
            {line && line.directions.length > 1 && (
              <div
                role="group"
                aria-label="Sentido"
                className="flex min-w-0 flex-1 flex-col gap-1 rounded-lg bg-secondary/60 p-1 sm:flex-row"
              >
                {line.directions.map((item, index) => (
                  <button
                    key={index}
                    type="button"
                    aria-pressed={item === direction}
                    onClick={() => setDirectionIndex(index)}
                    className={`min-w-0 flex-1 rounded-md px-3 py-2 text-left ${item === direction ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}
                  >
                    {/* Sentido con poblaciones («Tarragona → Reus») y, debajo, la parada final. */}
                    <span className="block truncate text-sm font-semibold">
                      {item.label ?? `Hacia ${item.headsign ?? item.stops.at(-1)?.name}`}
                    </span>
                    <span className="block truncate text-[0.7rem] opacity-80">
                      Llega a {item.stops.at(-1)?.name}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </div>

          {timetable.isPending ? (
            <Spinner size="md" label="Cargando el horario…" className="text-sm" />
          ) : timetable.isError ? (
            <p className="text-sm text-destructive">{timetable.error.message}</p>
          ) : !direction ? (
            <div className="space-y-2 rounded-lg border border-primary/40 bg-primary/10 px-4 py-3 text-sm">
              <p>
                <strong>Esta línea no circula el {formatDay(date)}.</strong>
                {line?.next_service_date
                  ? ` El próximo día con servicio es el ${formatDay(line?.next_service_date)}.`
                  : " No tiene más servicios en el periodo publicado."}
              </p>
              {line?.next_service_date && (
                <button
                  type="button"
                  onClick={() => line?.next_service_date && setDate(line.next_service_date)}
                  className="font-semibold text-primary hover:underline"
                >
                  Ver el horario del {formatDay(line?.next_service_date)} →
                </button>
              )}
            </div>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <span className="font-semibold">
                  {direction.trips.length} salidas · {formatDay(date)}
                </span>
                {nextTrip >= 0 ? (
                  <span className="text-primary">Próxima salida a las {firstTime(nextTrip)}</span>
                ) : date === today() ? (
                  <span className="flex items-center gap-2 text-muted-foreground">
                    Hoy ya no quedan más.
                    <button
                      type="button"
                      onClick={() => setDate(addDays(date, 1))}
                      className="font-semibold text-primary hover:underline"
                    >
                      Ver mañana →
                    </button>
                  </span>
                ) : null}
              </div>

              <div
                role="group"
                aria-label="Vista"
                className="flex gap-1 rounded-lg bg-secondary/60 p-1 sm:w-fit"
              >
                {(
                  [
                    ["trips", "Salidas y llegadas"],
                    ["stops", "Todas las paradas"],
                  ] as const
                ).map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    aria-pressed={view === value}
                    onClick={() => setView(value)}
                    className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium sm:flex-none ${view === value ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}
                  >
                    {label}
                  </button>
                ))}
              </div>

              {view === "trips" ? (
                <TripList
                  trips={direction.trips}
                  stops={direction.stops}
                  nextTrip={nextTrip}
                  nextRef={nextCell}
                />
              ) : (
                <div className="overflow-auto rounded-lg border border-border bg-card">
                  <table className="border-collapse text-sm">
                    <tbody>
                      {direction.stops.map((stop, row) => (
                        <tr
                          key={`${stop.code}-${row}`}
                          className="border-b border-border/60 last:border-b-0"
                        >
                          <th
                            scope="row"
                            className="sticky left-0 z-10 min-w-40 max-w-[45vw] truncate border-r border-border bg-card px-3 py-2 text-left font-medium sm:max-w-72"
                            title={stop.name}
                          >
                            {stop.name}
                          </th>
                          {direction.trips.map((trip, column) => (
                            <td
                              key={trip.id}
                              ref={row === 0 && column === nextTrip ? nextCell : undefined}
                              className={`whitespace-nowrap px-3 py-2 text-center font-mono tabular-nums ${column === nextTrip ? "bg-primary/20 text-primary" : column % 2 ? "bg-secondary/30" : ""} ${trip.times[row] ? "" : "text-muted-foreground/50"} ${row === firstStopOf(trip) || row === lastStopOf(trip) ? "font-bold" : ""}`}
                            >
                              {trip.times[row] ?? "·"}
                              {row === 0 && trip.note ? (
                                <sup className="text-primary" title={trip.note}>
                                  *
                                </sup>
                              ) : null}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {notes.length > 0 && (
                <ul className="space-y-1 text-sm text-primary">
                  {notes.map((note) => (
                    <li key={note}>* {note}</li>
                  ))}
                </ul>
              )}
              <p className="text-xs text-muted-foreground">
                {view === "stops"
                  ? "Cada columna es un autobús: se lee de arriba abajo. En negrita, su hora de salida y de llegada; «·» = no para en esa parada. "
                  : ""}
                Horario oficial de la Generalitat de Catalunya; sin información en tiempo real.
              </p>
            </>
          )}
        </div>
      </main>
    </div>
  );
}
