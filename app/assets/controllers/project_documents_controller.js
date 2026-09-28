import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["list"];

  static values = {
    prototype: String,
    index: Number,
  };

  connect() {
    this.form = this.element.closest("form");
    this.onProjectChanged = this.onProjectChanged.bind(this);
    this.onCollectionChange = this.onCollectionChange.bind(this);

    this.form?.addEventListener("project:changed", this.onProjectChanged);
    this.element.addEventListener("change", this.onCollectionChange);

    this.refreshRows();
  }

  disconnect() {
    this.form?.removeEventListener("project:changed", this.onProjectChanged);
    this.element.removeEventListener("change", this.onCollectionChange);
  }

  addFile(event) {
    event.preventDefault();
    this.addRow("file");
  }

  addLink(event) {
    event.preventDefault();
    this.addRow("link");
  }

  remove(event) {
    event.preventDefault();
    event.currentTarget.closest("[data-project-document-row]")?.remove();
    this.emitChanged();
  }

  onProjectChanged() {
    this.refreshRows();
  }

  onCollectionChange(event) {
    if (event.target.matches('[name$="[type]"]')) {
      this.refreshRow(event.target.closest("[data-project-document-row]"));
    }

    this.emitChanged();
  }

  addRow(kind) {
    if (!this.hasListTarget || !this.hasPrototypeValue) {
      return;
    }

    const index = this.indexValue || 0;
    const html = this.prototypeValue.replaceAll("__document__", String(index));

    const wrapper = document.createElement("div");
    wrapper.innerHTML = html.trim();

    const row = wrapper.firstElementChild;
    if (!row) {
      return;
    }

    const kindControl = row.querySelector('[name$="[kind]"]');
    if (kindControl) {
      kindControl.value = kind;
    }

    row.dataset.projectDocumentKind = kind;
    this.listTarget.appendChild(row);

    this.indexValue = index + 1;
    this.refreshRow(row);
    this.emitChanged();

    const firstControl = row.querySelector("select, input:not([type=hidden])");
    firstControl?.focus();
  }

  refreshRows() {
    this.element.querySelectorAll("[data-project-document-row]")
      .forEach((row) => this.refreshRow(row));
  }

  refreshRow(row) {
    if (!row) {
      return;
    }

    const kindControl = row.querySelector('[name$="[kind]"]');
    const kind = kindControl?.value || row.dataset.projectDocumentKind || "file";
    row.dataset.projectDocumentKind = kind;

    row.querySelector("[data-project-document-file]")
      ?.classList.toggle("d-none", kind !== "file");

    row.querySelector("[data-project-document-link]")
      ?.classList.toggle("d-none", kind !== "link");

    const typeControl = row.querySelector('[name$="[type]"]');
    if (typeControl) {
      const animation = this.isAnimationProject();

      typeControl.querySelectorAll('option[data-animation-only="1"]').forEach((option) => {
        option.hidden = !animation;
        option.disabled = !animation;
      });

      const existingDocument = row.dataset.existingDocument === "1";

      if (
        !animation
        && !existingDocument
        && typeControl.selectedOptions[0]?.dataset.animationOnly === "1"
      ) {
        typeControl.value = "";
      }

      row.querySelector("[data-project-document-other]")
        ?.classList.toggle("d-none", typeControl.value !== "other");
    }
  }

  isAnimationProject() {
    const type = this.form?.querySelector('[data-project-target="type"]')?.value || "";
    const genre = this.form?.querySelector('[data-project-target="filmingGenre"]')?.value || "";

    return type === "rodaje" && genre === "animacion";
  }

  emitChanged() {
    this.form?.dispatchEvent(new CustomEvent("project-documents:changed", {
      bubbles: true,
    }));
  }
}
