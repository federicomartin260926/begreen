import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['form', 'type', 'peopleFields', 'mealFields', 'sandwichFields', 'waterFields', 'drinkFields', 'coffeeFields', 'gasFields', 'menuLines', 'menuLine', 'previewStatus', 'previewEmission', 'previewTrace', 'previewMessages'];

  static values = { i18n: Object, previewUrl: String, previewToken: String };

  connect() { this.updateFields(); }

  disconnect() {
    clearTimeout(this.previewTimer);
    this.previewRequest?.abort();
  }

  updateFields() {
    const type = this.typeTarget.value;
    this.toggle(this.peopleFieldsTarget, ['breakfast', 'coffeebreak', 'snack'].includes(type));
    this.toggle(this.mealFieldsTarget, type === 'meal');
    this.toggle(this.sandwichFieldsTarget, type === 'sandwich');
    this.toggle(this.waterFieldsTarget, type === 'water');
    this.toggle(this.drinkFieldsTarget, type === 'drink');
    this.toggle(this.coffeeFieldsTarget, type === 'coffee');
    this.toggle(this.gasFieldsTarget, type === 'gas');
    this.queuePreview();
  }

  toggle(container, visible) {
    container.classList.toggle('d-none', !visible);
    container.querySelectorAll('input, select, textarea').forEach((field) => { field.disabled = !visible; });
  }

  addMenuLine() {
    const row = this.menuLineTargets[0].cloneNode(true);
    row.querySelectorAll('input').forEach((input) => { input.value = ''; });
    row.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
    this.menuLinesTarget.append(row);
    this.queuePreview();
  }

  removeMenuLine(event) {
    const row = event.currentTarget.closest('[data-catering-v1-form-target="menuLine"]');
    if (this.menuLineTargets.length > 1) {
      row.remove();
    } else {
      row.querySelectorAll('input').forEach((input) => { input.value = ''; });
      row.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
    }
    this.queuePreview();
  }

  queuePreview() {
    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.preview(), 300);
  }

  async preview() {
    if (!this.contextComplete) { this.clearPreview(); return; }
    this.previewRequest?.abort();
    this.previewRequest = new AbortController();
    const body = new URLSearchParams();
    new FormData(this.formTarget).forEach((value, key) => {
      if (typeof value === 'string' && key !== '_token') body.append(key, value);
    });
    body.set('_preview_token', this.previewTokenValue);
    this.previewStatusTarget.textContent = this.i18nValue.calculating;

    try {
      const response = await fetch(this.previewUrlValue, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
        signal: this.previewRequest.signal,
      });
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error || 'preview_failed');
      this.renderPreview(result);
    } catch (error) {
      if (error.name !== 'AbortError') this.clearPreview();
    }
  }

  renderPreview(result) {
    this.previewStatusTarget.textContent = this.i18nValue.statusLabels[result.status] || result.status;
    this.previewEmissionTarget.textContent = result.emissionKgCo2e === null ? '—' : `${this.formatDecimal(result.emissionKgCo2e)} kg CO₂e`;
    const lines = [];
    if (result.foodEmissionKgCo2e !== null) lines.push(`${this.i18nValue.food}: ${this.formatDecimal(result.foodEmissionKgCo2e)} kg CO₂e`);
    if (result.tablewareEmissionKgCo2e !== null) lines.push(`${this.i18nValue.tableware}: ${this.formatDecimal(result.tablewareEmissionKgCo2e)} kg CO₂e`);
    if (result.emissionKgCo2e !== null) lines.push(`${this.i18nValue.total}: ${this.formatDecimal(result.emissionKgCo2e)} kg CO₂e`);
    if (result.normalizedAmount !== null) lines.push(`${this.formatDecimal(result.normalizedAmount)} ${this.localizeUnit(result.normalizedUnit)}`.trim());
    this.previewTraceTarget.replaceChildren();
    lines.forEach((line) => { const item = document.createElement('li'); item.textContent = line; this.previewTraceTarget.append(item); });
    this.previewMessagesTarget.replaceChildren();
    (result.messages || []).forEach((message) => { const item = document.createElement('li'); item.textContent = this.i18nValue.messageLabels[message] || message; this.previewMessagesTarget.append(item); });
  }

  clearPreview() {
    this.previewStatusTarget.textContent = this.i18nValue.previewError;
    this.previewEmissionTarget.textContent = '—';
    this.previewTraceTarget.replaceChildren();
    this.previewMessagesTarget.replaceChildren();
  }

  validate(event) {
    if (!this.formTarget.checkValidity()) { event.preventDefault(); this.formTarget.classList.add('was-validated'); }
  }

  localizeUnit(unit) { return unit ? (this.i18nValue.unitLabels[unit] || unit) : ''; }

  formatDecimal(value) {
    const text = String(value);
    const trimmed = text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text;
    return (document.documentElement.lang || '').toLowerCase().startsWith('es') ? trimmed.replace('.', ',') : trimmed;
  }

  get contextComplete() {
    return ['startDate', 'endDate', 'country', 'activityType'].every((name) => Boolean(this.formTarget.querySelector(`[name="${name}"]`)?.value));
  }
}
