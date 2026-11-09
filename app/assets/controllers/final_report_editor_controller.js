import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'featuredSearch',
    'featuredItem',
    'featuredCheckbox',
    'featuredComment',
    'featuredSelectedCount',
    'featuredSelectedOnly',
    'featuredPagination',
    'featuredPageStatus',
    'featuredPrevious',
    'featuredNext',
    'featuredText',
    'featuredOrigin',
    'featuredPhotoPreview',
    'featuredPhotoPreviewContainer',
    'featuredNarrativeStatus',
    'narrativeSubmit',
    'narrativeSubmitLabel',
    'narrativeProcessingLabel',
    'photoOperationStatus',
  ];

  static values = {
    featuredPageSize: { type: Number, default: 10 },
    featuredPageStatusFormat: {
      type: String,
      default: 'Página %page% de %pages% · %count% medidas',
    },
    featuredNarrativeSuccessMessage: String,
    featuredNarrativeErrorMessage: String,
    featuredUnsavedMessage: String,
    featuredReplaceConfirmMessage: String,
    originManualLabel: String,
    originAiLabel: String,
    photoSaveErrorMessage: String,
    photoSavingMessage: String,
    sectionAiSuccessMessage: String,
    sectionAiErrorMessage: String,
    sectionAiUnsavedMessage: String,
    sectionAiReplaceConfirmMessage: String,
  };

  connect() {
    this.featuredPage = 1;
    this.featuredNarrativeRequests = new Map();
    this.sectionNarrativeRequests = new Map();
    this.refreshFeatured();
  }

  disconnect() {
    this.featuredNarrativeRequests?.forEach((controller) => controller.abort());
    this.featuredNarrativeRequests?.clear();
    this.sectionNarrativeRequests?.forEach((controller) => controller.abort());
    this.sectionNarrativeRequests?.clear();
  }

  syncRichEditors() {
    this.element.querySelectorAll('[data-rich-surface]:not([hidden])').forEach((surface) => {
      surface.dispatchEvent(new Event('blur'));
    });
  }

  updateFeaturedEditor(textarea, text) {
    textarea.value = text;
    textarea.defaultValue = text;
    textarea.dispatchEvent(new Event('rich:set'));
  }

  submitNarrative(event) {
    if (this.narrativeSubmitting) {
      event.preventDefault();
      return;
    }

    this.narrativeSubmitting = true;
    this.narrativeSubmitTarget.disabled = true;
    this.narrativeSubmitTarget.setAttribute('aria-busy', 'true');
    this.narrativeSubmitLabelTarget.classList.add('d-none');
    this.narrativeProcessingLabelTarget.classList.remove('d-none');
  }

  async generateSectionNarrative(event) {
    const button = event.currentTarget;
    const editor = button.closest('[data-ai-section-editor]');
    const textarea = editor?.querySelector('[data-rich-source]');
    const status = editor?.querySelector('[data-ai-section-status]');
    if (!editor || !textarea || !status || button.disabled) return;

    this.syncRichEditors();
    if (textarea.value !== textarea.defaultValue) {
      status.textContent = this.sectionAiUnsavedMessageValue;
      status.className = 'small text-danger';
      textarea.focus();
      return;
    }
    const replacesManual = editor.dataset.persistedOrigin !== 'ai' && textarea.defaultValue.trim() !== '';
    if (replacesManual && !window.confirm(this.sectionAiReplaceConfirmMessageValue)) return;

    const controller = new AbortController();
    this.sectionNarrativeRequests.set(button, controller);
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    status.textContent = '';
    const body = new URLSearchParams({ _token: button.dataset.csrfToken || '' });
    if (replacesManual) body.set('replaceManual', '1');
    try {
      const response = await fetch(button.dataset.endpoint, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        credentials: 'same-origin',
        body: body.toString(),
        signal: controller.signal,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || typeof payload.text !== 'string' || payload.origin !== 'ai') {
        throw new Error(typeof payload.error === 'string' ? payload.error : this.sectionAiErrorMessageValue);
      }
      this.updateFeaturedEditor(textarea, payload.text);
      editor.dataset.persistedOrigin = 'ai';
      status.className = 'small text-success';
      status.textContent = this.sectionAiSuccessMessageValue;
    } catch (error) {
      if (error.name !== 'AbortError') {
        status.className = 'small text-danger';
        status.textContent = error.message || this.sectionAiErrorMessageValue;
      }
    } finally {
      if (this.sectionNarrativeRequests.get(button) === controller) {
        this.sectionNarrativeRequests.delete(button);
        button.disabled = false;
        button.setAttribute('aria-busy', 'false');
      }
    }
  }

  filterFeatured() {
    this.featuredPage = 1;
    this.refreshFeatured();
  }

  filterSelected() {
    this.featuredPage = 1;
    this.refreshFeatured();
  }

  toggleFeatured(event) {
    this.refreshFeatured();
  }

  featuredTextChanged(event) {
    const item = event.currentTarget.closest('[data-final-report-editor-target="featuredItem"]');
    const origin = item?.querySelector('[data-final-report-editor-target="featuredOrigin"]');
    if (!item || !origin) {
      return;
    }

    const changed = event.currentTarget.value !== event.currentTarget.defaultValue;
    this.renderFeaturedOrigin(origin, changed ? 'manual' : (item.dataset.persistedOrigin || 'manual'));
  }

  previewFeaturedPhoto(event) {
    const item = event.currentTarget.closest('[data-final-report-editor-target="featuredItem"]');
    const container = item?.querySelector('[data-final-report-editor-target="featuredPhotoPreviewContainer"]');
    const image = item?.querySelector('[data-final-report-editor-target="featuredPhotoPreview"]');
    const url = event.currentTarget.selectedOptions[0]?.dataset.previewUrl || '';
    if (!container || !image) {
      return;
    }

    image.src = url;
    container.hidden = url === '';
  }

  async generateFeaturedNarrative(event) {
    const button = event.currentTarget;
    const item = button.closest('[data-final-report-editor-target="featuredItem"]');
    const textarea = item?.querySelector('[data-final-report-editor-target="featuredText"]');
    const checkbox = item?.querySelector('[data-final-report-editor-target="featuredCheckbox"]');
    const origin = item?.querySelector('[data-final-report-editor-target="featuredOrigin"]');
    const status = item?.querySelector('[data-final-report-editor-target="featuredNarrativeStatus"]');
    if (!item || !textarea || !checkbox || !origin || !status || button.disabled) {
      return;
    }

    if (textarea.value !== textarea.defaultValue || checkbox.checked !== checkbox.defaultChecked) {
      this.renderFeaturedStatus(status, this.featuredUnsavedMessageValue, true);
      textarea.focus();
      return;
    }

    const replacesManual = item.dataset.persistedOrigin !== 'ai' && textarea.defaultValue.trim() !== '';
    if (replacesManual && !window.confirm(this.featuredReplaceConfirmMessageValue)) {
      return;
    }

    const abortController = new AbortController();
    this.featuredNarrativeRequests.set(button, abortController);
    this.setFeaturedNarrativeLoading(button, true);
    this.renderFeaturedStatus(status, '', false);

    const body = new URLSearchParams({ _token: button.dataset.csrfToken || '' });
    if (replacesManual) {
      body.set('replaceManual', '1');
    }

    try {
      const response = await fetch(button.dataset.endpoint, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: body.toString(),
        credentials: 'same-origin',
        signal: abortController.signal,
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || typeof payload.text !== 'string' || payload.origin !== 'ai') {
        throw new Error(typeof payload.error === 'string' ? payload.error : this.featuredNarrativeErrorMessageValue);
      }

      this.updateFeaturedEditor(textarea, payload.text);
      item.dataset.persistedOrigin = 'ai';
      this.renderFeaturedOrigin(origin, 'ai');
      this.renderFeaturedStatus(status, this.featuredNarrativeSuccessMessageValue, false);
    } catch (error) {
      if (error.name !== 'AbortError') {
        this.renderFeaturedStatus(status, error.message || this.featuredNarrativeErrorMessageValue, true);
      }
    } finally {
      if (this.featuredNarrativeRequests.get(button) === abortController) {
        this.featuredNarrativeRequests.delete(button);
        this.setFeaturedNarrativeLoading(button, false);
      }
    }
  }

  async submitPhotoOperation(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.confirmMessage && !window.confirm(form.dataset.confirmMessage)) {
      return;
    }
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    if (this.photoOperationSubmitting) {
      return;
    }

    this.syncRichEditors();
    const editorialForm = document.getElementById('final-report-editorial-form');
    if (!editorialForm) {
      this.renderPhotoOperationStatus(this.photoSaveErrorMessageValue, true);
      return;
    }

    this.photoOperationSubmitting = true;
    const submitters = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
    submitters.forEach((submitter) => {
      submitter.disabled = true;
      submitter.setAttribute('aria-busy', 'true');
      const spinner = document.createElement('span');
      spinner.className = 'spinner-border spinner-border-sm me-2';
      spinner.setAttribute('aria-hidden', 'true');
      spinner.dataset.photoOperationSpinner = '1';
      submitter.prepend(spinner);
    });
    this.renderPhotoOperationStatus(this.photoSavingMessageValue, false);

    try {
      const response = await fetch(editorialForm.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(editorialForm),
        credentials: 'same-origin',
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok !== true) {
        throw new Error(this.photoSaveErrorMessageValue);
      }

      HTMLFormElement.prototype.submit.call(form);
    } catch (error) {
      this.photoOperationSubmitting = false;
      submitters.forEach((submitter) => {
        submitter.disabled = false;
        submitter.removeAttribute('aria-busy');
        submitter.querySelector('[data-photo-operation-spinner]')?.remove();
      });
      this.renderPhotoOperationStatus(error.message || this.photoSaveErrorMessageValue, true);
    }
  }

  previousFeaturedPage() {
    if (this.featuredPage > 1) {
      this.featuredPage -= 1;
      this.refreshFeatured();
    }
  }

  nextFeaturedPage() {
    const totalPages = Math.max(1, Math.ceil(this.matchingFeaturedItems().length / this.featuredPageSizeValue));
    if (this.featuredPage < totalPages) {
      this.featuredPage += 1;
      this.refreshFeatured();
    }
  }

  setFeaturedNarrativeLoading(button, loading) {
    button.disabled = loading;
    button.setAttribute('aria-busy', loading ? 'true' : 'false');
    button.querySelector('[data-role="label"]')?.classList.toggle('d-none', loading);
    button.querySelector('[data-role="loading"]')?.classList.toggle('d-none', !loading);
  }

  renderFeaturedOrigin(element, origin) {
    const isAi = origin === 'ai';
    element.textContent = isAi ? this.originAiLabelValue : this.originManualLabelValue;
    element.classList.toggle('text-bg-info', isAi);
    element.classList.toggle('text-bg-light', !isAi);
    element.classList.toggle('border', !isAi);
  }

  renderFeaturedStatus(element, message, error) {
    element.textContent = message;
    element.classList.toggle('text-danger', error && message !== '');
    element.classList.toggle('text-success', !error && message !== '');
  }

  renderPhotoOperationStatus(message, error) {
    if (!this.hasPhotoOperationStatusTarget) {
      return;
    }
    this.photoOperationStatusTarget.textContent = message;
    this.photoOperationStatusTarget.classList.toggle('text-danger', error);
    this.photoOperationStatusTarget.classList.toggle('text-success', !error && message !== '');
  }

  refreshFeatured() {
    const matchingItems = this.matchingFeaturedItems();
    const totalPages = Math.max(1, Math.ceil(matchingItems.length / this.featuredPageSizeValue));
    this.featuredPage = Math.min(this.featuredPage, totalPages);
    const firstIndex = (this.featuredPage - 1) * this.featuredPageSizeValue;
    const pageItems = new Set(matchingItems.slice(firstIndex, firstIndex + this.featuredPageSizeValue));

    this.featuredItemTargets.forEach((item) => {
      item.hidden = !pageItems.has(item);
    });

    const selectedCount = this.featuredCheckboxTargets.filter((checkbox) => checkbox.checked).length;
    if (this.hasFeaturedSelectedCountTarget) {
      this.featuredSelectedCountTarget.textContent = selectedCount.toString();
    }
    if (this.hasFeaturedPageStatusTarget) {
      this.featuredPageStatusTarget.textContent = this.featuredPageStatusFormatValue
        .replace('%page%', String(this.featuredPage))
        .replace('%pages%', String(totalPages))
        .replace('%count%', String(matchingItems.length));
    }
    if (this.hasFeaturedPreviousTarget) {
      this.featuredPreviousTarget.disabled = this.featuredPage === 1;
    }
    if (this.hasFeaturedNextTarget) {
      this.featuredNextTarget.disabled = this.featuredPage === totalPages;
    }
    if (this.hasFeaturedPaginationTarget) {
      this.featuredPaginationTarget.hidden = matchingItems.length <= this.featuredPageSizeValue;
    }
  }

  matchingFeaturedItems() {
    const query = this.normalize(this.featuredSearchTarget.value);
    const selectedOnly = this.hasFeaturedSelectedOnlyTarget && this.featuredSelectedOnlyTarget.checked;

    return this.featuredItemTargets.filter((item) => {
      const matchesName = query === '' || this.normalize(item.dataset.measureName || '').includes(query);
      const checkbox = item.querySelector('[data-final-report-editor-target="featuredCheckbox"]');

      return matchesName && (!selectedOnly || checkbox?.checked);
    });
  }

  normalize(value) {
    return value
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase();
  }
}
