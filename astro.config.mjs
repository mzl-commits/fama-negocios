import { defineConfig } from "astro/config";

export default defineConfig({
  site:
    process.env.PUBLIC_SITE_URL ||
    "https://fama-negocios-corporativo.laceycarat.chatgpt.site",
  output: "static",
  trailingSlash: "always",
  devToolbar: { enabled: false },
});
