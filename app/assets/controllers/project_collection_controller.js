import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["list", "addButton", "total", "feedback", "percentage", "companyModal", "companyModalHost", "companyModalFeedback"];

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
    this.form = this.element.closest("form");
    if (this.kindValue === "funding") {
      this.form?.addEventListener("project-collection:changed", this.onCollectionChange);
      if (this.hasCompanyModalTarget) {
        this.onCompanyModalHidden = () => this.discardPendingCompany();
        this.onCompanyModalEdit = () => this.clearCompanyModalFeedback();
        this.companyModalTarget.addEventListener("hidden.bs.modal", this.onCompanyModalHidden);
        this.companyModalTarget.addEventListener("input", this.onCompanyModalEdit);
        this.companyModalTarget.addEventListener("change", this.onCompanyModalEdit);
      }
    }

    this.toggleAddButton();
    this.updateFundingSummary();
    this.synchronizeFundingCompanyOptions();
    this.emitChanged();
  }

  disconnect() {
    this.listTarget.removeEventListener("input", this.onCollectionChange);
    this.listTarget.removeEventListener("change", this.onCollectionChange);
    this.form?.removeEventListener("project-collection:changed", this.onCollectionChange);
    if (this.hasCompanyModalTarget && this.onCompanyModalHidden) {
      this.companyModalTarget.removeEventListener("hidden.bs.modal", this.onCompanyModalHidden);
      this.companyModalTarget.removeEventListener("input", this.onCompanyModalEdit);
      this.companyModalTarget.removeEventListener("change", this.onCompanyModalEdit);
    }
  }

  addItem(event) {
    event.preventDefault();

    if (!this.hasPrototypeValue) {
      return;
    }

    const item = this.createItem();
    if (!item) {
      return;
    }

    this.appendItem(item);
  }

  createItem() {
    if (!this.hasPrototypeValue) {
      return null;
    }

    const html = this.prototypeValue.replace(/__name__|__company__|__funding__/g, this.indexValue);
    const wrapper = document.createElement("div");
    wrapper.innerHTML = html.trim();

    const item = wrapper.firstElementChild;
    if (!item) {
      return null;
    }

    item.setAttribute("data-collection-id", String(this.indexValue));
    this.indexValue += 1;

    return item;
  }

  appendItem(item) {
    this.listTarget.appendChild(item);
    this.toggleAddButton();
    this.updateFundingSummary();
    this.synchronizeFundingCompanyOptions();
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
    this.synchronizeFundingCompanyOptions();
    this.emitChanged();
  }

  onCollectionChange(event) {
    if (event?.type === "project-collection:changed" && event.target === this.element) {
      return;
    }

    if (event?.target?.matches?.("[data-funding-company-select]")) {
      this.synchronizeFundingRow(event.target.closest("[data-collection-id]"));
    }

    this.updateFundingSummary();
    this.synchronizeFundingCompanyOptions();
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

  synchronizeFundingCompanyOptions() {
    if (this.kindValue !== "funding") {
      return;
    }

    const companies = this.companyRows();
    this.listTarget.querySelectorAll("[data-funding-company-select]").forEach((select) => {
      const row = select.closest("[data-collection-id]");
      const nameInput = row?.querySelector('[name$="[name]"]');
      const previousValue = select.value;
      const previousCompanyExists = companies.some((company) => company.id === previousValue);
      let selectedValue = previousCompanyExists ? previousValue : "";

      if (select.dataset.initialized !== "1" || previousValue.startsWith("legacy:")) {
        const normalizedName = this.normalizeName(nameInput?.value);
        const matchingCompany = companies.find((company) => company.normalizedName === normalizedName);
        if (matchingCompany) {
          selectedValue = matchingCompany.id;
        } else if (normalizedName !== "") {
          selectedValue = `legacy:${row?.dataset.collectionId || "source"}`;
        }
      }

      select.replaceChildren(new Option(select.dataset.placeholder || "—", ""));
      companies.forEach((company) => {
        select.add(new Option(`${company.name} · ${company.typeLabel}`, company.id));
      });

      if (selectedValue.startsWith("legacy:")) {
        const legacyName = String(nameInput?.value || "").trim();
        const option = new Option(
          `${legacyName} · ${select.dataset.unmatchedLabel || "Not in companies"}`,
          selectedValue,
        );
        option.dataset.temporary = "1";
        select.add(option);
      }

      select.value = selectedValue;
      select.dataset.initialized = "1";
      this.synchronizeFundingRow(row, companies);
    });

    this.updateFundingCompanyAvailability();
  }

  synchronizeFundingRow(row, companies = this.companyRows()) {
    if (!row) {
      return;
    }

    const companySelect = row.querySelector("[data-funding-company-select]");
    const nameInput = row.querySelector('[name$="[name]"]');
    const typeSelect = row.querySelector("[data-funding-type-select]");
    const selectedCompany = companies.find((company) => company.id === companySelect?.value);
    const isTemporary = companySelect?.selectedOptions?.[0]?.dataset.temporary === "1";

    if (selectedCompany) {
      nameInput.value = selectedCompany.name;
      typeSelect.value = selectedCompany.type;
      this.lockFundingType(row, typeSelect, selectedCompany.type);
    } else if (!isTemporary) {
      nameInput.value = "";
      this.unlockFundingType(row, typeSelect);
    }
  }

  updateFundingCompanyAvailability() {
    const selects = Array.from(this.listTarget.querySelectorAll("[data-funding-company-select]"));
    const used = new Set(selects.map((select) => select.value).filter((value) => value && !value.startsWith("legacy:")));

    selects.forEach((select) => {
      Array.from(select.options).forEach((option) => {
        if (!option.value || option.value.startsWith("legacy:")) {
          return;
        }
        option.disabled = used.has(option.value) && option.value !== select.value;
      });
    });
  }

  lockFundingType(row, typeSelect, type) {
    if (!typeSelect) {
      return;
    }

    typeSelect.value = type;
    typeSelect.disabled = true;
    typeSelect.setAttribute("aria-disabled", "true");

    let mirror = row.querySelector("[data-funding-type-mirror]");
    if (!mirror) {
      mirror = document.createElement("input");
      mirror.type = "hidden";
      mirror.name = typeSelect.name;
      mirror.dataset.fundingTypeMirror = "1";
      typeSelect.insertAdjacentElement("afterend", mirror);
    }
    mirror.value = type;
  }

  unlockFundingType(row, typeSelect) {
    if (!typeSelect) {
      return;
    }

    typeSelect.disabled = false;
    typeSelect.removeAttribute("aria-disabled");
    row.querySelector("[data-funding-type-mirror]")?.remove();
  }

  companyRows() {
    const root = this.form?.querySelector('[data-project-collection-kind-value="company"]');
    if (!root) {
      return [];
    }

    return Array.from(root.querySelectorAll("[data-collection-id]")).map((row) => {
      const typeSelect = row.querySelector('[name$="[type]"]');
      const nameInput = row.querySelector('[name$="[name]"]');
      const name = String(nameInput?.value || "").trim();

      return {
        id: String(row.dataset.collectionId),
        type: String(typeSelect?.value || ""),
        typeLabel: typeSelect?.selectedOptions?.[0]?.textContent?.trim() || "",
        name,
        normalizedName: this.normalizeName(name),
        row,
      };
    }).filter((company) => company.name !== "" && company.type !== "");
  }

  openNewCompanyModal(event) {
    event.preventDefault();
    this.discardPendingCompany();

    const companyController = this.companyCollectionController();
    const item = companyController?.createItem();
    if (!item || !this.hasCompanyModalTarget || !this.hasCompanyModalHostTarget) {
      return;
    }

    item.querySelector("[data-company-row-remove]")?.classList.add("d-none");
    this.pendingCompanyItem = item;
    this.activeFundingSelect = this.firstIncompleteFundingSelect();
    this.companyModalHostTarget.replaceChildren(item);
    this.clearCompanyModalFeedback();

    bootstrap.Modal.getOrCreateInstance(this.companyModalTarget).show();
  }

  confirmNewCompany(event) {
    event.preventDefault();
    const item = this.pendingCompanyItem;
    const companyController = this.companyCollectionController();
    if (!item || !companyController) {
      return;
    }

    const typeSelect = item.querySelector('[name$="[type]"]');
    const nameInput = item.querySelector('[name$="[name]"]');
    const logoInput = item.querySelector('input[type="file"]');
    const invalidControl = [typeSelect, nameInput, logoInput].find((control) => control && !control.checkValidity());
    if (invalidControl) {
      invalidControl.reportValidity();
      return;
    }

    const duplicate = this.companyRows().find((company) => company.normalizedName === this.normalizeName(nameInput.value));
    if (duplicate && this.isCompanyUsedByAnotherFundingSource(duplicate.id)) {
      this.showCompanyModalFeedback();
      return;
    }

    let companyId;
    if (duplicate) {
      companyId = duplicate.id;
      item.remove();
    } else {
      item.querySelector("[data-company-row-remove]")?.classList.remove("d-none");
      companyController.appendItem(item);
      companyId = String(item.dataset.collectionId);
    }

    this.pendingCompanyItem = null;
    this.synchronizeFundingCompanyOptions();
    if (this.activeFundingSelect) {
      this.activeFundingSelect.value = companyId;
      this.synchronizeFundingRow(this.activeFundingSelect.closest("[data-collection-id]"));
      this.updateFundingCompanyAvailability();
      this.emitChanged();
    }
    bootstrap.Modal.getOrCreateInstance(this.companyModalTarget).hide();
  }

  discardPendingCompany() {
    this.pendingCompanyItem?.remove();
    this.pendingCompanyItem = null;
    this.activeFundingSelect = null;
    this.clearCompanyModalFeedback();
    if (this.hasCompanyModalHostTarget) {
      this.companyModalHostTarget.replaceChildren();
    }
  }

  isCompanyUsedByAnotherFundingSource(companyId) {
    return Array.from(this.listTarget.querySelectorAll("[data-funding-company-select]"))
      .some((select) => select !== this.activeFundingSelect && select.value === companyId);
  }

  firstIncompleteFundingSelect() {
    return Array.from(this.listTarget.querySelectorAll("[data-funding-company-select]"))
      .find((select) => select.value === "") || null;
  }

  showCompanyModalFeedback() {
    if (!this.hasCompanyModalFeedbackTarget) {
      return;
    }

    this.companyModalFeedbackTarget.textContent = this.companyModalFeedbackTarget.dataset.alreadyAssignedLabel || "";
    this.companyModalFeedbackTarget.classList.remove("d-none");
  }

  clearCompanyModalFeedback() {
    if (!this.hasCompanyModalFeedbackTarget) {
      return;
    }

    this.companyModalFeedbackTarget.textContent = "";
    this.companyModalFeedbackTarget.classList.add("d-none");
  }

  companyCollectionController() {
    const root = this.form?.querySelector('[data-project-collection-kind-value="company"]');
    return root ? this.application.getControllerForElementAndIdentifier(root, "project-collection") : null;
  }

  normalizeName(value) {
    return String(value || "").trim().toLocaleLowerCase();
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
