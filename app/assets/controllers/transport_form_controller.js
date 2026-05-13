// assets/controllers/transport_form_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'subCategory',
    'activitySelect',
    'activityRow',
    'dynamicFields',
    'detailsField',
    'amountWrapper',
    'unitLabel',
    'calcularDistanciaBtn',
  ];

  static values = {
    calculationDetails: String,
    categoryId: Number,
    activityId: String,
    i18n: Object, // 👈 textos traducidos inyectados desde Twig
  };

  // ---- Utils i18n/UI ----
  t(key) {
    return (this.hasI18nValue && this.i18nValue?.[key]) || key;
  }
  setBtnLoading(btn) {
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${this.t('calculating')}`;
  }
  setBtnIdle(btn) {
    if (!btn) return;
    btn.disabled = false;
    btn.textContent = this.t('calc_distance');
  }

  connect() {
    this.loadStoredValues();
    this.subCategoryTarget?.addEventListener('change', () => this.updateFormFields());
    this.updateFormFields();

    const form = this.element.querySelector('form');
    form.addEventListener('submit', (event) => this.handleFormSubmit(event, form));
    this.activitySelectTarget.addEventListener('change', () => this.updateUnitLabel());
  }

  handleFormSubmit(event, form) {
    // Validación HTML5 normal
    if (!form.checkValidity()) {
      event.preventDefault();
      form.classList.add('was-validated');
      return;
    }

    // Validación manual del campo readonly (amount)
    const amountInput = form.querySelector('#transport_emission_amount');
    if (amountInput && (!amountInput.value || isNaN(amountInput.value) || Number(amountInput.value) <= 0)) {
      event.preventDefault();
      amountInput.classList.add('is-invalid');
      amountInput.focus();

      // Mensaje de error debajo
      if (!amountInput.nextElementSibling || !amountInput.nextElementSibling.classList.contains('invalid-feedback')) {
        const msg = document.createElement('div');
        msg.className = 'invalid-feedback d-block';
        msg.textContent = this.t('must_calc_amount_first');
        amountInput.parentNode.appendChild(msg);
      }
      return;
    } else if (amountInput) {
      amountInput.classList.remove('is-invalid');
      if (amountInput.nextElementSibling && amountInput.nextElementSibling.classList.contains('invalid-feedback')) {
        amountInput.nextElementSibling.remove();
      }
    }

    // Guarda calculationDetails
    this.saveCalculationDetails(event);
  }

  loadStoredValues() {
    if (this.hasCalculationDetailsValue && this.calculationDetailsValue.trim()) {
      try {
        const stored = JSON.parse(this.calculationDetailsValue);
        if (stored.subCategory && this.subCategoryTarget) {
          this.subCategoryTarget.value = stored.subCategory;
        }
      } catch {
        console.warn(this.t('parse_error'));
      }
    }

    if (this.subCategoryTarget && this.subCategoryTarget.value) {
      this.updateFormFields();
    }
  }

  updateFormFields() {
    const subCategory = this.subCategoryTarget.value;
    const template = document.getElementById('tpl-transport');

    // Mostrar u ocultar #dynamic-fields según selección
    if (!subCategory || !template) {
      this.dynamicFieldsTarget.classList.add('d-none');
      this.dynamicFieldsTarget.innerHTML = '';
      this.amountWrapperTarget.classList.add('d-none');
      return;
    }

    this.dynamicFieldsTarget.classList.remove('d-none');
    this.dynamicFieldsTarget.innerHTML = template.innerHTML;
    this.amountWrapperTarget.classList.remove('d-none');

    // Mostrar/ocultar campo vehículos solo para carretera
    const vehiculosRow = this.dynamicFieldsTarget.querySelector('[data-transport-form-target="vehiculosRow"]');
    if (vehiculosRow) {
      if (subCategory === 'carretera') {
        vehiculosRow.classList.remove('d-none');
      } else {
        vehiculosRow.classList.add('d-none');
      }
    }

    // Mostrar/ocultar campo personas solo para ferroviario, maritimo, aereo, otros
    const personasRow = this.dynamicFieldsTarget.querySelector('[data-transport-form-target="personasRow"]');
    if (personasRow) {
      if (['ferroviario', 'maritimo', 'aereo', 'otros'].includes(subCategory)) {
        personasRow.classList.remove('d-none');
      } else {
        personasRow.classList.add('d-none');
      }
    }

    // Cargar datos guardados si existen
    let storedData = {};
    try {
      storedData = this.hasCalculationDetailsValue ? JSON.parse(this.calculationDetailsValue || '{}') : {};
    } catch {
      console.warn(this.t('json_malformed'));
    }
    const inputs = this.dynamicFieldsTarget.querySelectorAll('[data-field]');
    inputs.forEach((input) => {
      const key = input.dataset.field;
      if (Object.prototype.hasOwnProperty.call(storedData, key)) {
        if (input.type === 'checkbox') {
          input.checked = !!storedData[key];
        } else {
          input.value = storedData[key];
        }
      }
    });

    // Botoneras de toggles
    const viajeToggle = this.dynamicFieldsTarget.querySelector('[data-transport-form-target="viajeToggle"]');
    if (viajeToggle) {
      const hiddenInput = viajeToggle.querySelector('input[data-field="tipo_viaje"]');
      const value = hiddenInput.value;
      const buttons = viajeToggle.querySelectorAll('button[data-value]');
      let found = false;
      buttons.forEach((btn) => {
        if (btn.dataset.value === value) {
          this.selectTipoViaje({ currentTarget: btn });
          found = true;
        }
      });
      if (!found && buttons.length) {
        this.selectTipoViaje({ currentTarget: buttons[0] });
      }
    }

    const calculoToggle = this.dynamicFieldsTarget.querySelector('[data-transport-form-target="calculoToggle"]');
    if (calculoToggle) {
      const hiddenInput = calculoToggle.querySelector('input[data-field="tipo_calculo"]');
      const value = hiddenInput.value;
      const buttons = calculoToggle.querySelectorAll('button[data-value]');
      let found = false;
      buttons.forEach((btn) => {
        if (btn.dataset.value === value) {
          this.selectTipoCalculo({ currentTarget: btn });
          found = true;
        }
      });
      if (!found && buttons.length) {
        this.selectTipoCalculo({ currentTarget: buttons[0] });
      }
    }

    // Listeners para recalcular
    const distanciaInput = this.dynamicFieldsTarget.querySelector('input[data-field="distancia_valor"]');
    const unidadSelect = this.dynamicFieldsTarget.querySelector('select[data-field="distancia_unidad"]');
    const vehiculosInput = this.dynamicFieldsTarget.querySelector('input[data-field="vehiculos"]');
    const personasInput = this.dynamicFieldsTarget.querySelector('input[data-field="personas"]');

    distanciaInput?.addEventListener('input', () => this.calculateAmount());
    unidadSelect?.addEventListener('change', () => this.calculateAmount());
    vehiculosInput?.addEventListener('input', () => this.calculateAmount());
    personasInput?.addEventListener('input', () => this.calculateAmount());

    // Mostrar/ocultar actividad
    if (this.hasActivityRowTarget) {
      this.activityRowTarget.style.display = subCategory ? '' : 'none';
    }

    // Inicializa el cálculo
    this.calculateAmount();

    // Actividades
    this.updateActivitySelect();

    // Calcular distancias
    const calcularBtn = this.dynamicFieldsTarget.querySelector('#calcular-distancia-btn');
    if (calcularBtn) {
      calcularBtn.textContent = this.t('calc_distance');
      calcularBtn.addEventListener('click', () => this.calcularDistancia());
    }

    const recalcularBtn = this.dynamicFieldsTarget.querySelector('#recalcular-distancia-btn');
    if (recalcularBtn) {
      recalcularBtn.textContent = this.t('recalc');
    }

    this.toggleUbicacionesButtons();
  }

  activityReqId = 0;

  updateActivitySelect() {
    const subCategory = this.subCategoryTarget?.value;
    const activitySelect = this.activitySelectTarget;

    activitySelect.innerHTML = `<option value="">${this.t('loading')}</option>`;
    activitySelect.disabled = true;

    if (!subCategory) {
      activitySelect.innerHTML = `<option value="">${this.t('select')}</option>`;
      activitySelect.disabled = false;
      return;
    }

    const reqId = ++this.activityReqId;

    const categoryId = this.hasCategoryIdValue ? this.categoryIdValue : null;
    const url = `/index.php/backend/emission/by-subcategory?subcategory=${encodeURIComponent(subCategory)}&categoryId=${encodeURIComponent(categoryId ?? '')}`;
    fetch(url)
      .then((r) => r.json())
      .then((data) => {
        if (reqId !== this.activityReqId) return;

        const seen = new Set();
        activitySelect.innerHTML = `<option value="">${this.t('select')}</option>`;

        data.forEach((activity) => {
          const idStr = String(activity.id);
          if (seen.has(idStr)) return;
          seen.add(idStr);

          const option = document.createElement('option');
          option.value = activity.id;
          option.textContent = activity.name + (activity.unit ? ` (${activity.unit})` : '');
          if (activity.unit) option.dataset.unit = activity.unit;

          if (this.hasActivityIdValue && idStr === String(this.activityIdValue)) {
            option.selected = true;
          }
          activitySelect.appendChild(option);
        });

        activitySelect.disabled = false;
        this.updateUnitLabel();
      })
      .catch(() => {
        activitySelect.innerHTML = `<option value="">${this.t('activities_load_error')}</option>`;
        activitySelect.disabled = false;
      });
  }

  toggleTransportFields() {
    const tipoCalculoHidden = this.dynamicFieldsTarget.querySelector('input[data-field="tipo_calculo"]');
    const tipoCalculo = tipoCalculoHidden?.value;

    const ubicacionesFields = this.dynamicFieldsTarget.querySelector('#transport-ubicaciones-fields');
    const distanciaFields = this.dynamicFieldsTarget.querySelector('#carretera-distancia-fields');

    if (tipoCalculo === 'distancia') {
      ubicacionesFields?.classList.add('d-none');
      distanciaFields?.classList.remove('d-none');
    } else if (tipoCalculo === 'ubicaciones') {
      ubicacionesFields?.classList.remove('d-none');
      distanciaFields?.classList.add('d-none');
    } else {
      ubicacionesFields?.classList.add('d-none');
      distanciaFields?.classList.add('d-none');
    }
  }

  selectTipoViaje(event) {
    const container = event.currentTarget.closest('[data-transport-form-target="viajeToggle"]');
    if (!container) return;

    const hiddenInput = container.querySelector("input[data-field='tipo_viaje']");
    const buttons = container.querySelectorAll('button');

    buttons.forEach((btn) => {
      btn.classList.remove('btn-primary', 'text-white');
      btn.classList.add('btn-outline-primary');
    });
    event.currentTarget.classList.add('btn-primary', 'text-white');
    event.currentTarget.classList.remove('btn-outline-primary');
    hiddenInput.value = event.currentTarget.dataset.value;

    this.calculateAmount();
  }

  selectTipoCalculo(event) {
    const container = event.currentTarget.closest('[data-transport-form-target="calculoToggle"]');
    if (!container) return;

    const hiddenInput = container.querySelector("input[data-field='tipo_calculo']");
    const buttons = container.querySelectorAll('button');

    buttons.forEach((btn) => {
      btn.classList.remove('btn-primary', 'text-white');
      btn.classList.add('btn-outline-primary');
    });
    event.currentTarget.classList.add('btn-primary', 'text-white');
    event.currentTarget.classList.remove('btn-outline-primary');
    hiddenInput.value = event.currentTarget.dataset.value;

    // Limpia campos si seleccionas "ubicaciones"
    if (event.currentTarget.dataset.value === 'ubicaciones') {
      const distanciaInput = this.dynamicFieldsTarget.querySelector('input[data-field="distancia_valor"]');
      const unidadSelect = this.dynamicFieldsTarget.querySelector('select[data-field="distancia_unidad"]');
      if (distanciaInput) distanciaInput.value = '';
      if (unidadSelect) unidadSelect.value = 'km';
      const amountInput = this.amountWrapperTarget?.querySelector('input');
      if (amountInput) amountInput.value = '';
    }

    this.toggleTransportFields();
  }

  saveCalculationDetails() {
    const details = {};
    if (this.subCategoryTarget) {
      details.subCategory = this.subCategoryTarget.value;
    }
    const inputs = this.dynamicFieldsTarget.querySelectorAll('[data-field]');
    inputs.forEach((input) => {
      const key = input.dataset.field;
      details[key] = input.type === 'checkbox' ? input.checked : input.value;
    });
    this.detailsFieldTarget.value = JSON.stringify(details);
  }

  updateUnitLabel() {
    if (!this.hasActivitySelectTarget || !this.hasUnitLabelTarget) return;

    const selectedOption = this.activitySelectTarget.selectedOptions[0];
    const unit = selectedOption?.dataset.unit || '';
    this.unitLabelTarget.textContent = unit ? ` (${unit})` : '';
  }

  calculateAmount() {
    const distanciaInput = this.dynamicFieldsTarget.querySelector('input[data-field="distancia_valor"]');
    const distanciaCalculada = this.dynamicFieldsTarget.querySelector('input[data-field="distancia_calculada"]');
    const unidadSelect = this.dynamicFieldsTarget.querySelector('select[data-field="distancia_unidad"]');
    const viajeHidden = this.dynamicFieldsTarget.querySelector('input[data-field="tipo_viaje"]');
    const tipoCalculoHidden = this.dynamicFieldsTarget.querySelector('input[data-field="tipo_calculo"]');
    const amountInput = this.amountWrapperTarget?.querySelector('input');
    const subCategory = this.subCategoryTarget.value;

    const vehiculosInput = this.dynamicFieldsTarget.querySelector('input[data-field="vehiculos"]');
    const personasInput = this.dynamicFieldsTarget.querySelector('input[data-field="personas"]');

    if (!viajeHidden || !amountInput || !tipoCalculoHidden) {
      if (amountInput) amountInput.value = '';
      return;
    }

    let distancia = 0;
    let factorUnidad = 1;

    if (tipoCalculoHidden.value === 'ubicaciones') {
      distancia = parseFloat(distanciaCalculada?.value?.replace(',', '.')) || 0;
      factorUnidad = 1;
    } else if (tipoCalculoHidden.value === 'distancia') {
      distancia = parseFloat(distanciaInput?.value?.replace(',', '.')) || 0;
      const unidad = unidadSelect?.value || 'km';
      factorUnidad = unidad === 'km' ? 1 : 1.60934;
    } else {
      amountInput.value = '';
      return;
    }

    const tipoViaje = viajeHidden.value;
    const factorViaje = tipoViaje === 'ida_vuelta' ? 2 : 1;

    let total = 0;

    if (subCategory === 'carretera') {
      const vehiculos = parseFloat(vehiculosInput?.value) || 0;
      if (!distancia || !vehiculos || !tipoViaje) {
        amountInput.value = '';
        return;
      }
      total = distancia * factorUnidad * vehiculos * factorViaje;
    } else if (['ferroviario', 'maritimo', 'aereo', 'otros'].includes(subCategory)) {
      const personas = parseFloat(personasInput?.value) || 0;
      if (!distancia || !personas || !tipoViaje) {
        amountInput.value = '';
        return;
      }
      total = distancia * factorUnidad * personas * factorViaje;
    } else {
      amountInput.value = '';
      return;
    }

    amountInput.value = Number.isFinite(total) && total > 0 ? total.toFixed(2) : '';
  }

  toggleUbicacionesButtons() {
    const tipoCalculoHidden = this.dynamicFieldsTarget.querySelector('input[data-field="tipo_calculo"]');
    if (!tipoCalculoHidden || tipoCalculoHidden.value !== 'ubicaciones') return;

    const desdeInput = this.dynamicFieldsTarget.querySelector('#input-desde');
    const hastaInput = this.dynamicFieldsTarget.querySelector('#input-hasta');
    const calcBtn = this.dynamicFieldsTarget.querySelector('#calcular-distancia-btn');
    const recalcularBtn = this.dynamicFieldsTarget.querySelector('#recalcular-distancia-btn');

    if (!desdeInput || !hastaInput || !calcBtn || !recalcularBtn) return;

    const tieneDesde = (desdeInput.value || '').trim().length > 0;
    const tieneHasta = (hastaInput.value || '').trim().length > 0;

    if (tieneDesde && tieneHasta) {
      calcBtn.classList.add('d-none');
      recalcularBtn.classList.remove('d-none');
    } else {
      recalcularBtn.classList.add('d-none');
      calcBtn.classList.remove('d-none');
    }
  }

  reCalcularDistancia() {
    const desdeInput = this.dynamicFieldsTarget.querySelector('#input-desde');
    const hastaInput = this.dynamicFieldsTarget.querySelector('#input-hasta');
    const calcBtn = this.dynamicFieldsTarget.querySelector('#calcular-distancia-btn');
    const recalcularBtn = this.dynamicFieldsTarget.querySelector('#recalcular-distancia-btn');
    const distHidden = this.dynamicFieldsTarget.querySelector('#calculated-distance');
    const msg = this.dynamicFieldsTarget.querySelector('#mensaje-distancia');
    const err = this.dynamicFieldsTarget.querySelector('#distance-error-message');

    if (desdeInput) {
      desdeInput.value = '';
      delete desdeInput.dataset.lat;
      delete desdeInput.dataset.lon;
    }
    if (hastaInput) {
      hastaInput.value = '';
      delete hastaInput.dataset.lat;
      delete hastaInput.dataset.lon;
    }
    if (distHidden) distHidden.value = '';
    if (msg) msg.textContent = '';
    if (err) err.textContent = '';

    // Mostrar Calcular, ocultar Recalcular
    recalcularBtn?.classList.add('d-none');
    calcBtn?.classList.remove('d-none');
    calcBtn.textContent = this.t('calc_distance');

    // Limpia amount
    this.calculateAmount();
  }

  calcularDistancia() {
    const desdeInput = this.dynamicFieldsTarget.querySelector('#input-desde');
    const hastaInput = this.dynamicFieldsTarget.querySelector('#input-hasta');
    const distanceHidden = this.dynamicFieldsTarget.querySelector('input[data-field="distancia_calculada"]');
    const mensajeDistancia = this.dynamicFieldsTarget.querySelector('#mensaje-distancia');
    const errorDiv = this.dynamicFieldsTarget.querySelector('#distance-error-message');
    const button = this.dynamicFieldsTarget.querySelector('#calcular-distancia-btn');
    const recalcularBtn = this.dynamicFieldsTarget.querySelector('#recalcular-distancia-btn');

    if (mensajeDistancia) mensajeDistancia.textContent = '';
    if (errorDiv) errorDiv.textContent = '';

    if (!desdeInput || !hastaInput || !distanceHidden) {
      if (errorDiv) errorDiv.textContent = this.t('missing_required_fields');
      return;
    }

    // Leer lat y lon de los inputs
    const lat1 = desdeInput.dataset.lat;
    const lon1 = desdeInput.dataset.lon;
    const lat2 = hastaInput.dataset.lat;
    const lon2 = hastaInput.dataset.lon;

    if (!lat1 || !lon1 || !lat2 || !lon2) {
      if (errorDiv) errorDiv.textContent = this.t('must_pick_from_suggestions');
      return;
    }

    distanceHidden.value = ''; // Limpia antes del cálculo
    this.setBtnLoading(button);

    fetch('/index.php/backend/emission/calculate-distance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ lat1, lon1, lat2, lon2 }),
    })
      .then((res) => res.json())
      .then((data) => {
        this.setBtnIdle(button);

        if (data.kilometers !== undefined) {
          distanceHidden.value = data.kilometers;
          if (Number(data.kilometers) === 0) {
            if (mensajeDistancia) mensajeDistancia.textContent = '';
            if (errorDiv) errorDiv.textContent = this.t('same_points_error');
          } else {
            if (mensajeDistancia) {
              mensajeDistancia.innerHTML = `
                <i class="bi bi-check-circle-fill text-success me-2"></i>
                ${this.t('distance_ok')} <strong>${data.kilometers} km</strong>
              `;
            }
            if (errorDiv) errorDiv.textContent = '';

            // 🔁 Alternar a "Recalcular"
            button?.classList.add('d-none');
            recalcularBtn?.classList.remove('d-none');
            recalcularBtn.textContent = this.t('recalc');

            // Recalcular amount
            this.calculateAmount();
          }
        } else if (data.error) {
          if (mensajeDistancia) mensajeDistancia.textContent = '';
          if (errorDiv) {
            if (String(data.error).includes('Could not find routable point')) {
              errorDiv.textContent = this.t('no_road_nearby');
            } else {
              errorDiv.textContent = data.error;
            }
          }
        } else {
          if (mensajeDistancia) mensajeDistancia.textContent = '';
          if (errorDiv) errorDiv.textContent = this.t('generic_calc_error');
        }
      })
      .catch(() => {
        this.setBtnIdle(button);
        if (mensajeDistancia) mensajeDistancia.textContent = '';
        if (errorDiv) errorDiv.textContent = this.t('calc_exception');
      });
  }
}
