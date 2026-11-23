import { useCallback, useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { formatDistance, getCurrentPosition, type LatLng } from "@/lib/geo";
import type { JourneyEnd } from "@/lib/reus-api";
import {
  fetchPlanaCatalog,
  fetchPlanaJourneys,
  type PlanaBusLeg,
  type PlanaJourney,
  formatDay,
  type PlanaWhen,
} from "@/lib/plana-api";
import PlacePicker from "@/components/PlacePicker";
import { PlanaLineBadge } from "@/components/PlanaDepartures";
import Spinner from "@/components/Spinner";

const DAY_MS = 24 * 60 * 60 * 1000;

export type PlanaPlannerRequest = { destination: JourneyEnd; id: number };

function endKey(end: JourneyEnd | null) {
  if (!end) return null;
  return end.kind === "stop" ? `stop:${end.code}` : `${end.lat.toFixed(5)},${end.lng.toFixed(5)}`;
}

/** «2026-10-28T08:00» para el input de fecha y hora, en hora local. */
function localInputValue(date: Date) {
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function formatDuration(minutes: number) {
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/** «Cómo llegar» en la red interurbana del Camp de Tarragona, con horarios oficiales. */
export default function PlanaPlanner({
  onJourneyChange,
  request,
  onLocated,
  onLoadingChange,
  onShowTimetable,
}: {
  onJourneyChange: (journey: PlanaJourney | null) => void;
  request?: PlanaPlannerRequest | null;
  onLocated?: (position: LatLng) => void;
  onLoadingChange?: (label: string | null) => void;
  /** Abre el horario completo de una línea (desde un tramo de autobús). */
  onShowTimetable?: (lineId: string) => void;
}) {
  const [origin, setOrigin] = useState<JourneyEnd | null>(null);
  const [destination, setDestination] = useState<JourneyEnd | null>(null);
  const [selected, setSelected] = useState(0);
  const [leaveNow, setLeaveNow] = useState(true);
  const [when, setWhen] = useState(() => localInputValue(new Date()));
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState<string | null>(null);
  const locateRequest = useRef(0);
  const onLocatedRef = useRef(onLocated);
  onLocatedRef.current = onLocated;

  const locateOrigin = useCallback(() => {
    const id = ++locateRequest.current;
    setOrigin(null);
    setSelected(0);
    setLocationError(null);
    setLocating(true);
    getCurrentPosition()
      .then((position) => {
        if (id !== locateRequest.current) return;
        setOrigin({ kind: "location", name: "Mi ubicación", ...position });
        onLocatedRef.current?.(position);
      })
      .catch((error: unknown) => {
        if (id === locateRequest.current)
          setLocationError(error instanceof Error ? error.message : String(error));
      })
      .finally(() => {
        if (id === locateRequest.current) setLocating(false);
      });
  }, []);

  useEffect(() => {
    if (!request) return;
    setDestination(request.destination);
    locateOrigin();
  }, [request, locateOrigin]);

  const catalog = useQuery({
    queryKey: ["plana", "catalog"],
    queryFn: fetchPlanaCatalog,
    staleTime: DAY_MS,
  });

  const planWhen: PlanaWhen = leaveNow
    ? null
    : { date: when.slice(0, 10), time: when.slice(11, 16) };
  const journeys = useQuery({
    queryKey: ["plana", "journeys", endKey(origin), endKey(destination), planWhen],
    queryFn: () => fetchPlanaJourneys(origin!, destination!, planWhen),
    enabled: origin !== null && destination !== null,
    // Con «salir ahora», se recalcula cada minuto para no ofrecer autobuses ya salidos.
    refetchInterval: leaveNow ? 60_000 : false,
  });
  const results = origin && destination ? (journeys.data?.journeys ?? []) : [];
  const current = results[selected] ?? results[0] ?? null;

  useEffect(() => onJourneyChange(current), [current, onJourneyChange]);

  const loadingLabel = locating
    ? "Obteniendo tu ubicación…"
    : origin && destination && journeys.isPending
      ? "Calculando ruta…"
      : null;
  useEffect(() => onLoadingChange?.(loadingLabel), [loadingLabel, onLoadingChange]);

  const update = (setter: (end: JourneyEnd | null) => void) => (end: JourneyEnd | null) => {
    setter(end);
    setSelected(0);
  };

  return (
    <div className="space-y-3 px-5 py-4">
      {catalog.isError && (
        <p className="text-xs text-destructive">No se pudo cargar la lista de paradas.</p>
      )}
      <PlacePicker
        label="Desde"
        stops={catalog.data ?? []}
        value={origin}
        onChange={(end) => {
          locateRequest.current++;
          setLocating(false);
          update(setOrigin)(end);
        }}
        onRequestLocation={locateOrigin}
        busyText={locating ? "Obteniendo tu ubicación…" : undefined}
        area="catalunya"
      />
      {locating && (
        <Spinner
          label="Obteniendo tu ubicación…"
          className="rounded-md border border-primary/40 bg-primary/10 px-3 py-2 text-xs font-medium"
        />
      )}
      {locationError && (
        <p className="text-xs text-destructive">
          {locationError} Escribe en «Desde» una parada o un lugar para calcular la ruta.
        </p>
      )}
      <div className="flex justify-center">
        <button
          type="button"
          onClick={() => {
            setOrigin(destination);
            setDestination(origin);
            setSelected(0);
          }}
          disabled={!origin && !destination}
          aria-label="Intercambiar origen y destino"
          className="rounded-full border border-border px-3 py-0.5 text-xs text-muted-foreground hover:text-foreground disabled:opacity-40"
        >
          ⇅ Intercambiar
        </button>
      </div>
      <PlacePicker
        label="Hasta"
        stops={catalog.data ?? []}
        value={destination}
        onChange={update(setDestination)}
        area="catalunya"
      />

      <fieldset className="space-y-2">
        <legend className="text-xs font-medium">Salida</legend>
        <div className="flex gap-1 rounded-lg bg-secondary/60 p-1">
          {[
            { now: true, label: "Ahora" },
            { now: false, label: "Elegir día y hora" },
          ].map((option) => (
            <button
              key={option.label}
              type="button"
              aria-pressed={leaveNow === option.now}
              onClick={() => {
                setLeaveNow(option.now);
                setSelected(0);
                if (!option.now) setWhen(localInputValue(new Date()));
              }}
              className={`flex-1 rounded-md px-2 py-1 text-xs font-medium ${leaveNow === option.now ? "bg-primary text-primary-foreground" : "text-muted-foreground"}`}
            >
              {option.label}
            </button>
          ))}
        </div>
        {!leaveNow && (
          <input
            type="datetime-local"
            value={when}
            onChange={(event) => {
              if (event.target.value) setWhen(event.target.value);
              setSelected(0);
            }}
            aria-label="Día y hora de salida"
            className="w-full rounded-md border border-input bg-background px-2 py-2 text-sm"
          />
        )}
      </fieldset>

      {!origin || !destination ? (
        <p className="text-xs text-muted-foreground">
          Elige de dónde sales y a dónde vas en el Camp de Tarragona o el Priorat: tu ubicación, una
          parada o un lugar como «Platja de Llevant, Salou».
        </p>
      ) : journeys.isPending ? (
        <div className="space-y-2" aria-busy="true">
          <Spinner
            size="md"
            label="Calculando la ruta…"
            className="w-full rounded-lg border border-primary/40 bg-primary/10 px-3 py-2.5 text-sm font-medium"
          />
          {[0, 1].map((index) => (
            <div
              key={index}
              aria-hidden="true"
              className="h-14 animate-pulse rounded-lg border border-border bg-secondary/50"
            />
          ))}
        </div>
      ) : journeys.isError ? (
        <p className="text-xs text-destructive">{journeys.error.message}</p>
      ) : results.length === 0 ? (
        <NoJourneys outOfArea={journeys.data?.outOfArea ?? null} />
      ) : (
        <div className="space-y-2">
          {journeys.data?.nextAvailable && (
            <p
              role="status"
              className="rounded-md border border-primary/40 bg-primary/10 px-3 py-2 text-xs"
            >
              <strong>No hay autobuses a la hora elegida.</strong>{" "}
              {journeys.data.date !== journeys.data.requestedDate
                ? `Te mostramos los siguientes disponibles: ${formatDay(journeys.data.date)}.`
                : `Te mostramos los siguientes de hoy: el primero sale a las ${results[0]?.departs_at}.`}
            </p>
          )}
          <ul className="space-y-1.5">
            {results.map((journey, index) => (
              <li key={`${journey.departs_at}-${journey.lines.join(">")}-${index}`}>
                <button
                  type="button"
                  onClick={() => setSelected(index)}
                  aria-pressed={journey === current}
                  className={`w-full rounded-lg border px-3 py-2 text-left transition-colors ${journey === current ? "border-primary bg-primary/10" : "border-border hover:bg-secondary/60"}`}
                >
                  <span className="flex items-center justify-between gap-2">
                    <span className="flex flex-wrap items-center gap-1 text-xs font-semibold">
                      {journey.lines.length === 0 ? (
                        <span>A pie</span>
                      ) : (
                        journey.legs
                          .filter((leg): leg is PlanaBusLeg => leg.type === "bus")
                          .map((leg, i) => (
                            <span key={i} className="flex items-center gap-1">
                              {i > 0 && <span className="text-muted-foreground">→</span>}
                              <PlanaLineBadge line={leg.line} />
                            </span>
                          ))
                      )}
                    </span>
                    <span className="shrink-0 text-right leading-tight">
                      <span className="block text-sm font-semibold">
                        {journey.departs_at} → {journey.arrives_next_day ? "mañana " : ""}
                        {journey.arrives_at}
                      </span>
                      <span className="block text-[0.65rem] text-muted-foreground">
                        {formatDuration(journey.minutes)}
                      </span>
                    </span>
                  </span>
                  <span className="mt-1 block text-[0.7rem] text-muted-foreground">
                    {[
                      journey.lines.length
                        ? journey.transfers === 0
                          ? "Directo"
                          : `${journey.transfers} transbordo${journey.transfers > 1 ? "s" : ""}`
                        : null,
                      journey.walk_meters ? `${formatDistance(journey.walk_meters)} a pie` : null,
                      // En otro día, «sales en …» contaría desde su medianoche: mejor el día.
                      journeys.data && journeys.data.date !== journeys.data.requestedDate
                        ? `sale el ${formatDay(journeys.data.date)}`
                        : journey.wait_minutes > 0
                          ? `sales en ${formatDuration(journey.wait_minutes)}`
                          : "sales ya",
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </span>
                </button>
              </li>
            ))}
          </ul>

          {current && <PlanaSteps journey={current} onShowTimetable={onShowTimetable} />}

          <p className="text-[0.7rem] text-muted-foreground">
            Horarios oficiales de la Generalitat de Catalunya. Los operadores no publican tiempo
            real: el autobús puede ir con retraso.
          </p>
        </div>
      )}
    </div>
  );
}

export function PlanaSteps({
  journey,
  onShowTimetable,
}: {
  journey: PlanaJourney;
  onShowTimetable?: ((lineId: string) => void) | undefined;
}) {
  let busCount = 0;
  return (
    <div className="space-y-2 rounded-lg border border-border bg-background/40 p-3">
      <ol className="space-y-2">
        {journey.legs.map((leg, index) => (
          <li key={index} className="flex gap-2 text-xs">
            <span
              aria-hidden="true"
              className={`mt-0.5 size-2.5 shrink-0 rounded-full ${leg.type === "bus" ? "bg-primary" : "border border-muted-foreground"}`}
            />
            {leg.type === "walk" ? (
              <span>
                <span className="font-semibold">{leg.departs_at}</span> · Camina{" "}
                {formatDistance(leg.meters)} (~{leg.minutes} min) hasta{" "}
                <strong>{leg.to.name}</strong>
              </span>
            ) : (
              <PlanaBusStep
                leg={leg as PlanaBusLeg}
                transfer={busCount++ > 0}
                onShowTimetable={onShowTimetable}
              />
            )}
          </li>
        ))}
      </ol>
      <p className="border-t border-border pt-2 text-sm font-semibold">
        Llegada: {journey.arrives_next_day ? "mañana a las " : ""}
        {journey.arrives_at}
      </p>
    </div>
  );
}

function PlanaBusStep({
  leg,
  transfer,
  onShowTimetable,
}: {
  leg: PlanaBusLeg;
  transfer: boolean;
  onShowTimetable?: ((lineId: string) => void) | undefined;
}) {
  return (
    <span className="space-y-0.5">
      <span className="flex flex-wrap items-center gap-1">
        <span className="font-semibold">{leg.departs_at}</span> ·{" "}
        {transfer ? "Transbordo: toma la" : "Toma la"} <PlanaLineBadge line={leg.line} /> en{" "}
        <strong>{leg.from.name}</strong>
      </span>
      {leg.headsign && (
        <span className="block text-muted-foreground">Dirección {leg.headsign}</span>
      )}
      <span className="block">
        <span className="font-semibold">{leg.arrives_at}</span> · Baja en{" "}
        <strong>{leg.to.name}</strong> tras {leg.hops} parada{leg.hops === 1 ? "" : "s"} (
        {leg.minutes} min)
      </span>
      {leg.note && (
        <span className="block rounded border border-primary/40 bg-primary/10 px-1.5 py-1 text-primary">
          {leg.note}
        </span>
      )}
      {onShowTimetable && (
        <button
          type="button"
          onClick={() => onShowTimetable(leg.line.id)}
          className="block text-[0.7rem] font-medium text-primary hover:underline"
        >
          Ver todos los horarios de la línea {leg.line.name}
        </button>
      )}
    </span>
  );
}

/** Sin resultados: explica si es porque el origen o el destino quedan lejos de cualquier parada. */
export function NoJourneys({
  outOfArea,
}: {
  outOfArea: { origin: boolean; destination: boolean } | null;
}) {
  const far = outOfArea?.destination ? "El destino" : outOfArea?.origin ? "El origen" : null;
  return (
    <p className="rounded-md border border-border bg-secondary/40 px-3 py-2 text-sm text-muted-foreground">
      {far ? (
        <>
          <strong className="text-foreground">{far} queda fuera de la red.</strong> No hay ninguna
          parada de autobús del Camp de Tarragona a menos de 2,5 km a pie. Comprueba que has elegido
          el lugar correcto (hay nombres repetidos en otras poblaciones).
        </>
      ) : (
        <>
          No hay autobuses que hagan este trayecto en los próximos 7 días (con hasta dos
          transbordos). Prueba con una parada o un lugar cercano.
        </>
      )}
    </p>
  );
}
