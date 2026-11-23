import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { getCurrentPosition, type LatLng } from "@/lib/geo";
import type { JourneyEnd } from "@/lib/reus-api";
import type { PlanaPlannerRequest } from "@/components/PlanaPlanner";
import {
  fetchPlanaCatalog,
  fetchPlanaDay,
  formatDay,
  type PlanaBusLeg,
  type PlanaJourney,
  type PlanaWhen,
} from "@/lib/plana-api";
import PlacePicker from "@/components/PlacePicker";
import { PlanaLineBadge } from "@/components/PlanaDepartures";
import { NoJourneys, PlanaSteps } from "@/components/PlanaPlanner";
import Spinner from "@/components/Spinner";

const DAY_MS = 24 * 60 * 60 * 1000;
const NO_JOURNEYS: PlanaJourney[] = [];
/** Salidas visibles al principio; el resto, con «Ver más». */
const FIRST_SHOWN = 5;
const DAYS = ["Hoy", "Mañana", "Pasado mañana"] as const;

/** Fecha local (Y-m-d) dentro de `offset` días. */
function isoDate(offset: number) {
  const date = new Date(Date.now() + offset * DAY_MS);
  const month = String(date.getMonth() + 1).padStart(2, "0");
  return `${date.getFullYear()}-${month}-${String(date.getDate()).padStart(2, "0")}`;
}

function endKey(end: JourneyEnd | null) {
  if (!end) return null;
  return end.kind === "stop" ? `stop:${end.code}` : `${end.lat.toFixed(4)},${end.lng.toFixed(4)}`;
}

