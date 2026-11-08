import type {
  PDFDocumentLoadingTask,
  PDFDocumentProxy,
  RenderTask,
} from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";

export function initCatalogViewer() {
  const dialog = document.querySelector<HTMLDialogElement>(".catalog-viewer");
  if (!dialog) return;
  const title = dialog.querySelector<HTMLElement>("h2")!;
  const paper = dialog.querySelector<HTMLElement>(".catalog-paper")!;
  const canvas = paper.querySelector<HTMLCanvasElement>("canvas")!;
  const status = dialog.querySelector<HTMLElement>("[role=status]")!;
  const pageNumber = dialog.querySelector<HTMLElement>("[data-page-number]")!;
  const download = dialog.querySelector<HTMLAnchorElement>(
    "[data-viewer-download]",
  )!;
  const previous = dialog.querySelector<HTMLButtonElement>("[data-previous]")!;
  const next = dialog.querySelector<HTMLButtonElement>("[data-next]")!;
  const zoomOut = dialog.querySelector<HTMLButtonElement>("[data-zoom-out]")!;
  const zoomIn = dialog.querySelector<HTMLButtonElement>("[data-zoom-in]")!;
  let loading: PDFDocumentLoadingTask | undefined;
  let pdf: PDFDocumentProxy | undefined;
  let rendering: RenderTask | undefined;
  let page = 1;
  let zoom = 1;
  let session = 0;
  let request = 0;

  function updateControls() {
    previous.disabled = !pdf || page <= 1;
    next.disabled = !pdf || page >= pdf.numPages;
    zoomOut.disabled = !pdf || zoom <= 1;
    zoomIn.disabled = !pdf || zoom >= 2;
    pageNumber.textContent = pdf ? `${page} / ${pdf.numPages}` : "";
  }

  async function draw() {
    if (!pdf) return;
    const revision = ++request;
    rendering?.cancel();
    updateControls();
    status.textContent = "Cargando página…";
    try {
      const sheet = await pdf.getPage(page);
      if (revision !== request) return;
      const original = sheet.getViewport({ scale: 1 });
      const width = Math.min(paper.clientWidth - 24, 900) * zoom;
      const viewport = sheet.getViewport({ scale: width / original.width });
      const density = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.ceil(viewport.width * density);
      canvas.height = Math.ceil(viewport.height * density);
      canvas.style.width = `${viewport.width}px`;
      canvas.style.height = `${viewport.height}px`;
      canvas.hidden = false;
      rendering = sheet.render({
        canvas,
        viewport,
        transform: [density, 0, 0, density, 0, 0],
      });
      await rendering.promise;
      if (revision !== request) return;
      canvas.setAttribute("aria-label", `Página ${page} de ${pdf.numPages}`);
      paper.scrollTo(0, 0);
      status.textContent = "";
    } catch {
      if (revision !== request) return;
      canvas.hidden = true;
      status.textContent =
        "No se pudo mostrar esta página. Puedes descargar el PDF.";
    }
  }

  async function open(link: HTMLAnchorElement) {
    const currentSession = ++session;
    title.textContent = link.dataset.catalogPreview || "Vista previa";
    download.href = link.href;
    page = zoom = 1;
    canvas.hidden = true;
    updateControls();
    status.textContent = "Cargando PDF…";
    dialog!.showModal();
    try {
      const library = await import("pdfjs-dist");
      if (currentSession !== session) return;
      library.GlobalWorkerOptions.workerSrc = workerUrl;
      loading = library.getDocument({ url: link.href, useWasm: false });
      const document = await loading.promise;
      if (currentSession !== session) return;
      pdf = document;
      await draw();
    } catch {
      if (currentSession === session)
        status.textContent =
          "No se pudo abrir la vista previa. Puedes descargar el PDF.";
    }
  }

  document.addEventListener("click", (event) => {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey ||
      !(event.target instanceof Element)
    )
      return;
    const link = event.target.closest<HTMLAnchorElement>(
      "a[data-catalog-preview]",
    );
    if (!link || dialog.open) return;
    event.preventDefault();
    void open(link);
  });
  dialog.addEventListener("close", () => {
    session++;
    request++;
    rendering?.cancel();
    rendering = undefined;
    void loading?.destroy().catch(() => {});
    loading = pdf = undefined;
    canvas.width = canvas.height = 0;
  });
  previous.addEventListener("click", () => {
    page--;
    void draw();
  });
  next.addEventListener("click", () => {
    page++;
    void draw();
  });
  zoomOut.addEventListener("click", () => {
    zoom -= 0.25;
    void draw();
  });
  zoomIn.addEventListener("click", () => {
    zoom += 0.25;
    void draw();
  });
  window.addEventListener("resize", () => {
    if (dialog.open) void draw();
  });
}
