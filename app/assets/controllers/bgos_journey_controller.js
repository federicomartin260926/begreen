import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['segments', 'segmentTemplate', 'participantTemplate'];

  static values = {
    duplicateMessage: String,
    driverMessage: String,
    confirmMessage: String,
  };

  connect() {
    if (!this.hasSegmentsTarget) {
      return;
    }

    this.validateAllSegments();
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
