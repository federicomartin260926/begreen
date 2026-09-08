import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['form', 'previewStatus', 'previewEmission', 'previewTrace', 'previewMessages'];

  static values = {
    i18n: Object,
    previewUrl: String,
    previewToken: String,
  };

  connect() {
    this.queuePreview();
  }

  disconnect() {
    clearTimeout(this.previewTimer);
    this.previewRequest?.abort();
  }

  queuePreview() {
    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.preview(), 300);
  }

  async preview() {
    if (!this.contextComplete) {
      this.clearPreview();
      return;
    }

    this.previewRequest?.abort();
    this.previewRequest = new AbortController();
    const body = new URLSearchParams();
    new FormData(this.formTarget).forEach((value, key) => {
      if (typeof value === 'string' && key !== '_token') body.append(key, value);
    });
    body.set('_preview_token', this.previewTokenValue);
    this.previewStatusTarget.textContent = this.i18nValue.calculating;

    try {
      const response = await fetch(this.previewUrlValue, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: body.toString(),
        signal: this.previewRequest.signal,
      });
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error || 'preview_failed');
      this.renderPreview(result);
    } catch (error) {
      if (error.name === 'AbortError') return;
      this.clearPreview();
    }
  }

  renderPreview(result) {
    this.previewStatusTarget.textContent = this.i18nValue.statusLabels[result.status] || result.status;
    this.previewEmissionTarget.textContent = result.emissionKgCo2e === null
      ? '—'
      : `${this.formatDecimal(result.emissionKgCo2e)} kg CO₂e`;

    const lines = [];
    if (result.normalizedAmount !== null) {
      lines.push(`${this.formatDecimal(result.normalizedAmount)} ${result.normalizedUnit || ''}`.trim());
    }
    (result.factorTraces || []).forEach((trace) => {
      const component = this.i18nValue.componentLabels[trace.component] || trace.component;
      const parts = [component, trace.source, trace.sourceGeography];
      if (trace.factorValue !== null) {
        parts.push(`${this.formatDecimal(trace.factorValue)} ${trace.factorUnit || ''}`.trim());
      }
      if (trace.factorYear) parts.push(String(trace.factorYear));
      if (trace.fallback) parts.push(this.i18nValue.fallback);
      if (trace.geographicProxy) parts.push(this.i18nValue.geographicProxy);
      if (trace.dataQuality) parts.push(`${this.i18nValue.dataQuality}: ${trace.dataQuality}`);
      lines.push(parts.filter(Boolean).join(' · '));
    });
    this.previewTraceTarget.replaceChildren();
    lines.forEach((line) => {
      const item = document.createElement('li');
      item.textContent = line;
      this.previewTraceTarget.append(item);
    });

    this.previewMessagesTarget.replaceChildren();
    (result.messages || []).forEach((message) => {
      const item = document.createElement('li');
      item.textContent = this.i18nValue.messageLabels[message] || message;
      this.previewMessagesTarget.append(item);
    });
  }

  clearPreview() {
    this.previewStatusTarget.textContent = this.i18nValue.previewError;
    this.previewEmissionTarget.textContent = '—';
    this.previewTraceTarget.replaceChildren();
    this.previewMessagesTarget.replaceChildren();
  }

  validate(event) {
    if (!this.formTarget.checkValidity()) {
      event.preventDefault();
      this.formTarget.classList.add('was-validated');
    }
  }

  formatDecimal(value) {
    const text = String(value);
    const trimmed = text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text;

    return (document.documentElement.lang || '').toLowerCase().startsWith('es')
      ? trimmed.replace('.', ',')
      : trimmed;
  }

  get contextComplete() {
    return ['startDate', 'endDate', 'country', 'waterUseType', 'volumeInput', 'volumeInputUnit', 'destination']
      .every((name) => Boolean(this.formTarget.querySelector(`[name="${name}"]`)?.value));
  }
}
