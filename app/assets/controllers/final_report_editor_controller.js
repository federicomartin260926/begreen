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
    'narrativeSubmit',
    'narrativeSubmitLabel',
    'narrativeProcessingLabel',
  ];

  static values = {
    featuredPageSize: { type: Number, default: 10 },
    featuredPageStatusFormat: {
      type: String,
      default: 'Página %page% de %pages% · %count% medidas',
    },
  };

  connect() {
    this.featuredPage = 1;
    this.featuredCheckboxTargets.forEach((checkbox) => this.updateFeaturedComment(checkbox));
    this.refreshFeatured();
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

  filterFeatured() {
    this.featuredPage = 1;
    this.refreshFeatured();
  }

  filterSelected() {
    this.featuredPage = 1;
    this.refreshFeatured();
  }

  toggleFeatured(event) {
    this.updateFeaturedComment(event.currentTarget);
    this.refreshFeatured();
  }

  confirmPhotoRemoval(event) {
    if (!window.confirm(event.currentTarget.dataset.confirmMessage || '¿Quitar esta fotografía de la galería?')) {
      event.preventDefault();
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

  updateFeaturedComment(checkbox) {
    const item = checkbox.closest('[data-final-report-editor-target="featuredItem"]');
    const comment = item?.querySelector('[data-final-report-editor-target="featuredComment"]');

    if (comment) {
      comment.hidden = !checkbox.checked;
    }
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
