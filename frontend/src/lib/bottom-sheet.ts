import { useEffect, useState } from "react";

/**
 * Alturas del panel inferior en móvil: oculto (solo el botón para mostrarlo), media pantalla o
 * casi completa. Se cambia con un botón y no arrastrando: en los móviles, arrastrar hacia abajo
 * activa el «tirar para recargar» del navegador.
 */
export type SheetSnap = "collapsed" | "half" | "full";

/** Altura de la barra con el botón de mostrar/ocultar. */
const COLLAPSED_PX = 56;
/** Margen superior que deja ver un trozo de mapa con el panel completo. */
const FULL_MARGIN_PX = 64;

const DESKTOP_QUERY = "(min-width: 1024px)";

function useIsDesktop() {
  const [isDesktop, setIsDesktop] = useState(false);
  useEffect(() => {
    const media = window.matchMedia(DESKTOP_QUERY);
    const update = () => setIsDesktop(media.matches);
    update();
    media.addEventListener("change", update);
    return () => media.removeEventListener("change", update);
  }, []);
  return isDesktop;
}

function useViewportHeight() {
  const [height, setHeight] = useState(800);
  useEffect(() => {
    const update = () => setHeight(window.innerHeight);
    update();
    window.addEventListener("resize", update);
    return () => window.removeEventListener("resize", update);
  }, []);
  return height;
}

function heightFor(snap: SheetSnap, viewport: number) {
  if (snap === "collapsed") return COLLAPSED_PX;
  if (snap === "half") return Math.round(viewport * 0.5);
  return viewport - FULL_MARGIN_PX;
}

/**
 * Panel inferior para móvil. En escritorio el panel es lateral y `inset` vale 0.
 * `inset` es lo que el panel tapa del mapa, para encuadrar rutas y paradas por encima de él.
 */
export function useBottomSheet(initial: SheetSnap = "half") {
  const isDesktop = useIsDesktop();
  const viewport = useViewportHeight();
  const [snap, setSnap] = useState<SheetSnap>(initial);
  const height = heightFor(snap, viewport);

  return {
    snap,
    setSnap,
    collapsed: snap === "collapsed",
    /** Botón de mostrar/ocultar: oculto ↔ media pantalla. */
    toggle: () => setSnap((current) => (current === "collapsed" ? "half" : "collapsed")),
    /** Muestra el panel si estaba oculto, sin reducirlo si estaba completo. */
    reveal: () => setSnap((current) => (current === "collapsed" ? "half" : current)),
    height,
    isDesktop,
    inset: isDesktop ? 0 : height,
  };
}
