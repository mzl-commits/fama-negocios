import { site } from "../data";

function readPath(value: unknown, path: string): unknown {
  return path.split(".").reduce<unknown>((current, key) => {
    if (!current || typeof current !== "object") return undefined;
    return (current as Record<string, unknown>)[key];
  }, value);
}

function safeLink(value: string): boolean {
  return value.startsWith("/assets/") || value.startsWith("/uploads/");
}

export function initSite() {
  let content = site;
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  function animateIn(element: Element, delay = 0) {
    if (reducedMotion.matches) return;
    element.animate(
      [
        { opacity: 0.8, transform: "translateY(10px)" },
        { opacity: 1, transform: "translateY(0)" },
      ],
      {
        duration: 950,
        delay,
        fill: "backwards",
        easing: "cubic-bezier(0.22, 0.61, 0.36, 1)",
      },
    );
  }
  document.querySelectorAll(".hero-copy > *").forEach((element, index) => {
    animateIn(element, index * 100);
  });
  const revealItems = Array.from(
    document.querySelectorAll(
      ".division-row, .project-preview, .process-list li",
    ),
  );
  const revealObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        animateIn(entry.target, (revealItems.indexOf(entry.target) % 3) * 100);
        revealObserver.unobserve(entry.target);
      });
    },
    { threshold: 0.12 },
  );
  revealItems.forEach((element) => revealObserver.observe(element));
  reducedMotion.addEventListener("change", () => {
    if (reducedMotion.matches) {
      document.getAnimations().forEach((animation) => animation.cancel());
    }
  });
  const menuButton = document.querySelector<HTMLButtonElement>(".menu-toggle");
  const nav = document.querySelector<HTMLElement>("#main-nav");
  function closeMenu() {
    nav?.classList.remove("is-open");
    menuButton?.setAttribute("aria-expanded", "false");
    menuButton?.setAttribute("aria-label", "Abrir menú");
  }
  menuButton?.addEventListener("click", () => {
    const open = nav?.classList.toggle("is-open") ?? false;
    menuButton.setAttribute("aria-expanded", String(open));
    menuButton.setAttribute("aria-label", open ? "Cerrar menú" : "Abrir menú");
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeMenu();
  });
  document.addEventListener("click", (event) => {
    if (
      event.target instanceof Node &&
      nav &&
      !nav.contains(event.target) &&
      !menuButton?.contains(event.target)
    )
      closeMenu();
  });
  window.matchMedia("(min-width: 801px)").addEventListener("change", closeMenu);

  const filterButtons =
    document.querySelectorAll<HTMLButtonElement>("[data-filter]");
  function filterGallery(filter: string, animate = true) {
    const valid = Array.from(filterButtons).some(
      (button) => button.dataset.filter === filter,
    );
    const current = valid ? filter : "all";
    filterButtons.forEach((button) =>
      button.setAttribute(
        "aria-pressed",
        String(button.dataset.filter === current),
      ),
    );
    let visible = 0;
    document
      .querySelectorAll<HTMLElement>("[data-category]")
      .forEach((item) => {
        item.hidden = current !== "all" && item.dataset.category !== current;
        if (!item.hidden) visible++;
      });
    const status = document.querySelector("#filter-status");
    if (animate) {
      document
        .querySelectorAll(".gallery-item:not([hidden])")
        .forEach((element, index) =>
          animateIn(element, Math.min(index, 3) * 90),
        );
    }
    if (status)
      status.textContent = `${visible} ${visible === 1 ? "elemento" : "elementos"} en la galería`;
  }
  const params = new URLSearchParams(location.search);
  if (filterButtons.length) filterGallery(params.get("division") || "all");
  filterButtons.forEach((button) =>
    button.addEventListener("click", () => {
      const filter = button.dataset.filter || "all";
      const useTransition =
        !reducedMotion.matches && !!document.startViewTransition;
      const update = () => {
        filterGallery(filter, !useTransition);
        const url = new URL(location.href);
        if (filter === "all") url.searchParams.delete("division");
        else url.searchParams.set("division", filter);
        history.replaceState(null, "", url);
      };
      if (useTransition) document.startViewTransition(update);
      else update();
    }),
  );

  const dialog = document.querySelector<HTMLDialogElement>(".image-dialog");
  let closingDialog = false;
  async function closeDialog() {
    if (!dialog?.open || closingDialog) return;
    closingDialog = true;
    if (!reducedMotion.matches) {
      await dialog
        .animate(
          [
            { opacity: 1, transform: "translateY(0)" },
            { opacity: 0, transform: "translateY(6px)" },
          ],
          { duration: 240, easing: "ease-in" },
        )
        .finished.catch(() => {});
    }
    dialog.close();
    closingDialog = false;
  }
  document
    .querySelectorAll<HTMLButtonElement>(".gallery-item")
    .forEach((item) =>
      item.addEventListener("click", () => {
        const image = document.querySelector<HTMLImageElement>("#dialog-image");
        const title = document.querySelector("#dialog-title");
        const description = document.querySelector("#dialog-description");
        const quote =
          document.querySelector<HTMLAnchorElement>("#dialog-quote");
        if (!dialog || !image || !title || !description || !quote) return;
        image.src = item.dataset.image || "";
        image.alt = item.dataset.title || "";
        title.textContent = item.dataset.title || "";
        description.textContent = item.dataset.description || "";
        quote.href = `/contacto/?division=${encodeURIComponent(item.dataset.category || "")}&servicio=${encodeURIComponent(item.dataset.title || "")}`;
        dialog.showModal();
      }),
    );
  dialog
    ?.querySelector(".dialog-close")
    ?.addEventListener("click", closeDialog);
  dialog?.addEventListener("cancel", (event) => {
    event.preventDefault();
    void closeDialog();
  });
  dialog?.addEventListener("click", (event) => {
    if (event.target === dialog) {
      const r = dialog.getBoundingClientRect();
      if (
        event.clientX < r.left ||
        event.clientX > r.right ||
        event.clientY < r.top ||
        event.clientY > r.bottom
      )
        void closeDialog();
    }
  });

  const form = document.querySelector<HTMLFormElement>("#quote-form");
  const divisionSelect = document.querySelector<HTMLSelectElement>("#division");
  const message = document.querySelector<HTMLTextAreaElement>("#message");
  let emailToken = "";
  const emailEnabled =
    document.querySelector<HTMLMetaElement>('meta[name="fama-email-form"]')
      ?.content === "enabled";
  if (form && emailEnabled) {
    fetch("/api/contact.php", { credentials: "same-origin" })
      .then((response) => {
        if (!response.ok) throw new Error("Email unavailable");
        return response.json();
      })
      .then((result) => {
        emailToken = result.token;
        const submit = form.querySelector<HTMLButtonElement>('[type="submit"]');
        const help = form.querySelector(".form-help");
        if (submit) submit.textContent = "Enviar solicitud";
        if (help)
          help.textContent =
            "La solicitud se enviará al correo comercial de FAMA. Tus datos se usarán para atender tu consulta.";
      })
      .catch(() => {
        const status = form.querySelector(".form-status");
        if (status)
          status.textContent =
            "El envío a correo no está disponible. Puedes continuar por WhatsApp.";
      });
  }
  if (divisionSelect && params.get("division")) {
    const value = params.get("division") || "";
    if (
      Array.from(divisionSelect.options).some(
        (option) => option.value === value,
      )
    )
      divisionSelect.value = value;
  }
  if (message && params.get("servicio"))
    message.value = `Me interesa ${params.get("servicio")}. `;
  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const values = new FormData(form);
    if (emailToken) {
      const submit = form.querySelector<HTMLButtonElement>('[type="submit"]');
      const status = form.querySelector<HTMLElement>(".form-status");
      if (submit) {
        submit.disabled = true;
        submit.textContent = "Enviando…";
      }
      try {
        const response = await fetch("/api/contact.php", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            ...Object.fromEntries(values),
            token: emailToken,
          }),
        });
        const result = await response.json();
        if (!response.ok)
          throw new Error(result.error || "No se pudo enviar la solicitud.");
        if (status) {
          status.textContent = result.message;
          status.style.color = "#22593b";
        }
        form.reset();
      } catch (error) {
        if (status) {
          status.textContent =
            error instanceof Error
              ? error.message
              : "No se pudo enviar. Puedes comunicarte por WhatsApp.";
          status.style.color = "";
        }
      } finally {
        if (submit) {
          submit.disabled = false;
          submit.textContent = "Enviar solicitud";
        }
      }
      return;
    }
    const divisionName = divisionSelect?.selectedOptions[0]?.textContent || "";
    const text = [
      `Hola FAMA, quisiera solicitar una cotización.`,
      `Nombre: ${values.get("name")}`,
      `Empresa: ${values.get("company") || "No indicada"}`,
      `Correo: ${values.get("email")}`,
      `Teléfono: ${values.get("phone") || "No indicado"}`,
      `División: ${divisionName}`,
      `Proyecto: ${values.get("message")}`,
    ].join("\n");
    const url = `https://wa.me/${content.contact.whatsapp}?text=${encodeURIComponent(text)}`;
    window.location.assign(url);
  });

  function applyContent() {
    document
      .querySelectorAll<HTMLElement>("[data-content]")
      .forEach((element) => {
        const value = readPath(content, element.dataset.content || "");
        if (typeof value !== "string") return;
        if (element instanceof HTMLImageElement) {
          if (safeLink(value)) element.src = value;
        } else if (
          element.dataset.content !== "home.title" ||
          value !== site.home.title
        )
          element.textContent = value;
      });
    document
      .querySelectorAll<HTMLElement>("[data-contact]")
      .forEach((element) => {
        const key = element.dataset.contact;
        if (!key) return;
        const value = content.contact[key as keyof typeof content.contact];
        if (typeof value !== "string") return;
        element.textContent =
          key === "mobile" && element.closest("footer")
            ? `WhatsApp ${value}`
            : value;
        if (element instanceof HTMLAnchorElement) {
          if (key === "email") element.href = `mailto:${content.contact.email}`;
          if (key === "phone")
            element.href = `tel:${content.contact.phoneHref}`;
          if (key === "mobile")
            element.href = `https://wa.me/${content.contact.whatsapp}`;
        }
      });
    document
      .querySelectorAll<HTMLAnchorElement>('a[href*="wa.me/"]')
      .forEach((link) => {
        const url = new URL(link.href);
        url.pathname = `/${content.contact.whatsapp}`;
        link.href = url.href;
      });
    if (Array.isArray(content.catalogs)) {
      document
        .querySelectorAll<HTMLElement>("[data-catalog-group]")
        .forEach((list) => {
          const division = list.dataset.catalogGroup;
          const catalogs = content.catalogs.filter(
            (catalog) =>
              catalog.division === division && safeLink(catalog.file),
          );
          const original = site.catalogs.filter(
            (catalog) => catalog.division === division,
          );
          if (JSON.stringify(catalogs) === JSON.stringify(original)) return;
          list.replaceChildren(
            ...catalogs.map((catalog) => {
              const card = document.createElement("article");
              card.className = "catalog-card";
              const heading = document.createElement("h3");
              heading.textContent = catalog.name;
              const link = document.createElement("a");
              link.className = "text-link";
              link.href = catalog.file;
              link.target = "_blank";
              link.rel = "noopener noreferrer";
              link.textContent = "Ver PDF ↗";
              const download = document.createElement("a");
              download.className = "text-link";
              download.href = catalog.file;
              download.download = "";
              download.textContent = "Descargar PDF ↓";
              card.append(heading, link, download);
              return card;
            }),
          );
          const empty = list.nextElementSibling;
          if (empty instanceof HTMLElement) empty.hidden = catalogs.length > 0;
        });
    }
  }
  fetch("/content/site.json", { cache: "no-cache" })
    .then((response) => {
      if (!response.ok) throw new Error("Content unavailable");
      return response.json();
    })
    .then((latest) => {
      content = latest;
      applyContent();
    })
    .catch(() => {
      /* The server-rendered content remains available offline. */
    });

  const clarityId = document.querySelector<HTMLMetaElement>(
    'meta[name="fama-clarity-id"]',
  )?.content;
  if (clarityId && /^[a-z0-9]+$/i.test(clarityId)) {
    type ClarityFunction = ((...args: unknown[]) => void) & { q?: unknown[][] };
    const target = window as Window & { clarity?: ClarityFunction };
    target.clarity =
      target.clarity ||
      function (...args: unknown[]) {
        (target.clarity!.q ||= []).push(args);
      };
    const script = document.createElement("script");
    script.async = true;
    script.src = `https://www.clarity.ms/tag/${clarityId}`;
    document.head.append(script);
  }
}
