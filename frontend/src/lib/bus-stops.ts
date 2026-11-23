export type BusStop = {
  id: string;
  name: string;
  code: string;
  lat: number;
  lng: number;
  lines: string[];
};

export const MAP_CENTER: [number, number] = [41.1548, 1.1087];

/** Minúsculas y sin acentos, para que «placa» encuentre «Plaça». */
export function normalizeText(value: string) {
  return value
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .toLowerCase()
    .trim();
}

/** Devuelve el número de línea si la búsqueda es «60», «L60», «línea 60»…; si no, null. */
export function lineFromQuery(query: string) {
  const match = /^(?:linea|l)?\s*(\d+)$/.exec(normalizeText(query));
  return match?.[1] ?? null;
}

/** Distancia en km entre dos coordenadas (Haversine). */
export function distanceKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }) {
  const radius = 6371;
  const dLat = ((b.lat - a.lat) * Math.PI) / 180;
  const dLng = ((b.lng - a.lng) * Math.PI) / 180;
  const latitudeA = (a.lat * Math.PI) / 180;
  const latitudeB = (b.lat * Math.PI) / 180;
  const haversine =
    Math.sin(dLat / 2) ** 2 + Math.sin(dLng / 2) ** 2 * Math.cos(latitudeA) * Math.cos(latitudeB);

  return 2 * radius * Math.asin(Math.sqrt(haversine));
}
