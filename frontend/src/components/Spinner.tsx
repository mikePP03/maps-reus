/** Indicador giratorio; `label` se anuncia a lectores de pantalla y se muestra al lado. */
export default function Spinner({
  label,
  size = "sm",
  className = "",
}: {
  label?: string;
  size?: "sm" | "md";
  className?: string;
}) {
  const dimension = size === "md" ? "size-5" : "size-3.5";
  return (
    <span role="status" className={`inline-flex items-center gap-2 ${className}`}>
      <svg
        viewBox="0 0 24 24"
        className={`${dimension} shrink-0 animate-spin text-primary`}
        fill="none"
        aria-hidden="true"
      >
        <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
        <path
          d="M21 12a9 9 0 0 0-9-9"
          stroke="currentColor"
          strokeWidth="3"
          strokeLinecap="round"
        />
      </svg>
      {label ? <span>{label}</span> : <span className="sr-only">Cargando…</span>}
    </span>
  );
}
