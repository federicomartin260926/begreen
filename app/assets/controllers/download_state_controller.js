import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static values = {
    loadingLabel: { type: String, default: 'Generando PDF…' },
    resetAfter: { type: Number, default: 120000 },
  }

  connect() {
    this.originalHtml = this.element.innerHTML
    this.reset = this.reset.bind(this)

    window.addEventListener('pageshow', this.reset)
  }

  disconnect() {
    window.removeEventListener('pageshow', this.reset)
    this.clearResetTimer()
  }

  start(event) {
    if (this.element.getAttribute('aria-busy') === 'true') {
      event.preventDefault()
      return
    }

    this.element.setAttribute('aria-busy', 'true')
    this.element.classList.add('disabled')
    this.element.innerHTML = `
      <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
      ${this.escapeHtml(this.loadingLabelValue)}
    `

    if (this.element instanceof HTMLButtonElement) {
      this.element.disabled = true
    }

    this.resetTimer = window.setTimeout(
      this.reset,
      this.resetAfterValue,
    )
  }

  reset() {
    this.clearResetTimer()

    this.element.removeAttribute('aria-busy')
    this.element.classList.remove('disabled')
    this.element.innerHTML = this.originalHtml

    if (this.element instanceof HTMLButtonElement) {
      this.element.disabled = false
    }
  }

  clearResetTimer() {
    if (!this.resetTimer) {
      return
    }

    window.clearTimeout(this.resetTimer)
    this.resetTimer = null
  }

  escapeHtml(value) {
    const span = document.createElement('span')
    span.textContent = value

    return span.innerHTML
  }
}
