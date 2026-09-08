import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'form', 'family', 'activity', 'subproductContainer', 'subproduct', 'origin',
    'method', 'inputUnit', 'paperFormat', 'cardboardType', 'woodType',
    'boardFamily', 'boardThickness', 'batteryChemistry', 'batterySize',
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
    this.populateStaticOptions();
    const initialFamily = this.familyForActivity(this.initialValue.activity)?.value || '';
    this.replaceOptions(this.familyTarget, this.catalogValue.families || [], initialFamily);
    this.populateActivities(this.initialValue.activity || '');
    this.populateSubproducts(this.initialValue.subproduct || '');
    this.populateOrigins(this.initialValue.origin || '');
    this.populateMethods(this.initialValue.measurementMethod || '');
    this.updateQuantityFields();
    this.queuePreview();
  }

  disconnect() {
    clearTimeout(this.previewTimer);
    this.previewRequest?.abort();
  }

  familyChanged() {
    this.populateActivities('');
    this.populateSubproducts('');
    this.populateOrigins('');
    this.populateMethods('');
    this.updateQuantityFields();
    this.queuePreview();
  }

  activityChanged() {
    this.populateSubproducts('');
    this.populateOrigins('');
    this.queuePreview();
  }

  subproductChanged() {
    this.syncBatteryChemistry();
    this.populateOrigins('');
    this.queuePreview();
  }

  methodChanged() {
    this.updateQuantityFields();
    this.queuePreview();
  }

  boardFamilyChanged() {
    const selected = this.initialValue.boardFamily === this.boardFamilyTarget.value
      ? (this.initialValue.boardThickness || '')
      : '';
    this.replaceOptions(
      this.boardThicknessTarget,
      (this.catalogValue.woodBoards?.[this.boardFamilyTarget.value] || []).map((value) => ({ value, label: value })),
      selected,
    );
    this.queuePreview();
  }

  populateStaticOptions() {
    this.replaceOptions(this.paperFormatTarget, this.asOptions(this.catalogValue.paperFormats), this.initialValue.paperFormat || '');
    this.replaceOptions(this.cardboardTypeTarget, this.asOptions(this.catalogValue.cardboardTypes), this.initialValue.cardboardType || '');
    this.replaceOptions(this.woodTypeTarget, this.asOptions(this.catalogValue.woodTypes), this.initialValue.woodType || '');
    this.replaceOptions(this.boardFamilyTarget, this.asOptions(Object.keys(this.catalogValue.woodBoards || {})), this.initialValue.boardFamily || '');
    this.replaceOptions(this.batterySizeTarget, this.asOptions(this.catalogValue.batterySizes), this.initialValue.batterySize || '');
    this.boardFamilyChanged();
  }

  populateActivities(selected) {
    this.replaceOptions(this.activityTarget, this.selectedFamily?.activities || [], selected);
    if ((this.selectedFamily?.activities || []).length === 1) this.activityTarget.value = this.selectedFamily.activities[0].value;
  }

  populateSubproducts(selected) {
    const subproducts = this.selectedActivity?.subproducts || [];
    const needsSelection = !(subproducts.length === 1 && subproducts[0].value === '');
    this.subproductContainerTarget.classList.toggle('d-none', !needsSelection);
    this.subproductTarget.disabled = !needsSelection;
    this.subproductTarget.required = needsSelection;
    this.replaceOptions(this.subproductTarget, needsSelection ? subproducts : [], selected);
    if (!needsSelection) this.subproductTarget.value = '';
    this.syncBatteryChemistry();
  }

  populateOrigins(selected) {
    const origins = this.selectedSubproduct?.origins || [];
    this.replaceOptions(this.originTarget, this.asOptions(origins, true), selected);
    if (origins.length === 1) this.originTarget.value = origins[0];
  }

  populateMethods(selected) {
    const methods = (this.selectedFamily?.methods || []).map((value) => ({
      value,
      label: this.i18nValue.methods[value] || value,
    }));
    this.replaceOptions(this.methodTarget, methods, selected);
    if (methods.length === 1) this.methodTarget.value = methods[0].value;
  }

  updateQuantityFields() {
    this.element.querySelectorAll('[data-material-field]').forEach((container) => {
      container.classList.add('d-none');
      container.querySelectorAll('input, select').forEach((field) => { field.disabled = true; });
    });

    const family = this.familyTarget.value;
    const method = this.methodTarget.value;
    if (method === 'weight') this.showFields(['inputQuantity', 'inputUnit'], ['kg']);
    if (method === 'surface') this.showFields(['inputQuantity', 'inputUnit'], ['m²']);
    if (method === 'volume') {
      this.showFields(['inputQuantity', 'inputUnit'], family === 'solvent' ? ['l', 'ml', 'cl', 'gal_us'] : ['l']);
    }
    if (method === 'packages') this.showFields(['inputQuantity', 'paperFormat', 'sheetsPerPackage']);
    if (method === 'grammage') this.showFields(['paperFormat', 'unitCount', 'grammage']);
    if (method === 'units') {
      this.showFields(['unitCount']);
      if (['metal', 'plasterboard'].includes(family)) this.showFields(['pieceWeight']);
      if (family === 'battery') this.showFields(['batterySize']);
    }
    if (method === 'dimensions') {
      this.showFields(['length', 'width', 'unitCount', 'grammage']);
      if (family === 'wood') this.showFields(['thickness', 'woodType', 'boardFamily', 'boardThickness']);
      if (family === 'cardboard') this.showFields(['cardboardType']);
    }
  }

  showFields(names, units = null) {
    names.forEach((name) => {
      const container = this.element.querySelector(`[data-material-field="${name}"]`);
      if (!container) return;
      container.classList.remove('d-none');
      container.querySelectorAll('input, select').forEach((field) => { field.disabled = false; });
    });
    if (units) {
      this.replaceOptions(this.inputUnitTarget, this.asOptions(units, true), this.initialValue.inputUnit || units[0]);
      if (units.length === 1) this.inputUnitTarget.value = units[0];
    }
  }

  syncBatteryChemistry() {
    this.batteryChemistryTarget.value = this.selectedSubproduct?.normalizationValue || '';
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

  asOptions(values = [], preserveEmpty = false) {
    return values.map((value) => ({
      value,
      label: value === '' && preserveEmpty ? '—' : value,
    }));
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
      if (error.name !== 'AbortError') this.clearPreview();
    }
  }

  renderPreview(result) {
    this.previewStatusTarget.textContent = this.i18nValue.statuses[result.status] || result.status;
    this.previewEmissionTarget.textContent = result.emissionKgCo2e === null
      ? '—'
      : `${this.formatDecimal(result.emissionKgCo2e)} kg CO₂e`;
    const lines = [];
    if (result.normalizedAmount !== null) {
      lines.push(`${this.formatDecimal(result.normalizedAmount)} ${result.normalizedUnit || ''}`.trim());
    }
    (result.factorTraces || []).forEach((trace) => {
      const parts = [];
      if (trace.factorValue !== null) parts.push(`${this.formatDecimal(trace.factorValue)} ${trace.factorUnit || ''}`.trim());
      if (trace.source) parts.push(trace.source);
      if (trace.sourceDetail) parts.push(trace.sourceDetail);
      if (trace.temporalType) parts.push(trace.temporalType);
      if (trace.factorYear) parts.push(String(trace.factorYear));
      if (trace.metadata?.factorVersion) parts.push(`${this.i18nValue.factorVersion}: ${trace.metadata.factorVersion}`);
      if (trace.isFallback) parts.push(this.i18nValue.fallback);
      if (parts.length) lines.push(parts.join(' · '));
    });
    this.renderList(this.previewTraceTarget, lines, null);
    this.renderList(this.previewMessagesTarget, result.messages || [], this.i18nValue.messageLabels);
  }

  renderPreviewError(message) {
    this.previewStatusTarget.textContent = this.i18nValue.previewError;
    this.previewEmissionTarget.textContent = '—';
    this.renderList(this.previewTraceTarget, [], null);
    this.renderList(this.previewMessagesTarget, message ? [message] : [], this.i18nValue.messageLabels);
  }

  renderList(target, values, labels) {
    target.replaceChildren();
    values.forEach((value) => {
      const item = document.createElement('li');
      item.textContent = labels?.[value] || value;
      target.append(item);
    });
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

  familyForActivity(activity) {
    return (this.catalogValue.families || []).find((family) =>
      (family.activities || []).some((item) => item.value === activity)) || null;
  }

  get selectedFamily() {
    return (this.catalogValue.families || []).find((item) => item.value === this.familyTarget.value) || null;
  }

  get selectedActivity() {
    return (this.selectedFamily?.activities || []).find((item) => item.value === this.activityTarget.value) || null;
  }

  get selectedSubproduct() {
    const items = this.selectedActivity?.subproducts || [];
    if (items.length === 1 && items[0].value === '') return items[0];
    return items.find((item) => item.value === this.subproductTarget.value) || null;
  }

  get contextComplete() {
    const common = ['startDate', 'endDate', 'country', 'activity', 'measurementMethod'];
    if (!common.every((name) => Boolean(this.formTarget.querySelector(`[name="${name}"]`)?.value))) return false;
    if (!this.selectedSubproduct) return false;
    const origins = this.selectedSubproduct.origins || [];
    return origins.includes('') || Boolean(this.originTarget.value);
  }

  formatDecimal(value) {
    const text = String(value);
    const trimmed = text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text;
    return (document.documentElement.lang || '').toLowerCase().startsWith('es') ? trimmed.replace('.', ',') : trimmed;
  }
}
