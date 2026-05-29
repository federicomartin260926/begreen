import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';

export default class extends Controller {
  static targets = ['block'];
  static values = {
    measureBlocksBase: String,
    selectProtocolFirst: String,
    allBlocks: String,
    noBlocks: String,
  };

  connect() {
    this.tableSelector = '#measures-table';
    this.blockFilterFn = this.blockFilterFn.bind(this);
    $.fn.dataTable.ext.search.push(this.blockFilterFn);

    const protocolId = this.getSelectedProtocolId();
    if (protocolId) {
      this.loadBlocksForProtocol(protocolId);
      return;
    }

    this.resetBlockSelect(this.selectProtocolFirstValue || 'Seleccione un protocolo primero', true);
  }

  onProtocolChange() {
    const protocolId = this.getSelectedProtocolId();
    if (!protocolId) {
      this.resetBlockSelect(this.selectProtocolFirstValue || 'Seleccione un protocolo primero', true);
      return;
    }

    this.loadBlocksForProtocol(protocolId);
  }

  onBlockChange() {
    this.redrawTable();
  }

  reset() {
    this.resetBlockSelect(this.selectProtocolFirstValue || 'Seleccione un protocolo primero', true);
  }

  getSelectedProtocolId() {
    const protocolSelect = this.element.querySelector('#filter-protocol');
    if (!protocolSelect) {
      return '';
    }

    const selectedOption = protocolSelect.selectedOptions?.[0] || null;
    return String(selectedOption?.dataset?.protocolId || '');
  }

  async loadBlocksForProtocol(protocolId) {
    const blockSelect = this.blockTarget;
    if (!blockSelect) {
      return;
    }

    this.setBlockLoading(true);
    this.dispatchBlockChange();

    try {
      const url = `${this.measureBlocksBaseValue}?id=${encodeURIComponent(protocolId)}`;
      const response = await fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const data = await response.json();
      this.populateBlockSelect(Array.isArray(data) ? data : []);
    } catch (error) {
      console.error('Error cargando bloques para el filtro de medidas:', error);
      this.populateBlockSelect([]);
    } finally {
      this.setBlockLoading(false);
    }
  }

  populateBlockSelect(items) {
    const select = this.blockTarget;
    if (!select) {
      return;
    }

    while (select.firstChild) {
      select.removeChild(select.firstChild);
    }

    const hasItems = Array.isArray(items) && items.length > 0;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = hasItems
      ? (this.allBlocksValue || 'Todos los bloques')
      : (this.noBlocksValue || 'Sin bloques');
    select.appendChild(placeholder);

    if (hasItems) {
      items.forEach(({ id, name }) => {
        const option = document.createElement('option');
        option.value = String(name ?? '');
        option.textContent = String(name ?? '');
        option.dataset.blockId = String(id);
        select.appendChild(option);
      });
      select.disabled = false;
    } else {
      select.disabled = true;
    }

    this.dispatchBlockChange();
  }

  resetBlockSelect(placeholderText, disabled = false) {
    const select = this.blockTarget;
    if (!select) {
      return;
    }

    while (select.firstChild) {
      select.removeChild(select.firstChild);
    }

    const option = document.createElement('option');
    option.value = '';
    option.textContent = placeholderText;
    select.appendChild(option);
    select.disabled = disabled;
    select.value = '';
    this.dispatchBlockChange();
  }

  setBlockLoading(loading) {
    const select = this.blockTarget;
    if (!select) {
      return;
    }

    select.disabled = !!loading;
    if (!loading) {
      return;
    }

    while (select.firstChild) {
      select.removeChild(select.firstChild);
    }

    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'Cargando…';
    select.appendChild(option);
  }

  dispatchBlockChange() {
    const select = this.blockTarget;
    if (!select) {
      return;
    }

    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  redrawTable() {
    const table = document.querySelector(this.tableSelector);
    if (!table || !$.fn.dataTable.isDataTable(table)) {
      return;
    }

    $(table).DataTable().draw();
  }

  blockFilterFn(settings, data) {
    const table = document.querySelector(this.tableSelector);
    if (!table || settings.nTable !== table) {
      return true;
    }

    const selectedBlock = this.normalizeText(this.blockTarget?.value || '');
    if (!selectedBlock) {
      return true;
    }

    const rowBlock = this.normalizeText(String(data?.[14] || ''));
    return rowBlock !== '' && rowBlock === selectedBlock;
  }

  normalizeText(value) {
    return String(value || '')
      .replace(/<[^>]*>/g, '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  disconnect() {
    if (this.blockFilterFn) {
      const filters = $.fn.dataTable.ext.search;
      const index = filters.indexOf(this.blockFilterFn);
      if (index !== -1) {
        filters.splice(index, 1);
      }
    }
  }
}
