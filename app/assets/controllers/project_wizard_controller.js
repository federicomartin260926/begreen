import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = [
    "panel",
    "stepButton",
    "summary",
    "previousButton",
    "nextButton",
    "submitButton",
  ];

  static values = {
    overviewLabel: String,
    projectLabel: String,
    generalLabel: String,
    datesLabel: String,
    fundingLabel: String,
    companiesLabel: String,
    fundingSourcesLabel: String,
    ecoManagerLabel: String,
    summaryEmptyLabel: String,
    summaryNoticeCreateLabel: String,
    summaryNoticeUpdateLabel: String,
    noCompaniesLabel: String,
    noFundingLabel: String,
  };

  connect() {
    this.currentStep = this.findInitialStep();

    this.onFormMutation = this.onFormMutation.bind(this);
    this.onStepClick = this.onStepClick.bind(this);
    this.onPreviousClick = this.onPreviousClick.bind(this);
    this.onNextClick = this.onNextClick.bind(this);

    this.element.addEventListener("input", this.onFormMutation);
    this.element.addEventListener("change", this.onFormMutation);
    this.element.addEventListener("project:changed", this.onFormMutation);
    this.element.addEventListener("project-collection:changed", this.onFormMutation);

    this.stepButtonTargets.forEach((button) => {
      button.addEventListener("click", this.onStepClick);
    });

    if (this.hasPreviousButtonTarget) {
      this.previousButtonTarget.addEventListener("click", this.onPreviousClick);
    }

    if (this.hasNextButtonTarget) {
      this.nextButtonTarget.addEventListener("click", this.onNextClick);
    }

    this.refresh();
  }

  disconnect() {
    this.element.removeEventListener("input", this.onFormMutation);
    this.element.removeEventListener("change", this.onFormMutation);
    this.element.removeEventListener("project:changed", this.onFormMutation);
    this.element.removeEventListener("project-collection:changed", this.onFormMutation);

    this.stepButtonTargets.forEach((button) => {
      button.removeEventListener("click", this.onStepClick);
    });

    if (this.hasPreviousButtonTarget) {
      this.previousButtonTarget.removeEventListener("click", this.onPreviousClick);
    }

    if (this.hasNextButtonTarget) {
      this.nextButtonTarget.removeEventListener("click", this.onNextClick);
    }
  }

  onFormMutation() {
    this.refresh();
  }

  onStepClick(event) {
    event.preventDefault();
    const step = Number(event.currentTarget.dataset.step);
    this.goToStep(step, { focus: true });
  }

  onPreviousClick(event) {
    event.preventDefault();
    this.goToStep(this.currentStep - 1, { focus: true });
  }

  onNextClick(event) {
    event.preventDefault();
    if (!this.isStepValid(this.currentStep)) {
      this.focusFirstInvalidControl(this.currentStep);
      return;
    }

    this.goToStep(this.currentStep + 1, { focus: true, allowForward: true });
  }

  submit(event) {
    if (!this.isReadyForSubmit()) {
      event.preventDefault();
      this.goToStep(this.firstInvalidStep() || this.currentStep, { focus: true });
    }
  }

  refresh() {
    this.currentStep = this.clampStep(this.currentStep || 1);
    this.updatePanels();
    this.updateStepButtons();
    this.updateNavigation();
    this.updateSummary();
  }

  goToStep(step, { focus = false, allowForward = false } = {}) {
    const targetStep = this.clampStep(step);

    if (!allowForward && targetStep > this.currentStep) {
      return false;
    }

    this.currentStep = targetStep;
    this.refresh();

    if (focus) {
      this.focusCurrentStep();
    }

    return true;
  }

  updatePanels() {
    this.panelTargets.forEach((panel) => {
      const isActive = this.stepFromPanel(panel) === this.currentStep;
      panel.classList.toggle("d-none", !isActive);
      panel.setAttribute("aria-hidden", isActive ? "false" : "true");
    });
  }

  updateStepButtons() {
    this.stepButtonTargets.forEach((button) => {
      const step = Number(button.dataset.step);
      const state = this.stepState(step);
      const indicator = button.querySelector("[data-project-wizard-step-indicator]");

      button.classList.remove(
        "btn-success",
        "btn-outline-success",
        "btn-outline-secondary",
        "btn-light",
        "bg-success-subtle",
        "bg-white",
        "text-white",
        "text-success",
        "text-muted",
        "border-success",
        "border-secondary-subtle",
        "shadow-sm",
        "opacity-50"
      );
      button.disabled = state === "future";
      button.setAttribute("aria-current", state === "current" ? "step" : "false");
      button.setAttribute("aria-disabled", state === "future" ? "true" : "false");

      if (state === "current") {
        button.classList.add("btn-success", "text-white", "border-success", "shadow-sm");
      } else if (state === "completed") {
        button.classList.add("bg-success-subtle", "text-success", "border-success");
      } else {
        button.classList.add("bg-white", "text-muted", "border-secondary-subtle", "opacity-50");
      }

      if (indicator) {
        indicator.textContent = state === "completed" ? "✓" : String(step);
        indicator.classList.toggle("bg-success", state === "current");
        indicator.classList.toggle("text-white", state === "current");
        indicator.classList.toggle("bg-white", state !== "current");
        indicator.classList.toggle("text-success", state !== "current");
        indicator.classList.toggle("border-success", state !== "future");
        indicator.classList.toggle("border-secondary-subtle", state === "future");
      }
    });
  }

  updateNavigation() {
    if (this.hasPreviousButtonTarget) {
      this.previousButtonTarget.disabled = this.currentStep <= 1;
    }

    if (this.hasNextButtonTarget) {
      const showNext = this.currentStep < this.totalSteps;
      this.nextButtonTarget.classList.toggle("d-none", !showNext);
      this.nextButtonTarget.disabled = !showNext || !this.isStepValid(this.currentStep);
    }

    if (this.hasSubmitButtonTarget) {
      const showSubmit = this.currentStep === this.totalSteps;
      this.submitButtonTarget.classList.toggle("d-none", !showSubmit);
      this.submitButtonTarget.disabled = !this.isReadyForSubmit();
    }
  }

  updateSummary() {
    if (!this.hasSummaryTarget) {
      return;
    }

    this.summaryTarget.innerHTML = this.buildSummary();
  }

  buildSummary() {
    const sections = [
      this.renderIdentificationSection(),
      this.renderProjectSection(),
      this.renderGeneralSection(),
      this.renderDatesSection(),
      this.renderFundingSection(),
    ].filter(Boolean);

    if (!sections.length) {
      return `<div class="text-muted">${this.escapeHtml(this.summaryEmptyLabelValue || "—")}</div>`;
    }

    return `<div class="d-grid gap-3">${sections.join("")}</div>`;
  }

  renderIdentificationSection() {
    const items = [
      this.summaryItem(this.fieldLabel("name"), this.fieldValue("name")),
      this.summaryItem(this.fieldLabel("country"), this.choiceText("country")),
      this.summaryItem(this.fieldLabel("type"), this.choiceText("type")),
      this.summaryItem(this.fieldLabel("emissionSourceName"), this.choiceText("emissionSourceName")),
    ].filter(Boolean);

    return this.renderInfoCard(this.overviewLabelValue || "Identificación", items);
  }

  renderProjectSection() {
    const type = this.choiceValue("type");
    const items = [];

    if (type === "rodaje") {
      items.push(
        this.summaryItem(this.fieldLabel("filmingType"), this.choiceText("filmingType")),
        this.summaryItem(this.fieldLabel("filmingGenre"), this.choiceText("filmingGenre")),
        this.summaryItem(this.groupLabel("distributionMedia"), this.checkboxGroupText("distributionMedia")),
      );

      const filmingType = this.choiceValue("filmingType");
      if (filmingType === "tv_series" || filmingType === "tv_program") {
        items.push(
          this.summaryItem(this.fieldLabel("episodios"), this.formatEpisodes(this.fieldValue("episodios"))),
          this.summaryItem(this.fieldLabel("duracionEpisodio"), this.formatDuration(this.fieldValue("duracionEpisodio"))),
        );
      }
    }

    if (type === "evento") {
      items.push(
        this.summaryItem(this.fieldLabel("eventTypePrimary"), this.choiceText("eventTypePrimary")),
        this.summaryItem(this.fieldLabel("eventModality"), this.choiceText("eventModality")),
        this.summaryItem(this.fieldLabel("eventAttendeesCount"), this.formatCount(this.fieldValue("eventAttendeesCount"))),
        this.summaryItem(this.fieldLabel("eventOnlineConnections"), this.formatCount(this.fieldValue("eventOnlineConnections"))),
      );
    }

    if (!items.length) {
      return "";
    }

    return this.renderInfoCard(this.projectLabelValue || "Proyecto", items);
  }

  renderGeneralSection() {
    const items = [
      this.summaryItem(this.fieldLabel("mainLocation"), this.fieldValue("mainLocation")),
      this.summaryItem(this.fieldLabel("presupuesto"), this.formatMoney(this.fieldValue("presupuesto"))),
    ].filter(Boolean);

    const companiesTable = this.renderCompaniesTable();
    const body = [
      items.length ? this.renderPairs(items) : "",
      companiesTable ? `<div class="mt-3">${companiesTable}</div>` : "",
    ].filter(Boolean).join("");

    if (!body) {
      return "";
    }

    return this.renderInfoCard(this.generalLabelValue || "Datos generales", body, true);
  }

  renderDatesSection() {
    const rows = this.collectPhaseRows();
    if (!rows.length) {
      return "";
    }

    const headers = [
      this.fieldLabel("phase") || "Fase",
      this.fieldLabel("startDate") || "Inicio",
      this.fieldLabel("endDate") || "Fin",
    ];

    const tableRows = rows.map((row) => `
      <tr>
        <td class="fw-semibold">${this.escapeHtml(row.label)}</td>
        <td>${this.escapeHtml(row.start)}</td>
        <td>${this.escapeHtml(row.end)}</td>
      </tr>
    `).join("");

    return this.renderInfoCard(
      this.datesLabelValue || "Fechas",
      this.renderResponsiveTable(headers, tableRows),
      true
    );
  }

  renderFundingSection() {
    const fundingTable = this.renderFundingTable();
    const total = this.fundingTotal();
    const ecoManager = this.radioGroupText("ecoManagerStatus");
    const details = [
      fundingTable,
      total !== null ? `<div class="mt-3"><span class="small text-muted">${this.escapeHtml("Total")}</span> <span class="fw-semibold">${this.escapeHtml(this.formatPercentage(total))}</span></div>` : "",
      ecoManager ? `<div class="mt-3"><span class="small text-muted">${this.escapeHtml(this.ecoManagerLabelValue || this.groupLabel("ecoManagerStatus") || "Eco Manager")}</span> <span class="fw-semibold">${this.escapeHtml(ecoManager)}</span></div>` : "",
    ].filter(Boolean).join("");

    if (!details) {
      return "";
    }

    return this.renderInfoCard(this.fundingLabelValue || "Financiación", details, true);
  }

  renderCompaniesTable() {
    const rows = this.collectCollectionRows("company").filter((row) => row.type || row.name);
    if (!rows.length) {
      return `<div class="text-muted small">${this.escapeHtml(this.noCompaniesLabelValue || "Sin empresas implicadas.")}</div>`;
    }

    const headers = [
      this.fieldLabel("type") || "Tipo",
      this.fieldLabel("name") || "Nombre",
    ];

    const body = rows.map((row) => `
      <tr>
        <td>${this.escapeHtml(row.type)}</td>
        <td>${this.escapeHtml(row.name)}</td>
      </tr>
    `).join("");

    return this.renderResponsiveTable(headers, body);
  }

  renderFundingTable() {
    const rows = this.collectCollectionRows("funding").filter((row) => row.type || row.name || row.percentage);
    if (!rows.length) {
      return `<div class="text-muted small">${this.escapeHtml(this.noFundingLabelValue || "Sin fuentes de financiación.")}</div>`;
    }

    const headers = [
      this.fieldLabel("type") || "Tipo",
      this.fieldLabel("name") || "Nombre",
      this.fieldLabel("percentage") || "Porcentaje",
    ];

    const body = rows.map((row) => `
      <tr>
        <td>${this.escapeHtml(row.type)}</td>
        <td>${this.escapeHtml(row.name)}</td>
        <td>${this.escapeHtml(this.formatPercentage(row.percentage))}</td>
      </tr>
    `).join("");

    return this.renderResponsiveTable(headers, body);
  }

  renderInfoCard(title, content, rawHtml = false) {
    const body = rawHtml ? content : this.renderPairs(content);

    return `
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <div class="fw-semibold mb-3">${this.escapeHtml(title)}</div>
          ${body}
        </div>
      </div>
    `;
  }

  renderPairs(items) {
    return `
      <div class="row g-3">
        ${items.map((item) => `<div class="col-12 col-lg-6">${item}</div>`).join("")}
      </div>
    `;
  }

  renderResponsiveTable(headers, body) {
    return `
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>${headers.map((header) => `<th scope="col">${this.escapeHtml(header)}</th>`).join("")}</tr>
          </thead>
          <tbody>${body}</tbody>
        </table>
      </div>
    `;
  }

  summaryItem(label, value) {
    if (!label || !value) {
      return "";
    }

    return `
      <div class="border rounded-3 p-3 h-100 bg-light">
        <div class="small text-muted mb-1">${this.escapeHtml(label)}</div>
        <div class="fw-semibold">${this.escapeHtml(value)}</div>
      </div>
    `;
  }

  fieldLabel(fieldName) {
    const control = this.findControl(fieldName) || this.findGroupedControl(fieldName);
    return control ? this.controlLabel(control) : "";
  }

  fieldValue(fieldName) {
    const control = this.findControl(fieldName);
    return control ? this.controlDisplayValue(control) : "";
  }

  choiceText(fieldName) {
    const control = this.findControl(fieldName);
    if (!control) {
      return "";
    }

    if (control.tagName === "SELECT") {
      return control.selectedOptions?.[0]?.textContent?.trim() || "";
    }

    if (control.type === "radio") {
      const checked = this.element.querySelector(`input[type="radio"][name="${control.name}"]:checked`);
      return checked ? this.controlDisplayValue(checked) : "";
    }

    return this.controlDisplayValue(control);
  }

  choiceValue(fieldName) {
    const control = this.findControl(fieldName);
    return control?.value?.trim() || "";
  }

  checkboxGroupText(fieldName) {
    const selected = [];
    this.element.querySelectorAll(`input[type="checkbox"][name$="[${fieldName}][]"]`).forEach((checkbox) => {
      if (checkbox.checked) {
        const label = this.controlLabel(checkbox);
        if (label) {
          selected.push(label);
        }
      }
    });

    return selected.join(", ");
  }

  radioGroupText(fieldName) {
    const checked = this.element.querySelector(`input[type="radio"][name$="[${fieldName}]"]:checked`);
    return checked ? this.controlDisplayValue(checked) : "";
  }

  collectCollectionRows(kind) {
    const root = this.element.querySelector(`[data-project-collection-kind-value="${kind}"]`);
    if (!root) {
      return [];
    }

    return Array.from(root.querySelectorAll("[data-collection-id]"))
      .map((row) => {
        const typeControl = row.querySelector('[name$="[type]"]');
        const nameControl = row.querySelector('[name$="[name]"]');
        const percentageControl = row.querySelector('[name$="[percentage]"]');

        return {
          type: this.controlDisplayValue(typeControl),
          name: this.controlDisplayValue(nameControl),
          percentage: percentageControl ? percentageControl.value.trim() : "",
        };
      });
  }

  collectPhaseRows() {
    const rows = this.element.querySelectorAll('[data-project-target="list"] [data-collection-id]');
    return Array.from(rows).map((row) => {
      const label = row.querySelector('[data-project-target="label"]')?.value?.trim()
        || this.controlLabel(row.querySelector('[name$="[phase]"]'))
        || "—";
      const phase = row.querySelector('[name$="[phase]"]')?.value?.trim() || "";
      const start = row.querySelector('[name$="[startDate]"]')?.value?.trim() || "";
      const end = row.querySelector('[name$="[endDate]"]')?.value?.trim() || "";

      return {
        label,
        phase,
        start: this.formatDate(start),
        end: this.formatDate(end),
        rawStart: start,
        rawEnd: end,
      };
    }).filter((row) => row.start || row.end || row.phase !== "");
  }

  fundingTotal() {
    const rows = this.collectCollectionRows("funding");
    if (!rows.length) {
      return null;
    }

    let total = 0;
    for (const row of rows) {
      const parsed = this.parseHundredths(row.percentage);
      if (parsed === null) {
        return null;
      }
      total += parsed;
    }

    return total / 100;
  }

  isStepValid(step) {
    if (this.hasBackendError(step)) {
      return false;
    }

    switch (step) {
      case 1:
        return this.validateStepOne();
      case 2:
        return this.validateStepTwo();
      case 3:
        return this.validateStepThree();
      case 4:
        return this.validateStepFour();
      default:
        return true;
    }
  }

  isReadyForSubmit() {
    return [1, 2, 3, 4].every((step) => this.isStepValid(step));
  }

  firstInvalidStep() {
    for (let step = 1; step <= 4; step += 1) {
      if (!this.isStepValid(step)) {
        return step;
      }
    }

    return null;
  }

  validateStepOne() {
    const requiredControls = [
      this.findControl("name"),
      this.findControl("country"),
      this.findControl("type"),
      this.findControl("emissionSourceName"),
    ].filter(Boolean);

    if (requiredControls.some((control) => !this.isFilled(control))) {
      return false;
    }

    const commercialTier = this.findControl("commercialTier");
    if (commercialTier && !commercialTier.disabled && !this.isFilled(commercialTier)) {
      return false;
    }

    const type = this.choiceValue("type");
    if (type === "rodaje") {
      if (!this.isFilled(this.findControl("filmingType"))) {
        return false;
      }

      if (!this.hasCheckedCheckbox("distributionMedia")) {
        return false;
      }

      const filmingType = this.choiceValue("filmingType");
      if (filmingType === "tv_series" || filmingType === "tv_program") {
        if (!this.isFilled(this.findControl("episodios"))) {
          return false;
        }

        if (!this.isFilled(this.findControl("duracionEpisodio"))) {
          return false;
        }
      }
    }

    if (type === "evento") {
      if (!this.isFilled(this.findControl("eventTypePrimary"))) {
        return false;
      }

      if (!this.isFilled(this.findControl("eventModality"))) {
        return false;
      }

      const modality = this.choiceValue("eventModality");
      if (modality === "presencial" || modality === "hibrido") {
        if (!this.isFilled(this.findControl("eventAttendeesCount"))) {
          return false;
        }
      }

      if (modality === "virtual" || modality === "hibrido") {
        if (!this.isFilled(this.findControl("eventOnlineConnections"))) {
          return false;
        }
      }
    }

    return true;
  }

  validateStepTwo() {
    const rows = this.collectCollectionRows("company");
    if (!rows.length) {
      return true;
    }

    return rows.every((row, index) => {
      const root = this.collectionRow("company", index);
      const typeControl = root?.querySelector('[name$="[type]"]');
      const nameControl = root?.querySelector('[name$="[name]"]');
      return this.isFilled(typeControl) && this.isFilled(nameControl);
    });
  }

  validateStepThree() {
    const rows = this.collectPhaseRows();
    if (!rows.length) {
      return false;
    }

    const ordered = rows.map((row) => ({
      ...row,
      order: this.phaseOrder(row.phase),
    }));

    for (const row of ordered) {
      if (!row.rawStart) {
        return false;
      }

      if (!row.rawEnd) {
        return false;
      }

      if (this.compareDates(row.rawEnd, row.rawStart) < 0) {
        return false;
      }
    }

    const sorted = ordered.slice().sort((a, b) => a.order - b.order);
    for (let i = 1; i < sorted.length; i += 1) {
      if (this.compareDates(sorted[i].rawStart, sorted[i - 1].rawEnd) < 0) {
        return false;
      }
    }

    return true;
  }

  validateStepFour() {
    const rows = this.collectCollectionRows("funding");
    const requiresEcoManager = this.isRadioGroupRequired("ecoManagerStatus");

    if (requiresEcoManager && !this.radioGroupText("ecoManagerStatus")) {
      return false;
    }

    if (!rows.length) {
      return true;
    }

    let total = 0;

    for (const row of rows) {
      if (!row.type || !row.name || !row.percentage) {
        return false;
      }

      const percentage = this.parseHundredths(row.percentage);
      if (percentage === null || percentage <= 0 || percentage > 10000) {
        return false;
      }

      total += percentage;
    }

    return total === 10000;
  }

  isControlRequired(control) {
    if (!control) {
      return false;
    }

    return Boolean(control.required || control.getAttribute("aria-required") === "true");
  }

  isRadioGroupRequired(fieldName) {
    return Array.from(this.element.querySelectorAll(`input[type="radio"][name$="[${fieldName}]"]`))
      .some((radio) => this.isControlRequired(radio));
  }

  isFilled(control) {
    if (!control || control.disabled) {
      return false;
    }

    if (control.type === "checkbox") {
      return control.checked;
    }

    if (control.type === "radio") {
      return control.checked;
    }

    if (control.tagName === "SELECT") {
      return (control.value || "").trim() !== "";
    }

    return (control.value || "").trim() !== "";
  }

  hasCheckedCheckbox(fieldName) {
    return Array.from(this.element.querySelectorAll(`input[type="checkbox"][name$="[${fieldName}][]"]`))
      .some((checkbox) => checkbox.checked);
  }

  collectionRow(kind, index) {
    const root = this.element.querySelector(`[data-project-collection-kind-value="${kind}"]`);
    return root ? root.querySelectorAll("[data-collection-id]")[index] || null : null;
  }

  phaseOrder(phase) {
    const map = {
      preproduccion: 1,
      montaje: 1,
      actividad: 2,
      postproduccion: 3,
      desmontaje: 3,
    };

    return map[phase] || 99;
  }

  compareDates(left, right) {
    if (!left || !right) {
      return 0;
    }

    const leftTime = this.parseDate(left);
    const rightTime = this.parseDate(right);

    if (leftTime === null || rightTime === null) {
      return 0;
    }

    return leftTime - rightTime;
  }

  parseDate(value) {
    const parsed = new Date(`${value}T00:00:00`);
    return Number.isNaN(parsed.getTime()) ? null : parsed.getTime();
  }

  parseHundredths(value) {
    const normalized = String(value ?? "").trim().replace(",", ".");
    if (!normalized) {
      return null;
    }

    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
      return null;
    }

    const [integer, decimal = ""] = normalized.split(".");
    return parseInt(integer, 10) * 100 + parseInt((decimal + "00").slice(0, 2), 10);
  }

  formatDate(value) {
    if (!value) {
      return "";
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
      return value;
    }

    return new Intl.DateTimeFormat(this.locale(), {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    }).format(date);
  }

  formatMoney(value) {
    if (!value) {
      return "";
    }

    const numeric = Number(String(value).replace(",", "."));
    if (Number.isNaN(numeric)) {
      return value;
    }

    return new Intl.NumberFormat(this.locale(), {
      style: "currency",
      currency: "EUR",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(numeric);
  }

  formatPercentage(value) {
    if (value === null || value === undefined || value === "") {
      return "";
    }

    const numeric = Number(String(value).replace(",", "."));
    if (Number.isNaN(numeric)) {
      return String(value);
    }

    return `${new Intl.NumberFormat(this.locale(), {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(numeric)} %`;
  }

  formatEpisodes(value) {
    if (value === null || value === undefined || value === "") {
      return "";
    }

    return `${value} episodios`;
  }

  formatDuration(value) {
    if (value === null || value === undefined || value === "") {
      return "";
    }

    return `${value} min por episodio`;
  }

  formatCount(value) {
    if (value === null || value === undefined || value === "") {
      return "";
    }

    return String(value);
  }

  locale() {
    const lang = document.documentElement.lang || "es";
    return lang.toLowerCase().startsWith("en") ? "en-GB" : "es-ES";
  }

  findInitialStep() {
    return this.firstInvalidStep() || 1;
  }

  hasBackendError(step) {
    return this.element.querySelector(`[data-project-wizard-error-step="${step}"]`) !== null;
  }

  stepState(step) {
    if (step === this.currentStep) {
      return "current";
    }

    if (step < this.currentStep) {
      return "completed";
    }

    return "future";
  }

  clampStep(step) {
    return Math.min(Math.max(Number(step) || 1, 1), this.totalSteps);
  }

  get totalSteps() {
    return this.panelTargets.length;
  }

  stepFromPanel(panel) {
    return Number(panel.dataset.step);
  }

  findControl(fieldName) {
    return this.element.querySelector(`[data-project-target="${fieldName}"]`)
      || this.element.querySelector(`[name$="[${fieldName}]"]`)
      || null;
  }

  findGroupedControl(fieldName) {
    return this.element.querySelector(`input[type="checkbox"][name$="[${fieldName}][]"]`)
      || this.element.querySelector(`input[type="checkbox"][name$="[${fieldName}]"]`)
      || null;
  }

  groupLabel(fieldName) {
    const control = this.findGroupedControl(fieldName) || this.findControl(fieldName);
    if (!control) {
      return "";
    }

    const wrapper = control.closest(".form-label-over, .mb-3, .col-12, .col-md-4, .col-md-6, .col-md-3");
    const label = wrapper?.querySelector(".form-label");
    return label ? label.textContent.trim().replace(/\s+/g, " ") : this.controlLabel(control);
  }

  controlLabel(control) {
    if (!control) {
      return "";
    }

    if (control.id) {
      const label = this.element.querySelector(`label[for="${CSS.escape(control.id)}"]`);
      if (label) {
        return label.textContent.trim().replace(/\s+/g, " ");
      }
    }

    const wrapper = control.closest(".form-label-over, .form-check, .col, .mb-3");
    const wrapperLabel = wrapper?.querySelector(".form-label, .form-check-label");
    if (wrapperLabel) {
      return wrapperLabel.textContent.trim().replace(/\s+/g, " ");
    }

    return control.getAttribute("aria-label") || control.name || "";
  }

  controlDisplayValue(control) {
    if (!control) {
      return "";
    }

    if (control.type === "checkbox") {
      return control.checked ? (this.controlLabel(control) || "Sí") : "";
    }

    if (control.type === "radio") {
      return control.checked ? (this.controlLabel(control) || control.value) : "";
    }

    if (control.tagName === "SELECT") {
      return control.selectedOptions?.[0]?.textContent?.trim() || "";
    }

    const value = (control.value || "").trim();
    return value;
  }

  escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }
}
