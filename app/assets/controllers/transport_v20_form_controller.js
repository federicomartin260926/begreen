import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'form', 'mode', 'method', 'carSizeFields', 'carSize', 'vehicleTypeFields', 'vehicleType',
    'fuelFields', 'fuel', 'thermalFuelFields', 'thermalFuel',
    'routeFields', 'origin', 'destination', 'originSuggestions', 'destinationSuggestions', 'originLatitude', 'originLongitude',
    'destinationLatitude', 'destinationLongitude', 'tripTypeFields', 'tripType', 'stopsFields', 'stopsNotice',
    'routeButton', 'routeMessage', 'activityFields', 'activityLabel', 'activityValue', 'activityUnit',
    'weightFields', 'weightValue', 'weightUnit', 'passengerFields', 'passengers', 'operatorFields',
    'operatorReference', 'submit', 'startDate', 'endDate',
    'previewStatus', 'previewEmission', 'previewTrace', 'previewMessages',
  ];

  static values = {
    config: Object,
    initial: Object,
    i18n: Object,
    autocompleteUrl: String,
    distanceUrl: String,
    previewUrl: String,
    previewToken: String,
  };

  connect() {
    this.startDateChanged();
    this.populateFixedOptions();
    this.refreshModes(this.initialValue.mode);
    this.refreshMethods(this.initialValue.method);
    this.renderFields(false);
    this.queuePreview();
  }

  startDateChanged() {
    this.endDateTarget.min = this.startDateTarget.value;
  }

  queuePreview() {
    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.preview(), 300);
  }

  async preview() {
    if (!this.formTarget.checkValidity()) {
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
    this.previewStatusTarget.textContent = this.i18nValue.previewCalculating;

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
      if (!response.ok || result.error) throw new Error(result.error || 'preview_failed');
      this.renderPreview(result);
    } catch (error) {
      if (error.name !== 'AbortError') this.renderPreviewError(error.message);
    }
  }

  renderPreview(result) {
    this.previewStatusTarget.textContent = this.i18nValue.statusLabels[result.status] || result.status;
    this.previewEmissionTarget.textContent = result.generatedKgCo2e === null
      ? '—'
      : `${this.formatDecimal(result.generatedKgCo2e)} kg CO₂e`;

    const lines = [];
    if (result.normalizedActivityValue !== null) {
      lines.push(`${this.i18nValue.previewNormalized}: ${this.formatDecimal(result.normalizedActivityValue)} ${result.normalizedActivityUnit || ''}`.trim());
    }
    lines.push(`${this.i18nValue.previewActivityYear}: ${result.activityYear}`);
    if (result.factorValue !== null) {
      const factor = `${this.i18nValue.previewFactor}: ${this.formatDecimal(result.factorValue)} ${result.factorUnit || ''}`.trim();
      const provenance = [result.source, result.sourceDetail].filter(Boolean).join(' · ');
      lines.push(provenance ? `${factor} · ${provenance}` : factor);
    } else if (result.source) {
      lines.push(result.source);
    }
    if (result.factorYear !== null) {
      lines.push(`${this.i18nValue.previewFactorYear}: ${result.factorYear}${result.fallback ? ` · ${this.i18nValue.previewFallback}` : ''}`);
    }
    if (result.fallbackReason) {
      lines.push(this.i18nValue.fallbackReasonLabels[result.fallbackReason] || result.fallbackReason);
    }
    if (result.factorId) lines.push(`${this.i18nValue.previewFactorId}: ${result.factorId}`);
    if (result.factorVersion) lines.push(`${this.i18nValue.previewFactorVersion}: ${result.factorVersion}`);
    if (result.isGeographicProxy) {
      lines.push(`${this.i18nValue.previewGeographicProxy}${result.proxyGeography ? `: ${result.proxyGeography}` : ''}`);
    }
    this.previewTraceTarget.replaceChildren();
    lines.forEach((line) => {
      const item = document.createElement('li');
      item.textContent = line;
      this.previewTraceTarget.append(item);
    });

    this.previewMessagesTarget.replaceChildren();
    const message = this.i18nValue.statusMessages[result.status];
    if (message) {
      const item = document.createElement('li');
      item.textContent = message;
      this.previewMessagesTarget.append(item);
    }
  }

  clearPreview() {
    this.previewStatusTarget.textContent = this.i18nValue.previewError;
    this.previewEmissionTarget.textContent = '—';
    this.previewTraceTarget.replaceChildren();
    this.previewMessagesTarget.replaceChildren();
  }

  renderPreviewError(errorKey) {
    this.clearPreview();
    const message = this.i18nValue.errorLabels[errorKey];
    if (!message) return;
    const item = document.createElement('li');
    item.textContent = message;
    this.previewMessagesTarget.append(item);
  }

  formatDecimal(value) {
    const number = Number(value);
    return Number.isFinite(number)
      ? new Intl.NumberFormat(document.documentElement.lang || 'es', { maximumFractionDigits: 6 }).format(number)
      : value;
  }

  disconnect() {
    this.routeRequest?.abort();
    this.originSearchRequest?.abort();
    this.destinationSearchRequest?.abort();
    clearTimeout(this.originSearchTimer);
    clearTimeout(this.destinationSearchTimer);
    clearTimeout(this.previewTimer);
    this.previewRequest?.abort();
  }

  categoryChanged() {
    this.refreshModes(this.modeTarget.value);
    this.modeChanged();
  }

  modeChanged() {
    this.refreshMethods(this.methodTarget.value);
    this.renderFields(true);
  }

  methodChanged() {
    this.renderFields(true);
  }

  vehicleTypeChanged() {
    this.refreshMethods(this.methodTarget.value);
    this.renderFields(true);
  }

  countryChanged(event) {
    if (event?.target?.name === 'country') {
      this.renderFields(false);
      this.refreshMethods(this.methodTarget.value);
      this.renderFields(false);
      return;
    }

    this.refreshActivityUnits(this.methodTarget.value, this.activityUnitTarget.value);
    this.updateOrsAvailability();
  }

  tripTypeChanged() {
    const oneWay = this.activityValueTarget.dataset.oneWayDistance;

    if (this.tripTypeTarget.value === 'multiple') {
      if (oneWay) {
        this.activityValueTarget.value = '';
        delete this.activityValueTarget.dataset.oneWayDistance;
      }
      this.routeMessageTarget.textContent = this.i18nValue.multipleManualDistance;
      this.routeMessageTarget.className = 'small mb-3 text-warning';
      this.updateOrsAvailability();
      return;
    }

    if (oneWay) this.applyRouteDistance(oneWay);
    this.updateOrsAvailability();
  }

  validate(event) {
    this.syncCoordinates();
    if (!this.formTarget.checkValidity()) {
      event.preventDefault();
      this.formTarget.classList.add('was-validated');
    }
  }

  async calculateRoute() {
    const origin = this.originTarget.dataset;
    const destination = this.destinationTarget.dataset;
    if (!origin.lat || !origin.lon || !destination.lat || !destination.lon) {
      this.routeMessageTarget.textContent = this.i18nValue.selectSuggestions;
      this.routeMessageTarget.className = 'small mb-3 text-danger';
      return;
    }

    this.syncCoordinates();
    this.routeRequest?.abort();
    this.routeRequest = new AbortController();
    const requestId = (this.routeRequestId ?? 0) + 1;
    this.routeRequestId = requestId;
    this.routeButtonTarget.disabled = true;
    this.routeButtonTarget.textContent = this.i18nValue.calculating;
    this.routeMessageTarget.textContent = '';

    try {
      const response = await fetch(this.distanceUrlValue, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: JSON.stringify({ lat1: origin.lat, lon1: origin.lon, lat2: destination.lat, lon2: destination.lon }),
        signal: this.routeRequest.signal,
      });
      const data = await response.json();
      if (requestId !== this.routeRequestId) return;
      if (!response.ok || data.kilometers === undefined) throw new Error(data.error || this.i18nValue.genericOrsError);

      this.activityValueTarget.dataset.oneWayDistance = String(data.kilometers);
      this.applyRouteDistance(String(data.kilometers));
      this.routeMessageTarget.textContent = this.i18nValue.distanceReady.replace('%distance%', this.activityValueTarget.value);
      this.routeMessageTarget.className = 'small mb-3 text-success';
    } catch (error) {
      if (error.name === 'AbortError') return;
      this.routeMessageTarget.textContent = error.message || this.i18nValue.genericOrsError;
      this.routeMessageTarget.className = 'small mb-3 text-danger';
    } finally {
      if (requestId === this.routeRequestId) {
        this.routeButtonTarget.disabled = false;
        this.routeButtonTarget.textContent = this.i18nValue.calculateDistance;
      }
    }
  }

  searchOrigin(event) {
    this.queueAutocomplete('origin', event.target.value);
  }

  searchDestination(event) {
    this.queueAutocomplete('destination', event.target.value);
  }

  queueAutocomplete(kind, value) {
    const input = kind === 'origin' ? this.originTarget : this.destinationTarget;
    const suggestions = kind === 'origin' ? this.originSuggestionsTarget : this.destinationSuggestionsTarget;
    delete input.dataset.lat;
    delete input.dataset.lon;
    clearTimeout(this[`${kind}SearchTimer`]);
    this[`${kind}SearchRequest`]?.abort();
    suggestions.replaceChildren();

    const query = value.trim();
    if (!this.canUseOrs || query.length < 3) return;
    this[`${kind}SearchTimer`] = setTimeout(() => this.loadSuggestions(kind, query), 250);
  }

  async loadSuggestions(kind, query) {
    const request = new AbortController();
    this[`${kind}SearchRequest`] = request;
    const requestId = (this[`${kind}SearchRequestId`] ?? 0) + 1;
    this[`${kind}SearchRequestId`] = requestId;
    const suggestions = kind === 'origin' ? this.originSuggestionsTarget : this.destinationSuggestionsTarget;
    const input = kind === 'origin' ? this.originTarget : this.destinationTarget;

    try {
      const response = await fetch(`${this.autocompleteUrlValue}?text=${encodeURIComponent(query)}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: request.signal,
      });
      const data = await response.json();
      if (requestId !== this[`${kind}SearchRequestId`]) return;
      if (!response.ok) throw new Error(data.error || this.i18nValue.genericOrsError);

      suggestions.replaceChildren();
      (Array.isArray(data.results) ? data.results : []).forEach((result) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'ors-suggestion border-0 bg-transparent text-start w-100';
        item.textContent = result.label;
        item.addEventListener('mousedown', (event) => {
          event.preventDefault();
          input.value = result.label;
          input.dataset.lat = result.latitude;
          input.dataset.lon = result.longitude;
          suggestions.replaceChildren();
          this.syncCoordinates();
        });
        suggestions.appendChild(item);
      });
    } catch (error) {
      if (error.name === 'AbortError') return;
      suggestions.replaceChildren();
      const item = document.createElement('div');
      item.className = 'ors-suggestion text-danger';
      item.textContent = this.i18nValue.genericOrsError;
      suggestions.appendChild(item);
    }
  }

  populateFixedOptions() {
    const initial = this.initialValue;
    this.fillSelect(this.carSizeTarget, this.configValue.carSizes, initial.carSize);
    this.fillSelect(this.vehicleTypeTarget, this.configValue.vehicleTypes, initial.vehicleType);
    this.fillSelect(this.thermalFuelTarget, this.configValue.thermalFuels, initial.thermalFuel);
    this.fillSelect(this.tripTypeTarget, this.configValue.tripTypes, initial.tripType || 'one_way');
    this.fillSelect(this.weightUnitTarget, this.configValue.weightUnits, initial.weightUnit);
  }

  refreshModes(preferred) {
    const modes = this.configValue.categories[this.category] || [];
    this.fillSelect(this.modeTarget, modes, modes.includes(preferred) ? preferred : modes[0], false);
  }

  refreshMethods(preferred) {
    let methods = this.configValue.methodsByMode[this.modeTarget.value] || [];
    if (this.modeTarget.value === 'car' && this.vehicleTypeTarget.value) {
      methods = this.configValue.carTypeMethods[this.vehicleTypeTarget.value] || [];
    }
    const geographicExclusions = this.isSpain
      ? this.configValue.outsideSpainOnlyMethodsByMode
      : this.configValue.spainOnlyMethodsByMode;
    methods = methods.filter((method) => !(geographicExclusions[this.modeTarget.value] || []).includes(method));
    this.fillSelect(this.methodTarget, methods, methods.includes(preferred) ? preferred : methods[0], false, true);
  }

  renderFields(clearInactive) {
    const mode = this.modeTarget.value;
    const method = this.methodTarget.value;
    const isCar = mode === 'car';
    const taxiSpainNeedsVehicleType = mode === 'taxi'
      && this.isSpain
      && ['distance', 'route'].includes(method);

    const vehicleTypeOptions = taxiSpainNeedsVehicleType
      ? this.configValue.taxiSpainVehicleTypes
      : (isCar && this.isSpain ? this.configValue.carSpainVehicleTypes : this.configValue.vehicleTypes);
    const preferredVehicleType = this.vehicleTypeTarget.value || this.initialValue.vehicleType;
    this.fillSelect(
      this.vehicleTypeTarget,
      vehicleTypeOptions,
      vehicleTypeOptions.includes(preferredVehicleType) ? preferredVehicleType : null,
    );

    const vehicleType = this.vehicleTypeTarget.value;
    const isRoute = ['route', 'route_stops', 'route_weight'].includes(method);
    const hasTripType = method === 'route';
    const hasWeight = ['weight_distance', 'route_weight'].includes(method);
    const needsPassengers = ['distance', 'route', 'route_stops'].includes(method)
      && this.configValue.passengersByDistanceModes.includes(mode);

    this.toggle(this.carSizeFieldsTarget, isCar && !this.isSpain && method === 'distance', clearInactive);
    this.toggle(this.vehicleTypeFieldsTarget, isCar || taxiSpainNeedsVehicleType, clearInactive);

    const showFuelChoice = method === 'fuel' && !isCar;
    this.toggle(this.fuelFieldsTarget, showFuelChoice, clearInactive);
    if (showFuelChoice) {
      const fuels = this.configValue.fuelsByMode[mode] || [];
      const preferredFuel = clearInactive ? null : (this.fuelTarget.value || this.initialValue.fuel);
      this.fillSelect(this.fuelTarget, fuels, preferredFuel);
      this.fuelTarget.required = true;
    } else if (method === 'fuel' && isCar && ['petrol', 'diesel', 'lpg', 'cng'].includes(vehicleType)) {
      this.fillSelect(this.fuelTarget, [vehicleType], vehicleType, false);
      this.fuelTarget.disabled = false;
      this.fuelTarget.required = true;
    }

    const showThermalFuel = isCar
      && vehicleType === 'hev'
      && method === 'fuel';
    this.toggle(this.thermalFuelFieldsTarget, showThermalFuel, clearInactive);
    this.thermalFuelTarget.required = showThermalFuel;

    this.toggle(this.routeFieldsTarget, isRoute, clearInactive);
    this.toggle(this.tripTypeFieldsTarget, hasTripType, clearInactive);
    this.toggle(this.stopsFieldsTarget, method === 'route_stops', clearInactive);
    this.stopsNoticeTarget.hidden = method !== 'route_stops';
    this.updateOrsAvailability();
    this.originTarget.required = isRoute;
    this.destinationTarget.required = isRoute;
    this.tripTypeTarget.required = hasTripType;

    this.toggle(this.weightFieldsTarget, hasWeight, clearInactive);
    this.weightValueTarget.required = hasWeight;
    this.weightUnitTarget.required = hasWeight;
    this.toggle(this.passengerFieldsTarget, needsPassengers, clearInactive);
    this.passengersTarget.required = needsPassengers;
    this.toggle(this.operatorFieldsTarget, method === 'operator', clearInactive);
    this.operatorReferenceTarget.required = method === 'operator';

    this.activityLabelTarget.textContent = this.i18nValue.activityLabels[method] || '';
    this.refreshActivityUnits(method, clearInactive ? null : this.initialValue.activityUnit);
  }

  refreshActivityUnits(method, preferred) {
    let units = this.configValue.unitsByMethod[method] || [];
    if (method === 'fuel') units = this.fuelUnits();
    this.fillSelect(this.activityUnitTarget, units, units.includes(preferred) ? preferred : units[0], false);
  }

  fuelUnits() {
    const fuel = this.modeTarget.value === 'car'
      ? (['hev', 'phev'].includes(this.vehicleTypeTarget.value) ? this.thermalFuelTarget.value : this.vehicleTypeTarget.value)
      : this.fuelTarget.value;
    const isSpain = this.element.querySelector('[name="country"]')?.value.trim().toUpperCase() === 'ES';
    if (isSpain && ['cng', 'lng'].includes(fuel)) return ['kg'];
    return ['L', 'us_gal', 'imp_gal'];
  }

  applyRouteDistance(oneWay) {
    const multiplier = this.tripTypeTarget.value === 'round_trip' ? 2 : 1;
    this.activityValueTarget.value = String(Math.round(Number(oneWay) * multiplier * 100) / 100);
    this.activityUnitTarget.value = 'km';
    this.queuePreview();
  }

  syncCoordinates() {
    this.originLatitudeTarget.value = this.originTarget.dataset.lat || '';
    this.originLongitudeTarget.value = this.originTarget.dataset.lon || '';
    this.destinationLatitudeTarget.value = this.destinationTarget.dataset.lat || '';
    this.destinationLongitudeTarget.value = this.destinationTarget.dataset.lon || '';
  }

  updateOrsAvailability() {
    this.routeButtonTarget.hidden = !this.canUseOrs;
    if (!this.canUseOrs) {
      this.originSuggestionsTarget.replaceChildren();
      this.destinationSuggestionsTarget.replaceChildren();
    }
  }

  toggle(container, visible, clear) {
    container.hidden = !visible;
    container.querySelectorAll('[name]').forEach((field) => {
      field.disabled = !visible;
      if (!visible && clear) {
        if (field.tagName === 'SELECT') field.selectedIndex = 0;
        else field.value = '';
        delete field.dataset.lat;
        delete field.dataset.lon;
      }
    });
  }

  fillSelect(select, codes, selected, placeholder = true, useMethodLabels = false) {
    select.replaceChildren();
    if (placeholder) select.add(new Option(this.i18nValue.select, ''));
    codes.forEach((code) => {
      const labels = useMethodLabels ? this.i18nValue.methodLabels : this.labelMap(select);
      select.add(new Option(labels[code] || code, code, false, code === selected));
    });
    if (!select.value && !placeholder && codes.length) select.value = codes[0];
  }

  labelMap(select) {
    if (select === this.modeTarget) return this.i18nValue.modeLabels;
    if ([this.activityUnitTarget, this.weightUnitTarget].includes(select)) return this.i18nValue.unitLabels;
    if (select === this.vehicleTypeTarget) return this.i18nValue.vehicleTypeLabels;
    if (select === this.carSizeTarget) return this.i18nValue.carSizeLabels;
    if ([this.fuelTarget, this.thermalFuelTarget].includes(select)) return this.i18nValue.fuelLabels;
    return this.i18nValue.tripTypeLabels;
  }

  get category() {
    return this.element.querySelector('[name="category"]:checked')?.value || '';
  }

  get isSpain() {
    return this.element.querySelector('[name="country"]')?.value.trim().toUpperCase() === 'ES';
  }

  get canUseOrs() {
    const country = this.element.querySelector('[name="country"]')?.value.trim().toUpperCase();
    const method = this.methodTarget.value;

    if (method === 'route' && this.tripTypeTarget.value === 'multiple') {
      return false;
    }

    return country === 'ES'
      && ['route', 'route_stops'].includes(method)
      && this.configValue.orsRoadModes.includes(this.modeTarget.value);
  }
}
