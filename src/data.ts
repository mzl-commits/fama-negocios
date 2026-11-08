import content from "../public/content/site.json";

export interface Catalog {
  name: string;
  division: string;
  file: string;
  cover?: string;
}
export const site = { ...content, catalogs: content.catalogs as Catalog[] };
export type Division = (typeof site.divisions)[number];
export function whatsapp(
  message = "Hola, quisiera información para una cotización.",
) {
  return `https://wa.me/${site.contact.whatsapp}?text=${encodeURIComponent(message)}`;
}
