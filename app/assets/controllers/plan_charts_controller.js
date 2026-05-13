// assets/controllers/plan_charts_controller.js
import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';

Chart.register(ChartDataLabels);

export default class extends Controller {
    static targets = ['canvas'];
    static values = { chartsConfig: Object };

    connect() {
        this._charts = [];
        this.renderAll();
    }

    disconnect() {
        this.destroyAll();
    }

    renderAll() {
        this.destroyAll();
        const cfgs = this.chartsConfigValue || {};
        this.canvasTargets.forEach((canvas) => {
            const key = canvas.dataset.chartKey;
            const cfg = cfgs[key];
            if (!cfg) return;

            const isPie = (cfg.type === 'pie' || cfg.type === 'doughnut');

            const baseOptions = {
                responsive: true,
                maintainAspectRatio: false,
                layout: isPie ? { padding: { top: 16, bottom: 4, left: 4, right: 4 } } : {},
                plugins: {
                    legend: { position: 'bottom' },
                    title: { display: !!cfg.title, text: cfg.title || '' },
                    datalabels: {
                        // Para pies: dentro del arco. Para barras: como lo tenías.
                        anchor: isPie ? 'center' : 'end',
                        align: isPie ? 'center' : 'end',
                        offset: isPie ? 0 : 4,
                        clamp: true,  // evita que se salga del chart area
                        clip: false,
                        font: ctx => ({ weight: isPie ? '700' : '600' }),
                        // Oculta etiquetas de segmentos 0
                        display: (ctx) => {
                            const v = ctx.dataset.data?.[ctx.dataIndex];
                            return v > 0; // no mostramos 0
                        },
                        formatter: (value, context) => {
                            const t = context.chart.config.type;
                            const percentValues = cfg.percentValues === true;

                            if (t === 'pie' || t === 'doughnut') {
                                if (percentValues) return `${value}%`;
                                const dataArr = context.chart.data.datasets[0].data || [];
                                const sum = dataArr.reduce((a, b) => a + (parseFloat(b) || 0), 0);
                                if (!sum) return null;
                                const pct = Math.round((value / sum) * 100);
                                return pct ? `${pct}%` : null;
                            }

                            // Barras: valor absoluto
                            return value;
                        },
                    },
                },
                scales: isPie ? {} : { y: { beginAtZero: true } },
            };

            const chart = new Chart(canvas.getContext('2d'), {
                type: cfg.type || 'bar',
                data: {
                    labels: cfg.labels || [],
                    datasets: (cfg.datasets || []).map(ds => ({
                        ...ds,
                        borderWidth: ds.borderWidth ?? 1
                    }))
                },
                options: { ...baseOptions, ...(cfg.options || {}) }
            });

            this._charts.push(chart);
        });
    }

    destroyAll() {
        (this._charts || []).forEach(c => { try { c.destroy(); } catch (e) {} });
        this._charts = [];
    }
}
