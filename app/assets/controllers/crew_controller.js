// assets/controllers/crew_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container', 'prototype'];
    static values  = {
        allowAdd: Boolean,
        allowDelete: Boolean,
        prototypeName: String,
        i18nPlaceholderPosition: String,
        i18nNetworkError: String
    }

    connect() {
        // índice según elementos ya renderizados
        this.index = this.containerTarget.children.length;

        // Inicializar posiciones en todos los cards al cargar
        this.initPositionsOnLoad();

        // Delegación: al cambiar departamento, refrescar cargos de ese card
        this.element.addEventListener('change', (e) => {
            const sel = e.target;
            if (!(sel instanceof HTMLSelectElement)) return;
            if (!/\[department\]$/.test(sel.name)) return;

            const card = sel.closest('.card');
            if (!card) return;

            const deptId = sel.value;
            const posSelect = card.querySelector('select[name$="[position]"]');
            if (!posSelect) return;

            if (!deptId) {
                this.fillPositions(posSelect, []);
                return;
            }

            this.fetchPositions(deptId)
                .then(options => this.fillPositions(posSelect, options))
                .catch(() => this.fillPositions(posSelect, []));
        });
    }

    // -------- Añadir/eliminar --------
    add(event) {
        event.preventDefault();
        if (!this.allowAddValue) return;

        const template = this.prototypeTarget.innerHTML;
        const newFormHtml = template.replaceAll(this.prototypeNameValue, this.index);
        this.index++;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = newFormHtml.trim();
        const card = wrapper.firstElementChild;
        this.containerTarget.appendChild(card);

        // Inicializar cargos del nuevo card según su departamento (si viene preseleccionado)
        this.initCardPositions(card);
    }

    remove(event) {
        event.preventDefault();
        if (!this.allowDeleteValue) return;

        const card = event.target.closest('.card');
        if (card) card.remove();
    }

    // -------- Inicialización al cargar --------
    initPositionsOnLoad() {
        Array.from(this.containerTarget.children).forEach(card => this.initCardPositions(card));
    }

    initCardPositions(card) {
        const deptSel = card.querySelector('select[name$="[department]"]');
        const posSel  = card.querySelector('select[name$="[position]"]');
        if (!deptSel || !posSel) return;

        // Prioriza data-current-value; si no, lo que haya en el select
        const desired = posSel.dataset.currentValue || posSel.value || '';

        if (!deptSel.value) {
            this.fillPositions(posSel, [], desired);
            return;
        }

        this.fetchPositions(deptSel.value)
            .then(options => this.fillPositions(posSel, options, desired))
            .catch(()    => this.fillPositions(posSel, [], desired));
    }

    // -------- Utilidades AJAX/DOM --------
    async fetchPositions(deptId) {
        const url = `/backend/ajax/positions-by-department/${encodeURIComponent(deptId)}`;
        const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) throw new Error(this.i18nNetworkErrorValue || 'Network error');
        return await r.json(); // [{id, name}, ...]
    }

    fillPositions(selectEl, options, desired = '') {
        // limpiar
        while (selectEl.firstChild) selectEl.removeChild(selectEl.firstChild);

        // placeholder
        const ph = document.createElement('option');
        ph.value = '';
        ph.textContent = this.i18nPlaceholderPositionValue || 'Selecciona un cargo';
        selectEl.appendChild(ph);

        // opciones
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value = String(opt.id);
            o.textContent = opt.name;
            selectEl.appendChild(o);
        });

        // seleccionar si procede
        if (desired) {
            const desiredStr = String(desired);
            const exists = Array.from(selectEl.options).some(o => o.value === desiredStr);
            selectEl.value = exists ? desiredStr : '';
        } else {
            selectEl.value = ''; // sin preselección
        }
    }
}
