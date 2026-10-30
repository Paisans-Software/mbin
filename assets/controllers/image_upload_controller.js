import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        const container = this.element;
        const input = container.querySelector('.image-input');
        const preview = container.querySelector('.image-preview');
        const clearButton = container.querySelector('.image-preview-clear');

        input.addEventListener('change', (e) => {
            const file = e.target.files[0];
            const reader = new FileReader();

            reader.onload = (e) => {
                preview.src = e.target.result;
                preview.style.display = 'block';
                clearButton.setAttribute('style', 'display: inline-block !important');
                this.showThumbnail(e.target.result);
            };

            reader.readAsDataURL(file);
        });
    }

    toggle() {
        if (this.element.classList.contains('dropdown--open')) {
            this.close();
        } else {
            this.element.classList.add('dropdown--open');
            this.buttonTarget.setAttribute('aria-expanded', 'true');
        }
    }

    close() {
        this.element.classList.remove('dropdown--open');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    closeOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    clearPreview() {
        const container = this.element;
        const input = container.querySelector('.image-input');
        const preview = container.querySelector('.image-preview');
        const clearButton = container.querySelector('.image-preview-clear');

        input.value = '';
        preview.src = '#';
        preview.style.display = 'none';
        clearButton.style.display = 'none';
        this.hideThumbnail();
    }

    showThumbnail(src) {
        let thumb = this.buttonTarget.querySelector('.image-upload__thumb');
        if (!thumb) {
            thumb = document.createElement('img');
            thumb.className = 'image-upload__thumb';
            thumb.alt = '';
            this.buttonTarget.appendChild(thumb);
        }
        thumb.src = src;
        this.buttonTarget.classList.add('image-upload--has-thumb');
    }

    hideThumbnail() {
        this.endUpload();
        this.buttonTarget.querySelector('.image-upload__thumb')?.remove();
        this.buttonTarget.classList.remove('image-upload--has-thumb');
    }

    /** The form is sent as a normal page submit, which cannot report progress. */
    submitted(event) {
        if (event.target.contains(this.element) && this.hasFile()) {
            this.startUpload();
        }
    }

    /** Dispatched on the file input by subject#sendForm while it sends the form. */
    progress(event) {
        const { loaded, total } = event.detail;
        this.startUpload(total ? Math.round((loaded / total) * 100) : null);
    }

    startUpload(percent = null) {
        if (!this.hasFile()) {
            return;
        }

        this.close();

        let overlay = this.buttonTarget.querySelector('.image-upload__progress');
        if (!overlay) {
            overlay = document.createElement('span');
            overlay.className = 'image-upload__progress';
            overlay.setAttribute('role', 'progressbar');
            overlay.setAttribute('aria-valuemin', '0');
            overlay.setAttribute('aria-valuemax', '100');
            overlay.innerHTML = '<span class="image-upload__percent"></span><span class="image-upload__bar"></span>';
            this.buttonTarget.appendChild(overlay);
            this.buttonTarget.setAttribute('aria-busy', 'true');
        }

        const label = overlay.querySelector('.image-upload__percent');
        const bar = overlay.querySelector('.image-upload__bar');
        if (null === percent) {
            overlay.classList.add('image-upload__progress--indeterminate');
            overlay.removeAttribute('aria-valuenow');
            label.textContent = '';
            bar.style.width = '0';
        } else {
            overlay.classList.remove('image-upload__progress--indeterminate');
            overlay.setAttribute('aria-valuenow', percent);
            label.textContent = `${percent}%`;
            bar.style.width = `${percent}%`;
        }
    }

    endUpload() {
        this.buttonTarget.querySelector('.image-upload__progress')?.remove();
        this.buttonTarget.removeAttribute('aria-busy');
    }

    hasFile() {
        const input = this.element.querySelector('.image-input');

        return Boolean(input && input.files.length);
    }
}
