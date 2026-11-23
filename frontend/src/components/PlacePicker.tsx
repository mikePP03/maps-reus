import { useEffect, useId, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { normalizeText } from "@/lib/bus-stops";
import { searchPlaces, type CatalogStop, type JourneyEnd } from "@/lib/reus-api";

const MAX_STOPS = 6;

/**
 * Relevancia de una parada para lo escrito (menor es mejor), para que «tarragona» muestre antes
 * «Tarragona (estació d'autobusos)» que las 61 paradas que contienen la palabra:
 * 0 código exacto · 1 empieza por el texto y es estación de autobuses (1,5 otra estación) ·
 * 2 empieza por el texto ·
 * 3 una palabra empieza por el texto · 4 lo contiene en otra posición · Infinity no coincide.
 */
function stopRank(name: string, query: string, exactCode: boolean) {
  if (exactCode) return 0;
  if (name.startsWith(query)) {
    // Primero la estación de autobuses; después otras estaciones (la del tren del Camp).
    return name.includes("autobus") ? 1 : name.includes("estacio") ? 1.5 : 2;
  }
  // ¿Aparece al principio de alguna palabra? (precedido de algo que no sea letra o número)
  for (let at = name.indexOf(query); at > 0; at = name.indexOf(query, at + 1)) {
    if (!/[a-z0-9]/.test(name[at - 1]!)) return 3;
  }
  return name.includes(query) ? 4 : Infinity;
}

const DEBOUNCE_MS = 400;

type Option =
  | { type: "location" }
  | { type: "stop"; stop: CatalogStop }
  | { type: "place"; name: string; label: string; lat: number; lng: number };

type Props = {
  label: string;
  stops: CatalogStop[];
  value: JourneyEnd | null;
  onChange: (end: JourneyEnd | null) => void;
  /**
   * Si se pasa, ofrece «Mi ubicación» como primera opción y la delega en el padre, que muestra
   * el progreso (obtener la ubicación puede tardar varios segundos en el móvil).
   */
  onRequestLocation?: () => void;
  /** Texto mientras el padre trabaja en este campo (por ejemplo, obteniendo la ubicación). */
  busyText?: string | undefined;
  /** Dónde buscar lugares: alrededores de Reus (por defecto) o toda Cataluña. */
  area?: "reus" | "catalunya";
};

function useDebounced(value: string) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [value]);
  return debounced;
}

