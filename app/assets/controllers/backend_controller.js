import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = {
    // Auto-scroll a alertas (flashes)
    flashOffset:     { type: Number, default: 80 },
    flashSelector:   { type: String, default: '.alert.show, .alert' },
    flashOnlyIfHidden: { type: Boolean, default: true },

    // Inicializar popovers de Bootstrap
    enablePopovers:  { type: Boolean, default: true }
  };

  connect() {
    // 1) Scroll a flashes
    requestAnimationFrame(() => this.scrollToFirstFlash());
  }

  // ---------- Flashes ----------
  scrollToFirstFlash() {
    const alerts = Array.from(this.element.querySelectorAll(this.flashSelectorValue));
    if (!alerts.length) return;

    const firstVisible = alerts.find(el => {
      const cs = getComputedStyle(el);
      return cs.display !== 'none' && cs.visibility !== 'hidden';
    }) || alerts[0];

    const rect = firstVisible.getBoundingClientRect();
    const vh = window.innerHeight || document.documentElement.clientHeight;

    if (this.flashOnlyIfHiddenValue && rect.top >= 0 && rect.bottom <= vh) return;

    const y = rect.top + window.pageYOffset - Math.max(0, this.flashOffsetValue);
    window.scrollTo({ top: y, behavior: 'smooth' });
  }
}
