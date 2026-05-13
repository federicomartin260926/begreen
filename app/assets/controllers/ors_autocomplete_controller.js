import { Controller } from '@hotwired/stimulus';

// Pega aquí tu API key de ORS:
const ORS_API_KEY = 'eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6IjQ2ODcwZjM3NTQyYjRjOGJhYWVhNjA3MWI0NjBmYzNmIiwiaCI6Im11cm11cjY0In0=';

export default class extends Controller {
    static targets = ['input', 'suggestions'];

    connect() {
        // Borra sugerencias al perder foco si no se selecciona ninguna
        document.addEventListener('click', (e) => {
            if (!this.element.contains(e.target)) {
                this.suggestionsTarget.innerHTML = '';
            }
        });
    }

    inputTargetConnected(element) {
        element.addEventListener('input', (e) => this.onInput(e));
        element.addEventListener('focus', (e) => this.onInput(e)); // Muestra sugerencias al focus
    }

    async onInput(e) {
        const value = e.target.value.trim();
        if (value.length < 3) {
            this.suggestionsTarget.innerHTML = '';
            return;
        }

        const url = `https://api.openrouteservice.org/geocode/autocomplete?api_key=${ORS_API_KEY}&text=${encodeURIComponent(value)}&size=5&boundary.country=ES`;
        const res = await fetch(url);
        const data = await res.json();

        this.suggestionsTarget.innerHTML = '';
        if (data.features) {
            data.features.forEach(feature => {
                const item = document.createElement('div');
                item.className = 'ors-suggestion';
                item.textContent = feature.properties.label;
                item.dataset.lat = feature.geometry.coordinates[1];
                item.dataset.lon = feature.geometry.coordinates[0];
                item.addEventListener('mousedown', () => {
                    this.inputTarget.value = feature.properties.label;
                    this.inputTarget.dataset.lat = feature.geometry.coordinates[1];
                    this.inputTarget.dataset.lon = feature.geometry.coordinates[0];
                    this.suggestionsTarget.innerHTML = '';
                });
                this.suggestionsTarget.appendChild(item);
            });
        }
    }
}