/** Selector de origen o destino: tu ubicación, una parada o un lugar de Reus. */
export default function PlacePicker({
  label,
  stops,
  value,
  onChange,
  onRequestLocation,
  busyText,
  area = "reus",
}: Props) {
  const allowLocation = onRequestLocation !== undefined;
  const id = useId();
  const [text, setText] = useState("");
  const [open, setOpen] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const query = useDebounced(text.trim());

  const places = useQuery({
    queryKey: ["reus", "places", area, normalizeText(query)],
    queryFn: () => searchPlaces(query, area),
    enabled: open && query.length >= 3,
    staleTime: Infinity,
    retry: false,
  });

  const options = useMemo<Option[]>(() => {
    const q = normalizeText(text);
    const matchingStops = q
      ? stops
          .map((stop) => ({ stop, rank: stopRank(normalizeText(stop.name), q, stop.code === q) }))
          .filter((match) => match.rank < Infinity)
          .sort((a, b) => a.rank - b.rank || a.stop.name.length - b.stop.name.length)
          .slice(0, MAX_STOPS)
          .map((match) => match.stop)
      : [];
    return [
      ...(allowLocation && !q ? [{ type: "location" } as const] : []),
      ...matchingStops.map((stop) => ({ type: "stop", stop }) as const),
      ...(q.length >= 3
        ? (places.data ?? []).map((place) => ({ type: "place", ...place }) as const)
        : []),
    ];
  }, [text, stops, places.data, allowLocation]);

  const choose = (option: Option) => {
    setText("");
    setOpen(false);
    if (option.type === "stop") {
      onChange({ kind: "stop", code: option.stop.code, name: option.stop.name });
    } else if (option.type === "place") {
      onChange({ kind: "place", name: option.name, lat: option.lat, lng: option.lng });
    } else {
      onRequestLocation?.();
    }
  };

  const display = busyText
    ? busyText
    : value
      ? value.kind === "stop"
        ? `${value.name} · parada ${value.code}`
        : value.name
      : "";
  const searching = query.length >= 3 && places.isFetching;

  return (
    <div className="relative">
      <label htmlFor={id} className="block text-xs font-medium">
        {label}
      </label>
      <div className="mt-1 flex gap-1">
        <input
          id={id}
          role="combobox"
          aria-expanded={open}
          aria-controls={`${id}-list`}
          aria-autocomplete="list"
          autoComplete="off"
          value={open ? text : display}
          placeholder={
            allowLocation ? "Mi ubicación, parada o lugar" : "Parada o lugar (ej. Agro Reus)"
          }
          onFocus={() => {
            setOpen(true);
            setHighlighted(0);
          }}
          onBlur={() => setOpen(false)}
          onChange={(event) => {
            setText(event.target.value);
            setHighlighted(0);
            setOpen(true);
          }}
          onKeyDown={(event) => {
            if (event.key === "ArrowDown") {
              event.preventDefault();
              setHighlighted((index) => Math.min(index + 1, options.length - 1));
            } else if (event.key === "ArrowUp") {
              event.preventDefault();
              setHighlighted((index) => Math.max(index - 1, 0));
            } else if (event.key === "Enter" && open && options[highlighted]) {
              event.preventDefault();
              choose(options[highlighted]);
            } else if (event.key === "Escape") {
              setOpen(false);
            }
          }}
          className="w-full rounded-md border border-input bg-background px-2 py-2 text-sm placeholder:text-muted-foreground focus:border-ring focus:outline-none"
        />
        {value && (
          <button
            type="button"
            onClick={() => onChange(null)}
            aria-label={`Quitar ${label.toLowerCase()}`}
            className="rounded-md border border-input bg-background px-2 text-sm text-muted-foreground hover:text-foreground"
          >
            ×
          </button>
        )}
      </div>
      {open && (
        <ul
          id={`${id}-list`}
          role="listbox"
          className="ui-pop absolute z-[1000] mt-1 max-h-72 w-full overflow-y-auto rounded-md border border-border bg-card py-1 text-sm shadow-lg"
        >
          {options.length === 0 && (
            <li className="px-3 py-2 text-xs text-muted-foreground">
              {text.trim().length < 3
                ? "Escribe al menos 3 letras para buscar lugares."
                : searching
                  ? "Buscando lugares…"
                  : places.isError
                    ? places.error.message
                    : "No encontramos ninguna parada ni lugar."}
            </li>
          )}
          {options.map((option, index) => (
            <li
              key={
                option.type === "location"
                  ? "location"
                  : option.type === "stop"
                    ? `stop-${option.stop.code}`
                    : `place-${option.name}-${option.lat}`
              }
              role="option"
              aria-selected={index === highlighted}
              // mousedown en lugar de click para que el blur del input no cierre la lista antes.
              onMouseDown={(event) => {
                event.preventDefault();
                choose(option);
              }}
              onMouseEnter={() => setHighlighted(index)}
              className={`flex cursor-pointer items-baseline gap-2 px-3 py-1.5 ${index === highlighted ? "bg-secondary" : ""}`}
            >
              <span className="w-12 shrink-0 text-[0.65rem] font-semibold uppercase text-primary">
                {option.type === "location" ? "GPS" : option.type === "stop" ? "Parada" : "Lugar"}
              </span>
              <span className="min-w-0">
                {option.type === "location" ? (
                  "Mi ubicación"
                ) : option.type === "stop" ? (
                  <>
                    {option.stop.name}{" "}
                    <span className="text-xs text-muted-foreground">· {option.stop.code}</span>
                  </>
                ) : (
                  <>
                    {option.name}
                    {option.label && (
                      <span className="block truncate text-xs text-muted-foreground">
                        {option.label}
                      </span>
                    )}
                  </>
                )}
              </span>
            </li>
          ))}
          {options.length > 0 && searching && (
            <li className="px-3 py-1.5 text-xs text-muted-foreground">Buscando lugares…</li>
          )}
        </ul>
      )}
    </div>
  );
}
