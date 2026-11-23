import { useState, type CSSProperties, type ReactNode } from "react";
import { useNavigate } from "@tanstack/react-router";

type Network = "/" | "/camp-tarragona";

const EXIT_MS = 550;

/**
 * Pantalla de bienvenida: el usuario elige qué red quiere ver (Reus urbà o Camp de Tarragona).
 * El mapa ya se está cargando debajo, así que al elegir aparece al momento.
 */
export default function WelcomeScreen({
  current,
  onDone,
}: {
  current: Network;
  onDone: () => void;
}) {
  const navigate = useNavigate();
  const [chosen, setChosen] = useState<Network | null>(null);

  const choose = (network: Network) => {
    if (chosen) return;
    setChosen(network);
    if (network !== current) void navigate({ to: network });
    setTimeout(onDone, EXIT_MS);
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="welcome-title"
      className={`welcome-anim fixed inset-0 z-[3000] flex flex-col items-center justify-center overflow-hidden bg-background px-4 transition-opacity duration-500 ${chosen ? "pointer-events-none opacity-0" : "opacity-100"}`}
    >
      {/* Fondo: rutas de autobús que se dibujan solas. */}
      <svg
        aria-hidden="true"
        viewBox="0 0 400 800"
        preserveAspectRatio="xMidYMid slice"
        className="absolute inset-0 h-full w-full opacity-25"
      >
        <path
          className="welcome-route"
          d="M-20 140 C 80 120, 120 260, 220 240 S 380 120, 430 200"
          fill="none"
          stroke="var(--primary)"
          strokeWidth="3"
          strokeLinecap="round"
        />
        <path
          className="welcome-route welcome-route-2"
          d="M-20 620 C 90 660, 160 520, 250 560 S 360 700, 430 640"
          fill="none"
          stroke="#38bdf8"
          strokeWidth="3"
          strokeLinecap="round"
        />
      </svg>

      <div className="relative text-center">
        <p className="welcome-fade text-xs font-semibold uppercase tracking-[0.3em] text-primary">
          Bus
        </p>
        <h1
          id="welcome-title"
          className="welcome-fade mt-2 text-2xl font-semibold tracking-tight sm:text-3xl"
          style={{ animationDelay: "80ms" }}
        >
          ¿Qué rutas quieres ver?
        </h1>
      </div>

      <div className="relative mt-10 flex flex-wrap items-start justify-center gap-8 sm:gap-14">
        <Choice
          label="Reus urbà"
          detail="Autobuses de la ciudad, en tiempo real"
          delay={200}
          ring="var(--primary)"
          state={chosen === null ? "idle" : chosen === "/" ? "chosen" : "other"}
          onClick={() => choose("/")}
        >
          <ReusArt />
        </Choice>
        <Choice
          label="Camp de Tarragona"
          detail="Interurbanos: Tarragona, Salou, Cambrils…"
          delay={350}
          ring="#38bdf8"
          state={chosen === null ? "idle" : chosen === "/camp-tarragona" ? "chosen" : "other"}
          onClick={() => choose("/camp-tarragona")}
        >
          <CampArt />
        </Choice>
      </div>
    </div>
  );
}

function Choice({
  label,
  detail,
  delay,
  ring,
  state,
  onClick,
  children,
}: {
  label: string;
  detail: string;
  delay: number;
  ring: string;
  state: "idle" | "chosen" | "other";
  onClick: () => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="welcome-pop group flex w-36 flex-col items-center text-center focus:outline-none sm:w-48"
      style={{ animationDelay: `${delay}ms` }}
    >
      <span
        className={`welcome-float relative block size-36 transition-transform duration-500 ease-out sm:size-48 ${
          state === "chosen" ? "scale-110" : state === "other" ? "scale-90 opacity-40" : ""
        }`}
        style={{ animationDelay: `${delay + 600}ms` }}
      >
        {/* Anillo discontinuo que gira, como el recorrido de una línea. */}
        <svg aria-hidden="true" viewBox="0 0 100 100" className="welcome-spin absolute -inset-2.5">
          <circle
            cx="50"
            cy="50"
            r="48"
            fill="none"
            stroke={ring}
            strokeWidth="1.2"
            strokeDasharray="6 5"
            strokeLinecap="round"
          />
        </svg>
        <span
          className="absolute inset-0 overflow-hidden rounded-full shadow-[0_10px_40px_rgba(0,0,0,0.5)] ring-2 transition-[box-shadow,transform] duration-300 group-hover:scale-105 group-focus-visible:ring-4"
          style={{ "--tw-ring-color": ring } as CSSProperties}
        >
          {children}
        </span>
      </span>
      <span className="mt-5 text-base font-semibold sm:text-lg">{label}</span>
      <span className="mt-1 text-xs text-muted-foreground">{detail}</span>
    </button>
  );
}

