// assets/controllers/plan_measures_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['actionBtn', 'criticalReason', 'categoryAlert'];

    connect() {
        // --- Scroll al aviso de cambio de categoría, si existe ---
        if (this.hasCategoryAlertTarget) {
            // Espera un tick por si hay reflow inicial
            requestAnimationFrame(() => {
                try {
                    this.categoryAlertTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // foco breve para accesibilidad (no molesta al usuario)
                    this.categoryAlertTarget.focus({ preventScroll: true });
                } catch (_) {}
            });
        }

        // --- Listeners de botones ---
        this.element.querySelectorAll('[data-plan-measures-target="actionBtn"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const el = e.currentTarget;
                const measureId = el.dataset.measureId;
                const field = el.dataset.field;
                const value = el.dataset.value;
                this.postField(measureId, field, value);
            });
        });

        // --- Guardar motivo crítico al perder foco ---
        this.element.querySelectorAll('[data-plan-measures-target="criticalReason"]').forEach(input => {
            input.addEventListener('blur', (e) => {
                const el = e.currentTarget;
                const measureId = el.dataset.measureId;
                const value = el.value.trim();
                this.postField(measureId, 'criticalReason', value);
            });
        });
    }

    postField(measureId, field, value) {
        fetch('/index.php/backend/plan/update-selection', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `measureId=${encodeURIComponent(measureId)}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                }
            })
            .catch(() => {});
    }
}
