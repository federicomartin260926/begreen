import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['protocol', 'department', 'measureBlock', 'evidenceCount', 'evidenceBadges', 'evidenceEmpty', 'evidenceUnsaved'];
  static values  = {
    departmentsBase: String,
    measureBlocksBase: String,
    currentDepartment: String,
    currentMeasureBlock: String
  };

  connect() {
    // The current form values are the initial saved state; no AJAX save is performed.
    this.initialEvidenceValues = new Map(this.evidenceControls().map((select) => [select.name, select.value]));
    // Si ya hay un protocolo seleccionado al entrar (edit o default), cargar departamentos
    const protoId = this.protocolTarget?.value;
    if (protoId) {
      // Symfony already rendered the full multiple selection; preserve it across AJAX.
      const selectedIds = Array.from(this.departmentTarget.selectedOptions)
        .map((option) => option.value)
        .filter(Boolean);
      const preselectIds = selectedIds.length
        ? selectedIds
        : (this.currentDepartmentValue ? [this.currentDepartmentValue] : []);
      this.loadDepartments(protoId, preselectIds);
      this.loadMeasureBlocks(protoId, this.currentMeasureBlockValue || null);
    }
  }

  evidenceControls() {
    return Array.from(this.element.querySelectorAll('select[data-evidence-quality="1"]'));
  }

  onEvidenceQualityChange() {
    const selected = this.evidenceControls().filter((select) => select.value !== '');
    if (this.hasEvidenceCountTarget) this.evidenceCountTarget.textContent = String(selected.length);

    if (this.hasEvidenceBadgesTarget) {
      this.evidenceBadgesTarget.replaceChildren();
      for (const select of selected) {
        const badge = document.createElement('span');
        badge.className = 'badge rounded-pill text-bg-light border text-dark fw-normal';
        badge.textContent = `${select.dataset.evidenceLabel || ''} · ${select.value}`;
        this.evidenceBadgesTarget.appendChild(badge);
      }
      this.evidenceBadgesTarget.classList.toggle('d-none', selected.length === 0);
    }
    if (this.hasEvidenceEmptyTarget) this.evidenceEmptyTarget.classList.toggle('d-none', selected.length !== 0);
    if (this.hasEvidenceUnsavedTarget) {
      const dirty = this.evidenceControls().some((select) =>
        select.value !== this.initialEvidenceValues.get(select.name)
      );
      this.evidenceUnsavedTarget.classList.toggle('d-none', !dirty);
    }
  }

  onProtocolChange(event) {
    const protoId = event.currentTarget.value;
    // reset dept mientras carga
    this.populateDepartment([]);
    this.populateMeasureBlock([]);
    if (protoId) {
      this.loadDepartments(protoId, []);
      this.loadMeasureBlocks(protoId, null);
    }
  }

  async loadDepartments(protocolId, preselectIds = []) {
    const url = `${this.departmentsBaseValue}?id=${encodeURIComponent(protocolId)}`;

    // Deshabilitar select y poner "Cargando…"
    this.setDepartmentLoading(true);

    try {
      const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
      const data = await resp.json(); // [{id,name}, ...]

      this.populateDepartment(data, preselectIds);
    } catch (e) {
      console.error('Error cargando departamentos:', e);
      this.populateDepartment([]);
    } finally {
      this.setDepartmentLoading(false);
    }
  }

  populateDepartment(items, preselectIds = []) {
    const select = this.departmentTarget;
    if (!select) return;
    const selectedIds = new Set((preselectIds ?? []).map(String));

    // Vaciar
    while (select.firstChild) select.removeChild(select.firstChild);

    // Placeholder
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = items && items.length ? 'Seleccione un departamento' : '— No hay departamentos —';
    select.appendChild(ph);

    // Opciones
    (items || []).forEach(({id, name}) => {
      const opt = document.createElement('option');
      opt.value = String(id);
      opt.textContent = name;
      if (selectedIds.has(String(id))) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });
  }

  setDepartmentLoading(loading) {
    const select = this.departmentTarget;
    if (!select) return;

    select.disabled = !!loading;
    if (loading) {
      // Mostrar placeholder "Cargando…"
      while (select.firstChild) select.removeChild(select.firstChild);
      const ph = document.createElement('option');
      ph.value = '';
      ph.textContent = 'Cargando…';
      select.appendChild(ph);
    }
  }

  async loadMeasureBlocks(protocolId, preselectId = null) {
    const url = `${this.measureBlocksBaseValue}?id=${encodeURIComponent(protocolId)}`;

    this.setMeasureBlockLoading(true);

    try {
      const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
      const data = await resp.json();

      this.populateMeasureBlock(data, preselectId);
    } catch (e) {
      console.error('Error cargando bloques:', e);
      this.populateMeasureBlock([]);
    } finally {
      this.setMeasureBlockLoading(false);
    }
  }

  populateMeasureBlock(items, preselectId = null) {
    const select = this.measureBlockTarget;
    if (!select) return;

    while (select.firstChild) select.removeChild(select.firstChild);

    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = items && items.length ? 'Seleccione un bloque' : '— Sin bloques —';
    select.appendChild(ph);

    (items || []).forEach(({id, name}) => {
      const opt = document.createElement('option');
      opt.value = String(id);
      opt.textContent = name;
      if (preselectId && String(preselectId) === String(id)) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });
  }

  setMeasureBlockLoading(loading) {
    const select = this.measureBlockTarget;
    if (!select) return;

    select.disabled = !!loading;
    if (loading) {
      while (select.firstChild) select.removeChild(select.firstChild);
      const ph = document.createElement('option');
      ph.value = '';
      ph.textContent = 'Cargando…';
      select.appendChild(ph);
    }
  }
}
