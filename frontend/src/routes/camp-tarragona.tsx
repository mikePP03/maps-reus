import { createFileRoute, ClientOnly, Outlet, useNavigate } from "@tanstack/react-router";
import { lazy, Suspense, useMemo, useState, type CSSProperties } from "react";
import { useQuery } from "@tanstack/react-query";
import { useBottomSheet } from "@/lib/bottom-sheet";
import { lineFromQuery, normalizeText, type BusStop } from "@/lib/bus-stops";
import { getCurrentPosition } from "@/lib/geo";
import {
  fetchPlanaFeed,
  fetchPlanaLine,
  fetchPlanaLines,
  fetchPlanaMapStops,
  type PlanaJourney,
} from "@/lib/plana-api";
import LocateControl from "@/components/LocateControl";
import NetworkSwitch from "@/components/NetworkSwitch";
import PlanaDepartures from "@/components/PlanaDepartures";
import PlanaGoTo from "@/components/PlanaGoTo";
import type { PlanaPlannerRequest } from "@/components/PlanaPlanner";
import Spinner from "@/components/Spinner";

const BusMap = lazy(() => import("@/components/BusMap"));

export const Route = createFileRoute("/camp-tarragona")({
  head: () => ({
    meta: [
      { title: "Bus Camp de Tarragona | Paradas, horarios y rutas" },
      {
        name: "description",
        content:
          "Autobuses interurbanos del Camp de Tarragona (Plana, Hispano Igualadina, HIFE y más): paradas, próximas salidas y cómo llegar con los horarios oficiales de la Generalitat.",
      },
    ],
  }),
  component: CampPage,
});

type Tab = "goto" | "stops";

const DAY_MS = 24 * 60 * 60 * 1000;