function formatDuration(minutes: number) {
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/**
 * «Ir a»: solo se elige el destino. El origen es la ubicación del usuario (se puede cambiar) y
 * se muestran todas las salidas que quedan hoy para ese trayecto, con su itinerario.
 */
export default function PlanaGoTo({
  request,
  onJourneyChange,
  onLocated,
  onLoadingChange,
  onShowTimetable,
}: {
  /** «Cómo llegar aquí» desde una parada: fija ese destino. */
  request?: PlanaPlannerRequest | null;
  onJourneyChange: (journey: PlanaJourney | null) => void;
  onLocated?: (position: LatLng) => void;
  onLoadingChange?: (label: string | null) => void;
  onShowTimetable?: (lineId: string) => void;
}) {
  const [destination, setDestination] = useState<JourneyEnd | null>(null);
  const [origin, setOrigin] = useState<JourneyEnd | null>(null);
  const [changingOrigin, setChangingOrigin] = useState(false);
  const [selected, setSelected] = useState<number | null>(null);
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState<string | null>(null);
  const [dayOffset, setDayOffset] = useState(0);
  const [showAll, setShowAll] = useState(false);
  const locateRequest = useRef(0);
  const onLocatedRef = useRef(onLocated);
  onLocatedRef.current = onLocated;

  const locate = useCallback(() => {
    const id = ++locateRequest.current;
    setLocationError(null);
    setLocating(true);
    getCurrentPosition()
      .then((position) => {
        if (id !== locateRequest.current) return;
        setOrigin({ kind: "location", name: "Mi ubicación", ...position });
        onLocatedRef.current?.(position);
      })
      .catch((error: unknown) => {
        if (id !== locateRequest.current) return;
        setLocationError(error instanceof Error ? error.message : String(error));
        setChangingOrigin(true);
      })
      .finally(() => {
        if (id === locateRequest.current) setLocating(false);
      });
  }, []);

  useEffect(() => {
    if (!request) return;
    setDestination(request.destination);
    setSelected(null);
    setShowAll(false);
  }, [request]);

  // En cuanto hay destino, se pide la ubicación (solo si aún no hay origen).
  useEffect(() => {
    if (destination && !origin && !locating && !locationError) locate();
  }, [destination, origin, locating, locationError, locate]);

  const catalog = useQuery({
    queryKey: ["plana", "catalog"],
    queryFn: fetchPlanaCatalog,
    staleTime: DAY_MS,
  });

  const day = useQuery({
    queryKey: ["plana", "day", endKey(origin), endKey(destination), dayOffset],
    queryFn: () => {
      const when: PlanaWhen = dayOffset === 0 ? null : { date: isoDate(dayOffset), time: "00:00" };
      return fetchPlanaDay(origin!, destination!, when);
    },
    enabled: origin !== null && destination !== null,
    // La lista empieza «ahora»: se renueva cada minuto para quitar las salidas que ya pasaron.
    refetchInterval: 60_000,
  });
  const data = day.data;
  const journeys = useMemo(
    () => (origin && destination ? (data?.journeys ?? NO_JOURNEYS) : NO_JOURNEYS),
    [origin, destination, data],
  );
  const current = selected === null ? null : (journeys[selected] ?? null);

  useEffect(
    () => onJourneyChange(current ?? journeys[0] ?? null),
    [current, journeys, onJourneyChange],
  );

  const loadingLabel = locating
    ? "Obteniendo tu ubicación…"
    : origin && destination && day.isPending
      ? "Buscando todas las salidas…"
      : null;
  useEffect(() => onLoadingChange?.(loadingLabel), [loadingLabel, onLoadingChange]);

  const otherDay = day.data && day.data.date !== day.data.requestedDate;
  const selectDay = (offset: number) => {
    setDayOffset(offset);
    setSelected(null);
    setShowAll(false);
  };

  return (
    <div className="space-y-3 px-5 py-4">
      <PlacePicker
        label="¿A dónde vas?"
        stops={catalog.data ?? []}
        value={destination}
        onChange={(end) => {
          setDestination(end);
          setSelected(null);
          setShowAll(false);
        }}
        area="catalunya"
      />

      <div className="text-xs">
        {changingOrigin ? (
          <PlacePicker
            label="Desde"
            stops={catalog.data ?? []}
            value={origin}
            onChange={(end) => {
              locateRequest.current++;
              setLocating(false);
              setOrigin(end);
              setSelected(null);
              setShowAll(false);
              if (end) setChangingOrigin(false);
            }}
            onRequestLocation={() => {
              setChangingOrigin(false);
              locate();
            }}
            busyText={locating ? "Obteniendo tu ubicación…" : undefined}
            area="catalunya"
          />
        ) : (
          <p className="flex items-center justify-between gap-2 text-muted-foreground">
            <span className="truncate">
              Desde:{" "}
              <strong className="text-foreground">
                {locating
                  ? "buscando tu ubicación…"
                  : origin
                    ? origin.kind === "location"
                      ? "tu ubicación"
                      : origin.name
                    : "tu ubicación"}
              </strong>
            </span>
            <button
              type="button"
              onClick={() => setChangingOrigin(true)}
              className="shrink-0 font-medium text-primary hover:underline"
            >
              Cambiar
            </button>
          </p>
        )}
      </div>

      {locating && (
        <Spinner
          label="Obteniendo tu ubicación…"
          className="rounded-md border border-primary/40 bg-primary/10 px-3 py-2 text-xs font-medium"
        />
      )}
      {locationError && !origin && (
        <p className="text-xs text-destructive">
          {locationError} Elige desde dónde sales con «Desde».
        </p>
      )}

      {!destination ? (
        <p className="text-sm text-muted-foreground">
          Escribe a dónde quieres ir y te enseñamos los autobuses que puedes coger.
        </p>
      ) : !origin ? null : day.isPending ? (
        <div className="space-y-2" aria-busy="true">
          <Spinner
            size="md"
            label="Buscando todas las salidas…"
            className="w-full rounded-lg border border-primary/40 bg-primary/10 px-3 py-2.5 text-sm font-medium"
          />
          {[0, 1, 2].map((index) => (
            <div
              key={index}
              aria-hidden="true"
              className="h-14 animate-pulse rounded-lg border border-border bg-secondary/50"
            />
          ))}
        </div>
      ) : day.isError ? (
        <p className="text-xs text-destructive">{day.error.message}</p>
      ) : journeys.length === 0 ? (
        <div className="space-y-2">
          <DaySelect value={dayOffset} onChange={selectDay} />
          <NoJourneys outOfArea={day.data?.outOfArea ?? null} />
        </div>
      ) : (
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-2">
            <p className="text-sm font-semibold">
              {otherDay
                ? `${dayOffset === 0 ? "Hoy ya no hay autobuses" : "Ese día no hay autobuses"}. Salidas del ${formatDay(day.data.date)}:`
                : `${journeys.length} salida${journeys.length === 1 ? "" : "s"} ${dayOffset === 0 ? "hoy" : `el ${formatDay(day.data.date)}`}`}
            </p>
            <DaySelect value={dayOffset} onChange={selectDay} />
          </div>
          <ul className="ui-stagger space-y-1.5">
            {(showAll ? journeys : journeys.slice(0, FIRST_SHOWN)).map((journey, index) => {
              const isOpen = selected === index;
              const firstLeg = journey.legs[0];
              const walkFirst = firstLeg?.type === "walk" ? firstLeg : null;
              const busLegs = journey.legs.filter((leg): leg is PlanaBusLeg => leg.type === "bus");
              return (
                <li key={`${journey.departs_at}-${journey.lines.join(">")}`}>
                  <button
                    type="button"
                    onClick={() => setSelected(isOpen ? null : index)}
                    aria-expanded={isOpen}
                    className={`w-full rounded-lg border px-3 py-2 text-left transition active:scale-[0.99] ${isOpen ? "border-primary bg-primary/10" : "border-border hover:bg-secondary/60"}`}
                  >
                    <span className="flex items-center justify-between gap-2">
                      <span className="font-mono text-base font-semibold tabular-nums">
                        {journey.departs_at} → {journey.arrives_next_day ? "+1 " : ""}
                        {journey.arrives_at}
                      </span>
                      <span className="flex items-center gap-1.5">
                        {index === 0 && !otherDay && dayOffset === 0 && (
                          <span className="rounded bg-primary px-1.5 py-0.5 text-[0.65rem] font-semibold text-primary-foreground">
                            Próximo
                          </span>
                        )}
                        <span className="text-xs text-muted-foreground">
                          {formatDuration(journey.minutes)}
                        </span>
                      </span>
                    </span>
                    <span className="mt-1 flex flex-wrap items-center gap-1 text-xs">
                      {busLegs.map((leg, i) => (
                        <span key={i} className="flex items-center gap-1">
                          {i > 0 && <span className="text-muted-foreground">→</span>}
                          <PlanaLineBadge line={leg.line} />
                        </span>
                      ))}
                      <span className="text-muted-foreground">
                        {journey.transfers === 0
                          ? "Directo"
                          : `${journey.transfers} transbordo${journey.transfers > 1 ? "s" : ""}`}
                      </span>
                    </span>
                    {walkFirst && (
                      <span className="mt-0.5 block truncate text-[0.7rem] text-muted-foreground">
                        Camina {walkFirst.minutes} min hasta {walkFirst.to.name}
                      </span>
                    )}
                  </button>
                  {isOpen && (
                    <div className="ui-fade-up mt-1.5">
                      <PlanaSteps journey={journey} onShowTimetable={onShowTimetable} />
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
          {!showAll && journeys.length > FIRST_SHOWN && (
            <button
              type="button"
              onClick={() => setShowAll(true)}
              className="w-full rounded-lg border border-border py-2 text-sm font-medium text-primary hover:bg-secondary/60"
            >
              Ver más salidas ({journeys.length - FIRST_SHOWN})
            </button>
          )}
          <p className="text-[0.7rem] text-muted-foreground">
            Toca una salida para ver los pasos. Horarios oficiales, sin tiempo real.
          </p>
        </div>
      )}
    </div>
  );
}

function DaySelect({ value, onChange }: { value: number; onChange: (offset: number) => void }) {
  return (
    <select
      aria-label="Día del viaje"
      value={value}
      onChange={(event) => onChange(Number(event.target.value))}
      className="shrink-0 rounded-md border border-input bg-background px-2 py-1 text-xs"
    >
      {DAYS.map((label, offset) => (
        <option key={label} value={offset}>
          {label}
        </option>
      ))}
    </select>
  );
}
