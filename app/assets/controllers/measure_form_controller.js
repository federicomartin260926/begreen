import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['protocol', 'department', 'measureBlock'];
  static values  = {
    departmentsBase: String,
    measureBlocksBase: String,
    currentDepartment: String,
    currentMeasureBlock: String
  };

  connect() {
    // Si ya hay un protocolo seleccionado al entrar (edit o default), cargar departamentos
    const protoId = this.protocolTarget?.value;
    if (protoId) {
      this.loadDepartments(protoId, this.currentDepartmentValue || null);
      this.loadMeasureBlocks(protoId, this.currentMeasureBlockValue || null);
    }
  }

  onProtocolChange(event) {
    const protoId = event.currentTarget.value;
    // reset dept mientras carga
    this.populateDepartment([]);
    this.populateMeasureBlock([]);
    if (protoId) {
      this.loadDepartments(protoId, null);
      this.loadMeasureBlocks(protoId, null);
    }
  }

  async loadDepartments(protocolId, preselectId = null) {
    const url = `${this.departmentsBaseValue}?id=${encodeURIComponent(protocolId)}`;

    // Deshabilitar select y poner "Cargando…"
    this.setDepartmentLoading(true);

    try {
      const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
      const data = await resp.json(); // [{id,name}, ...]

      this.populateDepartment(data, preselectId);
    } catch (e) {
      console.error('Error cargando departamentos:', e);
      this.populateDepartment([]);
    } finally {
      this.setDepartmentLoading(false);
    }
  }

  populateDepartment(items, preselectId = null) {
    const select = this.departmentTarget;
    if (!select) return;

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
      if (preselectId && String(preselectId) === String(id)) {
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
