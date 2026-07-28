import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static values = {
    loadingLabel: { type: String, default: 'Generando PDF…' },
    resetAfter: { type: Number, default: 180000 },
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

  async start(event) {
    if (this.element.getAttribute('aria-busy') === 'true') {
      event.preventDefault()
      return
    }

    this.setLoadingState()

    if (!(this.element instanceof HTMLAnchorElement)) {
      this.scheduleFallbackReset()
      return
    }

    event.preventDefault()

    try {
      const response = await fetch(this.element.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/pdf,application/octet-stream',
        },
      })

      if (!response.ok) {
        throw new Error(`Download failed with status ${response.status}`)
      }

      const blob = await response.blob()
      const filename = this.extractFilename(
        response.headers.get('Content-Disposition'),
      )

      this.downloadBlob(blob, filename)
    } catch (error) {
      console.error('Unable to download the generated file.', error)

      // Fallback al comportamiento normal del navegador.
      window.location.assign(this.element.href)
    } finally {
      this.reset()
    }
  }

  setLoadingState() {
    this.element.setAttribute('aria-busy', 'true')
    this.element.classList.add('disabled')
    this.element.innerHTML = `
      <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
      ${this.escapeHtml(this.loadingLabelValue)}
    `

    if (this.element instanceof HTMLButtonElement) {
      this.element.disabled = true
    }
  }

  scheduleFallbackReset() {
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

  extractFilename(contentDisposition) {
    if (!contentDisposition) {
      return 'plan.pdf'
    }

    const utf8Match = contentDisposition.match(
      /filename\*=UTF-8''([^;]+)/i,
    )

    if (utf8Match) {
      return decodeURIComponent(utf8Match[1])
    }

    const filenameMatch = contentDisposition.match(
      /filename="?([^";]+)"?/i,
    )

    return filenameMatch?.[1] ?? 'plan.pdf'
  }

  downloadBlob(blob, filename) {
    const objectUrl = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = objectUrl
    link.download = filename
    link.classList.add('d-none')

    document.body.appendChild(link)
    link.click()
    link.remove()

    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
  }

  escapeHtml(value) {
    const span = document.createElement('span')
    span.textContent = value

    return span.innerHTML
  }
}
