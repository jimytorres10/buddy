import { Controller } from '@hotwired/stimulus';

/*
 * Fait disparaître les messages flash après quelques secondes.
 */
export default class extends Controller {
    connect() {
        this.timeout = setTimeout(() => {
            this.element.classList.add('is-leaving');
            setTimeout(() => this.element.remove(), 300);
        }, 3500);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
