import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'form', 'country', 'type', 'activityContainer', 'activity', 'treatment',
    'previewStatus', 'previewEmission', 'previewTrace', 'previewMessages',
  ];

  static values = {
    catalog: Object,
    initial: Object,
    i18n: Object,
    previewUrl: String,
    previewToken: String,
  };

  connect() {
    this.currentRegion = this.regionFor(this.countryTarget.value);
    this.populateTypes(this.initialValue.wasteType || '');
    this.populateActivities(this.initialValue.wasteActivity || '');
    this.populateTreatments(this.initialValue.treatment || '');
    this.queuePreview();
  }

  disconnect() {
    clearTimeout(this.previewTimer);
    this.previewRequest?.abort();
  }

  countryChanged() {
    const nextRegion = this.regionFor(this.countryTarget.value);
    const reset = this.currentRegion !== null && nextRegion !== this.currentRegion;
    this.currentRegion = nextRegion;

    this.populateTypes(reset ? '' : this.typeTarget.value);
    this.populateActivities(reset ? '' : this.activityTarget.value);
    this.populateTreatments(reset ? '' : this.treatmentTarget.value);
    this.queuePreview();
  }

  typeChanged() {
    this.populateActivities('');
    this.populateTreatments('');
    this.queuePreview();
  }

  activityChanged() {
    this.populateTreatments('');
    this.queuePreview();
  }

  populateTypes(selected) {
    const types = this.regionCatalog?.types || [];
    this.replaceOptions(
      this.typeTarget,
      types.map((item) => ({ value: item.value, label: item.label })),
      selected,
    );
  }

  populateActivities(selected) {
    const type = this.selectedType;
    const hasSubactivity = Boolean(type?.hasSubactivity);
    this.activityContainerTarget.classList.toggle('d-none', !hasSubactivity);
    this.activityTarget.disabled = !hasSubactivity;
    this.activityTarget.required = hasSubactivity;

    this.replaceOptions(
      this.activityTarget,
      hasSubactivity
        ? (type?.activities || []).map((item) => ({ value: item.value, label: item.value }))
        : [],
      selected,
    );
  }

  populateTreatments(selected) {
    const treatments = this.selectedActivity?.treatments || [];
    this.replaceOptions(
      this.treatmentTarget,
      treatments.map((value) => ({ value, label: value })),
      selected,
    );

    if (treatments.length === 1) {
      this.treatmentTarget.value = treatments[0];
    }
  }

  replaceOptions(select, options, selected) {
    const first = document.createElement('option');
    first.value = '';
    first.textContent = this.i18nValue.select;
    select.replaceChildren(first);

    options.forEach(({ value, label }) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      option.selected = value === selected;
      select.append(option);
    });
  }

  queuePreview() {
    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.preview(), 300);
  }

  async preview() {
    if (!this.contextComplete) {
      this.clearPreview();
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
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: body.toString(),
        signal: this.previewRequest.signal,
      });
      const result = await response.json();
      if (!response.ok || result.error) {
        this.renderPreviewError(result.message);
        return;
      }
      this.renderPreview(result);
    } catch (error) {
      if (error.name === 'AbortError') return;
      this.clearPreview();
    }
  }

  renderPreview(result) {
    this.previewStatusTarget.textContent = this.i18nValue.statusLabels[result.status] || result.status;
    this.previewEmissionTarget.textContent = result.emissionKgCo2e === null
      ? '—'
      : `${this.formatDecimal(result.emissionKgCo2e)} kg CO₂e`;

    const lines = [];
    if (result.normalizedAmount !== null) {
      lines.push(`${this.formatDecimal(result.normalizedAmount)} ${result.normalizedUnit || ''}`.trim());
    }

    (result.factorTraces || []).forEach((trace) => {
      const parts = [];

      if (trace.ruleType === 'NON_WASTE_ROUTE_ZERO') {
        parts.push(this.i18nValue.nonWasteZero);
      } else if (trace.ruleType === 'DERIVED_MAX_VALID_TREATMENTS') {
        parts.push(this.i18nValue.unknownRule);
      }

      if (trace.resolvedTreatment) {
        parts.push(`${this.i18nValue.chosenTreatment}: ${trace.resolvedTreatment}`);
      }
      if (trace.factorValue !== null && trace.factorValue !== undefined) {
        parts.push(`${this.formatDecimal(trace.factorValue)} ${trace.factorUnit || ''}`.trim());
      }
      if (trace.source) parts.push(trace.source);
      if (trace.sourceDetail) parts.push(trace.sourceDetail);
      if (trace.factorYear) parts.push(String(trace.factorYear));
      if (trace.temporalType === 'VERSIONED') parts.push('VERSIONED');
      if (trace.temporalType === 'RULE') parts.push('RULE');
      if (trace.isFallback) parts.push(this.i18nValue.fallback);
      if (trace.isGeographicProxy) {
        parts.push(trace.sourceGeography
          ? `${this.i18nValue.geographicProxy}: ${trace.sourceGeography}`
          : this.i18nValue.geographicProxy);
      }

      if (parts.length > 0) lines.push(parts.join(' · '));
    });

    this.previewTraceTarget.replaceChildren();
    lines.forEach((line) => {
      const item = document.createElement('li');
      item.textContent = line;
      this.previewTraceTarget.append(item);
    });

    this.previewMessagesTarget.replaceChildren();
    (result.messages || []).forEach((message) => {
      const item = document.createElement('li');
      item.textContent = this.i18nValue.messageLabels[message] || message;
      this.previewMessagesTarget.append(item);
    });
  }

  renderPreviewError(message) {
    this.previewStatusTarget.textContent = this.i18nValue.previewError;
    this.previewEmissionTarget.textContent = '—';
    this.previewTraceTarget.replaceChildren();
    this.previewMessagesTarget.replaceChildren();

    if (message) {
      const item = document.createElement('li');
      item.textContent = this.i18nValue.messageLabels[message] || message;
      this.previewMessagesTarget.append(item);
    }
  }

  clearPreview() {
    this.renderPreviewError(null);
  }

  validate(event) {
    if (!this.formTarget.checkValidity()) {
      event.preventDefault();
      this.formTarget.classList.add('was-validated');
    }
  }

  get regionCatalog() {
    const region = this.regionFor(this.countryTarget.value);
    return region ? this.catalogValue[region] : null;
  }

  get selectedType() {
    return (this.regionCatalog?.types || []).find(
      (item) => item.value === this.typeTarget.value,
    ) || null;
  }

  get selectedActivity() {
    const type = this.selectedType;
    if (!type) return null;

    if (type.hasSubactivity) {
      return (type.activities || []).find(
        (item) => item.value === this.activityTarget.value,
      ) || null;
    }

    return type.activities?.[0] || null;
  }

  regionFor(country) {
    if (!country) return null;
    return country === 'ESP' ? 'spain' : 'outside_spain';
  }

  get contextComplete() {
    const common = ['startDate', 'endDate', 'country', 'wasteType', 'weight', 'weightUnit'];
    if (!common.every((name) => Boolean(
      this.formTarget.querySelector(`[name="${name}"]`)?.value,
    ))) {
      return false;
    }
    if (this.selectedType?.hasSubactivity && !this.activityTarget.value) {
      return false;
    }

    return Boolean(this.treatmentTarget.value);
  }

  formatDecimal(value) {
    const text = String(value);
    const trimmed = text.includes('.')
      ? text.replace(/0+$/, '').replace(/\.$/, '')
      : text;

    return (document.documentElement.lang || '').toLowerCase().startsWith('es')
      ? trimmed.replace('.', ',')
      : trimmed;
  }
}