/** Reus: el campanario de Sant Pere al atardecer y un bus urbano. */
function ReusArt() {
  return (
    <svg viewBox="0 0 200 200" className="h-full w-full" aria-hidden="true">
      <defs>
        <linearGradient id="reus-sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#f59e5b" />
          <stop offset="0.55" stopColor="#f7c46c" />
          <stop offset="1" stopColor="#fde3a7" />
        </linearGradient>
      </defs>
      <rect width="200" height="200" fill="url(#reus-sky)" />
      <circle className="welcome-glow" cx="142" cy="70" r="22" fill="#fff4d6" opacity="0.9" />
      {/* Edificios del fondo */}
      <g fill="#b5603f" opacity="0.55">
        <rect x="0" y="112" width="34" height="60" />
        <rect x="28" y="98" width="26" height="74" />
        <rect x="128" y="104" width="30" height="68" />
        <rect x="154" y="92" width="46" height="80" />
      </g>
      {/* Campanario de Sant Pere */}
      <g fill="#6b2f35">
        <rect x="84" y="58" width="32" height="114" />
        <rect x="88" y="40" width="24" height="20" />
        <path d="M88 40 L100 18 L112 40 Z" />
        <rect x="99" y="10" width="2" height="10" />
        <rect x="96" y="12" width="8" height="2" />
      </g>
      <g fill="#f7c46c">
        <path d="M95 50 a5 5 0 0 1 10 0 v8 h-10 z" />
        <path d="M93 78 a7 7 0 0 1 14 0 v14 h-14 z" />
        <rect x="96" y="108" width="8" height="12" rx="4" />
      </g>
      {/* Edificios delanteros con ventanas */}
      <g fill="#8a3b3a">
        <rect x="10" y="128" width="44" height="44" />
        <rect x="56" y="118" width="26" height="54" />
        <rect x="118" y="122" width="34" height="50" />
        <rect x="154" y="134" width="40" height="38" />
      </g>
      <g fill="#ffd98a" opacity="0.85">
        <rect x="18" y="136" width="6" height="8" />
        <rect x="32" y="136" width="6" height="8" />
        <rect x="18" y="152" width="6" height="8" />
        <rect x="62" y="126" width="5" height="8" />
        <rect x="72" y="140" width="5" height="8" />
        <rect x="126" y="130" width="6" height="8" />
        <rect x="140" y="146" width="6" height="8" />
        <rect x="162" y="142" width="6" height="8" />
        <rect x="178" y="142" width="6" height="8" />
      </g>
      {/* Calle y bus */}
      <rect y="172" width="200" height="28" fill="#3b2a2f" />
      <line
        className="welcome-road"
        x1="0"
        y1="186"
        x2="240"
        y2="186"
        stroke="#f7c46c"
        strokeWidth="2"
        strokeDasharray="12 8"
        opacity="0.6"
      />
      <g className="welcome-bus">
        <rect x="0" y="160" width="46" height="20" rx="4" fill="#f0b13b" />
        <rect x="4" y="164" width="8" height="7" rx="1" fill="#3b2a2f" />
        <rect x="14" y="164" width="8" height="7" rx="1" fill="#3b2a2f" />
        <rect x="24" y="164" width="8" height="7" rx="1" fill="#3b2a2f" />
        <rect x="34" y="164" width="9" height="9" rx="1" fill="#3b2a2f" />
        <circle cx="11" cy="181" r="4" fill="#1f1418" />
        <circle cx="35" cy="181" r="4" fill="#1f1418" />
      </g>
    </svg>
  );
}

