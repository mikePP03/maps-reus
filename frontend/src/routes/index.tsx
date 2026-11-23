import { createFileRoute, ClientOnly } from "@tanstack/react-router";
import { lazy, Suspense, useMemo, useState, type CSSProperties } from "react";
import { useQuery } from "@tanstack/react-query";
import { distanceKm, lineFromQuery, normalizeText, type BusStop } from "@/lib/bus-stops";
import { useBottomSheet } from "@/lib/bottom-sheet";
import { getCurrentPosition } from "@/lib/geo";
import { fetchMapStops, type Journey } from "@/lib/reus-api";
import LocateControl from "@/components/LocateControl";
import NetworkSwitch from "@/components/NetworkSwitch";
import JourneyPlanner, { type PlannerRequest } from "@/components/JourneyPlanner";
import Spinner from "@/components/Spinner";
import StopDetails from "@/components/StopDetails";

const BusMap = lazy(() => import("@/components/BusMap"));

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "Mapa de autobuses de Reus | Paradas, líneas y llegadas" },
      {
        name: "description",
        content:
          "Mapa interactivo de las paradas de Reus Transport: busca por nombre o línea, consulta las llegadas en tiempo real y calcula rutas directas.",
      },
    ],
  }),
  component: Index,
});

type Tab = "stops" | "route";

