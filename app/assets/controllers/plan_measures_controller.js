// assets/controllers/plan_measures_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['actionBtn', 'observation', 'errorAlert'];
    connect() {
        this.currentIndex = Number(this.element.dataset.currentIndex || '0');
        this.totalMeasures = Number(this.element.dataset.totalMeasures || '0');
        this.updateUrl = this.element.dataset.updateUrl || '/index.php/backend/plan/update-selection';
        this.reviewUrl = this.element.dataset.reviewUrl || '/index.php/backend/plan/review';
        this.observationsError = this.element.dataset.observationsError || 'Debes escribir una observación para continuar.';
        this.observationsMinLength = Number(this.element.dataset.observationsMinLength || '50');

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

        this.observationTargets.forEach((input) => this.updateObservationUi(input));

    }

    updateContinueState(event) {
        const input = event.currentTarget;
        this.updateObservationUi(input);
        this.syncContinueState(this.getMeasureCard(input.dataset.measureId), input.dataset.measureId);
    }

    async completeDecision(event) {
        const button = event.currentTarget;
        const measureId = button.dataset.measureId;
        const card = this.getMeasureCardFromElement(button, measureId);
        const observations = String(card?.querySelector(`#observations-${measureId}`)?.value || '').trim();

        if (Array.from(observations).length < this.observationsMinLength) {
            this.showInlineError(this.observationsError, card);
            return;
        }

        await this.postField(button, measureId, 'completeDecision', observations);
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
            let nextUrl = null;
            for (const update of updates) {
                const res = await fetch(this.updateUrl, {
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
                nextUrl = data.nextUrl || nextUrl;
            }

            let sectionToScroll = null;
            if (field === 'decision' && !lastResponse?.unchangedDecision) {
                this.applyDecisionState(measureId, value);
                sectionToScroll = this.syncMeasureState(measureId);
                if (String(value) === 'true') {
                    sectionToScroll = card?.querySelector('[data-plan-measures-section="critical"]') || sectionToScroll;
                }
            } else {
                this.setSelectedButtonState(measureId, field, value);
                sectionToScroll = this.syncMeasureState(measureId);
                if (field === 'critical') {
                    sectionToScroll = card?.querySelector('[data-plan-measures-section="observations"]') || sectionToScroll;
                }
            }
            if (field === 'critical' || field === 'decision') {
                this.scrollToSection(sectionToScroll);
            }

            if (nextUrl) {
                window.location.assign(nextUrl);
            } else if (shouldAdvance) {
                this.navigateAfterSave(field, value);
            }

            return true;
        } catch (error) {
            const message = error instanceof Error ? error.message : 'No se pudo guardar';
            this.showInlineError(message, card);

            return false;
        } finally {
            if (triggerButton) {
                triggerButton.disabled = false;
            }
        }
    }

    buildUpdates(measureId, field, value, card = null) {
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
            btn.classList.contains('btn-success')
            || btn.classList.contains('btn-danger')
            || btn.classList.contains('btn-secondary')
        );

        return selected?.dataset.value ?? null;
    }

    syncMeasureState(measureId) {
        const card = this.getMeasureCard(measureId);
        if (!card) {
            return null;
        }

        const decision = this.getSelectedValue(measureId, 'decision');
        const critical = this.getSelectedValue(measureId, 'critical');
        const criticalVisible = this.toggleSection(card, 'critical', decision === 'true');
        const decisionComplete = decision === 'false'
            || decision === 'na'
            || (decision === 'true' && (critical === 'true' || critical === 'false'));
        const observationsVisible = this.toggleSection(card, 'observations', decisionComplete);
        const continueVisible = this.toggleSection(card, 'continue', decisionComplete);
        this.updateObservationUi(card.querySelector(`#observations-${measureId}`));
        this.syncContinueState(card, measureId);

        if (criticalVisible) {
            return card.querySelector('[data-plan-measures-section="critical"]');
        }

        if (observationsVisible) {
            return card.querySelector('[data-plan-measures-section="observations"]');
        }

        if (continueVisible) {
            return card.querySelector('[data-plan-measures-section="continue"]');
        }

        return null;
    }

    toggleSection(card, section, visible) {
        const el = card.querySelector(`[data-plan-measures-section="${section}"]`);
        if (!el) {
            return false;
        }

        const wasHidden = el.classList.contains('d-none');
        el.classList.toggle('d-none', !visible);
        return visible && wasHidden;
    }

    setSelectedButtonState(measureId, field, value) {
        this.getFieldButtons(measureId, field).forEach((btn) => {
            const isSelected = btn.dataset.value === String(value);
            const variant = this.getButtonVariant(btn.dataset.value);

            btn.querySelector('i.bi-check-lg')?.remove();
            btn.classList.remove(
                'btn-success',
                'btn-danger',
                'btn-secondary',
                'btn-outline-success',
                'btn-outline-danger',
                'btn-outline-secondary'
            );
            if (isSelected) {
                btn.classList.add(variant.selected);
                const icon = document.createElement('i');
                icon.className = 'bi bi-check-lg me-1';
                btn.prepend(icon);
                return;
            }

            btn.classList.add(variant.outline);
        });
    }

    applyDecisionState(measureId, value) {
        const card = this.getMeasureCard(measureId);
        if (value === 'na') {
            this.setSelectedButtonState(measureId, 'decision', value);
            this.clearSelectedButtonState(measureId, 'critical');
            return;
        }

        this.setSelectedButtonState(measureId, 'decision', value);
        this.clearSelectedButtonState(measureId, 'critical');
    }

    clearSelectedButtonState(measureId, field) {
        this.getFieldButtons(measureId, field).forEach((btn) => {
            btn.querySelector('i.bi-check-lg')?.remove();
            btn.classList.remove('btn-success', 'btn-danger', 'btn-secondary');
            btn.classList.remove('btn-outline-success', 'btn-outline-danger', 'btn-outline-secondary');

            const variant = this.getButtonVariant(btn.dataset.value);
            btn.classList.add(variant.outline);
        });
    }

    getButtonVariant(value) {
        if (String(value) === 'true') {
            return { selected: 'btn-success', outline: 'btn-outline-success' };
        }

        if (String(value) === 'false') {
            return { selected: 'btn-danger', outline: 'btn-outline-danger' };
        }

        return { selected: 'btn-secondary', outline: 'btn-outline-secondary' };
    }

    shouldAdvance(field, value) {
        return field === 'completeDecision';
    }

    syncContinueState(card, measureId) {
        if (!card) {
            return;
        }

        const button = card.querySelector('[data-plan-measures-action="continue"]');
        if (!button) {
            return;
        }

        const decision = this.getSelectedValue(measureId, 'decision', card);
        const critical = this.getSelectedValue(measureId, 'critical', card);
        const observations = String(card.querySelector(`#observations-${measureId}`)?.value || '').trim();
        const decisionComplete = decision === 'false'
            || decision === 'na'
            || (decision === 'true' && (critical === 'true' || critical === 'false'));
        const enable = decisionComplete && Array.from(observations).length >= this.observationsMinLength;
        button.disabled = !enable;
    }

    updateObservationUi(input) {
        if (!input) return;

        const card = input.closest('.measure-card');
        const count = Array.from(String(input.value || '').trim()).length;
        const counter = card?.querySelector('[data-observations-counter]');
        if (counter) {
            const template = this.element.dataset.observationsCounter || '{count}/{min}';
            counter.textContent = template
                .replaceAll('{count}', String(count))
                .replaceAll('{min}', String(this.observationsMinLength));
            counter.classList.toggle('text-danger', count < this.observationsMinLength);
            counter.classList.toggle('text-success', count >= this.observationsMinLength);
        }

        const help = card?.querySelector('[data-observations-help]');
        if (!help) return;

        const measureId = input.dataset.measureId;
        const decision = this.getSelectedValue(measureId, 'decision', card);
        const critical = this.getSelectedValue(measureId, 'critical', card);
        let helpKey = 'observationsHelpNonCritical';
        if (decision === 'na') helpKey = 'observationsHelpNotApplicable';
        else if (decision === 'false') helpKey = 'observationsHelpNotImplemented';
        else if (decision === 'true' && critical === 'true') {
            helpKey = this.element.dataset.projectType === 'evento'
                ? 'observationsHelpCriticalEvent'
                : 'observationsHelpCriticalFilming';
        }
        help.textContent = this.element.dataset[helpKey] || '';
    }

    showInlineError(message, card = null) {
        const inlineError = card?.querySelector('[data-observations-inline-error]');
        if (inlineError) {
            inlineError.textContent = message;
            inlineError.classList.remove('d-none');
        }
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
        const inlineError = card?.querySelector('[data-observations-inline-error]');
        if (inlineError) {
            inlineError.textContent = '';
            inlineError.classList.add('d-none');
        }
        const alert = card?.querySelector('[data-plan-measures-target="errorAlert"]')
            || (this.hasErrorAlertTarget ? this.errorAlertTarget : null);
        if (!alert) {
            return;
        }

        alert.textContent = '';
        alert.classList.add('d-none');
    }

    scrollToSection(sectionElement) {
        const planSummary = this.element.querySelector('#plan-commercial-summary');
        const target = planSummary || sectionElement;
        if (!target || target.classList.contains('d-none')) {
            return;
        }

        this.scrollToElement(target);
    }

    scrollToElement(element) {
        if (!element) {
            return;
        }

        window.requestAnimationFrame(() => {
            try {
                const fixedHeader = document.querySelector('.backend-topbar');
                const offset = fixedHeader?.getBoundingClientRect().height || 0;
                const top = element.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({
                    top: Math.max(top, 0),
                    behavior: 'smooth',
                });
            } catch (_) {}
        });
    }

    navigateAfterSave(field, value) {
        const goNext = field === 'completeDecision';
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