/** Camp de Tarragona: el anfiteatro romano junto al mar y un bus interurbano por la costa. */
function CampArt() {
  return (
    <svg viewBox="0 0 200 200" className="h-full w-full" aria-hidden="true">
      <defs>
        <linearGradient id="camp-sky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#38bdf8" />
          <stop offset="1" stopColor="#bae6fd" />
        </linearGradient>
        <linearGradient id="camp-sea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#0284c7" />
          <stop offset="1" stopColor="#075985" />
        </linearGradient>
      </defs>
      <rect width="200" height="200" fill="url(#camp-sky)" />
      <circle className="welcome-glow" cx="48" cy="46" r="16" fill="#fef9c3" />
      {/* Montañas */}
      <path d="M0 108 L40 70 L72 96 L110 58 L150 92 L200 64 L200 120 L0 120 Z" fill="#5b8c6a" />
      <path d="M0 114 L30 94 L64 110 L100 86 L140 106 L200 90 L200 124 L0 124 Z" fill="#3f6f52" />
      {/* Mar con olas */}
      <rect y="122" width="200" height="78" fill="url(#camp-sea)" />
      <g className="welcome-waves" fill="none" stroke="#e0f2fe" strokeWidth="1.6" opacity="0.7">
        <path d="M-40 150 q10 -5 20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0" />
        <path d="M-30 176 q10 -5 20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0" />
      </g>
      {/* Anfiteatro romano */}
      <g>
        <path d="M70 126 Q 130 92 190 126 Z" fill="#e7c9a0" />
        <path d="M76 126 Q 130 100 184 126" fill="none" stroke="#b98f62" strokeWidth="2" />
        <g fill="#9a7048">
          <path d="M86 126 v-6 a3 3 0 0 1 6 0 v6 z" />
          <path d="M100 126 v-9 a3 3 0 0 1 6 0 v9 z" />
          <path d="M114 126 v-11 a3 3 0 0 1 6 0 v11 z" />
          <path d="M128 126 v-12 a3 3 0 0 1 6 0 v12 z" />
          <path d="M142 126 v-11 a3 3 0 0 1 6 0 v11 z" />
          <path d="M156 126 v-9 a3 3 0 0 1 6 0 v9 z" />
          <path d="M170 126 v-6 a3 3 0 0 1 6 0 v6 z" />
        </g>
      </g>
      {/* Palmera */}
      <g>
        <path d="M34 126 q4 -20 2 -38" fill="none" stroke="#7c5a3a" strokeWidth="3" />
        <g className="welcome-palm" fill="#2f7d4f">
          <path d="M36 88 q-14 -6 -22 4 q12 -2 22 -4 z" />
          <path d="M36 88 q14 -8 24 2 q-12 -4 -24 -2 z" />
          <path d="M36 88 q-4 -14 -16 -14 q10 6 16 14 z" />
          <path d="M36 88 q6 -14 18 -12 q-12 4 -18 12 z" />
        </g>
      </g>
      {/* Carretera de la costa y bus interurbano */}
      <rect y="126" width="200" height="10" fill="#475569" />
      <g className="welcome-bus welcome-bus-slow">
        <rect x="0" y="112" width="50" height="16" rx="3" fill="#f8fafc" />
        <rect x="0" y="120" width="50" height="3" fill="#0284c7" />
        <rect x="4" y="115" width="9" height="5" rx="1" fill="#1e293b" />
        <rect x="15" y="115" width="9" height="5" rx="1" fill="#1e293b" />
        <rect x="26" y="115" width="9" height="5" rx="1" fill="#1e293b" />
        <rect x="38" y="114" width="9" height="8" rx="1" fill="#1e293b" />
        <circle cx="11" cy="129" r="3.5" fill="#0f172a" />
        <circle cx="39" cy="129" r="3.5" fill="#0f172a" />
      </g>
    </svg>
  );
}
