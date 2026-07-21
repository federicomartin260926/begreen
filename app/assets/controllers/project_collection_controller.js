import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["list", "addButton", "total", "feedback", "percentage"];

  static values = {
    prototype: String,
    index: Number,
    kind: String,
  };

  connect() {
    if (!this.hasIndexValue) {
      this.indexValue = this.listTarget.querySelectorAll("[data-collection-id]").length;
    }

    this.onCollectionChange = this.onCollectionChange.bind(this);
    this.listTarget.addEventListener("input", this.onCollectionChange);
    this.listTarget.addEventListener("change", this.onCollectionChange);

    this.toggleAddButton();
    this.updateFundingSummary();
    this.emitChanged();
  }

  disconnect() {
    this.listTarget.removeEventListener("input", this.onCollectionChange);
    this.listTarget.removeEventListener("change", this.onCollectionChange);
  }

  addItem(event) {
    event.preventDefault();

    if (!this.hasPrototypeValue) {
      return;
    }

    const html = this.prototypeValue.replace(/__name__|__company__|__funding__/g, this.indexValue);
    const wrapper = document.createElement("div");
    wrapper.innerHTML = html.trim();

    const item = wrapper.firstElementChild;
    if (!item) {
      return;
    }

    item.setAttribute("data-collection-id", String(this.indexValue));
    this.indexValue += 1;
    this.listTarget.appendChild(item);
    this.toggleAddButton();
    this.updateFundingSummary();
    this.emitChanged();
  }

  removeItem(event) {
    event.preventDefault();

    const item = event.currentTarget.closest("[data-collection-id]");
    if (!item) {
      return;
    }

    item.remove();
    this.toggleAddButton();
    this.updateFundingSummary();
    this.emitChanged();
  }

  onCollectionChange() {
    this.updateFundingSummary();
    this.emitChanged();
  }

  validateLogoFile(event) {
    const input = event.currentTarget;
    const file = input.files?.[0];
    input.setCustomValidity("");

    if (!file) {
      return;
    }

    const allowedTypes = ["image/png", "image/jpeg", "image/webp"];
    const allowedExtensions = ["png", "jpg", "jpeg", "webp"];
    const extension = file.name.split(".").pop()?.toLowerCase();
    if (!allowedTypes.includes(file.type) || !allowedExtensions.includes(extension)) {
      input.setCustomValidity(input.dataset.logoFormatError || "Invalid image format.");
      input.reportValidity();
      return;
    }

    if (file.size > Number(input.dataset.logoMaxSize || 2097152)) {
      input.setCustomValidity(input.dataset.logoSizeError || "File is too large.");
      input.reportValidity();
      return;
    }

    const item = input.closest("[data-collection-id]");
    if (item) {
      this.updateLogoRemovalState(item, false);
    }
  }

  toggleLogoRemoval(event) {
    event.preventDefault();
    const item = event.currentTarget.closest("[data-collection-id]");
    const field = item?.querySelector("[data-logo-remove-field]");
    if (!item || !field) {
      return;
    }

    this.updateLogoRemovalState(item, !field.checked);
    field.dispatchEvent(new Event("change", { bubbles: true }));
  }

  updateLogoRemovalState(item, active) {
    const field = item.querySelector("[data-logo-remove-field]");
    const button = item.querySelector("[data-logo-remove-button]");
    const preview = item.querySelector("[data-logo-preview]");
    if (!field || !button) {
      return;
    }

    field.checked = active;
    button.textContent = active ? button.dataset.undoLabel : button.dataset.removeLabel;
    button.setAttribute("aria-pressed", active ? "true" : "false");
    button.classList.toggle("text-danger", !active);
    button.classList.toggle("text-secondary", active);
    preview?.classList.toggle("opacity-50", active);
  }

  updateFundingSummary() {
    if (this.kindValue !== "funding") {
      return;
    }

    let total = 0;
    let hasInvalid = false;

    this.percentageTargets.forEach((input) => {
      const cents = this.parseHundredths(input.value);
      if (cents === null) {
        hasInvalid = input.value.trim() !== "";
        return;
      }
      total += cents;
    });

    if (this.hasTotalTarget) {
      this.totalTarget.textContent = `${(total / 100).toFixed(2)}%`;
    }

    if (!this.hasFeedbackTarget) {
      return;
    }

    if (hasInvalid) {
      this.feedbackTarget.textContent = this.feedbackTarget.dataset.invalidLabel || "Revisa los porcentajes.";
      this.feedbackTarget.className = "small text-warning";
      return;
    }

    if (total === 10000) {
      this.feedbackTarget.textContent = this.feedbackTarget.dataset.okLabel || "La financiación suma 100%.";
      this.feedbackTarget.className = "small text-success";
      return;
    }

    const diff = 10000 - total;
    const formattedDiff = `${(Math.abs(diff) / 100).toFixed(2)}%`;
    this.feedbackTarget.textContent = diff > 0
      ? `${this.feedbackTarget.dataset.missingPrefix || "Faltan"} ${formattedDiff}.`
      : `${this.feedbackTarget.dataset.excessPrefix || "Sobran"} ${formattedDiff}.`;
    this.feedbackTarget.className = diff > 0 ? "small text-warning" : "small text-danger";
  }

  toggleAddButton() {
    if (!this.hasAddButtonTarget) {
      return;
    }

    this.addButtonTarget.classList.toggle("d-none", false);
  }

  emitChanged() {
    this.element.dispatchEvent(
      new CustomEvent("project-collection:changed", {
        bubbles: true,
      })
    );
  }

  parseHundredths(value) {
    const normalized = String(value ?? "").trim().replace(",", ".");
    if (normalized === "") {
      return 0;
    }

    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
      return null;
    }

    const [integer, decimal = ""] = normalized.split(".");
    return (parseInt(integer, 10) * 100) + parseInt((decimal + "00").slice(0, 2), 10);
  }
}
