import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container', 'prototype'];

    static values = {
        allowAdd: Boolean,
        allowDelete: Boolean,
        prototypeName: String,
        positionsUrlTemplate: String,
        i18nPlaceholderPosition: String,
        i18nNetworkError: String
    }

    connect() {
        this.crewIndex = this.containerTarget.querySelectorAll('[data-crew-card]').length;

        this.containerTarget
            .querySelectorAll('[data-crew-card]')
            .forEach(card => this.initCrewCard(card));
    }

    addCrewMember(event) {
        event.preventDefault();
        if (!this.allowAddValue) return;

        const html = this.prototypeTarget.innerHTML.replaceAll(this.prototypeNameValue, this.crewIndex);
        this.crewIndex++;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const card = wrapper.firstElementChild;
        if (!card) return;

        this.containerTarget.appendChild(card);
        this.initCrewCard(card);
    }

    removeCrewMember(event) {
        event.preventDefault();
        if (!this.allowDeleteValue) return;

        event.currentTarget.closest('[data-crew-card]')?.remove();
    }

    addAssignment(event) {
        event.preventDefault();
        if (!this.allowAddValue) return;

        const card = event.currentTarget.closest('[data-crew-card]');
        const container = card?.querySelector('[data-crew-assignment-container]');
        const prototype = card?.querySelector('[data-crew-assignment-prototype]');
        if (!card || !container || !prototype) return;

        const index = Number.parseInt(card.dataset.assignmentIndex || '0', 10);
        const html = prototype.innerHTML.replaceAll('__assignment__', String(index));
        card.dataset.assignmentIndex = String(index + 1);

        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        if (!row) return;

        container.appendChild(row);
        this.initAssignmentRow(row);
    }

    removeAssignment(event) {
        event.preventDefault();
        if (!this.allowDeleteValue) return;

        event.currentTarget.closest('[data-crew-assignment-row]')?.remove();
    }

    departmentChanged(event) {
        const departmentSelect = event.currentTarget;
        if (!(departmentSelect instanceof HTMLSelectElement)) return;

        const row = departmentSelect.closest('[data-crew-assignment-row]');
        if (!row) return;

        this.loadPositions(row);
    }

    initCrewCard(card) {
        card.querySelectorAll('[data-crew-assignment-row]')
            .forEach(row => this.initAssignmentRow(row));
    }

    initAssignmentRow(row) {
        const positionSelect = row.querySelector('[data-crew-assignment-position]');
        const desiredPosition = positionSelect instanceof HTMLSelectElement ? positionSelect.value : '';

        this.loadPositions(row, desiredPosition);
    }

    async loadPositions(row, desiredPosition = '') {
        const departmentSelect = row.querySelector('[data-crew-assignment-department]');
        const positionSelect = row.querySelector('[data-crew-assignment-position]');
        if (!(departmentSelect instanceof HTMLSelectElement) || !(positionSelect instanceof HTMLSelectElement)) return;

        const departmentId = departmentSelect.value;
        this.fillPositions(positionSelect, []);
        positionSelect.disabled = true;

        if (!departmentId) return;

        positionSelect.dataset.loadingDepartment = departmentId;

        try {
            const positions = await this.fetchPositions(departmentId);
            if (departmentSelect.value !== departmentId) return;

            this.fillPositions(positionSelect, positions, desiredPosition);
            positionSelect.disabled = false;
        } catch (error) {
            if (departmentSelect.value !== departmentId) return;

            this.fillPositions(positionSelect, []);
            positionSelect.disabled = false;
            positionSelect.title = this.i18nNetworkErrorValue;
        } finally {
            if (positionSelect.dataset.loadingDepartment === departmentId) {
                delete positionSelect.dataset.loadingDepartment;
            }
        }
    }

    async fetchPositions(departmentId) {
        const url = this.positionsUrlTemplateValue.replace('__department__', encodeURIComponent(departmentId));
        const response = await fetch(url, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });

        if (!response.ok) {
            throw new Error(this.i18nNetworkErrorValue);
        }

        return await response.json();
    }

    fillPositions(select, positions, desiredPosition = '') {
        select.replaceChildren();
        select.removeAttribute('title');

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = this.i18nPlaceholderPositionValue;
        select.appendChild(placeholder);

        positions.forEach(position => {
            const option = document.createElement('option');
            option.value = String(position.id);
            option.textContent = position.name;
            select.appendChild(option);
        });

        const desired = String(desiredPosition || '');
        const exists = desired !== '' && Array.from(select.options).some(option => option.value === desired);
        select.value = exists ? desired : '';
    }
}
