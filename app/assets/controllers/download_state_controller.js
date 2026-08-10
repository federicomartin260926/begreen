import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static values = {
    loadingLabel: { type: String, default: 'Generando PDF…' },
    resetAfter: { type: Number, default: 180000 },
    requireCheckedName: String,
    errorLabel: String,
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

    if (!this.canStartForSubmitButton()) {
      return
    }

    if (!this.startManaged()) {
      event.preventDefault()
      return
    }

    if (!(this.element instanceof HTMLAnchorElement)) {
      return
    }

    event.preventDefault()

    this.clearError()

    try {
      const response = await fetch(this.element.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/pdf,application/octet-stream,application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      })

      const contentType = (
        response.headers.get('Content-Type') ?? ''
      ).toLowerCase()

      if (!response.ok) {
        throw new Error(
          await this.errorMessageFromResponse(response, contentType),
        )
      }

      if (!this.isDownloadContentType(contentType)) {
        throw new Error(this.fallbackErrorMessage())
      }

      const blob = await response.blob()
      const filename = this.extractFilename(
        response.headers.get('Content-Disposition'),
      )

      this.downloadBlob(blob, filename)
    } catch (error) {
      console.error('Unable to download the generated file.', error)

      this.showError(
        error instanceof Error && error.message
          ? error.message
          : this.fallbackErrorMessage(),
      )
    } finally {
      this.reset()
    }
  }

  startManaged() {
    if (this.element.getAttribute('aria-busy') === 'true') {
      return false
    }

    this.setLoadingState()
    this.scheduleFallbackReset()

    return true
  }

  canStartForSubmitButton() {
    if (!(this.element instanceof HTMLButtonElement) || this.element.type !== 'submit') {
      return true
    }

    const form = this.element.form
    if (!form || !form.checkValidity()) {
      return false
    }

    if (!this.hasRequireCheckedNameValue) {
      return true
    }

    return new FormData(form).getAll(this.requireCheckedNameValue).length > 0
  }

  setLoadingState() {
    this.element.setAttribute('aria-busy', 'true')
    this.element.classList.add('disabled')
    this.element.innerHTML = `
      <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
      ${this.escapeHtml(this.loadingLabelValue)}
    `

    if (
      this.element instanceof HTMLButtonElement
      && this.element.type !== 'submit'
    ) {
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

  isDownloadContentType(contentType) {
    return (
      contentType.includes('application/pdf')
      || contentType.includes('application/octet-stream')
    )
  }

  async errorMessageFromResponse(response, contentType) {
    if (contentType.includes('application/json')) {
      try {
        const payload = await response.json()

        if (
          typeof payload?.message === 'string'
          && payload.message.trim() !== ''
        ) {
          return payload.message
        }
      } catch (error) {
        console.error('Unable to parse download error response.', error)
      }
    }

    return this.fallbackErrorMessage()
  }

  fallbackErrorMessage() {
    if (
      this.hasErrorLabelValue
      && this.errorLabelValue.trim() !== ''
    ) {
      return this.errorLabelValue
    }

    return 'Unable to download the generated file.'
  }

  showError(message) {
    this.clearError()

    const alert = document.createElement('div')
    alert.className = 'alert alert-danger py-2 px-3 mt-2 mb-0 small'
    alert.setAttribute('role', 'alert')
    alert.dataset.downloadStateError = 'true'
    alert.textContent = message

    this.element.insertAdjacentElement('afterend', alert)
    this.errorElement = alert
  }

  clearError() {
    if (!this.errorElement) {
      return
    }

    this.errorElement.remove()
    this.errorElement = null
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
