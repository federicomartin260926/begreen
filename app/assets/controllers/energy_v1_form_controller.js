import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'form', 'electricityPanel', 'equipmentPanel', 'batteryPanel', 'digitalPanel', 'origin', 'inputMethod',
    'totalFields', 'meterFields', 'mixedFields', 'fuel', 'fuelUnit', 'equipmentMode', 'equipmentDirectFields',
    'cylinderFields', 'bottleSize', 'chargeSource', 'batteryMixedFields', 'previewStatus', 'previewEmission',
    'previewTrace', 'previewMessages',
  ];

  static values = {
    config: Object,
    initial: Object,
    i18n: Object,
    previewUrl: String,
    previewToken: String,
  };

  connect() {
    this.populateFuels(this.initialValue.fuel);
    this.renderFields();
    this.queuePreview();
  }

  disconnect() {
    clearTimeout(this.previewTimer);
    this.previewRequest?.abort();
  }

  refresh() {
    this.populateFuels(this.fuelTarget.value || this.initialValue.fuel);
    this.renderFields();
    this.queuePreview();
  }

  renderFields() {
    const family = this.family;
    this.toggle(this.electricityPanelTarget, family === 'electricity');
    this.toggle(this.equipmentPanelTarget, family === 'equipment');
    this.toggle(this.batteryPanelTarget, family === 'battery');
    this.toggle(this.digitalPanelTarget, family === 'digital');

    const meter = family === 'electricity' && this.inputMethodTarget.value === 'meter';
    this.toggle(this.totalFieldsTarget, family === 'electricity' && !meter);
    this.toggle(this.meterFieldsTarget, meter);
    this.toggle(this.mixedFieldsTarget, family === 'electricity' && this.originTarget.value === 'mixed');

    const cylinders = family === 'equipment' && this.equipmentModeTarget.value === 'cylinders';
    this.toggle(this.equipmentDirectFieldsTarget, family === 'equipment' && !cylinders);
    this.toggle(this.cylinderFieldsTarget, cylinders);
    this.populateBottleSizes();

    this.toggle(this.batteryMixedFieldsTarget, family === 'battery' && this.chargeSourceTarget.value === 'mixed');
  }

  populateFuels(preferred) {
    if (!this.hasFuelTarget) return;
    const geography = this.country === 'ES' ? 'ES' : 'OUTSIDE';
    const fuels = this.configValue.fuels?.[geography] || {};
    const selected = Object.prototype.hasOwnProperty.call(fuels, preferred) ? preferred : this.fuelTarget.value;
    this.fillSelect(this.fuelTarget, Object.keys(fuels), selected);
    this.populateFuelUnits(fuels);
  }

  populateFuelUnits(fuels = null) {
    if (!this.hasFuelUnitTarget) return;
    const geography = this.country === 'ES' ? 'ES' : 'OUTSIDE';
    const available = fuels || this.configValue.fuels?.[geography] || {};
    const units = available[this.fuelTarget.value] || [];
    const selected = units.includes(this.fuelUnitTarget.value)
      ? this.fuelUnitTarget.value
      : this.initialValue.unit;
    this.fillSelect(this.fuelUnitTarget, units, selected, false);
  }

  populateBottleSizes() {
    if (!this.hasBottleSizeTarget) return;
    const sizes = this.fuelTarget.value === 'Gas butano'
      ? ['6', '12.5']
      : (this.fuelTarget.value === 'Gas propano' ? ['11', '35'] : []);
    const selected = sizes.includes(this.bottleSizeTarget.value)
      ? this.bottleSizeTarget.value
      : this.initialValue.bottleSizeKg;
    this.fillSelect(this.bottleSizeTarget, sizes, selected);
  }

  queuePreview() {
    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.preview(), 300);
  }

  async preview() {
    if (!this.commonContextComplete) {
      this.previewStatusTarget.textContent = this.i18nValue.previewError;
      this.previewEmissionTarget.textContent = '—';
      this.previewTraceTarget.textContent = '';
      this.previewMessagesTarget.replaceChildren();
      return;
    }

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
      if (error.name === 'AbortError') return;
      this.previewStatusTarget.textContent = this.i18nValue.previewError;
      this.previewEmissionTarget.textContent = '—';
    }
  }

  renderPreview(result) {
    this.previewStatusTarget.textContent = this.i18nValue.statusLabels[result.status] || result.status;
    this.previewEmissionTarget.textContent = result.emissionKgCo2e === null ? '—' : `${result.emissionKgCo2e} kg CO₂e`;

    const traceParts = [];
    if (result.normalizedAmount !== null) traceParts.push(`${result.normalizedAmount} ${result.normalizedUnit || ''}`.trim());
    (result.factorTraces || []).forEach((trace) => {
      const parts = [trace.source, trace.factorYear ? String(trace.factorYear) : trace.temporalType];
      if (trace.factorValue !== null) parts.push(`${trace.factorValue} ${trace.factorUnit || ''}`.trim());
      if (trace.fallback) parts.push(trace.fallbackReason);
      if (trace.geographicProxy) parts.push(`${trace.proxyGeography}`);
      traceParts.push(parts.filter(Boolean).join(' · '));
    });
    this.previewTraceTarget.textContent = traceParts.join(' | ');

    this.previewMessagesTarget.replaceChildren();
    (result.messages || []).forEach((message) => {
      const item = document.createElement('li');
      item.textContent = this.i18nValue.messageLabels[message] || message;
      this.previewMessagesTarget.append(item);
    });
  }

  validate(event) {
    if (!this.formTarget.checkValidity()) {
      event.preventDefault();
      this.formTarget.classList.add('was-validated');
    }
  }

  toggle(container, visible) {
    container.hidden = !visible;
    container.querySelectorAll('[name]').forEach((field) => {
      field.disabled = !visible;
    });
  }

  fillSelect(select, options, selected, placeholder = true) {
    const current = select.value;
    select.replaceChildren();
    if (placeholder) select.add(new Option(this.i18nValue.select, ''));
    options.forEach((option) => select.add(new Option(option, option)));
    const preferred = options.includes(selected) ? selected : current;
    if (options.includes(preferred)) select.value = preferred;
    if (!placeholder && !select.value && options.length) select.value = options[0];
  }

  get family() {
    return this.formTarget.querySelector('[name="family"]:checked')?.value || '';
  }

  get country() {
    return this.formTarget.querySelector('[name="country"]')?.value || '';
  }

  get commonContextComplete() {
    return Boolean(
      this.family
      && this.country
      && this.formTarget.querySelector('[name="startDate"]')?.value
      && this.formTarget.querySelector('[name="endDate"]')?.value
    );
  }
}
