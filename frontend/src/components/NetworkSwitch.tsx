import { Link } from "@tanstack/react-router";

const NETWORKS = [
  { to: "/", label: "Reus urbà" },
  { to: "/camp-tarragona", label: "Camp de Tarragona" },
] as const;

/** Cambia entre la red urbana de Reus y la interurbana del Camp de Tarragona. */
export default function NetworkSwitch({ current }: { current: "/" | "/camp-tarragona" }) {
  return (
    <nav aria-label="Red de autobuses" className="flex gap-1 text-xs font-semibold">
      {NETWORKS.map((network) => (
        <Link
          key={network.to}
          to={network.to}
          aria-current={current === network.to ? "page" : undefined}
          className={`rounded-full border px-3 py-1 transition-colors ${
            current === network.to
              ? "border-primary text-primary"
              : "border-border text-muted-foreground hover:text-foreground"
          }`}
        >
          {network.label}
        </Link>
      ))}
    </nav>
  );
}
