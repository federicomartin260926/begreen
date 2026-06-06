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
    // --- Filtros ---
    const filtersForm = this.element.querySelector('#plan-filters-form');
    if (filtersForm) {
      filtersForm.querySelectorAll('select, input[type="checkbox"]').forEach(input => {
        input.addEventListener('change', () => filtersForm.submit());
      });

      const resetBtn = filtersForm.querySelector('#reset-filters');
      if (resetBtn) {
        resetBtn.addEventListener('click', (e) => {
          e.preventDefault();
          filtersForm.querySelectorAll('select').forEach(s => { s.value = ''; });
          filtersForm.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
          filtersForm.submit();
        });
      }
    }

    // Botones de collapse: icono/estilo
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach((btn) => {
      const icon = btn.querySelector('i');
      const targetSelector = btn.getAttribute('data-bs-target');
      const target = document.querySelector(targetSelector);
      if (!target) return;

      const onShown = () => {
        if (icon) { icon.classList.remove('bi-chevron-right'); icon.classList.add('bi-chevron-down'); }
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-primary');
      };
      const onHidden = () => {
        if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-right'); }
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline-primary');
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
    if (btn) btn.disabled = true;

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
      if (btn) btn.disabled = false;
    }
  }

  async toggleImplemented(event) {
    const input = event.currentTarget;
    const checked = input.checked;
    const measureId = input.dataset.measureId;
    const url = input.dataset.url;
    const allowed = input.dataset.allowed === '1';

    if (!allowed) {
      input.checked = false;
      this.showModal(this.t('modal.notice_title'), this.t('implemented_require_will_html'));
      return;
    }

    const prevChecked = !checked;
    const body = new URLSearchParams({ measureId, field: 'implemented', value: checked ? 'true' : 'false' });

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        input.checked = prevChecked;
        throw new Error(data.error || this.t('save_error_generic'));
      }

      const badge = document.querySelector(`[data-role="badge-implemented"][data-measure-id="${measureId}"]`);
      if (badge) {
        badge.classList.remove('bg-success', 'bg-secondary');
        if (checked) {
          badge.classList.add('bg-success');
          badge.textContent = this.t('implemented_yes');
        } else {
          badge.classList.add('bg-secondary');
          badge.textContent = this.t('implemented_no');
        }
      }
    } catch (err) {
      console.error(err);
      this.showModal(this.t('modal.error_title'), err.message || this.t('network_error'));
    }
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
      else { if (input.value) filters[key] = input.value; }
    });
    return filters;
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

  toggleCriticalReasonVisibility(e) {
    const inlineBox = e.currentTarget.closest('[data-plan-review-target="inlineEdit"]') || this.element;
    const group = inlineBox.querySelector('[data-critical-reason-group]');
    if (!group) return;

    const val = String(e.currentTarget.value).toLowerCase(); // "true" | "false"
    if (val === 'true') {
      group.style.display = '';
    } else {
      group.style.display = 'none';
      const input = group.querySelector('input, textarea');
      if (input) input.value = '';
    }
  }

  onAppliesChange(e) {
    const box = e.currentTarget.closest('[data-plan-review-target="inlineEdit"]');
    if (!box) return;

    const appliesYes = e.currentTarget.value === 'true';
    const critYes  = box.querySelector('input[id^="critical-yes-"]');
    const critNo   = box.querySelector('input[id^="critical-no-"]');
    const implYes  = box.querySelector('input[id^="implement-yes-"]');
    const implNo   = box.querySelector('input[id^="implement-no-"]');
    const reasonGp = box.querySelector('[data-critical-reason-group]');
    const reasonEl = box.querySelector('#' + (reasonGp?.querySelector('input,textarea')?.id || ''));
    const setDisabled = (el, disabled) => { if (el) el.disabled = disabled; };

    if (!appliesYes) {
      [critYes, critNo, implYes, implNo].forEach(el => { if (el) { el.checked = false; setDisabled(el, true); } });
      if (reasonGp) reasonGp.style.display = 'none';
      if (reasonEl) reasonEl.value = '';
    } else {
      [critYes, critNo, implYes, implNo].forEach(el => setDisabled(el, false));
      const criticalCheckedYes = critYes && critYes.checked;
      if (reasonGp) reasonGp.style.display = criticalCheckedYes ? '' : 'none';
    }
  }

  async saveInlineEdit(event) {
    const btn = event.currentTarget;
    const measureId = btn.dataset.measureId;
    const inlineBox = document.getElementById(`inline-edit-${measureId}`);
    const clickedInEstado = !!btn.closest(`#inline-edit-${measureId}`);
    const canSend = (el) => el && !el.disabled && el.dataset.locked !== '1';
    const updates = [];

    if (clickedInEstado && inlineBox) {
      // ======= BLOQUE: ESTADO EN EL PLAN =======
      const getRadioVal = (name) => {
        const el = inlineBox.querySelector(`input[name="${name}-${measureId}"]:checked`);
        return el ? el.value : null;
      };

      const valApplies   = getRadioVal('applies');
      const valCritical  = getRadioVal('critical');
      const valImplement = getRadioVal('implement');

      const textReasonEl = inlineBox.querySelector(`#critical-reason-${measureId}`);
      const reasonText   = textReasonEl ? (textReasonEl.value || '').trim() : '';

      if (valApplies === 'false') {
        updates.push({ field: 'isApplicable', value: 'false' });
      } else {
        if (valApplies === 'true' && (valCritical === null || valImplement === null)) {
          this.showModal(this.t('modal.missing_title'), this.t('save_missing_fields_html'));
          return;
        }
        if (valCritical === 'true' && reasonText === '') {
          this.showModal(this.t('modal.missing_title'), this.t('critical_reason_required_html'));
          return;
        }
        if (valApplies !== null)    updates.push({ field: 'isApplicable',    value: valApplies });
        if (valCritical !== null)   updates.push({ field: 'critical',        value: valCritical });
        if (valCritical === 'true') updates.push({ field: 'critical_reason', value: reasonText });
        if (valImplement !== null)  updates.push({ field: 'willImplement',   value: valImplement });
      }
    } else {
      // ======= BLOQUE: ACCIONES =======
      const implementedEl   = document.getElementById(`implemented-${measureId}`);
      const verificationEl  = document.getElementById(`verification-${measureId}`);
      const actionTakenEl   = document.getElementById(`action-taken-${measureId}`);
      const observationsEl  = document.getElementById(`observations-${measureId}`);
      const internalNotesEl = document.getElementById(`internal-notes-${measureId}`);
      const responsiblesEl  = document.getElementById(`responsibles-${measureId}`);

      if (implementedEl && !implementedEl.disabled) {
        updates.push({ field: 'implemented', value: implementedEl.checked ? 'true' : 'false' });
      }
      if (canSend(verificationEl)) updates.push({ field: 'verification', value: verificationEl.checked ? 'true' : 'false' });
      if (canSend(actionTakenEl))  updates.push({ field: 'action_taken', value: (actionTakenEl.value || '').trim() });
      if (canSend(observationsEl)) updates.push({ field: 'observations', value: (observationsEl.value || '').trim() });
      if (canSend(internalNotesEl)) updates.push({ field: 'internal_notes', value: (internalNotesEl.value || '').trim() });
      if (responsiblesEl && !responsiblesEl.disabled) {
        const selectedResponsibleIds = Array.from(responsiblesEl.selectedOptions || []).map(opt => opt.value).join(',');
        updates.push({ field: 'responsibles', value: selectedResponsibleIds });
      }

      // Evidencias
      const filesInput = document.getElementById(`evidence-files-${measureId}`);
      if (filesInput && filesInput.files && filesInput.files.length > 0) {
        const form = new FormData();
        for (const f of filesInput.files) form.append('files[]', f);

        const upRes  = await fetch('/index.php/backend/plan/upload-evidences', { method: 'POST', body: form });
        const raw    = await upRes.text();
        let upData   = null;
        try { upData = JSON.parse(raw); } catch (_) {
          throw new Error(this.t('upload_invalid_response'));
        }
        if (!upRes.ok || !upData?.success || !Array.isArray(upData.files) || upData.files.length === 0) {
          const msg = (upData && upData.error) ? upData.error : this.t('upload_failed_http', { code: upRes.status });
          throw new Error(msg);
        }
        const evidenceJson = JSON.stringify(upData.files);
        updates.push({ field: 'evidence', value: evidenceJson });
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

      // Mantener la medida abierta
      const params = new URLSearchParams(window.location.search);
      params.set('open', String(measureId));
      window.location.search = params.toString();
    } catch (err) {
      console.error(err);
      this.showModal(this.t('modal.error_title'), err.message || this.t('network_error'));
      btn.disabled = false;
    }
  }

  uploadEvidences(event) {
    const input = event.currentTarget;
    if (!input.files || input.files.length === 0) return;
    const measureId = input.dataset.measureId;
    const files = input.files;
    if (!files || files.length === 0) return;

    const fd = new FormData();
    fd.append('measureId', measureId);
    for (const f of files) fd.append('evidences[]', f);

    fetch('/index.php/backend/plan/upload-evidences', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    })
      .then(r => r.json())
      .then(data => {
        if (!data?.success) throw new Error(data?.error || this.t('evidence_upload_failed'));
        const params = new URLSearchParams(window.location.search);
        params.set('open', String(measureId));
        window.location.search = params.toString();
      })
      .catch(err => {
        console.error(err);
        this.showModal(this.t('modal.error_title'), err.message || this.t('evidence_upload_failed'));
      });
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
        const params = new URLSearchParams(window.location.search);
        params.set('open', String(measureId));
        window.location.search = params.toString();
      })
      .catch(err => {
        console.error(err);
        this.showModal(this.t('modal.error_title'), err.message || this.t('evidence_delete_failed'));
      });
  }

  validateEvidenceFiles(event) {
    const input = event.currentTarget;
    const files = Array.from(input.files || []);
    if (files.length === 0) return;

    const MAX_FILES = 4;
    const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    const tooMany = files.length > MAX_FILES;
    const tooBig = files.filter(f => f.size > MAX_SIZE_BYTES);

    if (!tooMany && tooBig.length === 0) return;

    let html = '<div class="small">';
    if (tooMany) {
      html += `<p>${this.t('evidence_max_files', { max: MAX_FILES })}</p>`;
      html += `<p>${this.t('evidence_selected_files', { n: files.length })}</p>`;
    }
    if (tooBig.length > 0) {
      html += `<p>${this.t('evidence_max_size', { mb: 5 })}<br>${this.t('evidence_oversized_list_intro')}</p><ul class="mb-0">`;
      tooBig.forEach(f => {
        const mb = (f.size / (1024 * 1024)).toFixed(2);
        html += `<li>${f.name} — ${mb} MB</li>`;
      });
      html += '</ul>';
    }
    html += '</div>';

    input.value = '';
    this.showEvidenceError(html);
  }

  showEvidenceError(messageHtml) {
    if (this.hasErrorModalBodyTarget) this.errorModalBodyTarget.innerHTML = messageHtml;
    const modalEl = document.getElementById('evidenceErrorModal');
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
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
