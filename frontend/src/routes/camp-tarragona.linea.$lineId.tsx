import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useCallback } from "react";
import PlanaTimetable from "@/components/PlanaTimetable";

/**
 * Horario de una línea a pantalla completa. Es hija de /camp-tarragona: la página del mapa sigue
 * montada debajo, así que al volver se conserva lo que se estaba consultando.
 */
export const Route = createFileRoute("/camp-tarragona/linea/$lineId")({
  head: () => ({ meta: [{ title: "Horario de la línea | Bus Camp de Tarragona" }] }),
  component: LineTimetablePage,
});

function LineTimetablePage() {
  const { lineId } = Route.useParams();
  const navigate = useNavigate();
  const close = useCallback(() => {
    // Si se llegó desde la app, vuelve atrás (conserva el desplazamiento); si se abrió el
    // enlace directamente, va a la página del Camp de Tarragona.
    if (window.history.length > 1) window.history.back();
    else void navigate({ to: "/camp-tarragona" });
  }, [navigate]);

  return <PlanaTimetable key={lineId} lineId={lineId} onClose={close} />;
}