function CampPage() {
  // «Ir a» es la pestaña de inicio: lo más habitual es querer ir a un sitio desde donde se está.
  const [tab, setTab] = useState<Tab>("goto");
  const [query, setQuery] = useState("");
  const [lineId, setLineId] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [focus, setFocus] = useState<[number, number] | null>(null);
  const [userPosition, setUserPosition] = useState<{ lat: number; lng: number } | null>(null);
  const [geoState, setGeoState] = useState<"idle" | "loading" | "error">("idle");
  const [gotoJourney, setGotoJourney] = useState<PlanaJourney | null>(null);
  const [gotoLoading, setGotoLoading] = useState<string | null>(null);
  const [plannerRequest, setPlannerRequest] = useState<PlanaPlannerRequest | null>(null);
  const sheet = useBottomSheet();
  const navigate = useNavigate();

  const {
    data: stops = [],
    isPending,
    isError,
    error,
    refetch,
  } = useQuery({
    queryKey: ["plana", "map-stops"],
    queryFn: async (): Promise<BusStop[]> =>
      (await fetchPlanaMapStops()).map((stop) => ({
        id: stop.id,
        code: stop.code,
        name: stop.name,
        lat: stop.latitude,
        lng: stop.longitude,
        lines: stop.lines.map((item) => item.name),
      })),
    staleTime: DAY_MS,
  });
  const lines = useQuery({
    queryKey: ["plana", "lines"],
    queryFn: fetchPlanaLines,
    staleTime: DAY_MS,
  });
  const feed = useQuery({
    queryKey: ["plana", "feed"],
    queryFn: fetchPlanaFeed,
    staleTime: DAY_MS,
  });
  const line = useQuery({
    queryKey: ["plana", "line", lineId],
    queryFn: () => fetchPlanaLine(lineId!),
    enabled: lineId !== null,
    staleTime: DAY_MS,
  });
  const selectedLine = lines.data?.find((item) => item.id === lineId) ?? null;

  const lineStopCodes = useMemo(
    () =>
      line.data ? new Set(line.data.patterns.flatMap((p) => p.stops.map((s) => s.code))) : null,
    [line.data],
  );

  const results = useMemo(() => {
    const q = normalizeText(query);
    const lineQuery = lineFromQuery(query);
    return stops.filter((stop) => {
      const matchesQuery =
        !q ||
        normalizeText(stop.name).includes(q) ||
        stop.lines.some((name) => normalizeText(name) === q) ||
        (lineQuery !== null && stop.lines.some((name) => name.replace(/\D/g, "") === lineQuery));
      const matchesLine = !lineStopCodes || lineStopCodes.has(stop.code);
      return matchesQuery && matchesLine;
    });
  }, [query, stops, lineStopCodes]);

  const selectedStop = stops.find((stop) => stop.id === selectedId) ?? null;
  const shownJourney = tab === "goto" ? gotoJourney : null;
  const mapStops = useMemo(() => {
    if (!shownJourney) return results;
    const codes = new Set(
      shownJourney.legs.flatMap((leg) =>
        leg.type === "bus" ? leg.stops.flatMap((stop) => (stop.code ? [stop.code] : [])) : [],
      ),
    );
    return stops.filter((stop) => codes.has(stop.code));
  }, [shownJourney, results, stops]);
  const lineShapes = useMemo(
    () => (tab === "stops" && line.data ? line.data.patterns.map((p) => p.geometry) : []),
    [tab, line.data],
  );
  const mapLoading =
    geoState === "loading" ? "Obteniendo tu ubicación…" : tab === "goto" ? gotoLoading : null;

  const navigateTo = (stop: BusStop) => {
    setTab("goto");
    sheet.setSnap("half");
    setPlannerRequest({
      destination: { kind: "stop", code: stop.code, name: stop.name },
      id: Date.now(),
    });
  };

  /**
   * Abre el horario de la línea en su propia página a pantalla completa. Es una subruta: esta
   * página sigue montada debajo, así que al volver se conserva lo que se estaba consultando.
   */
  const showTimetable = (id: string) => {
    void navigate({ to: "/camp-tarragona/linea/$lineId", params: { lineId: id } });
  };

  const selectStop = (stop: BusStop) => {
    setTab("stops");
    sheet.setSnap("half");
    setSelectedId(stop.id);
    setFocus([stop.lat, stop.lng]);
  };

  const locate = () => {
    setGeoState("loading");
    getCurrentPosition().then(
      (coords) => {
        setUserPosition(coords);
        setFocus([coords.lat, coords.lng]);
        setGeoState("idle");
      },
      () => setGeoState("error"),
    );
  };

  const tabButton = (value: Tab, label: string) => (
    <button
      type="button"
      role="tab"
      id={`tab-${value}`}
      aria-selected={tab === value}
      aria-controls={`panel-${value}`}
      onClick={() => {
        setTab(value);
        sheet.reveal();
      }}
      className={`relative z-10 flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-300 active:scale-[0.97] ${
        tab === value ? "text-primary-foreground" : "text-muted-foreground hover:text-foreground"
      }`}
    >
      {label}
    </button>
  );

  return (
    <main
      style={{ "--sheet-h": `${sheet.inset}px` } as CSSProperties}
      className="relative h-dvh overflow-hidden bg-background lg:flex lg:flex-row"
    >
      <aside
        style={{ "--sheet-px": `${sheet.height}px` } as CSSProperties}
        className="fixed inset-x-0 bottom-0 z-[1100] flex h-[var(--sheet-px)] flex-col overflow-hidden rounded-t-2xl border-t border-border bg-card shadow-[0_-8px_30px_rgba(0,0,0,0.45)] transition-[height] duration-200 ease-out lg:static lg:z-auto lg:h-full lg:w-[360px] lg:shrink-0 lg:rounded-none lg:border-r lg:border-t-0 lg:shadow-none lg:transition-none"
      >
        <div className="flex h-14 shrink-0 items-center justify-between gap-3 px-5 lg:hidden">
          {/* En móvil la red ya se eligió en la bienvenida: solo se muestra su nombre. */}
          <p className="truncate text-base font-semibold tracking-tight">
            Bus <span className="text-primary">Camp de Tarragona</span>
          </p>
          <button
            type="button"
            onClick={sheet.toggle}
            aria-expanded={!sheet.collapsed}
            aria-controls="sheet-content"
            className="flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-secondary px-3 py-1.5 text-xs font-semibold"
          >
            {sheet.collapsed ? "Mostrar" : "Ocultar"}
            <svg
              viewBox="0 0 24 24"
              className={`size-4 transition-transform ${sheet.collapsed ? "" : "rotate-180"}`}
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="m6 15 6-6 6 6" />
            </svg>
          </button>
        </div>
        <header
          id="sheet-content"
          className="shrink-0 space-y-3 border-b border-border px-5 pb-4 pt-1 lg:pt-4"
        >
          <div className="hidden lg:block">
            <NetworkSwitch current="/camp-tarragona" />
          </div>
          <h1 className="hidden text-xl font-semibold tracking-tight lg:block">
            Bus <span className="text-primary">Camp de Tarragona</span>
          </h1>
          <div role="tablist" className="relative flex gap-1 rounded-lg bg-secondary/60 p-1">
            {/* Indicador que se desliza bajo la pestaña activa. */}
            <span
              aria-hidden="true"
              className={`absolute inset-y-1 left-1 w-[calc(50%-0.375rem)] rounded-md bg-primary shadow-sm transition-transform duration-300 ease-out ${tab === "goto" ? "translate-x-0" : "translate-x-[calc(100%+0.25rem)]"}`}
            />
            {tabButton("goto", "Ir a")}
            {tabButton("stops", "Paradas")}
          </div>
        </header>

        <div
          id="panel-stops"
          role="tabpanel"
          aria-labelledby="tab-stops"
          hidden={tab !== "stops"}
          className={tab === "stops" ? "ui-fade-up flex min-h-0 flex-1 flex-col" : "hidden"}
        >
          {selectedStop ? (
            <section
              aria-label={`Salidas desde ${selectedStop.name}`}
              key={selectedStop.id}
              className="ui-fade-up min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4"
            >
              <button
                type="button"
                onClick={() => setSelectedId(null)}
                className="text-xs font-medium text-primary hover:underline"
              >
                ← Volver a la lista
              </button>
              <div>
                <h2 className="text-lg font-semibold leading-tight">{selectedStop.name}</h2>
                <p className="text-xs text-muted-foreground">
                  Líneas: {selectedStop.lines.join(", ")}
                </p>
              </div>
              <button
                type="button"
                onClick={() => navigateTo(selectedStop)}
                className="w-full rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90 active:scale-[0.98]"
              >
                Cómo llegar aquí
              </button>
              <div>
                <p className="mb-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                  Próximas salidas
                </p>
                <PlanaDepartures stop={selectedStop.code} onShowTimetable={showTimetable} />
              </div>
            </section>
          ) : (
            <>
              <div className="space-y-2 px-5 pt-4">
                <label htmlFor="plana-search" className="sr-only">
                  Buscar paradas
                </label>
                <input
                  id="plana-search"
                  type="search"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  onFocus={() => sheet.setSnap("full")}
                  placeholder="Buscar parada o población…"
                  className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
                <label htmlFor="plana-line" className="sr-only">
                  Filtrar por línea
                </label>
                <select
                  id="plana-line"
                  value={lineId ?? ""}
                  onChange={(e) => {
                    setLineId(e.target.value || null);
                    setSelectedId(null);
                  }}
                  className="w-full rounded-lg border border-input bg-background px-2 py-2 text-sm"
                >
                  <option value="">Todas las líneas ({lines.data?.length ?? "…"})</option>
                  {lines.data?.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name} · {item.description}
                    </option>
                  ))}
                </select>
                {selectedLine && (
                  <div className="flex items-center justify-between gap-2">
                    <p className="min-w-0 text-xs text-muted-foreground">
                      {line.isPending ? (
                        <Spinner label="Cargando el recorrido…" />
                      ) : (
                        <>
                          {selectedLine.description} · {selectedLine.operator}
                        </>
                      )}
                    </p>
                    <button
                      type="button"
                      onClick={() => showTimetable(selectedLine.id)}
                      className="shrink-0 rounded-md border border-primary px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/10"
                    >
                      Ver horarios
                    </button>
                  </div>
                )}
              </div>

              <div className="flex items-center justify-between px-5 py-2.5 text-xs text-muted-foreground">
                <span>{isPending ? "Cargando paradas…" : `${results.length} paradas`}</span>
                {(query || lineId) && (
                  <button
                    type="button"
                    onClick={() => {
                      setQuery("");
                      setLineId(null);
                    }}
                    className="font-medium text-primary hover:underline"
                  >
                    Limpiar filtros
                  </button>
                )}
              </div>

              <ul className="min-h-0 flex-1 overflow-y-auto px-3 pb-6">
                {isError && (
                  <li className="mx-2 my-4 rounded-lg border border-destructive/40 bg-destructive/10 p-4 text-sm">
                    <p>No se pudo cargar la red del Camp de Tarragona.</p>
                    <p className="mt-1 text-xs text-muted-foreground">{error.message}</p>
                    <button
                      type="button"
                      className="mt-2 font-semibold text-primary hover:underline"
                      onClick={() => void refetch()}
                    >
                      Reintentar
                    </button>
                  </li>
                )}
                {results.slice(0, 300).map((stop) => (
                  <li key={stop.id}>
                    <button
                      type="button"
                      onClick={() => selectStop(stop)}
                      className="mb-1 w-full rounded-lg border border-transparent px-2 py-2 text-left transition-colors hover:border-border hover:bg-secondary/60"
                    >
                      <span className="block truncate text-sm font-medium">{stop.name}</span>
                      <span className="block truncate text-xs text-muted-foreground">
                        {stop.lines.join(" · ")}
                      </span>
                    </button>
                  </li>
                ))}
                {results.length > 300 && (
                  <li className="px-3 py-2 text-center text-xs text-muted-foreground">
                    Mostrando 300 de {results.length}. Escribe para filtrar.
                  </li>
                )}
                {!isPending && !isError && results.length === 0 && (
                  <li className="px-3 py-8 text-center text-sm text-muted-foreground">
                    No hay paradas que coincidan con los filtros.
                  </li>
                )}
              </ul>
            </>
          )}
        </div>

        {/* Se mantiene montado al cambiar de pestaña para no perder destino ni resultados. */}
        <div
          id="panel-goto"
          role="tabpanel"
          aria-labelledby="tab-goto"
          hidden={tab !== "goto"}
          className="ui-fade-up min-h-0 flex-1 overflow-y-auto"
        >
          <PlanaGoTo
            request={plannerRequest}
            onJourneyChange={setGotoJourney}
            onLocated={setUserPosition}
            onLoadingChange={setGotoLoading}
            onShowTimetable={showTimetable}
          />
        </div>

        {feed.data && (
          <p className="hidden shrink-0 border-t border-border px-5 py-2 text-[0.65rem] text-muted-foreground lg:block">
            Datos: {feed.data.source}. Vigentes hasta el{" "}
            {feed.data.valid_to?.split("-").reverse().join("/")}.
          </p>
        )}
      </aside>

      <section className="absolute inset-0 lg:relative lg:inset-auto lg:h-full lg:flex-1">
        <ClientOnly fallback={<div className="h-full w-full animate-pulse bg-secondary/40" />}>
          <Suspense fallback={<div className="h-full w-full animate-pulse bg-secondary/40" />}>
            <BusMap
              stops={mapStops}
              selectedId={selectedId}
              onSelect={(id) => {
                setTab("stops");
                setSelectedId(id);
                if (sheet.snap === "full") sheet.setSnap("half");
              }}
              onNavigate={navigateTo}
              userPosition={userPosition}
              radiusKm={null}
              focus={focus}
              journey={shownJourney}
              bottomInset={sheet.inset}
              renderPopup={(stop) => (
                <PlanaDepartures stop={stop.code} onShowTimetable={showTimetable} />
              )}
              lineShapes={lineShapes}
              initialZoom={11}
              {...(selectedLine?.color ? { lineColor: `#${selectedLine.color}` } : {})}
            />
          </Suspense>
        </ClientOnly>
        {mapLoading && (
          <div className="ui-pop pointer-events-none absolute left-1/2 top-3 z-[1000] -translate-x-1/2 whitespace-nowrap">
            <Spinner
              label={mapLoading}
              className="rounded-full border border-border bg-card px-4 py-2 text-sm font-medium shadow-lg"
            />
          </div>
        )}
        <LocateControl
          active={userPosition !== null}
          state={geoState}
          radiusKm={null}
          onLocate={locate}
          onRadiusChange={() => undefined}
          showRadius={false}
        />
      </section>
      {/* Subpáginas a pantalla completa (horario de una línea) encima del mapa. */}
      <Outlet />
    </main>
  );
}
