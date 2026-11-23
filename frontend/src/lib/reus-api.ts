export type ApiLine = {
  id: string;
  name: string;
  description: string | null;
};

export type ApiMapStop = {
  id: string;
  code: string;
  name: string;
  latitude: number;
  longitude: number;
  lines: ApiLine[];
};

/** Parada del catálogo completo, incluidas las que aún no tienen coordenadas. */
export type CatalogStop = {
  code: string;
  name: string;
  hasCoordinates: boolean;
};

/** Lugar encontrado en OpenStreetMap («Polígon Industrial Agro-Reus»). */
export type Place = {
  name: string;
  label: string;
  lat: number;
  lng: number;
};

/** Punto de un viaje: una parada (con `code`) o un lugar/ubicación (sin él). */
export type JourneyPoint = {
  code: string | null;
  name: string;
  lat: number | null;
  lng: number | null;
};

export type WalkLeg = {
  type: "walk";
  from: JourneyPoint;
  to: JourneyPoint;
  meters: number;
  minutes: number;
};

export type BusLeg = {
  type: "bus";
  line: { id: string; name: string };
  from: JourneyPoint;
  to: JourneyPoint;
  stops: JourneyPoint[];
  /** Trazado por calles en orden de marcha ([lat, lng]); recto donde no hay datos. */
  geometry: [number, number][];
  hops: number;
  minutes: number;
  /** En una línea circular, el tramo sigue después de la cabecera. */
  crosses_terminus: boolean;
};

export type Journey = {
  /** Líneas en orden; vacío si se propone ir andando. */
  lines: string[];
  /** Estimación sin esperas: distancia a pie y número de paradas. */
  minutes: number;
  walk_meters: number;
  transfers: number;
  bus_stops: number;
  legs: Array<WalkLeg | BusLeg>;
};

/** Origen o destino de un viaje, tal como lo pide /journeys. */
export type JourneyEnd =
  | { kind: "stop"; code: string; name: string }
  | { kind: "place" | "location"; name: string; lat: number; lng: number };
export type StopArrival = {
  line: { id: string; name: string };
  destination: string | null;
  timetoarrival: string | null;
  available: boolean;
  error: string | null;
};

export type StopSchedule = {
  line: string;
  stop: string;
  sections: Array<{ title: string; times: string[]; message: string | null }>;
  safe_text: string;
};

export type ApiResponse<T> = {
  success: boolean;
  data: T;
  meta: Record<string, unknown>;
  message?: string;
};

const DEV_API_URL = "http://127.0.0.1:8000/api/v1/reus";

// En desarrollo se usa el backend local; en producción la URL debe definirse al compilar,
// porque un build con 127.0.0.1 haría que cada visitante llamase a su propio ordenador.
export const API_URL = (
  import.meta.env.VITE_REUS_API_URL || (import.meta.env.DEV ? DEV_API_URL : "")
).replace(/\/$/, "");

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/** URL de la API de la red interurbana Plana: misma raíz que la de Reus. */
export const PLANA_API_URL = API_URL.replace(/\/reus$/, "/plana");

/** Petición GET a la API (de Reus por defecto) con el sobre {success, data, meta}. */
export async function apiGet<T>(
  path: string,
  parameters?: Record<string, string>,
  base: string = API_URL,
): Promise<ApiResponse<T>> {
  if (!base) {
    throw new ApiError("Falta configurar VITE_REUS_API_URL al compilar la aplicación.", 0);
  }
  const query = parameters ? `?${new URLSearchParams(parameters)}` : "";
  const response = await fetch(`${base}${path}${query}`, {
    headers: { Accept: "application/json" },
  });
  const payload = (await response.json().catch(() => null)) as ApiResponse<T> | null;

  if (!response.ok || !payload?.success) {
    const message =
      response.status === 429
        ? "Demasiadas consultas seguidas. Espera un minuto."
        : (payload?.message ?? `El backend respondió con el estado ${response.status}.`);
    throw new ApiError(message, response.status);
  }

  return payload;
}

export async function fetchMapStops(): Promise<ApiMapStop[]> {
  return (await get<ApiMapStop[]>("/map-stops")).data;
}

/** Recorre la paginación de /stops para obtener todas las paradas activas. */
export async function fetchCatalogStops(): Promise<CatalogStop[]> {
  type Row = {
    external_id: string;
    name: string;
    latitude: string | null;
    longitude: string | null;
  };
  const stops: CatalogStop[] = [];
  for (let page = 1; ; page++) {
    const payload = await get<Row[]>("/stops", { per_page: "100", page: String(page) });
    stops.push(
      ...payload.data.map((row) => ({
        code: row.external_id,
        name: row.name,
        hasCoordinates: row.latitude !== null && row.longitude !== null,
      })),
    );
    const lastPage = Number(payload.meta["last_page"] ?? page);
    if (page >= lastPage) return stops;
  }
}

