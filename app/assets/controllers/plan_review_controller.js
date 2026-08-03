// assets/controllers/plan_review_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'spinner',
    'etForm',
    'etWarning',
    'etCounter',
    'etSpinner',
    'etSubmitBtn',
    'inlineEdit',
    'errorModalBody',
    'modalTitle',
    'modalBody',
    'modalDialog'
  ];

  static values = {
    i18n: Object, // 👈 textos traducidos inyectados desde Twig
  };

  // ===== i18n helpers =====
  t(key, vars = {}) {
    const raw = (this.hasI18nValue && this.i18nValue?.[key]) || key;
    return Object.keys(vars).reduce((acc, k) => acc.replaceAll(`{${k}}`, String(vars[k])), raw);
  }

  connect() {
    this.initializeImplementedSwitches();
    this.initializeImplementationCollapses();

    // --- Filtros ---
    const filtersForm = this.element.querySelector('#plan-filters-form');
    if (filtersForm) {
      filtersForm.querySelectorAll('select, input[type="checkbox"], input[type="radio"]').forEach(input => {
        input.addEventListener('change', () => filtersForm.submit());
      });
    }

    const resetBtn = this.element.querySelector('#reset-filters');
    if (resetBtn && filtersForm) {
      resetBtn.addEventListener('click', (e) => {
        e.preventDefault();
        filtersForm.querySelectorAll('select').forEach(s => { s.value = ''; });
        filtersForm.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
        const defaultState = filtersForm.querySelector('input[name="state"][value="all"]');
        if (defaultState) {
          defaultState.checked = true;
        }
        filtersForm.submit();
      });
    }

    // Botones de collapse: icono/estilo
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach((btn) => {
      const icon = btn.querySelector('i');
      const targetSelector = btn.getAttribute('data-bs-target');
      const target = document.querySelector(targetSelector);
      if (!target) return;

      const onShown = () => {
        if (icon) { icon.classList.remove('bi-chevron-right'); icon.classList.add('bi-chevron-down'); }
        if (btn.dataset.collapseStaticClass !== '1') {
          btn.classList.remove('btn-outline-primary');
          btn.classList.add('btn-primary');
        }

        const collapsedLabel = btn.querySelector('[data-collapse-label="collapsed"]');
        const expandedLabel = btn.querySelector('[data-collapse-label="expanded"]');
        if (collapsedLabel && expandedLabel) {
          collapsedLabel.classList.add('d-none');
          expandedLabel.classList.remove('d-none');
        }

      };
      const onHidden = () => {
        if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-right'); }
        if (btn.dataset.collapseStaticClass !== '1') {
          btn.classList.remove('btn-primary');
          btn.classList.add('btn-outline-primary');
        }

        const collapsedLabel = btn.querySelector('[data-collapse-label="collapsed"]');
        const expandedLabel = btn.querySelector('[data-collapse-label="expanded"]');
        if (collapsedLabel && expandedLabel) {
          collapsedLabel.classList.remove('d-none');
          expandedLabel.classList.add('d-none');
        }
      };

      target.addEventListener('shown.bs.collapse', onShown);
      target.addEventListener('hidden.bs.collapse', onHidden);
      if (target.classList.contains('show')) onShown(); else onHidden();
    });

    // Modal ET: reset visual al abrir/cerrar
    const etModalEl = document.getElementById('etEmailModal');
    if (etModalEl) {
      etModalEl.addEventListener('show.bs.modal', () => { this.hideEtWarning(); this.updateEtCounter(); });
      etModalEl.addEventListener('hidden.bs.modal', () => { this.hideEtWarning(); });
    }

    this.restoreReviewScrollPosition();
  }

  // --- Vista previa (modal) ---
  loadPreview() {
    const modal = new bootstrap.Modal(document.getElementById('planPreviewModal'));
    const previewBody = document.getElementById('plan-preview-body');

    previewBody.innerHTML = `<div class="text-center text-muted">${this.t('loading_preview')}</div>`;
    modal.show();

    const filters = this.collectFilters();
    const query = new URLSearchParams(filters).toString();

    fetch(`/index.php/backend/plan/preview?${query}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(response => {
        if (!response.ok) throw new Error(this.t('preview_error'));
        return response.text();
      })
      .then(html => { previewBody.innerHTML = html; })
      .catch(error => {
        previewBody.innerHTML = `<div class="alert alert-danger text-center">${error.message}</div>`;
      });
  }

  // --- Descarga PDF sin nueva pestaña, con spinner ---
  async downloadPdf(event) {
    if (event) event.preventDefault();
    const btn = event?.currentTarget;
    const downloadState = btn
      ? this.application.getControllerForElementAndIdentifier(btn, 'download-state')
      : null;

    if (downloadState) {
      if (!downloadState.startManaged()) return;
    } else if (btn) {
      btn.disabled = true;
    }

    this.showSpinner();
    try {
      const filters = this.collectFilters();
      const query = new URLSearchParams(filters).toString();
      const url = `/index.php/backend/plan/download?${query}`;

      const res = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!res.ok) throw new Error(this.t('pdf_error_http', { code: res.status }));

      const blob = await res.blob();
      const dlUrl = URL.createObjectURL(blob);
      const filename = this.extractFilename(res.headers.get('Content-Disposition')) || this.t('pdf_default_filename');

      const a = document.createElement('a');
      a.href = dlUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(dlUrl);
    } catch (err) {
      console.error(err);
      this.showModal(this.t('modal.error_title'), this.t('pdf_error_generic'));
    } finally {
      this.hideSpinner();
      if (downloadState) {
        downloadState.reset();
      } else if (btn) {
        btn.disabled = false;
      }
    }
  }

  disconnect() {
    this.implementedSwitchAbortController?.abort();
    this.implementationCollapseAbortController?.abort();
  }

  initializeImplementationCollapses() {
    this.implementationCollapseAbortController?.abort();
    this.implementationCollapseAbortController = new AbortController();
    const { signal } = this.implementationCollapseAbortController;

    this.element.querySelectorAll('[data-implementation-category-collapse]').forEach((collapse) => {
      collapse.addEventListener('shown.bs.collapse', () => {
        const category = collapse.closest('[data-implementation-category]');
        const toggle = category?.querySelector('[data-implementation-category-toggle]');
        toggle?.classList.remove('btn-outline-success');
        toggle?.classList.add('btn-success');
        if (toggle?.dataset.collapseLabel) toggle.setAttribute('aria-label', toggle.dataset.collapseLabel);
        this.replaceReviewContext({ open_category: category?.dataset.implementationCategory || null });
      }, { signal });
      collapse.addEventListener('hidden.bs.collapse', () => {
        const category = collapse.closest('[data-implementation-category]');
        const toggle = category?.querySelector('[data-implementation-category-toggle]');
        toggle?.classList.remove('btn-success');
        toggle?.classList.add('btn-outline-success');
        if (toggle?.dataset.expandLabel) toggle.setAttribute('aria-label', toggle.dataset.expandLabel);
        const categoryKey = category?.dataset.implementationCategory || null;
        const params = new URLSearchParams(window.location.search);
        const updates = params.get('open_category') === categoryKey ? { open_category: null } : {};
        const openMeasure = category?.querySelector('[data-implementation-measure-collapse].show');
        if (openMeasure && params.get('open') === openMeasure.id.replace('mrow_', '')) {
          updates.open = null;
        }
        this.replaceReviewContext(updates);
      }, { signal });
    });

    this.element.querySelectorAll('[data-implementation-measure-collapse]').forEach((collapse) => {
      collapse.addEventListener('shown.bs.collapse', () => {
        const measure = collapse.closest('[data-implementation-measure]');
        const category = collapse.closest('[data-implementation-category]');
        this.replaceReviewContext({
          open: measure?.dataset.measureId || null,
          open_category: category?.dataset.implementationCategory || null,
        });
      }, { signal });
      collapse.addEventListener('hidden.bs.collapse', () => {
        const measure = collapse.closest('[data-implementation-measure]');
        const params = new URLSearchParams(window.location.search);
        if (params.get('open') === measure?.dataset.measureId) {
          this.replaceReviewContext({ open: null });
        }
      }, { signal });
    });
  }

  replaceReviewContext(updates) {
    if (Object.keys(updates).length === 0) return;

    const url = new URL(window.location.href);
    Object.entries(updates).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') url.searchParams.delete(key);
      else url.searchParams.set(key, String(value));
    });
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
  }

  initializeImplementedSwitches() {
    this.implementedSwitchAbortController?.abort();
    this.implementedSwitchAbortController = new AbortController();
    const { signal } = this.implementedSwitchAbortController;

    this.element.querySelectorAll('[data-implemented-control]').forEach((control) => {
      const measureContainer = control.closest('[data-plan-measure-row]');
      this.updateExecutionDecisionPanels(measureContainer, control.dataset.currentValue || 'null');

      const knob = control.querySelector('.implemented-switch__knob');
      if (!knob) return;

      knob.addEventListener('pointerdown', (event) => {
        this.startImplementedSwitchDrag(event, control, knob);
      }, { signal });
    });
  }

  startImplementedSwitchDrag(event, control, knob) {
    if (!event.isPrimary || control.dataset.saving === '1' || control.dataset.locked === '1') return;

    const selectedInput = control.querySelector('.implemented-switch__input:checked');
    if (!selectedInput || selectedInput.disabled) return;

    event.preventDefault();
    selectedInput.focus({ preventScroll: true });
    knob.setPointerCapture(event.pointerId);
    control.classList.add('is-dragging');

    const updateKnobPosition = (pointerEvent) => {
      if (pointerEvent.pointerId !== event.pointerId) return;

      const track = control.querySelector('.implemented-switch__track');
      if (!track) return;

      const trackRect = track.getBoundingClientRect();
      const knobWidth = knob.getBoundingClientRect().width;
      const minLeft = 2;
      const maxLeft = trackRect.width - knobWidth - 2;
      const left = Math.min(maxLeft, Math.max(minLeft, pointerEvent.clientX - trackRect.left - (knobWidth / 2)));
      control.style.setProperty('--implemented-knob-left', `${left}px`);
    };

    const finishDrag = (pointerEvent) => {
      if (pointerEvent.pointerId !== event.pointerId) return;

      updateKnobPosition(pointerEvent);
      const track = control.querySelector('.implemented-switch__track');
      const trackRect = track?.getBoundingClientRect();
      const knobWidth = knob.getBoundingClientRect().width;
      const minLeft = 2;
      const maxLeft = (trackRect?.width ?? knobWidth) - knobWidth - 2;
      const currentLeft = Number.parseFloat(control.style.getPropertyValue('--implemented-knob-left'));
      const positions = [
        { value: 'false', left: minLeft },
        { value: 'null', left: (minLeft + maxLeft) / 2 },
        { value: 'true', left: maxLeft },
      ];
      const closest = positions.reduce((current, candidate) => (
        Math.abs(candidate.left - currentLeft) < Math.abs(current.left - currentLeft) ? candidate : current
      ));

      control.classList.remove('is-dragging');
      control.style.removeProperty('--implemented-knob-left');
      knob.removeEventListener('pointermove', updateKnobPosition);
      knob.removeEventListener('pointerup', finishDrag);
      knob.removeEventListener('pointercancel', cancelDrag);
      if (knob.hasPointerCapture(event.pointerId)) knob.releasePointerCapture(event.pointerId);

      const input = control.querySelector(`.implemented-switch__input[value="${closest.value}"]`);
      if (!input || input.checked || input.disabled) return;

      input.checked = true;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const cancelDrag = (pointerEvent) => {
      if (pointerEvent.pointerId !== event.pointerId) return;

      control.classList.remove('is-dragging');
      control.style.removeProperty('--implemented-knob-left');
      knob.removeEventListener('pointermove', updateKnobPosition);
      knob.removeEventListener('pointerup', finishDrag);
      knob.removeEventListener('pointercancel', cancelDrag);
    };

    knob.addEventListener('pointermove', updateKnobPosition);
    knob.addEventListener('pointerup', finishDrag);
    knob.addEventListener('pointercancel', cancelDrag);
    updateKnobPosition(event);
  }

  async toggleImplemented(event) {
    const input = event.currentTarget;
    if (!input.checked) return;

    const value = String(input.value);
    const measureId = input.dataset.measureId;
    const url = input.dataset.url;
    const measureContainer = input.closest('[data-plan-measure-row]');
    const control = input.closest('[data-implemented-control]');
    const previousValue = String(control?.dataset.currentValue || 'null');
    if (!measureContainer) {
      console.error('plan-review: measure container not found for implemented toggle', { measureId, input });
      this.showModal(this.t('modal.error_title'), this.t('network_error'));
      return;
    }
    if (control?.dataset.saving === '1') {
      const previousInput = control.querySelector(`input[value="${previousValue}"]`);
      if (previousInput) previousInput.checked = true;
      return;
    }
    if (control?.dataset.locked === '1') {
      const previousInput = control.querySelector(`input[value="${previousValue}"]`);
      if (previousInput) previousInput.checked = true;
      return;
    }

    this.updateExecutionDecisionPanels(measureContainer, value);

    if (control) {
      control.dataset.saving = '1';
      control.querySelectorAll('input').forEach((radio) => {
        radio.dataset.wasDisabled = radio.disabled ? '1' : '0';
        radio.disabled = true;
      });
    }

    try {
      if (value === 'false') {
        const incidentEl = measureContainer.querySelector(`#execution-incident-${measureId}`);
        const incident = String(incidentEl?.value || '').trim();
        if (incident === '') {
          incidentEl?.focus({ preventScroll: true });
          return;
        }
        await this.persistSelectionField(url, measureId, 'executionIncident', incident);
      }

      const data = await this.persistSelectionField(url, measureId, 'implemented', value);
      if (control) control.dataset.currentValue = value;
      this.updateExecutionDecisionPanels(measureContainer, value);
      this.updateOperationalState(measureContainer, data.operationalState);
    } catch (err) {
      const previousInput = control?.querySelector(`input[value="${previousValue}"]`);
      if (previousInput) previousInput.checked = true;
      this.updateExecutionDecisionPanels(measureContainer, previousValue);
      console.error(err);
      this.showModal(this.t('modal.error_title'), err.message || this.t('network_error'));
    } finally {
      if (control) {
        delete control.dataset.saving;
        control.querySelectorAll('input').forEach((radio) => {
          radio.disabled = control.dataset.locked === '1' || radio.dataset.wasDisabled === '1';
          delete radio.dataset.wasDisabled;
        });
      }
    }
  }

  updateExecutionDecisionPanels(measureContainer, value) {
    if (!measureContainer) return;

    const normalizedValue = ['false', 'null', 'true'].includes(String(value)) ? String(value) : 'null';
    const executionContainer = measureContainer.querySelector('[data-execution-decision]');
    if (executionContainer) executionContainer.dataset.executionDecision = normalizedValue;

    measureContainer.querySelectorAll('[data-execution-panel]').forEach((panel) => {
      const active = panel.dataset.executionPanel === normalizedValue;
      panel.classList.toggle('d-none', !active);
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');

      panel.querySelectorAll('[data-execution-incident]').forEach((incident) => {
        incident.required = active && normalizedValue === 'false';
      });
    });
  }

  updateOperationalState(measureContainer, state) {
    if (!state) return;

    const measure = measureContainer.closest('[data-implementation-measure]');
    const summary = measure?.querySelector('[data-measure-summary]');
    if (!measure || !summary) return;

    Array.from(measure.classList)
      .filter(className => className.startsWith('implementation-measure-row--'))
      .forEach(className => measure.classList.remove(className));
    measure.classList.add(`implementation-measure-row--${state}`);

    const dot = summary.querySelector('.plan-measure-status-dot');
    if (dot) {
      Array.from(dot.classList)
        .filter(className => className.startsWith('plan-measure-status-dot--'))
        .forEach(className => dot.classList.remove(className));
      dot.classList.add(`plan-measure-status-dot--${state}`);
      const label = this.t(`status_${state}`);
      dot.title = label;
      dot.setAttribute('aria-label', label);
    }

    const stateLabel = summary.querySelector('.implementation-measure-row__state');
    if (stateLabel) stateLabel.textContent = this.t(`status_${state}`);
    measure.querySelectorAll('[data-operational-state-label]').forEach((label) => {
      label.textContent = this.t(`status_${state}`);
    });
  }

  // ========== ET EMAIL ==========

  toggleDepartment(event) {
    const deptId = (event.params && event.params.deptId) || event.currentTarget.getAttribute('data-dept-id') || event.currentTarget.dataset.deptId;
    const checked = event.currentTarget.checked;
    const inputs = this.element.querySelectorAll(`input[type="checkbox"][data-dept="${deptId}"]`);
    inputs.forEach(i => { i.checked = checked; });
    this.updateEtCounter();
    this.hideEtWarning();
  }

  updateEtCounter() {
    const selected = this.element.querySelectorAll('input[type="checkbox"][name="crew_ids[]"]:checked').length;
    if (this.hasEtCounterTarget) this.etCounterTarget.textContent = String(selected);
  }

  submitEtEmails(event) {
    if (this._etSubmitting) { event.preventDefault(); return; }

    const selected = this.element.querySelectorAll('input[type="checkbox"][name="crew_ids[]"]:checked').length;
    if (selected === 0) {
      event.preventDefault();
      this.showEtWarning();
      return;
    }

    this._etSubmitting = true;
    this.hideEtWarning();

    if (this.hasEtFormTarget) {
      this.etFormTarget.classList.add('position-relative', 'pe-none');
    }
    if (this.hasEtSpinnerTarget) {
      this.etSpinnerTarget.classList.remove('d-none');
      this.etSpinnerTarget.classList.add('d-flex');
    }

    const modalEl = document.getElementById('etEmailModal');
    if (modalEl) {
      modalEl.querySelectorAll('[data-bs-dismiss="modal"], .btn-close').forEach(btn => {
        btn.setAttribute('data-prev-disabled', btn.disabled ? '1' : '0');
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
      });
    }
    if (this.hasEtSubmitBtnTarget) this.etSubmitBtnTarget.disabled = true;
  }

  showEtWarning() { if (this.hasEtWarningTarget) this.etWarningTarget.classList.remove('d-none'); }
  hideEtWarning() { if (this.hasEtWarningTarget) this.etWarningTarget.classList.add('d-none'); }

  // --- Utils ---
  collectFilters() {
    const filters = {};
    document.querySelectorAll('[data-dt-filter]').forEach(input => {
      const key = input.getAttribute('data-dt-filter');
      if (input.type === 'checkbox') { if (input.checked) filters[key] = input.value; }
      else if (input.type === 'radio') { if (input.checked) filters[key] = input.value; }
      else { if (input.value) filters[key] = input.value; }
    });
    return filters;
  }

  buildReviewQuery(extraParams = {}) {
    const params = new URLSearchParams(window.location.search);
    const filterKeys = [
      'protocol',
      'category',
      'department',
      'ods',
      'impact_area',
      'triple_balance_axis',
      'scope',
      'esg',
      'state',
      'is_critical',
      'open',
    ];

    const filters = this.collectFilters();
    filterKeys.forEach((key) => params.delete(key));

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.set(key, String(value));
      }
    });

    Object.entries(extraParams).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') {
        params.delete(key);
      } else {
        params.set(key, String(value));
      }
    });

    return params.toString();
  }

  reloadReviewWithQuery(extraParams = {}, anchor = 'implementation-filters', scrollMeasureId = null) {
    const nextQuery = this.buildReviewQuery(extraParams);
    const targetUrl = new URL(window.location.href);
    targetUrl.search = nextQuery;
    targetUrl.hash = anchor ? `#${anchor}` : '';

    if (scrollMeasureId !== null && scrollMeasureId !== undefined) {
      try {
        sessionStorage.setItem('planReviewScrollMeasure', String(scrollMeasureId));
      } catch (error) {
        console.warn('plan-review: could not persist scroll target', error);
      }
    }

    const sameLogicalUrl = window.location.pathname === targetUrl.pathname
      && window.location.search === targetUrl.search;

    if (sameLogicalUrl) {
      if (window.location.hash !== targetUrl.hash) {
        try {
          window.history.replaceState(
            window.history.state,
            '',
            `${targetUrl.pathname}${targetUrl.search}${targetUrl.hash}`
          );
        } catch (error) {
          console.warn('plan-review: could not update navigation hash before reload', error);
        }
      }
      window.location.reload();
      return;
    }

    window.location.assign(`${targetUrl.pathname}${targetUrl.search}${targetUrl.hash}`);
  }

  restoreReviewScrollPosition() {
    let measureId = null;
    try {
      measureId = sessionStorage.getItem('planReviewScrollMeasure');
      sessionStorage.removeItem('planReviewScrollMeasure');
    } catch (error) {
      console.warn('plan-review: could not restore scroll target', error);
      return;
    }

    if (!measureId) return;

    const measureRow = document.getElementById(`mrow_${measureId}`);
    if (!measureRow) return;

    window.requestAnimationFrame(() => {
      const top = measureRow.getBoundingClientRect().top + window.scrollY - 96;
      window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    });
  }

  extractFilename(contentDisposition) {
    if (!contentDisposition) return null;
    const match = /filename\*?=(?:UTF-8'')?"?([^\";]+)/i.exec(contentDisposition);
    return match ? decodeURIComponent(match[1]) : null;
  }

  // ----- Inline edit -----
  toggleInlineEdit(event) {
    const id = event.currentTarget.dataset.editId;
    const box = document.getElementById(`inline-edit-${id}`);
    if (!box) return;
    box.classList.toggle('d-none');
  }

  onAppliesChange(e) {
    const box = e.currentTarget.closest('[data-plan-review-target="inlineEdit"]');
    if (!box) return;

    const appliesYes = e.currentTarget.value === 'true';
    const critYes  = box.querySelector('input[id^="critical-yes-"]');
    const critNo   = box.querySelector('input[id^="critical-no-"]');
    const implYes  = box.querySelector('input[id^="implement-yes-"]');
    const implNo   = box.querySelector('input[id^="implement-no-"]');
    const setDisabled = (el, disabled) => { if (el) el.disabled = disabled; };

    if (!appliesYes) {
      [critYes, critNo, implYes, implNo].forEach(el => { if (el) { el.checked = false; setDisabled(el, true); } });
    } else {
      [implYes, implNo].forEach(el => setDisabled(el, false));
      const implementationSelected = implYes?.checked === true;
      [critYes, critNo].forEach(el => setDisabled(el, !implementationSelected));
    }
  }

  onImplementChange(e) {
    const box = e.currentTarget.closest('[data-plan-review-target="inlineEdit"]');
    if (!box) return;

    const willImplement = e.currentTarget.value === 'true';
    const criticalInputs = [
      box.querySelector('input[id^="critical-yes-"]'),
      box.querySelector('input[id^="critical-no-"]')
    ];

    criticalInputs.forEach((input) => {
      if (!input) return;
      input.disabled = !willImplement;
      if (!willImplement) input.checked = false;
    });
  }

  async saveInlineEdit(event) {
    const btn = event.currentTarget;
    const measureId = btn.dataset.measureId;
    const saveSection = btn.dataset.saveSection;
    const inlineBox = document.getElementById(`inline-edit-${measureId}`);
    const measureContainer = btn.closest('[data-plan-measure-row]')
      || this.element.querySelector(`[data-plan-measure-row][data-measure-id="${measureId}"]`);
    const canSend = (el) => el && !el.disabled && el.dataset.locked !== '1';
    const updates = [];

    if (saveSection === 'state' && inlineBox) {
      // ======= BLOQUE: ESTADO EN EL PLAN =======
      const getRadioVal = (name) => {
        const el = inlineBox.querySelector(`input[name="${name}-${measureId}"]:checked`);
        return el ? el.value : null;
      };

      const valApplies   = getRadioVal('applies');
      const valCritical  = getRadioVal('critical');
      const valImplement = getRadioVal('implement');
      const observationsEl = inlineBox.querySelector(`#decision-observations-${measureId}`);
      const observations = String(observationsEl?.value || '').trim();

      if (valApplies === null
        || (valApplies === 'true' && valImplement === null)
        || (valApplies === 'true' && valImplement === 'true' && valCritical === null)) {
        this.showModal(this.t('modal.missing_title'), this.t('save_missing_fields_html'));
        return;
      }

      if (observations === '') {
        this.showModal(this.t('modal.missing_title'), this.t('observations_required_html'));
        return;
      }

      const decision = valApplies === 'false' ? 'na' : valImplement;
      updates.push({ field: 'decision', value: decision });
      if (decision === 'true') updates.push({ field: 'critical', value: valCritical });
      updates.push({ field: 'completeDecision', value: observations });
    } else if (saveSection === 'actions') {
      // ======= BLOQUE: ACCIONES =======
      const implementedEl = measureContainer?.querySelector(`input[name="implemented-${measureId}"]:checked`);
      const decision = String(implementedEl?.value || 'null');
      const activePanel = measureContainer?.querySelector(`[data-execution-panel="${decision}"]`);

      if (decision === 'false' && activePanel) {
        const executionIncidentEl = activePanel.querySelector(`#execution-incident-${measureId}`);
        if (canSend(executionIncidentEl)) {
          updates.push({ field: 'executionIncident', value: (executionIncidentEl.value || '').trim() });
        }
      } else if (decision === 'true' && activePanel) {
        const verificationEl = activePanel.querySelector(`#verification-${measureId}`);
        const actionTakenEl = activePanel.querySelector(`#action-taken-${measureId}`);
        const internalNotesEl = activePanel.querySelector(`#internal-notes-${measureId}`);
        const responsiblesEl = activePanel.querySelector(`#responsibles-${measureId}`);

        if (canSend(verificationEl)) updates.push({ field: 'verification', value: verificationEl.checked ? 'true' : 'false' });
        if (canSend(actionTakenEl)) updates.push({ field: 'action_taken', value: (actionTakenEl.value || '').trim() });
        if (canSend(internalNotesEl)) updates.push({ field: 'internal_notes', value: (internalNotesEl.value || '').trim() });
        if (responsiblesEl && !responsiblesEl.disabled) {
          const selectedResponsibleIds = Array.from(responsiblesEl.selectedOptions || []).map(opt => opt.value).join(',');
          updates.push({ field: 'responsibles', value: selectedResponsibleIds });
        }

        const evidenceMetadata = {};
        const existingEvidenceSourceSelects = activePanel.querySelectorAll('[data-evidence-source-select="1"][data-evidence-path]');
        existingEvidenceSourceSelects.forEach((select) => {
          const path = String(select.dataset.evidencePath || '').trim();
          const sourceCode = String(select.value || '').trim();
          if (path !== '' && sourceCode !== '') evidenceMetadata[path] = sourceCode;
        });
        if (existingEvidenceSourceSelects.length > 0) {
          updates.push({ field: 'evidence_metadata', value: JSON.stringify(evidenceMetadata) });
        }
      }

      if (implementedEl && !implementedEl.disabled && decision !== 'null') {
        updates.push({ field: 'implemented', value: decision });
      }
    }

    if (updates.length === 0) return;

    btn.disabled = true;

    try {
      for (const up of updates) {
        const body = new URLSearchParams({ measureId, field: up.field, value: up.value });
        const res = await fetch('/index.php/backend/plan/update-selection', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: body.toString()
        });

        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
          const msg = (data && data.error) ? data.error : this.t('save_error_generic');
          throw new Error(msg);
        }

      }

      const decisionModal = btn.closest('.modal');
      if (decisionModal) {
        try {
          bootstrap.Modal.getOrCreateInstance(decisionModal).hide();
        } catch (error) {
          console.warn('plan-review: could not close decision modal before reload', error);
        }
      }

      // Reconstruir la tarjeta desde backend, manteniéndola abierta y visible.
      this.reloadReviewWithQuery({ open: measureId }, null, measureId);
    } catch (err) {
      console.error(err);
      this.showModal(this.t('modal.error_title'), err.message || this.t('network_error'));
    } finally {
      btn.disabled = false;
    }
  }

  openEvidenceModal(event) {
    const btn = event.currentTarget;
    const modalId = btn.dataset.evidenceModalId;
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;

    const fileInput = modalEl.querySelector('[data-evidence-modal-file="1"]');
    const sourceSelect = modalEl.querySelector('[data-evidence-modal-source="1"]');
    if (fileInput) fileInput.value = '';
    if (sourceSelect) {
      sourceSelect.value = '';
      this.clearEvidenceModalSourceError(sourceSelect);
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  clearEvidenceModalSourceError(eventOrSelect) {
    const select = eventOrSelect?.currentTarget || eventOrSelect;
    if (!select) return;

    const modalEl = select.closest('.modal');
    const feedback = modalEl?.querySelector('[data-evidence-modal-source-feedback="1"]');
    const defaultMessage = this.t('evidence_add_modal_source_required');

    select.classList.remove('is-invalid');
    select.removeAttribute('aria-invalid');
    if (feedback) {
      feedback.classList.add('d-none');
      feedback.textContent = defaultMessage;
    }
  }

  showEvidenceModalSourceError(modalEl, message = this.t('evidence_add_modal_source_required')) {
    const sourceSelect = modalEl?.querySelector('[data-evidence-modal-source="1"]');
    const feedback = modalEl?.querySelector('[data-evidence-modal-source-feedback="1"]');
    if (!sourceSelect || !feedback) return;

    sourceSelect.classList.add('is-invalid');
    sourceSelect.setAttribute('aria-invalid', 'true');
    feedback.textContent = message;
    feedback.classList.remove('d-none');
    sourceSelect.focus();
  }

  async submitEvidenceModal(event) {
    const btn = event.currentTarget;
    const measureId = btn.dataset.measureId;
    const modalId = btn.dataset.evidenceModalId;
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;

    const fileInput = modalEl.querySelector('[data-evidence-modal-file="1"]');
    const sourceSelect = modalEl.querySelector('[data-evidence-modal-source="1"]');
    const file = fileInput?.files?.[0] || null;
    const sourceCode = String(sourceSelect?.value || '').trim();

    if (!file) {
      this.showModal(this.t('modal.error_title'), this.t('evidence_add_modal_file_required'));
      return;
    }
    if (!sourceCode) {
      this.showEvidenceModalSourceError(modalEl);
      return;
    }

    btn.disabled = true;
    try {
      const form = new FormData();
      form.append('measureId', measureId);
      form.append('source_code', sourceCode);
      form.append('evidences[]', file);

      const res = await fetch('/index.php/backend/plan/upload-evidences', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: form
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        const backendError = String(data?.error || '').trim();
        if (this.isEvidenceModalSourceRequiredError(backendError)) {
          this.showEvidenceModalSourceError(modalEl, this.t('evidence_add_modal_source_required'));
          return;
        }
        throw new Error(data.error || this.t('evidence_upload_failed'));
      }

      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      this.reloadReviewWithQuery({ open: measureId }, null, measureId);
    } catch (err) {
      console.error(err);
      if (this.isEvidenceModalSourceRequiredError(err?.message)) {
        this.showEvidenceModalSourceError(modalEl, this.t('evidence_add_modal_source_required'));
        return;
      }
      this.showModal(this.t('modal.error_title'), err.message || this.t('evidence_upload_failed'));
    } finally {
      btn.disabled = false;
    }
  }

  isEvidenceModalSourceRequiredError(error) {
    const value = String(error || '').trim();
    return [
      'evidence_add_modal_source_required',
      this.t('evidence_add_modal_source_required'),
      this.t('evidence_add_modal_source_required_inline'),
      'Selecciona una fuente de verificación.',
      'Debes seleccionar una fuente de verificación.',
      'Select a verification source.',
      'You must select a verification source.'
    ].includes(value);
  }

  deleteEvidence(event) {
    const btn = event.currentTarget;
    const measureId = btn.dataset.measureId;
    const file = btn.dataset.file;
    if (!file) return;
    if (!confirm(this.t('evidence_delete_confirm'))) return;

    const body = new URLSearchParams({ measureId, file });

    fetch('/index.php/backend/plan/delete-evidence', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body.toString()
    })
      .then(r => r.json())
      .then(data => {
        if (!data?.success) throw new Error(data?.error || this.t('evidence_delete_error'));
        this.reloadReviewWithQuery({ open: measureId }, null, measureId);
      })
      .catch(err => {
        console.error(err);
        this.showModal(this.t('modal.error_title'), err.message || this.t('evidence_delete_failed'));
      });
  }

  showEvidenceError(messageHtml) {
    if (this.hasErrorModalBodyTarget) this.errorModalBodyTarget.innerHTML = messageHtml;
    const modalEl = document.getElementById('evidenceErrorModal');
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  getEvidenceCount(measureContainer, measureId) {
    const wrapper = measureContainer?.querySelector(`[data-measure-id="${measureId}"][data-evidence-count]`);
    if (!wrapper) return 0;

    const parsed = Number.parseInt(wrapper.dataset.evidenceCount || '0', 10);
    return Number.isNaN(parsed) ? 0 : parsed;
  }

  async persistSelectionField(url, measureId, field, value) {
    const body = new URLSearchParams({ measureId, field, value });
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || this.t('save_error_generic'));
    }

    return data;
  }

  showModal(title = this.t('modal.default_title'), html = '', { size = 'md' } = {}) {
    if (this.hasModalDialogTarget) {
      const dlg = this.modalDialogTarget;
      dlg.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
      if (size === 'sm') dlg.classList.add('modal-sm');
      else if (size === 'lg') dlg.classList.add('modal-lg');
      else if (size === 'xl') dlg.classList.add('modal-xl');
    }

    if (this.hasModalTitleTarget) this.modalTitleTarget.textContent = title;
    if (this.hasModalBodyTarget) this.modalBodyTarget.innerHTML = html;

    const modalEl = document.getElementById('appModal');
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  showSpinner() { if (this.hasSpinnerTarget) this.spinnerTarget.classList.remove('d-none'); }
  hideSpinner() { if (this.hasSpinnerTarget) this.spinnerTarget.classList.add('d-none'); }
}
