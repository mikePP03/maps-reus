import { useCallback, useEffect, useRef, useState } from "react";
import { useQueries, useQuery } from "@tanstack/react-query";
import { formatDistance, getCurrentPosition, type LatLng } from "@/lib/geo";
import {
  fetchCatalogStops,
  fetchJourneyEta,
  fetchJourneys,
  fetchStopArrivals,
  formatArrival,
  journeyKey,
  type BusLeg,
  type Journey,
  type JourneyEnd,
  type JourneyEta,
  type LegEta,
} from "@/lib/reus-api";
import PlacePicker from "@/components/PlacePicker";
import Spinner from "@/components/Spinner";

const DAY_MS = 24 * 60 * 60 * 1000;
const ETA_REFRESH_MS = 30_000;

function endKey(end: JourneyEnd | null) {
  if (!end) return null;
  return end.kind === "stop" ? `stop:${end.code}` : `${end.lat.toFixed(5)},${end.lng.toFixed(5)}`;
}

/** Petición externa de «cómo llegar aquí»; `id` cambia en cada clic aunque se repita la parada. */
export type PlannerRequest = { destination: JourneyEnd; id: number };

/** «Cómo llegar»: de tu ubicación, una parada o un lugar a otro, con hasta un transbordo. */
export default function JourneyPlanner({
  onJourneyChange,
  request,
  onLocated,
  onLoadingChange,
}: {
  onJourneyChange: (journey: Journey | null) => void;
  /** Al llegar una petición, fija ese destino y usa la ubicación actual como origen. */
  request?: PlannerRequest | null;
  onLocated?: (position: LatLng) => void;
  /** Qué se está haciendo («Obteniendo tu ubicación…», «Calculando ruta…»), o null. */
  onLoadingChange?: (label: string | null) => void;
}) {
  const [origin, setOrigin] = useState<JourneyEnd | null>(null);
  const [destination, setDestination] = useState<JourneyEnd | null>(null);
  const [selected, setSelected] = useState(0);
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState<string | null>(null);
  const [changingOrigin, setChangingOrigin] = useState(false);
  // Solo cuenta la última petición de ubicación si se piden varias seguidas.
  const locateRequest = useRef(0);
  const onLocatedRef = useRef(onLocated);
  onLocatedRef.current = onLocated;

  /** Usa la ubicación actual como origen. Vale para «Cómo llegar aquí» y para «Mi ubicación». */
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
    locateOrigin();
  }, [request, locateOrigin]);

  // En cuanto hay destino, el origen es tu ubicación (salvo que ya se haya elegido otro).
  useEffect(() => {
    if (destination && !origin && !locating && !locationError) locateOrigin();
  }, [destination, origin, locating, locationError, locateOrigin]);

  const catalog = useQuery({
    queryKey: ["reus", "catalog-stops"],
    queryFn: fetchCatalogStops,
    staleTime: DAY_MS,
  });

  const journeys = useQuery({
    queryKey: ["reus", "journeys", endKey(origin), endKey(destination)],
    queryFn: () => fetchJourneys(origin!, destination!),
    enabled: origin !== null && destination !== null,
  });
  const results = origin && destination ? (journeys.data ?? []) : [];
  const current = results[selected] ?? results[0] ?? null;

  // Horas reales de cada opción (tiempo real + horario), refrescadas cada 30 s.
  const etas = useQueries({
    queries: results.map((journey) => ({
      queryKey: ["reus", "eta", journeyKey(journey)],
      queryFn: () => fetchJourneyEta(journey),
      enabled: journey.lines.length > 0,
      refetchInterval: ETA_REFRESH_MS,
      staleTime: ETA_REFRESH_MS / 2,
      retry: 1,
    })),
  });
  const best = bestIndex(
    results.map((journey, index) =>
      journey.lines.length === 0 ? journey.minutes : (etas[index]?.data?.total_minutes ?? null),
    ),
  );

  useEffect(() => onJourneyChange(current), [current, onJourneyChange]);

  // Avisa al mapa de lo que se está haciendo, para mostrarlo también allí.
  const loadingLabel = locating
    ? "Obteniendo tu ubicación…"
    : origin !== null && destination !== null && journeys.isFetching
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
        label="¿A dónde vas?"
        stops={catalog.data ?? []}
        value={destination}
        onChange={update(setDestination)}
      />
      <div className="text-xs">
        {changingOrigin ? (
          <PlacePicker
            label="Desde"
            stops={catalog.data ?? []}
            value={origin}
            onChange={(end) => {
              // Elegir otra cosa cancela una búsqueda de ubicación en curso.
              locateRequest.current++;
              setLocating(false);
              update(setOrigin)(end);
              if (end) setChangingOrigin(false);
            }}
            onRequestLocation={() => {
              setChangingOrigin(false);
              locateOrigin();
            }}
            busyText={locating ? "Obteniendo tu ubicación…" : undefined}
          />
        ) : (
          <p className="flex items-center justify-between gap-2 text-muted-foreground">
            <span className="truncate">
              Desde:{" "}
              <strong className="text-foreground">
                {locating
                  ? "buscando tu ubicación…"
                  : origin && origin.kind !== "location"
                    ? origin.name
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
          Escribe a dónde quieres ir y te diremos qué autobús coger.
        </p>
      ) : !origin ? null : journeys.isPending ? (
        <RouteLoading />
      ) : journeys.isError ? (
        <p className="text-xs text-destructive">{journeys.error.message}</p>
      ) : results.length === 0 ? (
        <p className="text-xs text-muted-foreground">
          No encontramos cómo llegar con hasta un transbordo. Prueba con una parada o un lugar
          cercano.
        </p>
      ) : (
        <div className="space-y-2">
          <ul className="ui-stagger space-y-1.5">
            {results.map((journey, index) => (
              <li key={`${journeyKey(journey)}-${index}`}>
                <button
                  type="button"
                  onClick={() => setSelected(index)}
                  aria-pressed={journey === current}
                  className={`w-full rounded-lg border px-3 py-2 text-left transition active:scale-[0.99] ${journey === current ? "border-primary bg-primary/10" : "border-border hover:bg-secondary/60"}`}
                >
                  <span className="flex items-center justify-between gap-2">
                    <span className="flex flex-wrap items-center gap-1 text-xs font-semibold">
                      {journey.lines.length === 0 ? (
                        <span>A pie</span>
                      ) : (
                        journey.lines.map((line, i) => (
                          <span key={`${line}-${i}`} className="flex items-center gap-1">
                            {i > 0 && <span className="text-muted-foreground">→</span>}
                            <span className="rounded bg-primary px-1.5 py-0.5 text-primary-foreground">
                              L{line}
                            </span>
                          </span>
                        ))
                      )}
                      {index === best && results.length > 1 && (
                        <span className="rounded-full border border-primary px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase text-primary">
                          Llega antes
                        </span>
                      )}
                    </span>
                    <ArrivalSummary journey={journey} eta={etas[index]} />
                  </span>
                  <span className="mt-1 block text-[0.7rem] text-muted-foreground">
                    {[
                      journey.transfers === 0
                        ? journey.lines.length
                          ? "Directo"
                          : null
                        : `${journey.transfers} transbordo`,
                      journey.bus_stops ? `${journey.bus_stops} paradas` : null,
                      journey.walk_meters ? `${formatDistance(journey.walk_meters)} a pie` : null,
                      journey.lines.length ? `~${journey.minutes} min sin esperas` : null,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </span>
                </button>
              </li>
            ))}
          </ul>

          {current && (
            <JourneySteps journey={current} eta={etas[results.indexOf(current)]?.data ?? null} />
          )}

          <p className="text-[0.7rem] text-muted-foreground">
            Las horas combinan el tiempo real del próximo autobús con el horario de la parada y se
            actualizan cada 30 s. El tiempo dentro del bus es una estimación.
          </p>
        </div>
      )}
    </div>
  );
}

/** Índice de la opción que llega antes; null si aún no hay datos para compararlas. */
function bestIndex(minutes: Array<number | null>) {
  let best: number | null = null;
  minutes.forEach((value, index) => {
    if (value !== null && (best === null || value < minutes[best]!)) best = index;
  });
  return best;
}

function formatDuration(minutes: number) {
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

function ArrivalSummary({
  journey,
  eta,
}: {
  journey: Journey;
  eta: { data?: JourneyEta | undefined; isPending: boolean; isError: boolean } | undefined;
}) {
  if (journey.lines.length === 0) {
    return <span className="shrink-0 text-sm font-semibold">~{journey.minutes} min</span>;
  }
  if (!eta || eta.isPending) {
    return <Spinner label="Calculando…" className="shrink-0 text-xs text-muted-foreground" />;
  }
  if (eta.isError || !eta.data) {
    return <span className="shrink-0 text-sm font-semibold">~{journey.minutes} min</span>;
  }
  if (!eta.data.complete || !eta.data.arrives_at) {
    return <span className="shrink-0 text-xs font-semibold text-destructive">Sin buses</span>;
  }
  return (
    <span className="shrink-0 text-right leading-tight">
      <span className="block text-sm font-semibold">
        {eta.data.arrives_next_day ? "Mañana " : "Llegas "}
        {eta.data.arrives_at}
      </span>
      <span className="block text-[0.65rem] text-muted-foreground">
        {formatDuration(eta.data.total_minutes ?? 0)}
      </span>
    </span>
  );
}

function JourneySteps({ journey, eta }: { journey: Journey; eta: JourneyEta | null }) {
  // Posición de cada tramo de autobús dentro de eta.legs (que solo contiene los de autobús).
  const busPositions = journey.legs.reduce<number[]>(
    (positions, leg, index) => (leg.type === "bus" ? [...positions, index] : positions),
    [],
  );

  return (
    <div className="space-y-2 rounded-lg border border-border bg-background/40 p-3">
      <ol className="space-y-2">
        {journey.legs.map((leg, index) => {
          const busIndex = busPositions.indexOf(index);
          return (
            <li key={index} className="flex gap-2 text-xs">
              <span
                aria-hidden="true"
                className={`mt-0.5 size-2.5 shrink-0 rounded-full ${leg.type === "bus" ? "bg-primary" : "border border-muted-foreground"}`}
              />
              {leg.type === "walk" ? (
                <span>
                  Camina {formatDistance(leg.meters)} (~{leg.minutes} min) hasta{" "}
                  <strong>{leg.to.name}</strong>
                  {leg.to.code && (
                    <span className="text-muted-foreground"> · parada {leg.to.code}</span>
                  )}
                </span>
              ) : (
                <BusStep leg={leg} eta={eta?.legs[busIndex] ?? null} transfer={busIndex > 0} />
              )}
            </li>
          );
        })}
      </ol>
      {eta?.complete && eta.arrives_at && (
        <p className="border-t border-border pt-2 text-sm font-semibold">
          Llegada estimada: {eta.arrives_next_day ? "mañana a las " : ""}
          {eta.arrives_at}
        </p>
      )}
    </div>
  );
}

function BusStep({ leg, eta, transfer }: { leg: BusLeg; eta: LegEta | null; transfer: boolean }) {
  return (
    <span className="space-y-0.5">
      <span className="block">
        {transfer ? "Transbordo: toma la " : "Toma la "}
        <span className="rounded bg-primary px-1 font-semibold text-primary-foreground">
          L{leg.line.name}
        </span>{" "}
        en <strong>{leg.from.name}</strong>
        {leg.from.code && <span className="text-muted-foreground"> · parada {leg.from.code}</span>}
      </span>
      {eta ? (
        <DepartureInfo eta={eta} transfer={transfer} />
      ) : (
        leg.from.code && <NextBus stop={leg.from.code} line={leg.line.id} />
      )}
      <span className="block">
        Baja en <strong>{leg.to.name}</strong> tras {leg.hops} parada{leg.hops === 1 ? "" : "s"} (~
        {leg.minutes} min{eta?.arrives_at ? `, hacia las ${eta.arrives_at}` : ""})
      </span>
      {leg.crosses_terminus && (
        <span className="block text-muted-foreground">
          Es una línea circular: no te bajes en la cabecera, el bus continúa la vuelta.
        </span>
      )}
    </span>
  );
}

/** Cuándo sale el bus del tramo y, en un transbordo, si llegas a tiempo o cuánto esperas. */
function DepartureInfo({ eta, transfer }: { eta: LegEta; transfer: boolean }) {
  if (eta.source === null) {
    return (
      <span className="block font-medium text-destructive">
        {eta.missed_at
          ? `El último pasa a las ${eta.missed_at}, antes de que llegues, y el horario no tiene más.`
          : "No hay más autobuses según el horario."}
      </span>
    );
  }
  const when = `${eta.next_day ? "mañana a las " : "a las "}${eta.departs_at}`;
  return (
    <span className="block space-y-0.5">
      {eta.missed_at && (
        <span className="block font-medium text-destructive">
          {transfer
            ? `No llegas al de las ${eta.missed_at}: pasa antes de que llegues al transbordo.`
            : `El de las ${eta.missed_at} pasa antes de que llegues a la parada.`}
        </span>
      )}
      <span className="block font-medium text-primary">
        Sale {when}
        {eta.source === "realtime"
          ? ` (en ${eta.departs_in} min, tiempo real)`
          : " (según el horario)"}
        {transfer && eta.wait_minutes !== undefined && eta.wait_minutes > 0
          ? ` · esperas ${formatDuration(eta.wait_minutes)}`
          : ""}
      </span>
    </span>
  );
}

/** Próxima llegada de una línea a una parada, compartiendo caché con el panel de llegadas. */
function NextBus({ stop, line }: { stop: string; line: string }) {
  const arrivals = useQuery({
    queryKey: ["reus", "arrivals", stop],
    queryFn: () => fetchStopArrivals(stop),
    refetchInterval: 30_000,
    staleTime: 15_000,
    retry: 1,
  });
  const arrival = arrivals.data?.find((item) => item.line.id === line);
  const value = arrival?.available ? arrival.timetoarrival : null;
  const hasPrediction = value && !/consulteu\s+horari/i.test(value);

  return (
    <span className="block font-medium text-primary">
      {arrivals.isPending ? (
        <Spinner label="Consultando el próximo bus…" />
      ) : hasPrediction ? (
        `Próximo bus: ${formatArrival(value)}`
      ) : (
        "Sin previsión en tiempo real: consulta el horario."
      )}
    </span>
  );
}

/** Mientras se calcula la ruta: spinner y tarjetas de relleno donde aparecerán las opciones. */
function RouteLoading() {
  return (
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
  );
}
