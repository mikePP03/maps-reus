import { useEffect, useMemo, useRef, type ReactNode } from "react";
import L from "leaflet";
import { useQuery } from "@tanstack/react-query";
import { MapContainer, TileLayer, Marker, Popup, Circle, Polyline, useMap } from "react-leaflet";
import { MAP_CENTER, type BusStop } from "@/lib/bus-stops";
import { fetchWalkPath, type Journey } from "@/lib/reus-api";
import StopDetails from "@/components/StopDetails";

function stopIcon(active: boolean) {
  return L.divIcon({
    className: "",
    html: `<span class="map-pin${active ? " map-pin--active" : ""}"></span>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  });
}

const NO_SHAPES: [number, number][][] = [];

const userIcon = L.divIcon({
  className: "",
  html: `<span class="map-user"></span>`,
  iconSize: [16, 16],
  iconAnchor: [8, 8],
});

/** Cierra el popup antes de lanzar la ruta, para que no tape el recorrido. */
function NavigateButton({ onClick }: { onClick: () => void }) {
  const map = useMap();
  return (
    <button
      type="button"
      onClick={() => {
        map.closePopup();
        onClick();
      }}
      className="w-full rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:opacity-90"
    >
      Cómo llegar aquí
    </button>
  );
}

/** Lo que tapa la hoja inferior en móvil; en un ref para no recentrar al mover la hoja. */
function useInsetRef(inset: number) {
  const ref = useRef(inset);
  ref.current = inset;
  return ref;
}

function Recenter({
  center,
  zoom,
  inset,
}: {
  center: [number, number] | null;
  zoom: number;
  inset: number;
}) {
  const map = useMap();
  const insetRef = useInsetRef(inset);
  useEffect(() => {
    const bottom = visibleBottomPadding(map, insetRef.current);
    if (!center || bottom === null) return;
    // Centra el punto en la parte visible, por encima de la hoja inferior.
    const target = map.unproject(map.project(center, zoom).add([0, bottom / 2]), zoom);
    map.flyTo(target, zoom, { duration: 0.8 });
  }, [center, zoom, map, insetRef]);
  return null;
}

/** Al abrir, centra la ciudad en la parte visible del mapa (por encima de la hoja en móvil). */
function InitialView({ inset }: { inset: number }) {
  const map = useMap();
  const done = useRef(false);
  useEffect(() => {
    if (done.current || inset === 0) return;
    done.current = true;
    map.panBy([0, inset / 2], { animate: false });
  }, [inset, map]);
  return null;
}

/**
 * Margen inferior útil: lo que tapa la hoja, pero dejando siempre una franja visible de mapa.
 * Devuelve null si el mapa aún no tiene tamaño (oculto o sin pintar): Leaflet calcularía NaN.
 */
function visibleBottomPadding(map: L.Map, inset: number) {
  const size = map.getSize();
  if (!size.x || !size.y) return null;
  return Math.min(inset, Math.max(0, size.y - 160));
}

/** Encuadra el viaje completo cada vez que cambia el seleccionado. */
function FitRoute({ points, inset }: { points: [number, number][]; inset: number }) {
  const map = useMap();
  const insetRef = useInsetRef(inset);
  useEffect(() => {
    const bottom = visibleBottomPadding(map, insetRef.current);
    if (points.length < 2 || bottom === null) return;
    map.flyToBounds(points, {
      paddingTopLeft: [40, 70],
      paddingBottomRight: [40, bottom + 40],
      duration: 0.8,
    });
  }, [points, map, insetRef]);
  return null;
}

const endpointIcon = (variant: "start" | "end") =>
  L.divIcon({
    className: "",
    html: `<span class="map-endpoint map-endpoint--${variant}"></span>`,
    iconSize: [22, 22],
    iconAnchor: [11, 11],
  });
const startIcon = endpointIcon("start");
const endIcon = endpointIcon("end");

/** Un color por tramo de autobús, para distinguir las líneas cuando hay transbordo. */
const BUS_COLORS = ["#f5a524", "#22d3ee"];

type Segment = { kind: "bus" | "walk"; positions: [number, number][]; color: string };

function journeySegments(journey: Journey | null): Segment[] {
  if (!journey) return [];
  let busIndex = 0;
  return journey.legs.map((leg) => {
    const straight = (leg.type === "bus" ? leg.stops : [leg.from, leg.to]).flatMap((point) =>
      point.lat === null || point.lng === null ? [] : [[point.lat, point.lng] as [number, number]],
    );
    return {
      kind: leg.type,
      // El autobús trae el trazado por calles; los tramos a pie se piden aparte (WalkLine).
      positions: leg.type === "bus" && leg.geometry?.length > 1 ? leg.geometry : straight,
      color: leg.type === "bus" ? BUS_COLORS[busIndex++ % BUS_COLORS.length]! : "#e5e7eb",
    };
  });
}

const WALK_STYLE = {
  color: "#e5e7eb",
  weight: 4,
  opacity: 0.85,
  dashArray: "2 10",
  className: "map-walk",
};

/** Tramo a pie: se dibuja recto al momento y se sustituye por el camino real cuando llega. */
function WalkLine({ positions }: { positions: [number, number][] }) {
  const [from, to] = [positions[0], positions[positions.length - 1]];
  const path = useQuery({
    queryKey: ["reus", "walk-path", from, to],
    queryFn: () => fetchWalkPath({ lat: from![0], lng: from![1] }, { lat: to![0], lng: to![1] }),
    enabled: from !== undefined && to !== undefined,
    staleTime: Infinity,
    retry: false,
  });
  const geometry = path.data?.geometry;
  // El camino empieza y acaba en la calle: se une con los puntos exactos de origen y destino.
  const line = geometry && geometry.length > 1 && from && to ? [from, ...geometry, to] : positions;

  return <Polyline positions={line} pathOptions={WALK_STYLE} />;
}

type Props = {
  stops: BusStop[];
  selectedId: string | null;
  onSelect: (id: string) => void;
  /** «Cómo llegar aquí» desde el popup de la parada. */
  onNavigate: (stop: BusStop) => void;
  userPosition: { lat: number; lng: number } | null;
  radiusKm: number | null;
  focus: [number, number] | null;
  journey: Journey | null;
  /** Píxeles que tapa la hoja inferior en móvil (0 en escritorio). */
  bottomInset: number;
  /** Contenido del popup bajo el nombre; por defecto, las llegadas en tiempo real de Reus. */
  renderPopup?: (stop: BusStop) => ReactNode;
  /** Recorridos de una línea seleccionada (uno por sentido), dibujados y encuadrados. */
  lineShapes?: [number, number][][];
  lineColor?: string;
  initialZoom?: number;
};

export default function BusMap({
  stops,
  selectedId,
  onSelect,
  onNavigate,
  userPosition,
  radiusKm,
  focus,
  journey,
  bottomInset,
  renderPopup = (stop) => <StopDetails code={stop.code} />,
  lineShapes = NO_SHAPES,
  lineColor = "#22d3ee",
  initialZoom = 13,
}: Props) {
  const icons = useMemo(() => ({ base: stopIcon(false), active: stopIcon(true) }), []);
  const segments = useMemo(() => journeySegments(journey), [journey]);
  const points = useMemo(() => {
    const route = segments.flatMap((segment) => segment.positions);
    return route.length > 1 ? route : lineShapes.flat();
  }, [segments, lineShapes]);

  return (
    <MapContainer
      center={MAP_CENTER}
      zoom={initialZoom}
      scrollWheelZoom
      className="h-full w-full"
      zoomControl={false}
    >
      <TileLayer
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
        className="map-tiles--dark"
        maxZoom={19}
      />
      <InitialView inset={bottomInset} />
      <Recenter center={focus} zoom={15} inset={bottomInset} />
      <FitRoute points={points} inset={bottomInset} />
      {segments.map(
        (segment, index) =>
          segment.positions.length > 1 &&
          (segment.kind === "bus" ? (
            <Polyline
              key={index}
              positions={segment.positions}
              pathOptions={{ color: segment.color, weight: 6, opacity: 0.9 }}
            />
          ) : (
            <WalkLine key={index} positions={segment.positions} />
          )),
      )}
      {lineShapes.map(
        (shape, index) =>
          shape.length > 1 && (
            <Polyline
              key={`line-${index}`}
              positions={shape}
              pathOptions={{ color: lineColor, weight: 5, opacity: 0.85 }}
            />
          ),
      )}
      {segments.length > 0 && points.length > 1 && (
        <>
          <Marker position={points[0]!} icon={startIcon} interactive={false} />
          <Marker position={points[points.length - 1]!} icon={endIcon} interactive={false} />
        </>
      )}
      {userPosition && (
        <>
          <Marker position={[userPosition.lat, userPosition.lng]} icon={userIcon} />
          {radiusKm !== null && (
            <Circle
              center={[userPosition.lat, userPosition.lng]}
              radius={radiusKm * 1000}
              pathOptions={{ color: "#f5a524", weight: 1, fillOpacity: 0.06 }}
            />
          )}
        </>
      )}
      {stops.map((stop) => (
        <Marker
          key={stop.id}
          position={[stop.lat, stop.lng]}
          icon={selectedId === stop.id ? icons.active : icons.base}
          eventHandlers={{ click: () => onSelect(stop.id) }}
        >
          {/* react-leaflet solo monta el contenido mientras el popup está abierto,
              así que las llegadas se consultan únicamente al abrirlo. */}
          <Popup
            minWidth={240}
            maxWidth={300}
            autoPanPaddingTopLeft={[20, 80]}
            autoPanPaddingBottomRight={[20, bottomInset + 20]}
          >
            <div className="space-y-2">
              <div>
                <p className="text-sm font-semibold">{stop.name}</p>
                <p className="text-xs opacity-70">Parada {stop.code}</p>
              </div>
              <NavigateButton onClick={() => onNavigate(stop)} />
              {renderPopup(stop)}
            </div>
          </Popup>
        </Marker>
      ))}
    </MapContainer>
  );
}
