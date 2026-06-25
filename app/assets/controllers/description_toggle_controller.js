import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['excerpt', 'full', 'toggle'];

  connect() {
    this.expanded = false;
    this.sync();
  }

  toggle(event) {
    event.preventDefault();
    this.expanded = !this.expanded;
    this.sync();
  }

  sync() {
    if (this.hasExcerptTarget) {
      this.excerptTarget.classList.toggle('d-none', this.expanded);
    }

    if (this.hasFullTarget) {
      this.fullTarget.classList.toggle('d-none', !this.expanded);
    }

    if (this.hasToggleTarget) {
      this.toggleTarget.textContent = this.expanded
        ? this.t('less')
        : this.t('more');
      this.toggleTarget.setAttribute('aria-expanded', this.expanded ? 'true' : 'false');
    }
  }

  t(key) {
    const translations = {
      more: this.element.dataset.moreLabel || 'Ver más',
      less: this.element.dataset.lessLabel || 'Ver menos',
    };

    return translations[key] || key;
  }
}
