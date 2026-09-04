import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'suggestions'];
    static values = { errorMessage: String };

    connect() {
        this.closeSuggestions = (e) => {
            if (!this.element.contains(e.target)) {
                this.suggestionsTarget.innerHTML = '';
            }
        };
        document.addEventListener('click', this.closeSuggestions);
    }

    disconnect() {
        document.removeEventListener('click', this.closeSuggestions);
        clearTimeout(this.searchTimer);
        this.searchRequest?.abort();
    }

    inputTargetConnected(element) {
        element.addEventListener('input', (e) => this.onInput(e));
        element.addEventListener('focus', (e) => this.onInput(e)); // Muestra sugerencias al focus
    }

    onInput(e) {
        const value = e.target.value.trim();
        if (e.type === 'input') {
            delete e.target.dataset.lat;
            delete e.target.dataset.lon;
        }
        clearTimeout(this.searchTimer);
        this.searchRequest?.abort();

        if (value.length < 3) {
            this.suggestionsTarget.innerHTML = '';
            return;
        }

        this.searchTimer = setTimeout(() => this.loadSuggestions(value), 250);
    }

    async loadSuggestions(value) {
        this.searchRequest = new AbortController();

        try {
            const url = `/index.php/backend/emission/location-autocomplete?text=${encodeURIComponent(value)}`;
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.searchRequest.signal,
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || this.errorMessageValue);
            }

            this.suggestionsTarget.innerHTML = '';
            const results = Array.isArray(data.results) ? data.results : [];
            results.forEach(result => {
                const item = document.createElement('div');
                item.className = 'ors-suggestion';
                item.textContent = result.label;
                item.addEventListener('mousedown', () => {
                    this.inputTarget.value = result.label;
                    this.inputTarget.dataset.lat = result.latitude;
                    this.inputTarget.dataset.lon = result.longitude;
                    this.suggestionsTarget.innerHTML = '';
                });
                this.suggestionsTarget.appendChild(item);
            });
        } catch (error) {
            if (error.name === 'AbortError') return;

            this.suggestionsTarget.innerHTML = '';
            const item = document.createElement('div');
            item.className = 'ors-suggestion text-danger';
            item.textContent = this.errorMessageValue;
            this.suggestionsTarget.appendChild(item);
        }
    }
}
