import type { APIRoute } from "astro";
import { site } from "../data";

export const GET: APIRoute = ({ site: origin }) => {
  const paths = [
    "",
    "nosotros/",
    "divisiones/",
    "catalogos/",
    "proyectos/",
    "contacto/",
    ...site.divisions.map((d) => `divisiones/${d.slug}/`),
  ];
  return new Response(
    `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${paths.map((path) => `<url><loc>${new URL(path, origin)}</loc></url>`).join("")}</urlset>`,
    { headers: { "Content-Type": "application/xml; charset=utf-8" } },
  );
};
