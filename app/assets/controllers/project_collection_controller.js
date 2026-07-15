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

    this.toggleAddButton();
    this.updateFundingSummary();
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
