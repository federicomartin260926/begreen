// La verificación cliente es preventiva. El backend siempre debe validar el contenido real.
export function validateUploadInput(input, { report = false } = {}) {
  if (!(input instanceof HTMLInputElement) || input.type !== "file") return true;

  const files = Array.from(input.files || []);
  const allowedExtensions = csv(input.dataset.uploadExtensions);
  const allowedMimeTypes = csv(input.dataset.uploadMimeTypes);
  const maxSize = Number(input.dataset.uploadMaxSize || 0);
  const maxFiles = Number(input.dataset.uploadMaxFiles || 0);
  let message = "";

  if (maxFiles > 0 && files.length > maxFiles) {
    message = input.dataset.uploadCountError || uploadMessage("count", maxFiles);
  }
  for (const file of files) {
    if (message) break;
    const extension = file.name.includes(".") ? file.name.split(".").pop().toLowerCase() : "";
    // file.type puede estar vacío o ser inexacto: no sustituye la validación backend.
    if ((allowedExtensions.length && !allowedExtensions.includes(extension))
      || (allowedMimeTypes.length && file.type && !allowedMimeTypes.includes(file.type.toLowerCase()))) {
      message = input.dataset.uploadFormatError || uploadMessage("format");
    } else if (maxSize > 0 && file.size > maxSize) {
      message = input.dataset.uploadSizeError || uploadMessage("size");
    }
  }

  input.setCustomValidity(message);
  input.classList.toggle("is-invalid", message !== "");
  updateInlineFeedback(input, message);
  if (message && report) input.reportValidity();
  return message === "";
}

function uploadMessage(type, count = 0) {
  const es = (document.documentElement.lang || navigator.language || "es").toLowerCase().startsWith("es");
  if (type === "count") return es ? `Máximo ${count} archivos por selección.` : `Maximum ${count} files per selection.`;
  if (type === "size") return es ? "El archivo supera el tamaño máximo permitido." : "File exceeds the maximum permitted size.";
  return es ? "Formato de archivo no admitido." : "File format is not allowed.";
}

// Captura tanto los formularios tradicionales como los gestionados por Stimulus,
// sin intervenir en las peticiones de subidas cuyo flujo ya valida explícitamente.
export function installGlobalUploadValidation() {
  if (document.documentElement.dataset.globalUploadValidation === "1") return;
  document.documentElement.dataset.globalUploadValidation = "1";
  const selector = 'input[type="file"][data-upload-max-size]';

  document.addEventListener("change", (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches(selector)) return;
    validateUploadInput(input, { report: true });
    const form = input.form;
    if (form) validateFormFileCount(form, true);
  }, true);

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    let valid = true;
    form.querySelectorAll(selector).forEach((input) => {
      if (!validateUploadInput(input, { report: false })) valid = false;
    });
    if (!validateFormFileCount(form, false)) valid = false;
    if (!valid) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const invalid = form.querySelector('input[type="file"]:invalid');
      invalid?.reportValidity();
    }
  }, true);
}

function validateFormFileCount(form, report) {
  const inputs = Array.from(form.querySelectorAll('input[type="file"]'));
  const total = inputs.reduce((sum, input) => sum + (input.files?.length || 0), 0);
  const first = inputs.find((input) => input.files?.length) || inputs[0];
  if (!first) return true;
  // PHP-FPM está configurado para aceptar hasta 20 archivos por petición.
  if (total <= 20) {
    if (first.dataset.uploadGlobalCountInvalid === "1") {
      first.dataset.uploadGlobalCountInvalid = "0";
      validateUploadInput(first);
    }
    return true;
  }
  const msg = uploadMessage("count", 20);
  first.dataset.uploadGlobalCountInvalid = "1";
  first.setCustomValidity(msg);
  first.classList.add("is-invalid");
  updateInlineFeedback(first, msg);
  if (report) first.reportValidity();
  return false;
}

function csv(value) {
  return String(value || "")
    .split(",")
    .map((item) => item.trim().toLowerCase())
    .filter(Boolean);
}

function updateInlineFeedback(input, message) {
  let feedback = input.parentElement?.querySelector(":scope > [data-upload-client-error]");
  if (!feedback && message) {
    feedback = document.createElement("div");
    feedback.className = "invalid-feedback";
    feedback.dataset.uploadClientError = "1";
    input.insertAdjacentElement("afterend", feedback);
  }

  if (feedback) {
    feedback.textContent = message;
    feedback.classList.toggle("d-none", message === "");
  }
}
