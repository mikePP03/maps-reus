import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { fetchSchedule, fetchStopArrivals, formatArrival, type StopArrival } from "@/lib/reus-api";

const REFRESH_MS = 30_000;

/** Llegadas en tiempo real de todas las líneas de una parada, con su horario a demanda. */
export default function StopDetails({ code }: { code: string }) {
  const arrivals = useQuery({
    queryKey: ["reus", "arrivals", code],
    queryFn: () => fetchStopArrivals(code),
    refetchInterval: REFRESH_MS,
    staleTime: REFRESH_MS / 2,
    retry: 1,
  });

  if (arrivals.isPending) {
    return <p className="text-xs text-muted-foreground">Consultando llegadas…</p>;
  }
  if (arrivals.isError) {
    return (
      <div className="text-xs">
        <p className="text-destructive">{arrivals.error.message}</p>
        <button
          type="button"
          onClick={() => void arrivals.refetch()}
          className="mt-1 font-semibold text-primary hover:underline"
        >
          Reintentar
        </button>
      </div>
    );
  }
  if (arrivals.data.length === 0) {
    return (
      <p className="text-xs text-muted-foreground">Ninguna línea activa pasa por esta parada.</p>
    );
  }

  return (
    <div className="space-y-1.5">
      <ul className="ui-stagger space-y-1.5">
        {arrivals.data.map((arrival) => (
          <ArrivalRow key={arrival.line.id} code={code} arrival={arrival} />
        ))}
      </ul>
      <p className="text-[0.65rem] text-muted-foreground">
        {arrivals.isFetching
          ? "Actualizando…"
          : `Actualizado a las ${new Date(arrivals.dataUpdatedAt).toLocaleTimeString("es-ES", { hour: "2-digit", minute: "2-digit" })} · se refresca cada 30 s`}
      </p>
    </div>
  );
}

function ArrivalRow({ code, arrival }: { code: string; arrival: StopArrival }) {
  const [showSchedule, setShowSchedule] = useState(false);
  const noPrediction = !arrival.timetoarrival || /consulteu\s+horari/i.test(arrival.timetoarrival);

  return (
    <li className="rounded-md border border-border bg-background/60 px-2 py-1.5">
      <div className="flex items-center justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2">
          <span className="shrink-0 rounded bg-primary px-1.5 py-0.5 text-[0.7rem] font-bold text-primary-foreground">
            L{arrival.line.name}
          </span>
          {arrival.destination && (
            <span className="truncate text-xs text-muted-foreground">{arrival.destination}</span>
          )}
        </div>
        <span
          className={`flex shrink-0 items-center gap-1.5 text-xs font-semibold ${arrival.available && !noPrediction ? "text-primary" : "text-muted-foreground"}`}
        >
          {arrival.available && !noPrediction && (
            <span className="ui-live-dot" aria-hidden="true" title="Tiempo real" />
          )}
          {!arrival.available
            ? (arrival.error ?? "Sin datos")
            : noPrediction
              ? "Sin previsión"
              : formatArrival(arrival.timetoarrival ?? "")}
        </span>
      </div>
      <button
        type="button"
        onClick={() => setShowSchedule((value) => !value)}
        aria-expanded={showSchedule}
        className="mt-1 text-[0.7rem] font-medium text-primary hover:underline"
      >
        {showSchedule ? "Ocultar horario" : "Ver horario"}
      </button>
      {showSchedule && <Schedule line={arrival.line.id} code={code} />}
    </li>
  );
}

function Schedule({ line, code }: { line: string; code: string }) {
  const schedule = useQuery({
    queryKey: ["reus", "schedule", line, code],
    queryFn: () => fetchSchedule(line, code),
    staleTime: 60 * 60 * 1000,
    retry: 1,
  });

  if (schedule.isPending) {
    return <p className="mt-1 text-[0.7rem] text-muted-foreground">Cargando horario…</p>;
  }
  if (schedule.isError) {
    return <p className="mt-1 text-[0.7rem] text-destructive">{schedule.error.message}</p>;
  }

  const sections = schedule.data.sections.filter(
    (section) => section.times.length > 0 || section.message,
  );
  if (sections.length === 0) {
    return (
      <p className="mt-1 text-[0.7rem] text-muted-foreground">El proveedor no publica horario.</p>
    );
  }

  return (
    <div className="mt-1 space-y-1">
      {sections.map((section) => (
        <div key={section.title}>
          {section.message && (
            <p className="text-[0.7rem] text-muted-foreground">{section.message}</p>
          )}
          <p className="flex flex-wrap gap-1">
            {section.times.map((time) => (
              <span
                key={time}
                className="rounded bg-secondary px-1 py-0.5 font-mono text-[0.65rem] text-secondary-foreground"
              >
                {time}
              </span>
            ))}
          </p>
        </div>
      ))}
    </div>
  );
}
