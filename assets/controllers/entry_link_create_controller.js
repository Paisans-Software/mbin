import { ApplicationController, useThrottle } from 'stimulus-use';
import { fetch, ok } from '@app/utils/http';
import router from '@app/utils/routing';

/* stimulusFetch: 'lazy' */
export default class extends ApplicationController {
    static throttles = ['fetchLink'];
    static targets = ['title', 'description', 'url', 'loader', 'imagePreview', 'imagePreviewImage'];
    static values = {
        loading: Boolean,
    };

    timeoutId = null;

    connect() {
        useThrottle(this, {
            wait: 1000,
        });

        const params = new URLSearchParams(window.location.search);
        const url = params.get('url');
        if (url) {
            this.urlTarget.value = url;
            this.urlTarget.dispatchEvent(new Event('input'));
        }
    }

    fetchLink(event) {
        if (!event.target.value) {
            this.hideImagePreview();
            return;
        }

        if (this.timeoutId) {
            window.clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }

        this.timeoutId = window.setTimeout(() => {
            this.loadingValue = true;
            this.fetchTitleAndDescription(event)
                .then(() => {
                    this.loadingValue = false;
                    this.timeoutId = null;
                })
                .catch(() => {
                    this.loadingValue = false;
                    this.timeoutId = null;
                    this.hideImagePreview();
                });
        }, 1000);
    }

    loadingValueChanged(val) {
        this.titleTarget.disabled = val;
        this.descriptionTarget.disabled = val;

        if (val) {
            this.loaderTarget.classList.remove('hide');
        } else {
            this.loaderTarget.classList.add('hide');
        }
    }

    async fetchTitleAndDescription(event) {
        if (this.titleTarget.value && false === confirm('Are you sure you want to fetch the title and description? This will overwrite the current values.')) {
            return;
        }

        const url = router().generate('ajax_fetch_title');
        let response = await fetch(url, {
            method: 'POST',
            body: JSON.stringify({
                'url': event.target.value,
            }),
        });

        response = await ok(response);
        response = await response.json();

        this.titleTarget.value = response.title;
        this.descriptionTarget.value = response.description;

        // required for input length indicator
        this.titleTarget.dispatchEvent(new Event('input'));
        this.descriptionTarget.dispatchEvent(new Event('input'));

        this.showImagePreview(response.image);
    }

    // The cover is downloaded and stored only once the entry is saved. Until then, show the
    // image the link points to, so the author sees which picture the entry will get.
    showImagePreview(image) {
        if (!this.hasImagePreviewTarget) {
            return;
        }

        const url = URL.canParse(image) ? new URL(image) : null;

        if (!url || !['http:', 'https:'].includes(url.protocol)) {
            this.hideImagePreview();
            return;
        }

        this.imagePreviewImageTarget.src = url.href;
        this.imagePreviewTarget.classList.remove('hidden');
    }

    hideImagePreview() {
        if (!this.hasImagePreviewTarget) {
            return;
        }

        this.imagePreviewTarget.classList.add('hidden');
        this.imagePreviewImageTarget.removeAttribute('src');
    }
}
