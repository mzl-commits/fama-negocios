import { cp, mkdir } from "node:fs/promises";

// Run after Astro builds. PHP files are excluded from the static review site.
await mkdir("dist", { recursive: true });
await cp("server", "dist", { recursive: true });
console.log("Archivos PHP añadidos a dist para el alojamiento de FAMA.");
