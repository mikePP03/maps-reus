import {
  apiGet,
  PLANA_API_URL,
  type ApiMapStop,
  type BusLeg,
  type CatalogStop,
  type Journey,
  type JourneyEnd,
  type JourneyPoint,
  type WalkLeg,
} from "@/lib/reus-api";

/** Red interurbana de Plana: horarios oficiales de la Generalitat (GTFS), sin tiempo real. */

export type PlanaLine = {
  id: string;
  /** Código comercial («e5», «N30») o, si no tiene, el oficial («L0592»). */
  name: string;
  description: string;
  operator?: string;
  color: string | null;
  text_color: string | null;
};

export type PlanaLineDetail = PlanaLine & {
  patterns: Array<{
    direction: number | null;
    headsign: string | null;
    stops: Array<{ code: string; name: string; lat: number; lng: number }>;
    geometry: [number, number][];
  }>;
};

export type PlanaDeparture = {
  time: string;
  next_day: boolean;
  /** Días desde hoy: 0 hoy, 1 mañana, 2 o más si no había servicio antes. */
  day_offset: number;
  date: string;
  in_minutes: number;
  line: PlanaLine;
  headsign: string | null;
  /** Aviso oficial: servicio a demanda, recorrido alternativo… */
  note: string | null;
};

export type PlanaFeed = {
  imported_at: string;
  valid_from: string | null;
  valid_to: string | null;
  source: string;
};

type Timed = { departs_at: string; arrives_at: string };

export type PlanaBusLeg = BusLeg &
  Timed & {
    line: PlanaLine;
    headsign: string | null;
    note: string | null;
    stops: Array<JourneyPoint & { time: string }>;
  };

export type PlanaWalkLeg = WalkLeg & Timed;

/** Viaje con horas exactas; compatible con `Journey` para dibujarlo en el mapa. */
export type PlanaJourney = Omit<Journey, "legs"> & {
  legs: Array<PlanaWalkLeg | PlanaBusLeg>;
  departs_at: string;
  arrives_at: string;
  arrives_next_day: boolean;
  /** Minutos hasta tener que salir desde la hora de la consulta. */
  wait_minutes: number;
};

/** Fecha y hora de la consulta; sin ellas, el backend usa «ahora» en hora de Reus. */
export type PlanaWhen = { date: string; time: string } | null;

function whenParameters(when: PlanaWhen): Record<string, string> {
  return when ? { date: when.date, time: when.time } : {};
}

function get<T>(path: string, parameters?: Record<string, string>) {
  return apiGet<T>(path, parameters, PLANA_API_URL);
}

export async function fetchPlanaFeed(): Promise<PlanaFeed | null> {
  return (await get<PlanaFeed | null>("/feed")).data;
}

export async function fetchPlanaLines(): Promise<PlanaLine[]> {
  return (await get<PlanaLine[]>("/lines")).data;
}

export async function fetchPlanaLine(id: string): Promise<PlanaLineDetail> {
  return (await get<PlanaLineDetail>(`/lines/${encodeURIComponent(id)}`)).data;
}

export async function fetchPlanaMapStops(): Promise<ApiMapStop[]> {
  return (await get<ApiMapStop[]>("/map-stops")).data;
}

export async function fetchPlanaCatalog(): Promise<CatalogStop[]> {
  type Row = { external_id: string; name: string };
  return (await get<Row[]>("/stops")).data.map((row) => ({
    code: row.external_id,
    name: row.name,
    hasCoordinates: true,
  }));
}

export async function fetchPlanaDepartures(
  stop: string,
  when: PlanaWhen = null,
): Promise<PlanaDeparture[]> {
  return (
    await get<PlanaDeparture[]>(
      `/stops/${encodeURIComponent(stop)}/departures`,
      whenParameters(when),
    )
  ).data;
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

export type PlanaJourneyResult = {
  journeys: PlanaJourney[];
  /** Día de los resultados (Y-m-d); puede ser posterior al pedido. */
  date: string;
  requestedDate: string;
  /** No había autobuses en las próximas horas: son los siguientes disponibles. */
  nextAvailable: boolean;
  /** Origen o destino sin ninguna parada a menos de 2,5 km a pie (solo si no hay resultados). */
  outOfArea: { origin: boolean; destination: boolean } | null;
};

export async function fetchPlanaJourneys(
  from: JourneyEnd,
  to: JourneyEnd,
  when: PlanaWhen = null,
): Promise<PlanaJourneyResult> {
  const response = await get<PlanaJourney[]>("/journeys", {
    ...endParameters("from", from),
    ...endParameters("to", to),
    ...whenParameters(when),
  });
  return {
    journeys: response.data,
    date: String(response.meta["date"] ?? ""),
    requestedDate: String(response.meta["requested_date"] ?? response.meta["date"] ?? ""),
    nextAvailable: response.meta["next_available"] === true,
    outOfArea: (response.meta["out_of_area"] as PlanaJourneyResult["outOfArea"]) ?? null,
  };
}

/** «2026-10-29» → «jueves 29/10». */
export function formatDay(date: string) {
  const [year, month, day] = date.split("-").map(Number);
  if (!year || !month || !day) return date;
  const weekday = new Date(year, month - 1, day).toLocaleDateString("es-ES", { weekday: "long" });
  return `${weekday} ${String(day).padStart(2, "0")}/${String(month).padStart(2, "0")}`;
}

export type PlanaTimetable = PlanaLine & {
  date: string;
  /** Si ese día no circula, el próximo día con servicio (Y-m-d). */
  next_service_date: string | null;
  directions: Array<{
    direction: number | null;
    /** «Tarragona → Reus»; null si no se pudo deducir (entonces, la parada final). */
    label: string | null;
    headsign: string | null;
    /** Filas: paradas del recorrido más largo del sentido, en orden. */
    stops: Array<{ code: string; name: string }>;
    /** Columnas: cada expedición con su hora en cada parada (null si no para). */
    trips: Array<{
      id: string;
      headsign: string | null;
      note: string | null;
      times: Array<string | null>;
    }>;
  }>;
};

/** Horario completo de una línea para un día (Y-m-d). */
export async function fetchPlanaTimetable(lineId: string, date: string): Promise<PlanaTimetable> {
  return (await get<PlanaTimetable>(`/lines/${encodeURIComponent(lineId)}/timetable`, { date }))
    .data;
}

/**
 * Todas las salidas que quedan en el día para el trayecto (o las del próximo día con servicio),
 * cada una con su itinerario completo.
 */
export async function fetchPlanaDay(
  from: JourneyEnd,
  to: JourneyEnd,
  when: PlanaWhen = null,
): Promise<PlanaJourneyResult> {
  const response = await get<PlanaJourney[]>("/journeys/day", {
    ...endParameters("from", from),
    ...endParameters("to", to),
    ...whenParameters(when),
  });
  return {
    journeys: response.data,
    date: String(response.meta["date"] ?? ""),
    requestedDate: String(response.meta["requested_date"] ?? response.meta["date"] ?? ""),
    nextAvailable: response.meta["next_available"] === true,
    outOfArea: (response.meta["out_of_area"] as PlanaJourneyResult["outOfArea"]) ?? null,
  };
}
