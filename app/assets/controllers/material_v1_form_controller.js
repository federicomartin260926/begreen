import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'form', 'family', 'activity', 'subproductContainer', 'subproduct', 'origin',
    'method', 'inputUnit', 'paperFormat', 'cardboardType', 'woodSelection', 'woodSpecies', 'woodType',
    'boardFamily', 'boardThickness', 'batteryChemistry', 'batterySize',
    'sustainabilitySeal', 'cardboardStructure', 'metalMaterial', 'metalForm',
    'clothingGroup',
    'previewStatus', 'previewEmission', 'previewTrace', 'previewMessages',
  ];

  static values = {
    catalog: Object,
    initial: Object,
    i18n: Object,
    previewUrl: String,
    previewToken: String,
  };

  static UNKNOWN_WOOD_TYPE = 'Desconocida / promedio';

  static SOLID_WOOD_SELECTION = 'Madera maciza';

  static UNKNOWN_WOOD_SELECTION = 'Desconocida';

  connect() {
    this.boardThicknessByFamily = {};
    if (this.initialValue.boardFamily && this.initialValue.boardThickness) {
      this.boardThicknessByFamily[this.initialValue.boardFamily] = this.initialValue.boardThickness;
    }
    this.populateStaticOptions();
    const initialFamily = this.familyForActivity(this.initialValue.activity)?.value || '';
    this.replaceOptions(this.familyTarget, this.catalogValue.families || [], initialFamily);
    this.populateSustainabilitySeals(this.initialValue.sustainabilitySeal || '');
    this.populateClothingGroups(this.initialValue.clothingGroup || '');
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
    this.populateSustainabilitySeals('');
    this.populateClothingGroups('');
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

  clothingGroupChanged() {
    const current = this.subproductTarget.value;
    const compatible = (this.selectedClothingGroup?.subproducts || [])
      .some((item) => item.value === current);
    this.populateSubproducts(compatible ? current : '');
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

  woodSelectionChanged() {
    this.rememberBoardThickness();
    this.syncWoodSelection();
    this.updateQuantityFields();
    this.queuePreview();
  }

  woodSpeciesChanged() {
    if (this.woodSelectionTarget.value === this.constructor.SOLID_WOOD_SELECTION) {
      this.woodTypeTarget.value = this.woodSpeciesTarget.value;
    }
    this.queuePreview();
  }

  boardThicknessChanged() {
    this.rememberBoardThickness();
    this.updateQuantityFields();
    this.queuePreview();
  }

  populateStaticOptions() {
    this.replaceOptions(this.paperFormatTarget, this.asOptions(this.catalogValue.paperFormats), this.initialValue.paperFormat || '');
    this.replaceOptions(this.cardboardTypeTarget, this.asOptions(this.catalogValue.cardboardTypes), this.initialValue.cardboardType || '');
    const solidWoodTypes = (this.catalogValue.woodTypes || [])
      .filter((value) => value !== this.constructor.UNKNOWN_WOOD_TYPE);
    const initialSpecies = this.initialValue.woodType === this.constructor.UNKNOWN_WOOD_TYPE
      ? ''
      : (this.initialValue.woodType || '');
    this.replaceOptions(this.woodSpeciesTarget, this.asOptions(solidWoodTypes), initialSpecies);
    this.replaceOptions(this.boardFamilyTarget, this.asOptions(Object.keys(this.catalogValue.woodBoards || {})), this.initialValue.boardFamily || '');
    this.replaceOptions(this.woodSelectionTarget, this.asOptions(this.catalogValue.woodSelections), this.initialWoodSelection());
    this.replaceOptions(this.batterySizeTarget, this.asOptions(this.catalogValue.batterySizes), this.initialValue.batterySize || '');
    this.replaceOptions(this.cardboardStructureTarget, this.asOptions(this.catalogValue.cardboardStructures), this.initialValue.cardboardStructure || '');
    this.replaceOptions(this.metalMaterialTarget, this.asOptions(this.catalogValue.metalMaterials), this.initialValue.metalMaterial || '');
    this.replaceOptions(this.metalFormTarget, this.asOptions(this.catalogValue.metalForms), this.initialValue.metalForm || '');
    this.syncWoodSelection();
  }

  populateSustainabilitySeals(selected) {
    this.replaceOptions(
      this.sustainabilitySealTarget,
      this.asOptions(this.catalogValue.sustainabilitySeals?.[this.familyTarget.value]),
      selected,
    );
  }

  populateClothingGroups(selected) {
    this.replaceOptions(
      this.clothingGroupTarget,
      (this.catalogValue.clothingGroups || []).map((group) => ({ value: group.value, label: group.value })),
      selected,
    );
  }

  populateActivities(selected) {
    const activities = (this.selectedFamily?.activities || []).map((activity) => ({
      ...activity,
      label: activity.translationKey
        ? (this.i18nValue.plasticActivities?.[activity.translationKey] || activity.label)
        : activity.label,
    }));
    this.replaceOptions(this.activityTarget, activities, selected);
  }

  populateSubproducts(selected) {
    const clothing = this.familyTarget.value === 'clothing';
    const subproducts = clothing
      ? (this.selectedClothingGroup?.subproducts || [])
      : (this.selectedActivity?.subproducts || []);
    const needsSelection = (!clothing || Boolean(this.selectedClothingGroup))
      && !(subproducts.length === 1 && subproducts[0].value === '');
    this.subproductContainerTarget.classList.toggle('d-none', !needsSelection);
    this.subproductTarget.disabled = !needsSelection;
    this.subproductTarget.required = needsSelection;
    this.replaceOptions(this.subproductTarget, needsSelection ? subproducts : [], selected);
    if (!needsSelection) this.subproductTarget.value = '';
    this.syncBatteryChemistry();
  }

  populateOrigins(selected) {
    const origins = this.selectedSubproduct?.origins || [];
    const options = origins.map((origin) => origin === '' ? {
      value: this.catalogValue.purchasedOriginPresentationValue,
      label: this.i18nValue.purchasedOrigin,
    } : { value: origin, label: origin });
    this.replaceOptions(this.originTarget, options, selected);
  }

  populateMethods(selected) {
    const methods = (this.selectedFamily?.methods || []).map((value) => ({
      value,
      label: this.i18nValue.methods[value] || value,
    }));
    this.replaceOptions(this.methodTarget, methods, selected);
  }

  updateQuantityFields() {
    this.element.querySelectorAll('[data-material-field]').forEach((container) => {
      container.classList.add('d-none');
      container.querySelectorAll('input, select').forEach((field) => { field.disabled = true; });
    });
    this.woodTypeTarget.disabled = true;

    const family = this.familyTarget.value;
    const method = this.methodTarget.value;
    if (method === 'weight') {
      const legacyWoodWeight = family === 'wood'
        && this.initialValue.family === 'wood'
        && this.initialValue.measurementMethod === 'weight'
        && this.initialValue.inputQuantity
        && !this.initialValue.pieceWeightKg;

      if (family === 'wood' && !legacyWoodWeight) {
        this.showFields(['pieceWeight', 'unitCount']);
      } else {
        this.showFields(['inputQuantity', 'inputUnit'], ['kg']);
      }
    }
    if (method === 'surface') this.showFields(['inputQuantity', 'inputUnit'], ['m²']);
    if (method === 'volume') {
      this.showFields(['inputQuantity', 'inputUnit'], family === 'solvent' ? ['l', 'ml', 'cl', 'gal_us'] : ['l']);
    }
    if (method === 'packages') this.showFields(['inputQuantity', 'sheetsPerPackage']);
    if (method === 'grammage') this.showFields(['unitCount', 'grammage']);
    if (method === 'units') {
      this.showFields(['unitCount']);
      if (['metal', 'plasterboard'].includes(family)) this.showFields(['pieceWeight']);
      if (family === 'battery') this.showFields(['batterySize']);
    }
    if (method === 'dimensions' && family !== 'wood') {
      this.showFields(['length', 'width', 'unitCount', 'grammage']);
      if (family === 'cardboard') this.showFields(['cardboardType']);
    }
    if (family === 'wood') this.updateWoodFields(method);
    if (family === 'paper' && ['packages', 'grammage'].includes(method)) this.showFields(['paperFormat']);
    if (['wood', 'paper', 'cardboard'].includes(family)) this.showFields(['sustainabilitySeal']);
    if (family === 'cardboard') this.showFields(['cardboardStructure']);
    if (family === 'metal') this.showFields(['metalMaterial', 'metalForm']);
    if (family === 'clothing') this.showFields(['clothingGroup']);
  }

  updateWoodFields(method) {
    this.showFields(['woodSelection']);
    const selection = this.woodSelectionTarget.value;

    if (selection === this.constructor.SOLID_WOOD_SELECTION) {
      this.showFields(['woodSpecies']);
      this.woodTypeTarget.disabled = false;
      this.woodTypeTarget.value = this.woodSpeciesTarget.value;
      if (method === 'dimensions') this.showFields(['length', 'width', 'thickness', 'unitCount']);
      return;
    }

    if (selection === this.constructor.UNKNOWN_WOOD_SELECTION) {
      this.woodTypeTarget.disabled = false;
      this.woodTypeTarget.value = this.constructor.UNKNOWN_WOOD_TYPE;
      if (method === 'dimensions') this.showFields(['length', 'width', 'thickness', 'unitCount']);
      return;
    }

    this.woodTypeTarget.disabled = true;
    if (!this.isBoardSelection(selection)) return;

    this.boardFamilyTarget.value = selection;
    this.boardFamilyTarget.disabled = false;
    if (method !== 'dimensions') return;

    this.showFields(['boardThickness', 'unitCount']);
    if (this.boardThicknessTarget.value === 'Desconocido / manual') {
      // Compatibility exception: the current normalizer still requires all three manual dimensions.
      this.showFields(['length', 'width', 'thickness']);
    }
  }

  initialWoodSelection() {
    const { boardFamily, boardThickness, measurementMethod, woodType } = this.initialValue;
    const hasBoardFamily = this.isBoardSelection(boardFamily);
    const validThicknesses = this.catalogValue.woodBoards?.[boardFamily] || [];
    const hasValidBoard = hasBoardFamily && validThicknesses.includes(boardThickness);

    if (hasValidBoard || (hasBoardFamily && measurementMethod === 'weight')) {
      return boardFamily;
    }
    if (woodType === this.constructor.UNKNOWN_WOOD_TYPE) {
      return this.constructor.UNKNOWN_WOOD_SELECTION;
    }
    if (woodType) return this.constructor.SOLID_WOOD_SELECTION;

    // Keep an incomplete board selection available for completion.
    return hasBoardFamily ? boardFamily : '';
  }

  syncWoodSelection() {
    const selection = this.woodSelectionTarget.value;
    if (this.isBoardSelection(selection)) {
      this.boardFamilyTarget.value = selection;
      this.populateBoardThickness(selection);
      this.woodTypeTarget.value = '';
      return;
    }

    this.boardFamilyTarget.value = '';
    if (selection === this.constructor.UNKNOWN_WOOD_SELECTION) {
      this.woodTypeTarget.value = this.constructor.UNKNOWN_WOOD_TYPE;
    } else if (selection === this.constructor.SOLID_WOOD_SELECTION) {
      this.woodTypeTarget.value = this.woodSpeciesTarget.value;
    } else {
      this.woodTypeTarget.value = '';
    }
  }

  populateBoardThickness(family) {
    const selected = this.boardThicknessByFamily[family]
      || (this.initialValue.boardFamily === family ? (this.initialValue.boardThickness || '') : '');
    this.replaceOptions(
      this.boardThicknessTarget,
      (this.catalogValue.woodBoards?.[family] || []).map((value) => ({ value, label: value })),
      selected,
    );
  }

  rememberBoardThickness() {
    const family = this.boardFamilyTarget.value;
    if (this.isBoardSelection(family) && this.boardThicknessTarget.value) {
      this.boardThicknessByFamily[family] = this.boardThicknessTarget.value;
    }
  }

  isBoardSelection(value) {
    return Boolean(value && this.catalogValue.woodBoards?.[value]);
  }

  showFields(names, units = null) {
    names.forEach((name) => {
      const container = this.element.querySelector(`[data-material-field="${name}"]`);
      if (!container) return;
      container.classList.remove('d-none');
      container.querySelectorAll('input, select').forEach((field) => { field.disabled = false; });
    });
    if (units) {
      this.replaceOptions(this.inputUnitTarget, this.asOptions(units, true), this.inputUnitTarget.value || this.initialValue.inputUnit || '');
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
    let selectionApplied = false;
    options.forEach(({ value, label }) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      option.selected = !selectionApplied && value === selected;
      selectionApplied ||= option.selected;
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

  get selectedClothingGroup() {
    return (this.catalogValue.clothingGroups || [])
      .find((group) => group.value === this.clothingGroupTarget.value) || null;
  }

  get contextComplete() {
    const common = ['startDate', 'endDate', 'country', 'activity', 'measurementMethod'];
    if (!common.every((name) => Boolean(this.formTarget.querySelector(`[name="${name}"]`)?.value))) return false;
    if (!this.selectedSubproduct) return false;
    return Boolean(this.originTarget.value);
  }

  formatDecimal(value) {
    const text = String(value);
    const trimmed = text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text;
    return (document.documentElement.lang || '').toLowerCase().startsWith('es') ? trimmed.replace('.', ',') : trimmed;
  }
}
