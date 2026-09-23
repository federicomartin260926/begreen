import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['segments', 'segmentTemplate', 'participantTemplate'];

  static values = {
    duplicateMessage: String,
    driverMessage: String,
    confirmMessage: String,
    autocompleteUrl: String,
    distanceUrl: String,
    autocompleteErrorMessage: String,
    distanceErrorMessage: String,
    selectLocationsMessage: String,
  };

  connect() {
    if (!this.hasSegmentsTarget) {
      return;
    }

    this.autocompleteRequests = new WeakMap();
    this.autocompleteTimers = new WeakMap();
    this.validateAllSegments();
    this.updateRoutingAvailability();
  }

  addSegment(event) {
    event.preventDefault();

    const index = this.nextIndex(
      this.segmentsTarget.querySelectorAll('[data-bgos-journey-segment]'),
      'segmentIndex',
    );
    const html = this.segmentTemplateTarget.innerHTML.replaceAll('__segment__', String(index));
    this.segmentsTarget.insertAdjacentHTML('beforeend', html);
    this.validateAllSegments();
    this.updateRoutingAvailability();
  }

  removeSegment(event) {
    event.preventDefault();

    const segments = this.segmentsTarget.querySelectorAll('[data-bgos-journey-segment]');
    if (segments.length <= 1) {
      return;
    }

    event.currentTarget.closest('[data-bgos-journey-segment]')?.remove();
  }

  addParticipant(event) {
    event.preventDefault();

    const segment = event.currentTarget.closest('[data-bgos-journey-segment]');
    const participants = segment?.querySelector('[data-bgos-journey-participants]');
    if (!segment || !participants) {
      return;
    }

    const participantIndex = this.nextIndex(
      participants.querySelectorAll('[data-bgos-journey-participant]'),
      'participantIndex',
    );
    const hasDriver = [...segment.querySelectorAll('[data-bgos-journey-role]')]
      .some((select) => select.value === 'driver');

    const html = this.participantTemplateTarget.innerHTML
      .replaceAll('__segment__', segment.dataset.segmentIndex)
      .replaceAll('__participant__', String(participantIndex));
    participants.insertAdjacentHTML('beforeend', html);

    const participantRows = participants.querySelectorAll('[data-bgos-journey-participant]');
    const newParticipant = participantRows[participantRows.length - 1];
    const newRole = newParticipant?.querySelector('[data-bgos-journey-role]');
    if (hasDriver && newRole) {
      newRole.value = 'passenger';
    }

    this.validateSegment(segment);
  }

  revalidateSegment(event) {
    const segment = event.currentTarget.closest('[data-bgos-journey-segment]');
    if (segment) {
      this.validateSegment(segment);
    }
  }

  updateRoutingAvailability() {
    const mode = this.element.querySelector('[name="mode"]')?.value;
    const canRoute = ['car', 'taxi', 'urban_bus', 'coach'].includes(mode);

    this.segmentsTarget.querySelectorAll('[data-bgos-journey-route-button]').forEach((button) => {
      button.hidden = !canRoute;
      button.disabled = !canRoute;
    });
  }

  locationChanged(event) {
    const input = event.currentTarget;
    const segment = input.closest('[data-bgos-journey-segment]');
    if (!segment) {
      return;
    }

    this.clearEndpointCoordinates(segment, input.dataset.endpoint);
    this.invalidateOrsDistance(segment);
    this.clearSuggestions(input);
    this.queueAutocomplete(input);
  }

  distanceChanged(event) {
    const segment = event.currentTarget.closest('[data-bgos-journey-segment]');
    const source = segment?.querySelector('[data-bgos-journey-distance-source]');
    if (source) {
      source.value = event.currentTarget.value.trim() === '' ? '' : 'manual';
    }
  }

  queueAutocomplete(input) {
    const previousTimer = this.autocompleteTimers.get(input);
    if (previousTimer) {
      clearTimeout(previousTimer);
    }
    this.autocompleteRequests.get(input)?.abort();

    const query = input.value.trim();
    if (query.length < 3) {
      return;
    }

    const timer = setTimeout(() => this.loadSuggestions(input, query), 250);
    this.autocompleteTimers.set(input, timer);
  }

  async loadSuggestions(input, query) {
    const request = new AbortController();
    this.autocompleteRequests.set(input, request);

    try {
      const response = await fetch(`${this.autocompleteUrlValue}?text=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: request.signal,
      });
      const data = await response.json().catch(() => null);
      if (data === null) {
        throw new Error(this.autocompleteErrorMessageValue);
      }
      if (this.autocompleteRequests.get(input) !== request || input.value.trim() !== query) {
        return;
      }
      if (!response.ok) {
        throw new Error(data.error || this.autocompleteErrorMessageValue);
      }

      this.renderSuggestions(input, Array.isArray(data.results) ? data.results : []);
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }
      this.showFeedback(input.closest('[data-bgos-journey-segment]'), error.message || this.autocompleteErrorMessageValue, true);
    }
  }

  renderSuggestions(input, results) {
    const suggestions = input.parentElement.querySelector('[data-bgos-journey-suggestions]');
    if (!suggestions) {
      return;
    }

    suggestions.replaceChildren();
    results.forEach((result) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'list-group-item list-group-item-action py-2';
      item.textContent = result.label;
      item.addEventListener('mousedown', (event) => {
        event.preventDefault();
        const segment = input.closest('[data-bgos-journey-segment]');
        this.invalidateOrsDistance(segment);
        input.value = result.label;
        this.setEndpointCoordinates(segment, input.dataset.endpoint, result.latitude, result.longitude);
        suggestions.replaceChildren();
        this.showFeedback(segment, '', false);
      });
      suggestions.appendChild(item);
    });
  }

  clearSuggestions(input) {
    input.parentElement.querySelector('[data-bgos-journey-suggestions]')?.replaceChildren();
  }

  async calculateDistance(event) {
    const button = event.currentTarget;
    const segment = button.closest('[data-bgos-journey-segment]');
    const coordinates = this.segmentCoordinates(segment);
    if (Object.values(coordinates).some((value) => value === '')) {
      this.showFeedback(segment, this.selectLocationsMessageValue, true);
      return;
    }

    button.disabled = true;
    this.showFeedback(segment, '', false);
    try {
      const response = await fetch(this.distanceUrlValue, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(coordinates),
      });
      const data = await response.json().catch(() => null);
      if (data === null) {
        throw new Error(this.distanceErrorMessageValue);
      }
      if (!response.ok || data.kilometers === undefined) {
        throw new Error(data.error || this.distanceErrorMessageValue);
      }

      segment.querySelector('[data-bgos-journey-distance]').value = String(data.kilometers);
      segment.querySelector('[data-bgos-journey-distance-source]').value = 'ors';
    } catch (error) {
      this.showFeedback(segment, error.message || this.distanceErrorMessageValue, true);
    } finally {
      button.disabled = false;
    }
  }

  segmentCoordinates(segment) {
    return {
      lat1: segment.querySelector('[data-bgos-journey-origin-latitude]').value,
      lon1: segment.querySelector('[data-bgos-journey-origin-longitude]').value,
      lat2: segment.querySelector('[data-bgos-journey-destination-latitude]').value,
      lon2: segment.querySelector('[data-bgos-journey-destination-longitude]').value,
    };
  }

  setEndpointCoordinates(segment, endpoint, latitude, longitude) {
    segment.querySelector(`[data-bgos-journey-${endpoint}-latitude]`).value = latitude;
    segment.querySelector(`[data-bgos-journey-${endpoint}-longitude]`).value = longitude;
  }

  clearEndpointCoordinates(segment, endpoint) {
    this.setEndpointCoordinates(segment, endpoint, '', '');
  }

  invalidateOrsDistance(segment) {
    if (segment?.querySelector('[data-bgos-journey-distance-source]')?.value !== 'ors') {
      return;
    }

    segment.querySelector('[data-bgos-journey-distance]').value = '';
    segment.querySelector('[data-bgos-journey-distance-source]').value = '';
  }

  showFeedback(segment, message, isError) {
    const feedback = segment?.querySelector('[data-bgos-journey-routing-feedback]');
    if (!feedback) {
      return;
    }
    feedback.textContent = message;
    feedback.className = `small mt-2 ${isError ? 'text-danger' : 'text-muted'}`;
  }

  removeParticipant(event) {
    event.preventDefault();

    const segment = event.currentTarget.closest('[data-bgos-journey-segment]');
    const participants = segment?.querySelectorAll('[data-bgos-journey-participant]') ?? [];
    if (participants.length <= 1) {
      return;
    }

    event.currentTarget.closest('[data-bgos-journey-participant]')?.remove();
    if (segment) {
      this.validateSegment(segment);
    }
  }

  validate(event) {
    this.validateAllSegments();

    if (!this.element.checkValidity()) {
      event.preventDefault();
      this.element.reportValidity();
    }
  }

  confirmRemoval(event) {
    if (this.hasConfirmMessageValue && !window.confirm(this.confirmMessageValue)) {
      event.preventDefault();
    }
  }

  validateAllSegments() {
    this.segmentsTarget
      .querySelectorAll('[data-bgos-journey-segment]')
      .forEach((segment) => this.validateSegment(segment));
  }

  validateSegment(segment) {
    const memberSelects = [...segment.querySelectorAll('[data-bgos-journey-member]')];
    const roleSelects = [...segment.querySelectorAll('[data-bgos-journey-role]')];
    const seenMembers = new Set();

    memberSelects.forEach((select) => {
      select.setCustomValidity('');
      if (!select.value) {
        return;
      }

      if (seenMembers.has(select.value)) {
        select.setCustomValidity(this.duplicateMessageValue);
      }
      seenMembers.add(select.value);
    });

    const drivers = roleSelects.filter((select) => select.value === 'driver');
    roleSelects.forEach((select) => select.setCustomValidity(''));
    if (drivers.length > 1) {
      drivers.slice(1).forEach((select) => select.setCustomValidity(this.driverMessageValue));
    }
  }

  nextIndex(elements, dataKey) {
    return [...elements].reduce(
      (next, element) => Math.max(next, Number(element.dataset[dataKey]) + 1),
      0,
    );
  }
}
