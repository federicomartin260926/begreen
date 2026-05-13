// assets/controllers/emission_controller.js
import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';
import $ from 'jquery';

Chart.register(ChartDataLabels);

export default class extends Controller {
  static targets = ['form', 'detailsField', 'activity', 'unit', 'chart'];

  static values = {
    chartData: Object,
    i18n: Object, // textos traducidos inyectados desde Twig
  };

  // === helpers i18n/locale ===
  t(key, vars = {}) {
    const raw = (this.hasI18nValue && this.i18nValue?.[key]) || key;
    return Object.keys(vars).reduce((acc, k) => acc.replaceAll(`{${k}}`, String(vars[k])), raw);
  }
  locale() {
    return document.documentElement.lang || navigator.language || 'es';
  }
  nf = new Intl.NumberFormat(this.locale(), { maximumFractionDigits: 2 });

  connect() {
    this.initCharts();
    this.initUnitLabel();
    this.initActiveTab();
    this.restoreFieldsFromDetails();

    if (this.hasFormTarget) {
      this.initAmountCalculation();
      this.formTarget.addEventListener('submit', this.handleSubmit.bind(this));
    }
  }

  handleSubmit() {
    this.saveCalculationDetails();
  }

  // 🔹 Restaurar valores desde calculationDetails
  restoreFieldsFromDetails() {
    if (!this.hasDetailsFieldTarget) return;

    const raw = this.detailsFieldTarget.value;
    if (!raw) return;

    let storedData = {};
    try {
      storedData = JSON.parse(raw);
    } catch (e) {
      console.warn('[emission] malformed JSON in calculationDetails');
      return;
    }

    Object.entries(storedData).forEach(([key, value]) => {
      const input = this.element.querySelector(`[data-field="${key}"]`);
      if (input) {
        if (input.type === 'checkbox') {
          input.checked = !!value;
        } else {
          input.value = value;
        }
      }
    });
  }

  // 🔹 Guardar valores en calculationDetails al enviar
  saveCalculationDetails() {
    const inputs = this.element.querySelectorAll('[data-field]');
    const details = {};

    inputs.forEach((input) => {
      const key = input.dataset.field;
      details[key] = input.type === 'checkbox' ? input.checked : input.value;
    });

    if (this.hasDetailsFieldTarget) {
      this.detailsFieldTarget.value = JSON.stringify(details);
    } else {
      console.warn('[emission] detailsFieldTarget not found');
    }
  }

  // 🔹 Cálculos automáticos por categoría
  initAmountCalculation() {
    const category = this.formTarget?.dataset.category;
    if (!category) return;

    switch (category) {
      case 'eventos_online':
        this.initEventosOnlineCalculation();
        break;
      default:
        return;
    }
  }

  initEventosOnlineCalculation() {
    const get = (fieldName) => {
      const el = this.element.querySelector(`[data-field="${fieldName}"]`);
      if (!el) return 0;
      const n = parseFloat(String(el.value).replace(',', '.'));
      return isNaN(n) ? 0 : n;
    };

    const fields = [
      'participantes_evento_virtual',
      'duracion_asistencia_virtual',
      'participantes_ensayos',
      'duracion_ensayos',
    ];

    const updateAmount = () => {
      const participantes = get('participantes_evento_virtual');
      const duracion = get('duracion_asistencia_virtual');
      const ensayos = get('participantes_ensayos');
      const ensayoDuracion = get('duracion_ensayos');
      const totalHoras = participantes * duracion + ensayos * ensayoDuracion;

      const amountInput = this.element.querySelector('#emission_record_amount');
      if (amountInput) amountInput.value = totalHoras.toFixed(2);
    };

    fields.forEach((field) => {
      const el = this.element.querySelector(`[data-field="${field}"]`);
      if (el) el.addEventListener('input', updateAmount);
    });

    updateAmount(); // inicializa
  }

  // 🔹 Mostrar unidad dinámica junto al label del amount (según actividad)
  initUnitLabel() {
    if (!this.hasActivityTarget || !this.hasUnitTarget) return;

    const $activity = $(this.activityTarget);
    const $unit = $(this.unitTarget);

    const update = () => {
      const unit = $activity.find(':selected').data('unit') || '';
      $unit.text(unit ? ` (${unit})` : '');
    };

    $activity.on('change', update);
    update();
  }

  // 🔹 Activar pestaña si se pasa por query string
  initActiveTab() {
    const urlParams = new URLSearchParams(window.location.search);
    const category = urlParams.get('category');
    if (!category) return;

    const trigger = document.querySelector(
      `[data-bs-toggle="tab"][data-category="${category}"]`
    );

    if (trigger && window.bootstrap?.Tab) {
      const tab = new bootstrap.Tab(trigger);
      tab.show();
    }
  }

  // 🔹 Gráficos con Chart.js (landing e index)
  initCharts() {
    if (!this.hasChartTarget || !this.chartDataValue) return;

    this.chartTargets.forEach((canvas) => {
      const category = canvas.dataset.chartCategory;

      const rawData =
        category === 'fases'
          ? this.chartDataValue
          : this.chartDataValue[category];

      if (!rawData) return;

      const labels = Object.keys(rawData);
      const values = labels.map((k) => Number(rawData[k] || 0));

      const isPhases = category === 'fases';
      const config = {
        type: 'bar',
        data: {
          labels,
          datasets: [
            {
              label: isPhases
                ? this.t('backend.emission.chart.legend.by_phase')
                : this.t('backend.emission.chart.legend.by_activity'),
              data: values,
              backgroundColor: 'rgba(63, 195, 138, 0.40)',
              borderColor: 'rgba(63, 195, 138, 1)',
              borderWidth: 1,
            },
          ],
        },
        options: {
            responsive: true,
            layout: {
                padding: { top: 0 }
            },
            plugins: {
                legend: { display: true },
                title: {
                display: true,
                text: isPhases
                    ? this.t('backend.emission.chart.title.by_phase')
                    : this.t('backend.emission.chart.title.by_activity', { category }),
                // Más espacio debajo del título
                padding: { bottom: 12 }
                },
                datalabels: {
                anchor: 'end',
                align: 'top',
                // separa un poco la etiqueta de datos de la barra
                offset: 4,
                color: '#000',
                font: { weight: 'bold' },
                formatter: (value) => this.nf.format(value),
                },
                tooltip: {
                callbacks: {
                    label: (ctx) => {
                    const v = ctx.parsed.y ?? ctx.parsed;
                    return `${this.nf.format(v)} ${this.t('backend.emission.chart.unit.kgco2e')}`;
                    },
                },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grace: '15%',
                    title: {
                    display: true,
                    text: this.t('backend.emission.chart.axis.kgco2e'),
                    },
                    ticks: {
                    callback: (v) => this.nf.format(v),
                    },
                },
            },
        }

      };

      new Chart(canvas.getContext('2d'), config);
    });
  }
}
