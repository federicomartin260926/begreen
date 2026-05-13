import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'subCategory',
    'electricityMethod',
    'electricityMethodWrapper',
    'amountWrapper',
    'dynamicFields',
    'detailsField',
    'unitLabel',
    'unidadTexto',
  ];

  static values = {
    calculationDetails: String,
    i18n: Object, // <<---- aquí entran los textos traducidos
  };

  connect() {
    // 1) Restaurar estado desde calculationDetails
    if (this.hasCalculationDetailsValue && this.calculationDetailsValue.trim()) {
      try {
        const stored = JSON.parse(this.calculationDetailsValue);
        if (stored.subCategory && this.hasSubCategoryTarget) {
          this.subCategoryTarget.value = stored.subCategory;
        }
        if (stored.electricityMethod && this.hasElectricityMethodTarget) {
          this.electricityMethodTarget.value = stored.electricityMethod;
        }
      } catch {
        console.warn(this.t('parseError'));
      }
    }

    // 2) Listeners
    this.subCategoryTarget?.addEventListener('change', () => this.updateSubform());
    this.electricityMethodTarget?.addEventListener('change', () => this.updateSubform());

    // 3) Primera render
    this.updateSubform();
  }

  updateSubform() {
    let storedData = {};
    if (this.hasCalculationDetailsValue && this.calculationDetailsValue.trim()) {
      try {
        storedData = JSON.parse(this.calculationDetailsValue);
      } catch {
        console.warn(this.t('jsonMalformed'));
      }
    }
    this.renderTemplateFromStoredData(storedData);
  }

  renderTemplateFromStoredData(storedData = {}) {
    const subCategory = this.subCategoryTarget?.value || '';
    const method = this.electricityMethodTarget?.value || '';

    // Mostrar/ocultar método de electricidad
    if (subCategory === 'electricidad') {
      this.electricityMethodWrapperTarget?.classList.remove('d-none');
    } else {
      this.electricityMethodWrapperTarget?.classList.add('d-none');
    }

    // Mostrar amount si hay subcategoría
    if (subCategory) {
      this.amountWrapperTarget?.classList.remove('d-none');
    } else {
      this.amountWrapperTarget?.classList.add('d-none');
    }

    // Selección de plantilla
    let templateId = null;
    if (subCategory === 'electricidad') {
      templateId = `tpl-electricidad-${method || 'default'}`;
    } else if (subCategory) {
      templateId = `tpl-${subCategory}`;
    }

    const template = templateId ? document.getElementById(templateId) : null;
    if (template?.innerHTML) {
      this.dynamicFieldsTarget.innerHTML = template.innerHTML;
      $('#dynamic-fields').show();
    } else {
      this.dynamicFieldsTarget.innerHTML = `<div class="text-muted">${this.t('noForm')}</div>`;
      $('#dynamic-fields').hide();
    }

    // Rellenar inputs desde storedData
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

    // === Sincronizaciones / cálculos automáticos ===
    this.syncSimpleAmount([
      'medidor_consumo',
      'gaffer_consumo_estimado',
      'espacio_estimacion_consumo',
      'factura_consumo_estimado',
      'vehiculo_consumo',
      'generador_consumo',
      'remoto_horas',
      'animacion_horas',
      'montaje_horas_funcionamiento',
      'generador_total_combustible',
    ]);

    if (subCategory === 'almacenamiento') this.calcAlmacenamientoOnChange();
    if (subCategory === 'electricidad' && method === 'contador') this.calcFromContador();
    if (subCategory === 'electricidad' && method === 'estimacion_espacio') this.calcEstimacionEspacio();
    if (subCategory === 'electricidad' && method === 'factura') this.calcFactura();
    if (subCategory === 'electricidad' && method === 'vehiculo') this.calcVehiculo();
    if (subCategory === 'gas_caldera') this.calcGasCaldera();
    if (subCategory === 'gas_propano') this.calcGasPropano();
    if (subCategory === 'gas_bombona') this.calcGasBombona();

    // Guardar calculationDetails al enviar
    const form = this.element.querySelector('form');
    form?.addEventListener('submit', () => this.saveCalculationDetails(), { once: true });
  }

  // --- Helpers de cálculo / sync ---

  get amountInput() {
    return this.element.querySelector('#energy_emission_amount');
  }

  setAmount(value) {
    if (!this.amountInput) return;
    const num = parseFloat(value);
    this.amountInput.value = Number.isFinite(num) && num >= 0 ? num.toFixed(2) : '';
  }

  syncSimpleAmount(fieldNames = []) {
    if (!this.amountInput) return;
    fieldNames.forEach((fieldName) => {
      const input = this.dynamicFieldsTarget.querySelector(`[data-field="${fieldName}"]`);
      if (!input) return;
      const update = () => this.setAmount(input.value);
      input.addEventListener('input', update);
      update();
    });
  }

  calcAlmacenamientoOnChange() {
    const keys = [
        'almacenamiento_datos_archivados',
        'almacenamiento_factor_replicacion',
        'almacenamiento_duracion_meses',
    ];

    const update = () => {
        const datos = this.n('almacenamiento_datos_archivados');
        const rep   = this.n('almacenamiento_factor_replicacion', 1);
        const meses = this.n('almacenamiento_duracion_meses', 1);
        const total = +(datos * rep * meses).toFixed(3);
        this.setAmount(total);
    };

    keys.forEach((k) => {
        const el = this.sel(k);
        el?.addEventListener('input', update);
        el?.addEventListener('change', update);
    });

    update();
  }

  calcFromContador() {
    const lecturaInicial = this.sel('contador_lectura_inicial');
    const lecturaFinal = this.sel('contador_lectura_final');
    const update = () => {
      const inicio = parseFloat(lecturaInicial?.value || 0);
      const fin = parseFloat(lecturaFinal?.value || 0);
      this.setAmount(fin - inicio);
    };
    lecturaInicial?.addEventListener('input', update);
    lecturaFinal?.addEventListener('input', update);
    update();
  }

  calcEstimacionEspacio() {
    const factorMap = {
      bar_pub_club: 0.05,
      hotel_conferencias: 0.04,
      museo_galeria: 0.03,
      escuela_comunitario: 0.025,
      teatro_concierto: 0.06,
      universidad: 0.02,
      otros: 0.035,
    };
    const tipo = this.sel('espacio_tipo_espacio');
    const sup = this.sel('espacio_superficie_utilizada');
    const horas = this.sel('espacio_horas_uso');
    const estimacion = this.sel('espacio_estimacion_consumo');

    const update = () => {
      const f = factorMap[tipo?.value] || 0;
      const v = (parseFloat(sup?.value || 0) * parseFloat(horas?.value || 0) * f) || 0;
      if (estimacion) estimacion.value = v.toFixed(2);
      this.setAmount(v);
    };
    tipo?.addEventListener('change', update);
    sup?.addEventListener('input', update);
    horas?.addEventListener('input', update);
    update();
  }

  calcFactura() {
    const fact = this.sel('factura_consumo');
    const supTot = this.sel('factura_superficie_total');
    const supUse = this.sel('factura_superficie_utilizada');
    const usoPct = this.sel('factura_tiempo_uso');
    const estim = this.sel('factura_consumo_estimado');

    const update = () => {
      const total = parseFloat(fact?.value) || 0;
      const t = parseFloat(supTot?.value) || 1;
      const u = parseFloat(supUse?.value) || 1;
      const p = parseFloat(usoPct?.value) || 100;
      const res = total * (u / t) * (p / 100);
      if (estim) estim.value = res.toFixed(2);
      this.setAmount(res);
    };
    [fact, supTot, supUse, usoPct].forEach((el) => el?.addEventListener('input', update));
    update();
  }

  calcVehiculo() {
    const km = this.sel('vehiculo_distancia_km');
    const c100 = this.sel('vehiculo_consumo_kwh_100km');
    const total = this.sel('vehiculo_consumo');
    const update = () => {
      const v = (parseFloat(km?.value) || 0) * ((parseFloat(c100?.value) || 0) / 100);
      if (total) total.value = v.toFixed(2);
      this.setAmount(v);
    };
    km?.addEventListener('input', update);
    c100?.addEventListener('input', update);
    update();
  }

  calcGasCaldera() {
    const consumo = this.sel('caldera_consumo_gas');
    const unidad = this.sel('caldera_unidad_consumo');
    const unidadTxt = this.dynamicFieldsTarget.querySelector('[data-field="caldera_unidad_texto"]');

    // Conversión m³ -> kWh
    const PCS = 11.7;           // Poder calorífico superior (aprox.)
    const factor = 1.01;        // Corrección
    const toKwh = (m3) => m3 * PCS * factor;

    const update = () => {
      const c = parseFloat(consumo?.value || 0);
      let kwh = c;
      if (unidad?.value === 'm3') kwh = toKwh(c);
      this.setAmount(kwh);
      if (unidadTxt) unidadTxt.textContent = unidad?.value === 'm3' ? 'm³' : 'kWh';
    };

    consumo?.addEventListener('input', update);
    unidad?.addEventListener('change', update);
    update();
  }

  calcGasPropano() {
    const cargas = this.sel('propano_numero_cargas');
    const kg = this.sel('propano_kg_por_carga');
    const update = () => this.setAmount((parseFloat(cargas?.value || 0) * parseFloat(kg?.value || 0)) || 0);
    cargas?.addEventListener('input', update);
    kg?.addEventListener('input', update);
    update();
  }

  calcGasBombona() {
    const kg = this.sel('bombona_kg');
    const n = this.sel('bombona_cantidad');
    const update = () => this.setAmount((parseFloat(kg?.value || 0) * parseFloat(n?.value || 0)) || 0);
    kg?.addEventListener('input', update);
    n?.addEventListener('input', update);
    update();
  }

  // --- Utilidades DOM/datos ---
  sel(dataField) {
    return this.dynamicFieldsTarget.querySelector(`[data-field="${dataField}"]`);
  }

  n(dataField, fallback = 0) {
    const el = this.sel(dataField);
    const v = parseFloat(el?.value);
    return Number.isFinite(v) ? v : fallback;
  }

  saveCalculationDetails() {
    const details = {};
    if (this.hasSubCategoryTarget) details.subCategory = this.subCategoryTarget.value || '';
    if (this.hasElectricityMethodTarget) details.electricityMethod = this.electricityMethodTarget.value || '';

    const inputs = this.dynamicFieldsTarget.querySelectorAll('[data-field]');
    inputs.forEach((input) => {
      const field = input.dataset.field;
      details[field] = input.type === 'checkbox' ? input.checked : input.value;
    });

    if (this.hasDetailsFieldTarget) {
      this.detailsFieldTarget.value = JSON.stringify(details);
    }
  }

  updateUnitLabel() {
    if (!this.hasSubCategoryTarget || !this.hasUnitLabelTarget) return;
    const selectedOption = this.subCategoryTarget.selectedOptions[0];
    const unit = selectedOption?.dataset.unit || '';
    this.unitLabelTarget.textContent = unit ? ` (${unit})` : '';
  }

  // Traducción segura
  t(key) {
    if (!this.hasI18nValue) return key;
    return this.i18nValue?.[key] ?? key;
  }
}
