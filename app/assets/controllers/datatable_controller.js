// controllers/datatable_controller.js
import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';
import esLanguage from '../datatables/i18n/es-ES.json';
import enLanguage from '../datatables/i18n/en-GB.json';

export default class extends Controller {
  connect() {
    const $table = $(this.element);
    if ($table.hasClass('datatable-initialized')) return;
    window.__BGMF_DATATABLE_MARKER__ = 'datatable-local-i18n';

    // ===== 0) Locale actual → archivo i18n =====
    const htmlLang = (document.documentElement.getAttribute('lang') || 'es').toLowerCase();
    const attrLang = ($table.data('dt-lang') || '').toLowerCase(); // override opcional por tabla
    const dtMode = ($table.data('dt-mode') || '').toLowerCase();
    const locale   = attrLang || htmlLang;
    const languageMap = {
      es: esLanguage,
      en: enLanguage,
    };
    const languageConfig = Object.assign({}, languageMap[locale] || enLanguage);

    // ===== 1) Columnas sin orden =====
    const noOrderIdx = [];
    $table.find('thead th').each(function (idx) {
      const $th = $(this);
      if ($th.hasClass('dt-nosort') || $th.is('[data-dt-nosort]')) {
        noOrderIdx.push(idx);
      }
    });

    // ===== 2) Orden inicial =====
    let initialOrder;
    const orderAttr = $table.data('dt-order');
    if (orderAttr) {
      const [col, dir] = String(orderAttr).split(',');
      if (!isNaN(parseInt(col, 10)) && (dir === 'asc' || dir === 'desc')) {
        initialOrder = [[parseInt(col, 10), dir]];
      }
    }
    if (!initialOrder) initialOrder = [[0, 'asc']];

    const compactDetailsMode = dtMode === 'compact-details';
    const columnDefs = [];

    if (compactDetailsMode) {
      columnDefs.push(
        {
          targets: 0,
          className: 'dtr-control',
          orderable: false,
          searchable: false,
          width: '1%'
        },
        {
          targets: [3, 4, 5, 6, 7, 8, 9, 10, 11, 14, 15, 16, 17, 18, 19],
          className: 'none'
        },
        {
          targets: 1,
          className: 'measure-main-cell'
        },
        {
          targets: [2, 12, 13, 20],
          className: 'text-nowrap'
        }
      );
    }

    const options = {
      responsive: compactDetailsMode
        ? {
            details: {
              type: 'column',
              target: 0,
            },
          }
        : true,
      language: $.extend(true, {}, languageConfig),
      order: initialOrder,
      bgmBuildMarker: 'datatable-local-i18n'
    };

    if (noOrderIdx.length) {
      columnDefs.push({ orderable: false, targets: noOrderIdx });
    }

    if (columnDefs.length) {
      options.columnDefs = columnDefs;
    }

    const datatable = $table.DataTable(options);
    $table.addClass('datatable-initialized');

    // ========== Filtros por igualdad (selects) ==========
    $('[data-dt-filter]').on('change', function () {
      const columnIndex = parseInt($(this).data('dt-filter'));
      const value = $(this).val();
      datatable.column(columnIndex).search(value).draw();
    });

    // ========== Filtro por rango (min/max) ==========
    this.setupRangeFilter($table, datatable);

    // Reset filtros
    $('#reset-dt-filters').on('click', () => {
      // limpia selects (filtros por igualdad)
      $('[data-dt-filter]').val('');
      datatable.columns().search('').draw();

      // limpia rangos SOLO de esta tabla
      const tableId = $table.attr('id');
      if (tableId) {
        const selFor = `#${tableId}`;
        $(`[data-dt-range-min][data-dt-for="${selFor}"], [data-dt-range-max][data-dt-for="${selFor}"]`).val('');
      }

      // redibuja para aplicar el cambio de rango
      datatable.draw();
    });

    // Actualización dinámica (ej. checkboxes)
    $table.on('change', 'input[type="checkbox"]', function () {
      const td = this.closest('td');
      const span = td?.querySelector('.filter-value');
      if (span) {
        span.textContent = this.checked ? 'true' : 'false';
        datatable.cell(td).invalidate().draw(false);
      }
    });
  }

  setupRangeFilter($table, datatable) {
    const tableDom = $table.get(0);
    const tableId  = $table.attr('id');
    if (!tableId) return; // necesitamos id para asociar inputs

    const selFor = `#${tableId}`;
    const $min = $(`[data-dt-range-min][data-dt-for="${selFor}"]`).first();
    const $max = $(`[data-dt-range-max][data-dt-for="${selFor}"]`).first();
    if (!$min.length || !$max.length) return; // exigir ambos

    const minIdx = parseInt($min.attr('data-dt-range-min'), 10);
    const maxIdx = parseInt($max.attr('data-dt-range-max'), 10);
    if (!Number.isInteger(minIdx) || !Number.isInteger(maxIdx) || minIdx !== maxIdx) return;

    const colIdx = minIdx;

    const toNumber = (raw) => {
      if (raw == null) return NaN;
      const text = $('<div>').html(String(raw)).text().trim(); // limpia posible HTML
      const m = text.match(/-?\d+(?:[.,]\d+)?/);
      return m ? parseFloat(m[0].replace(',', '.')) : NaN;
    };

    const filterFn = (settings, data) => {
      if (settings.nTable !== tableDom) return true; // solo esta tabla

      const minVal = $min.val() === '' ? null : parseFloat($min.val());
      const maxVal = $max.val() === '' ? null : parseFloat($max.val());
      if (minVal === null && maxVal === null) return true;

      const cellVal = toNumber(data[colIdx]);
      if (Number.isNaN(cellVal)) return false;
      if (minVal !== null && cellVal < minVal) return false;
      if (maxVal !== null && cellVal > maxVal) return false;
      return true;
    };

    $.fn.dataTable.ext.search.push(filterFn);

    const redraw = () => datatable.draw();
    $min.on('input change keyup', redraw);
    $max.on('input change keyup', redraw);
  }
}
