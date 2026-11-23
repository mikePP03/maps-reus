import { createFileRoute, redirect } from "@tanstack/react-router";

/** Dirección antigua: la página de Plana pasó a ser la del Camp de Tarragona. */
export const Route = createFileRoute("/plana")({
  beforeLoad: () => {
    throw redirect({ to: "/camp-tarragona", replace: true });
  },
});
