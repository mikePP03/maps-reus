const RADII = [0.5, 1, 2] as const;

type Props = {
  active: boolean;
  state: "idle" | "loading" | "error";
  /** Radio del filtro en km; null muestra todas las paradas. */
  radiusKm: number | null;
  onLocate: () => void;
  onRadiusChange: (radius: number | null) => void;
  /** Selector de radio tras localizar (solo tiene sentido si la lista se filtra por cercanía). */
  showRadius?: boolean;
};

/** Botón flotante «mi ubicación» y, una vez localizado, el selector de radio. */
export default function LocateControl({
  active,
  state,
  radiusKm,
  onLocate,
  onRadiusChange,
  showRadius = true,
}: Props) {
  const option = (radius: number | null, label: string) => (
    <button
      key={label}
      type="button"
      onClick={() => onRadiusChange(radius)}
      aria-pressed={radiusKm === radius}
      className={`rounded-full px-2.5 py-1 text-xs font-medium transition-colors ${
        radiusKm === radius
          ? "bg-primary text-primary-foreground"
          : "text-muted-foreground hover:text-foreground"
      }`}
    >
      {label}
    </button>
  );

  return (
    <div className="pointer-events-none absolute right-3 top-3 z-[1000] flex flex-col items-end gap-2">
      <button
        type="button"
        onClick={onLocate}
        disabled={state === "loading"}
        title="Centrar el mapa en mi ubicación"
        aria-label={
          state === "loading"
            ? "Buscando tu ubicación"
            : active
              ? "Recentrar en mi ubicación"
              : "Mi ubicación"
        }
        className="pointer-events-auto flex items-center gap-2 rounded-full bg-primary p-3 text-sm lg:px-4 lg:py-2.5 font-semibold text-primary-foreground shadow-lg ring-2 ring-background/60 transition hover:opacity-90 active:scale-95 disabled:opacity-70"
      >
        {state === "loading" ? (
          // Mientras busca, el icono gira: en móvil el botón no tiene texto.
          <svg
            viewBox="0 0 24 24"
            className="size-5 animate-spin"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="9" strokeOpacity="0.3" />
            <path d="M21 12a9 9 0 0 0-9-9" />
          </svg>
        ) : (
          <svg
            viewBox="0 0 24 24"
            className="size-5"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v3M12 19v3M2 12h3M19 12h3" />
          </svg>
        )}
        {/* En móvil solo el icono, para no tapar el mapa. */}
        <span className="hidden lg:inline">
          {state === "loading" ? "Buscando…" : active ? "Recentrar" : "Mi ubicación"}
        </span>
      </button>

      {active && showRadius && (
        <div
          role="group"
          aria-label="Radio de búsqueda"
          className="pointer-events-auto flex gap-0.5 rounded-full border border-border bg-card p-1 shadow-lg"
        >
          {RADII.map((radius) =>
            option(radius, radius < 1 ? `${radius * 1000} m` : `${radius} km`),
          )}
          {option(null, "Todas")}
        </div>
      )}

      {state === "error" && (
        <p className="pointer-events-auto max-w-56 rounded-md border border-destructive/40 bg-card px-3 py-2 text-xs text-destructive shadow-lg">
          No pudimos obtener tu ubicación. Revisa los permisos del navegador.
        </p>
      )}
    </div>
  );
}