function Index() {
  // Lo primero es decir a dónde vas; las paradas, en la segunda pestaña.
  const [tab, setTab] = useState<Tab>("route");
  const [query, setQuery] = useState("");
  const [line, setLine] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [focus, setFocus] = useState<[number, number] | null>(null);
  const [userPosition, setUserPosition] = useState<{ lat: number; lng: number } | null>(null);
  const [geoState, setGeoState] = useState<"idle" | "loading" | "error">("idle");
  const [radiusKm, setRadiusKm] = useState<number | null>(null);
  const [journey, setJourney] = useState<Journey | null>(null);
  const [plannerRequest, setPlannerRequest] = useState<PlannerRequest | null>(null);
  const sheet = useBottomSheet();
  const [routeLoading, setRouteLoading] = useState<string | null>(null);

  const {
    data: stops = [],
    isPending,
    isError,
    error,
    refetch,
  } = useQuery({
    queryKey: ["reus", "map-stops"],
    queryFn: async (): Promise<BusStop[]> =>
      (await fetchMapStops()).map((stop) => ({
        id: stop.id,
        code: stop.code,
        name: stop.name,
        lat: stop.latitude,
        lng: stop.longitude,
        lines: stop.lines.map((item) => item.name),
      })),
    staleTime: 5 * 60 * 1000,
  });

  const allLines = useMemo(
    () =>
      Array.from(new Set(stops.flatMap((stop) => stop.lines))).sort(
        (a, b) => Number(a) - Number(b),
      ),
    [stops],
  );

  const results = useMemo(() => {
    const q = normalizeText(query);
    const lineQuery = lineFromQuery(query);
    let list: (BusStop & { distance?: number })[] = stops.filter((s) => {
      const matchesQuery =
        !q ||
        normalizeText(s.name).includes(q) ||
        s.code.toLowerCase() === q ||
        (lineQuery !== null && s.lines.includes(lineQuery));
      const matchesLine = !line || s.lines.includes(line);
      return matchesQuery && matchesLine;
    });

    if (userPosition) {
      list = list.map((s) => ({ ...s, distance: distanceKm(userPosition, s) }));
      if (radiusKm !== null) list = list.filter((s) => (s.distance ?? 0) <= radiusKm);
      list.sort((a, b) => (a.distance ?? 0) - (b.distance ?? 0));
    }
    return list;
  }, [query, line, userPosition, radiusKm, stops]);

  const selectedStop = stops.find((stop) => stop.id === selectedId) ?? null;
  // Aviso sobre el mapa: la ubicación (botón del mapa o «Cerca de mí») o el planificador.
  const mapLoading =
    geoState === "loading" ? "Obteniendo tu ubicación…" : tab === "route" ? routeLoading : null;

  // Con una ruta calculada a la vista, el mapa muestra solo sus paradas: el resto distrae.
  const shownJourney = tab === "route" ? journey : null;
  const mapStops = useMemo(() => {
    if (!shownJourney) return results;
    const codes = new Set(
      shownJourney.legs.flatMap((leg) =>
        leg.type === "bus" ? leg.stops.flatMap((stop) => (stop.code ? [stop.code] : [])) : [],
      ),
    );
    return stops.filter((stop) => codes.has(stop.code));
  }, [shownJourney, results, stops]);
  const hasFilters = query !== "" || line !== null || (userPosition !== null && radiusKm !== null);

  /** «Cómo llegar aquí»: abre el planificador con la parada como destino y tu ubicación como origen. */
  const navigateTo = (stop: BusStop) => {
    setTab("route");
    sheet.setSnap("half");
    setPlannerRequest({
      destination: { kind: "stop", code: stop.code, name: stop.name },
      id: Date.now(),
    });
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
        setRadiusKm((current) => current ?? 1);
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
    // En móvil el mapa ocupa toda la pantalla y el panel es una hoja inferior que se muestra u oculta;
    // --sheet-h también sube los controles del mapa (atribución) por encima de la hoja.
    <main
      style={{ "--sheet-h": `${sheet.inset}px` } as CSSProperties}
      className="relative h-dvh overflow-hidden bg-background lg:flex lg:flex-row"
    >
      <aside
        // La altura va en una variable CSS para que en escritorio mande siempre lg:h-full,
        // también antes de que React sepa el tamaño de pantalla.
        style={{ "--sheet-px": `${sheet.height}px` } as CSSProperties}
        className={`fixed inset-x-0 bottom-0 z-[1100] flex h-[var(--sheet-px)] flex-col overflow-hidden rounded-t-2xl border-t border-border bg-card shadow-[0_-8px_30px_rgba(0,0,0,0.45)] lg:static lg:z-auto lg:h-full lg:w-[360px] lg:shrink-0 lg:rounded-none lg:border-r lg:border-t-0 lg:shadow-none transition-[height] duration-200 ease-out lg:transition-none`}
      >
        {/* Solo móvil: barra con el botón de mostrar/ocultar el panel (sin arrastre, que en
            los móviles activa el «tirar para recargar» del navegador). */}
        <div className="flex h-14 shrink-0 items-center justify-between gap-3 px-5 lg:hidden">
          {/* En móvil la red ya se eligió en la bienvenida: solo se muestra su nombre. */}
          <p className="truncate text-base font-semibold tracking-tight">
            Bus urbà de <span className="text-primary">Reus</span>
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
            <NetworkSwitch current="/" />
          </div>
          <h1 className="hidden text-xl font-semibold tracking-tight lg:block">
            Paradas de bus <span className="text-primary">Reus</span>
          </h1>
          <div role="tablist" className="relative flex gap-1 rounded-lg bg-secondary/60 p-1">
            {/* Indicador que se desliza bajo la pestaña activa. */}
            <span
              aria-hidden="true"
              className={`absolute inset-y-1 left-1 w-[calc(50%-0.375rem)] rounded-md bg-primary shadow-sm transition-transform duration-300 ease-out ${tab === "route" ? "translate-x-0" : "translate-x-[calc(100%+0.25rem)]"}`}
            />
            {tabButton("route", "Ir a")}
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
              aria-label={`Llegadas en ${selectedStop.name}`}
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
                <p className="text-xs text-muted-foreground">Parada {selectedStop.code}</p>
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
                  Próximas llegadas
                </p>
                <StopDetails code={selectedStop.code} />
              </div>
            </section>
          ) : (
            <>
              <div className="flex gap-2 px-5 pt-4">
                <label htmlFor="search" className="sr-only">
                  Buscar paradas
                </label>
                <input
                  id="search"
                  type="search"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  onFocus={() => sheet.setSnap("full")}
                  placeholder="Buscar parada o línea…"
                  className="min-w-0 flex-1 rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-ring focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
                <label htmlFor="line" className="sr-only">
                  Filtrar por línea
                </label>
                <select
                  id="line"
                  value={line ?? ""}
                  onChange={(e) => setLine(e.target.value || null)}
                  className="rounded-lg border border-input bg-background px-2 py-2 text-sm"
                >
                  <option value="">Línea: todas</option>
                  {allLines.map((l) => (
                    <option key={l} value={l}>
                      Línea {l}
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex items-center justify-between px-5 py-2.5 text-xs text-muted-foreground">
                <span>
                  {isPending
                    ? "Cargando paradas…"
                    : `${results.length} paradas${userPosition && radiusKm !== null ? " cerca de ti" : ""}`}
                </span>
                <span className="flex items-center gap-3">
                  {!(userPosition && radiusKm !== null) && (
                    <button
                      type="button"
                      onClick={() => (userPosition ? setRadiusKm(1) : locate())}
                      disabled={geoState === "loading"}
                      className="inline-flex items-center gap-1 font-medium text-primary hover:underline disabled:opacity-60"
                    >
                      <svg
                        viewBox="0 0 24 24"
                        className="size-3.5"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        aria-hidden="true"
                      >
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v3M12 19v3M2 12h3M19 12h3" />
                      </svg>
                      {geoState === "loading" ? "Buscando…" : "Cerca de mí"}
                    </button>
                  )}
                  {hasFilters && (
                    <button
                      type="button"
                      onClick={() => {
                        setQuery("");
                        setLine(null);
                        setRadiusKm(null);
                      }}
                      className="font-medium text-primary hover:underline"
                    >
                      Limpiar filtros
                    </button>
                  )}
                </span>
              </div>
              {geoState === "error" && (
                <p className="mx-5 mb-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                  No pudimos obtener tu ubicación. Permite el acceso a la ubicación en el navegador
                  (icono del candado en la barra de direcciones).
                </p>
              )}

              <ul className="min-h-0 flex-1 overflow-y-auto px-3 pb-6">
                {isError && (
                  <li className="mx-2 my-4 rounded-lg border border-destructive/40 bg-destructive/10 p-4 text-sm">
                    <p>No se pudo conectar con el backend de Reus.</p>
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
                {results.map((stop) => (
                  <li key={stop.id}>
                    <button
                      type="button"
                      onClick={() => selectStop(stop)}
                      className="mb-1 flex w-full items-center justify-between gap-3 rounded-lg border border-transparent px-2 py-2 text-left transition-colors hover:border-border hover:bg-secondary/60"
                    >
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{stop.name}</span>
                        <span className="text-xs text-muted-foreground">
                          {stop.distance !== undefined
                            ? stop.distance < 1
                              ? `${Math.round(stop.distance * 1000)} m`
                              : `${stop.distance.toFixed(1)} km`
                            : `Parada ${stop.code}`}
                        </span>
                      </span>
                      <span className="flex shrink-0 flex-wrap justify-end gap-1">
                        {stop.lines.map((l) => (
                          <span
                            key={l}
                            className="rounded bg-secondary px-1.5 py-0.5 text-[0.65rem] font-semibold text-secondary-foreground"
                          >
                            {l}
                          </span>
                        ))}
                      </span>
                    </button>
                  </li>
                ))}
                {!isPending && !isError && results.length === 0 && (
                  <li className="px-3 py-8 text-center text-sm text-muted-foreground">
                    No hay paradas que coincidan con los filtros.
                  </li>
                )}
              </ul>
            </>
          )}
        </div>

        {/* Se mantiene montado al cambiar de pestaña para no perder origen y destino. */}
        <div
          id="panel-route"
          role="tabpanel"
          aria-labelledby="tab-route"
          hidden={tab !== "route"}
          className="ui-fade-up min-h-0 flex-1 overflow-y-auto"
        >
          <JourneyPlanner
            onJourneyChange={setJourney}
            request={plannerRequest}
            onLocated={setUserPosition}
            onLoadingChange={setRouteLoading}
          />
        </div>
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
              bottomInset={sheet.inset}
              onNavigate={navigateTo}
              userPosition={userPosition}
              radiusKm={radiusKm}
              focus={focus}
              journey={shownJourney}
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
          radiusKm={radiusKm}
          onLocate={locate}
          onRadiusChange={setRadiusKm}
        />
      </section>
    </main>
  );
}
