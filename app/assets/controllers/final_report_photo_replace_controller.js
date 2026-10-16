import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['image', 'input', 'submit'];
  static values = {
    confirmMessage: String,
    maxSizeMessage: String,
  };

  connect() {
    this.objectUrl = null;
  }

  disconnect() {
    this.releasePreview();
  }

  preview() {
    const [file] = this.inputTarget.files;
    this.releasePreview();
    this.inputTarget.setCustomValidity('');
    this.submitTarget.disabled = false;

    if (!file || !file.type.startsWith('image/')) {
      return;
    }

    if (file.size > 4 * 1024 * 1024) {
      this.inputTarget.setCustomValidity(this.maxSizeMessageValue);
      this.inputTarget.reportValidity();
      this.submitTarget.disabled = true;
      return;
    }

    this.objectUrl = URL.createObjectURL(file);
    this.imageTarget.src = this.objectUrl;
    this.submitTarget.disabled = false;
  }

  confirmReplacement(event) {
    if (!this.inputTarget.files.length || !window.confirm(this.confirmMessageValue)) {
      event.preventDefault();
    }
  }

  releasePreview() {
    if (this.objectUrl) {
      URL.revokeObjectURL(this.objectUrl);
      this.objectUrl = null;
    }
  }
}
