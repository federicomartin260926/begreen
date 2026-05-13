// assets/controllers/project_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['form', 'list'];
  static values = {
    // pon 500 para activar búsqueda con debounce al teclear
    debounce: { type: Number, default: 0 }
  };

  connect() {
    console.log('ProjectController connected');

    // Fallback: si no hay target="form", usamos el elemento con data-controller
    this._formEl = this.hasFormTarget ? this.formTarget : this.element;

    // Auto-submit para selects y fechas
    this._onChange = () => this._formEl.requestSubmit();
    this._formEl.querySelectorAll('select, input[type="date"]').forEach(el => {
      el.addEventListener('change', this._onChange);
    });

    // Botón limpiar (si existe)
    const resetBtn = this._formEl.querySelector('#reset-filters');
    if (resetBtn) {
      this._onResetClick = (e) => {
        e.preventDefault();
        this.resetFilters();
      };
      resetBtn.addEventListener('click', this._onResetClick);
    }

    // (Opcional) auto-submit al teclear en inputs de texto con debounce
    if (this.debounceValue > 0) {
      this._onTyping = this._debounce(() => this._formEl.requestSubmit(), this.debounceValue);
      this._formEl
        .querySelectorAll('input[type="text"], input[type="search"], input[type="email"]')
        .forEach(el => el.addEventListener('keyup', this._onTyping));
    }
  }

  disconnect() {
    if (!this._formEl) return;

    if (this._onChange) {
      this._formEl.querySelectorAll('select, input[type="date"]').forEach(el => {
        el.removeEventListener('change', this._onChange);
      });
    }

    const resetBtn = this._formEl.querySelector('#reset-filters');
    if (resetBtn && this._onResetClick) {
      resetBtn.removeEventListener('click', this._onResetClick);
    }

    if (this._onTyping) {
      this._formEl
        .querySelectorAll('input[type="text"], input[type="search"], input[type="email"]')
        .forEach(el => el.removeEventListener('keyup', this._onTyping));
    }
  }

  // Acción pública por si la usas con data-action="change->project#submit"
  submit() {
    (this._formEl || this.formTarget).requestSubmit();
  }

  // Acción pública para limpiar con data-action="click->project#reset"
  reset(event) {
    if (event) event.preventDefault();
    this.resetFilters();
  }

  // --- helpers ---
  resetFilters() {
    const f = this._formEl;
    f.querySelectorAll('input[type="text"], input[type="search"], input[type="email"]').forEach(i => i.value = '');
    f.querySelectorAll('input[type="date"]').forEach(i => i.value = '');
    f.querySelectorAll('select').forEach(s => s.value = '');
    f.requestSubmit();
  }

  _debounce(fn, ms) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }
}
