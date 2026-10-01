import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'file',
        'submit',
        'submitLabel',
        'spinner',
        'cancel',
        'close',
        'status'
    ];

    static values = {
        processingText: String
    };

    connect() {
        this.processing = false;
        this.modal = this.element.closest('.modal');
        this.preventModalClose = (event) => {
            if (this.processing) {
                event.preventDefault();
            }
        };

        this.modal?.addEventListener('hide.bs.modal', this.preventModalClose);
    }

    disconnect() {
        this.modal?.removeEventListener('hide.bs.modal', this.preventModalClose);
    }

    submit(event) {
        if (this.processing) {
            event.preventDefault();

            return;
        }

        if (!this.element.checkValidity()) {
            event.preventDefault();
            this.element.reportValidity();

            return;
        }

        event.preventDefault();
        this.processing = true;

        this.element.setAttribute('aria-busy', 'true');

        this.submitTarget.disabled = true;
        this.cancelTarget.disabled = true;
        this.closeTarget.disabled = true;

        // Do not set disabled on the file input: it must remain part of
        // multipart/form-data. Block interaction only.
        this.fileTarget.classList.add('pe-none', 'opacity-50');
        this.fileTarget.setAttribute('aria-disabled', 'true');
        this.fileTarget.setAttribute('tabindex', '-1');

        this.spinnerTarget.classList.remove('d-none');
        this.submitLabelTarget.textContent = this.processingTextValue;
        this.statusTarget.classList.remove('d-none');

        // Give the browser one frame to paint the loading state before
        // starting the normal multipart submission.
        requestAnimationFrame(() => {
            HTMLFormElement.prototype.submit.call(this.element);
        });
    }
}
