// assets/controllers/plan_measures_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['actionBtn', 'criticalReason', 'categoryAlert', 'errorAlert'];

    connect() {
        this.currentIndex = Number(this.element.dataset.currentIndex || '0');
        this.totalMeasures = Number(this.element.dataset.totalMeasures || '0');
        this.reviewUrl = this.element.dataset.reviewUrl || '/index.php/backend/plan/review';
        this.criticalReasonError = this.element.dataset.criticalReasonError || 'Debes escribir un motivo cuando la medida es crítica.';

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
                this.postField(el, measureId, field, value);
            });
        });
    }

    async postField(triggerEl, measureId, field, value) {
        const card = this.getMeasureCardFromElement(triggerEl, measureId);
        this.clearInlineError(card);

        const updates = this.buildUpdates(measureId, field, value, card);
        if (updates === null) {
            return;
        }

        const shouldAdvance = this.shouldAdvance(field, value);
        const triggerButton = triggerEl?.tagName === 'BUTTON' ? triggerEl : null;

        if (triggerButton) {
            triggerButton.disabled = true;
        }

        try {
            let lastResponse = null;
            for (const update of updates) {
                const res = await fetch('/index.php/backend/plan/update-selection', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `measureId=${encodeURIComponent(measureId)}&field=${encodeURIComponent(update.field)}&value=${encodeURIComponent(update.value)}`
                });

                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.success) {
                    throw new Error(data.error || 'No se pudo guardar');
                }
                lastResponse = data;
            }

            this.setSelectedButtonState(measureId, field, value);
            if (field === 'criticalReason' || field === 'critical' || field === 'isApplicable') {
                this.syncMeasureState(measureId);
            }

            if (lastResponse?.nextUrl) {
                window.location.assign(lastResponse.nextUrl);
            } else if (shouldAdvance) {
                this.navigateAfterSave(field, value);
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'No se pudo guardar';
            this.showInlineError(message, card);
        } finally {
            if (triggerButton) {
                triggerButton.disabled = false;
            }
        }
    }

    buildUpdates(measureId, field, value, card = null) {
        const scope = card || this.getMeasureCard(measureId) || this.element;
        const reasonInput = scope.querySelector(`#crit-reason-${measureId}`);
        const reasonText = String(reasonInput?.value || '').trim();
        const criticalYes = this.getSelectedValue(measureId, 'critical', scope) === 'true';

        if (field === 'criticalReason') {
            return [{ field: 'criticalReason', value }];
        }

        if (field === 'critical') {
            return [{ field, value }];
        }

        if (field === 'isApplicable' && String(value) === 'false') {
            return [{ field, value }];
        }

        if (field === 'willImplement') {
            if (criticalYes && reasonText === '') {
                this.showInlineError(this.criticalReasonError);
                return null;
            }

            const updates = [];
            if (criticalYes) {
                updates.push({ field: 'criticalReason', value: reasonText });
            }
            updates.push({ field, value });
            return updates;
        }

        return [{ field, value }];
    }

    getMeasureCardFromElement(triggerEl, measureId) {
        return triggerEl?.closest('.measure-card') || this.getMeasureCard(measureId);
    }

    getMeasureCard(measureId) {
        const btn = this.element.querySelector(`[data-measure-id="${measureId}"][data-field]`);
        return btn?.closest('.measure-card') || this.element.querySelector('.measure-card');
    }

    getFieldButtons(measureId, field, scope = null) {
        const root = scope || this.element;
        return Array.from(root.querySelectorAll(
            `[data-measure-id="${measureId}"][data-field="${field}"]`
        ));
    }

    getSelectedValue(measureId, field, scope = null) {
        const selected = this.getFieldButtons(measureId, field, scope).find((btn) =>
            btn.classList.contains('btn-success') || btn.classList.contains('btn-danger')
        );

        return selected?.dataset.value ?? null;
    }

    syncMeasureState(measureId) {
        const card = this.getMeasureCard(measureId);
        if (!card) {
            return;
        }

        const applicable = this.getSelectedValue(measureId, 'isApplicable');
        const critical = this.getSelectedValue(measureId, 'critical');

        this.toggleSection(card, 'critical', applicable === 'true');
        this.toggleSection(card, 'critical-reason', critical === 'true');
        this.toggleSection(card, 'implement', applicable === 'true' && critical !== null);
    }

    toggleSection(card, section, visible) {
        const el = card.querySelector(`[data-plan-measures-section="${section}"]`);
        if (!el) {
            return;
        }

        el.classList.toggle('d-none', !visible);
    }

    setSelectedButtonState(measureId, field, value) {
        this.getFieldButtons(measureId, field).forEach((btn) => {
            const isSelected = btn.dataset.value === String(value);
            const positive = btn.dataset.value === 'true';
            const negative = btn.dataset.value === 'false';

            btn.querySelector('i.bi-check-lg')?.remove();
            btn.classList.remove('btn-success', 'btn-danger', 'btn-outline-success', 'btn-outline-danger');
            if (isSelected) {
                btn.classList.add(positive ? 'btn-success' : 'btn-danger');
                const icon = document.createElement('i');
                icon.className = 'bi bi-check-lg me-1';
                btn.prepend(icon);
                return;
            }

            btn.classList.add(positive ? 'btn-outline-success' : 'btn-outline-danger');
        });
    }

    shouldAdvance(field, value) {
        if (field === 'isApplicable' && String(value) === 'false') {
            return true;
        }

        return field === 'willImplement';
    }

    showInlineError(message, card = null) {
        const alert = card?.querySelector('[data-plan-measures-target="errorAlert"]')
            || (this.hasErrorAlertTarget ? this.errorAlertTarget : null);
        if (!alert) {
            return;
        }

        alert.textContent = message;
        alert.classList.remove('d-none');
        try {
            alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (_) {}
    }

    clearInlineError(card = null) {
        const alert = card?.querySelector('[data-plan-measures-target="errorAlert"]')
            || (this.hasErrorAlertTarget ? this.errorAlertTarget : null);
        if (!alert) {
            return;
        }

        alert.textContent = '';
        alert.classList.add('d-none');
    }

    navigateAfterSave(field, value) {
        const goNext = field === 'willImplement' || (field === 'isApplicable' && String(value) === 'false');
        if (goNext && (this.currentIndex + 1) < this.totalMeasures) {
            const url = new URL(window.location.href);
            url.searchParams.set('i', String(this.currentIndex + 1));
            window.location.assign(url.toString());
            return;
        }

        if (goNext) {
            window.location.assign(this.reviewUrl);
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('i', String(Math.max(0, this.currentIndex)));
        window.location.assign(url.toString());
    }
}