export async function fetchStopArrivals(code: string): Promise<StopArrival[]> {
  return (await get<StopArrival[]>(`/stops/${encodeURIComponent(code)}/arrivals`)).data;
}

export async function fetchSchedule(line: string, stop: string): Promise<StopSchedule> {
  return (await get<StopSchedule>("/schedules", { line, stop })).data;
}

function endParameters(side: "from" | "to", end: JourneyEnd): Record<string, string> {
  return end.kind === "stop"
    ? { [`${side}_stop`]: end.code, [`${side}_label`]: end.name }
    : {
        [`${side}_lat`]: String(end.lat),
        [`${side}_lng`]: String(end.lng),
        [`${side}_label`]: end.name,
      };
}

/** Viajes de A a B: a pie, con una línea directa o con un transbordo. */
export async function fetchJourneys(from: JourneyEnd, to: JourneyEnd): Promise<Journey[]> {
  return (
    await get<Journey[]>("/journeys", {
      ...endParameters("from", from),
      ...endParameters("to", to),
    })
  ).data;
}

/** Busca lugares de Reus por texto (mínimo 3 caracteres). */
/** Busca lugares por texto (mínimo 3 caracteres): en Reus o en toda Cataluña. */
export async function searchPlaces(
  query: string,
  area: "reus" | "catalunya" = "reus",
): Promise<Place[]> {
  return (await get<Place[]>("/places", { q: query, area })).data;
}

/** El proveedor devuelve a veces solo el número («5»): se le añade la unidad. */
export function formatArrival(value: string) {
  return /^\d+$/.test(value.trim()) ? `${value.trim()} min` : value;
}

export type WalkPath = { geometry: [number, number][]; meters: number };

/** Camino a pie por calles entre dos puntos; null si el servicio de rutas no responde. */
export async function fetchWalkPath(
  from: { lat: number; lng: number },
  to: { lat: number; lng: number },
): Promise<WalkPath | null> {
  return (
    await get<WalkPath | null>("/walk-path", {
      from_lat: from.lat.toFixed(6),
      from_lng: from.lng.toFixed(6),
      to_lat: to.lat.toFixed(6),
      to_lng: to.lng.toFixed(6),
    })
  ).data;
}

/** Horas de un tramo de autobús, combinando tiempo real y horario. */
export type LegEta = {
  line: string;
  stop: string;
  /** De dónde sale la hora de paso; null si no se encontró ningún autobús. */
  source: "realtime" | "schedule" | null;
  ready_at?: string;
  departs_at?: string;
  departs_in?: number;
  wait_minutes?: number;
  next_day?: boolean;
  /** Autobús que pasa antes de que llegues a la parada. */
  missed_at?: string | null;
  arrives_at?: string;
};

export type JourneyEta = {
  /** false si en algún tramo no hay autobús (ni en tiempo real ni en el horario). */
  complete: boolean;
  now: string;
  arrives_at: string | null;
  arrives_next_day: boolean;
  total_minutes: number | null;
  legs: LegEta[];
};

/** Horas reales del viaje: si llegas a cada autobús y cuánto esperas en el transbordo. */
export async function fetchJourneyEta(journey: Journey): Promise<JourneyEta> {
  const parameters: Record<string, string> = {};
  journey.legs
    .filter((leg): leg is BusLeg => leg.type === "bus")
    .forEach((leg, index) => {
      parameters[`legs[${index}][line]`] = leg.line.id;
      parameters[`legs[${index}][stop]`] = leg.from.code ?? "";
      parameters[`legs[${index}][ride]`] = String(leg.minutes);
    });
  const first = journey.legs[0];
  const last = journey.legs[journey.legs.length - 1];
  parameters["walk_before"] = String(first?.type === "walk" ? first.minutes : 0);
  parameters["walk_after"] = String(
    last?.type === "walk" && journey.legs.length > 1 ? last.minutes : 0,
  );
  return (await get<JourneyEta>("/journeys/eta", parameters)).data;
}

/** Clave estable de un viaje para cachear su cálculo de horas. */
export function journeyKey(journey: Journey) {
  return journey.legs
    .map((leg) => (leg.type === "bus" ? `${leg.line.id}@${leg.from.code}` : `walk${leg.minutes}`))
    .join(">");
}

function get<T>(path: string, parameters?: Record<string, string>) {
  return apiGet<T>(path, parameters);
}
