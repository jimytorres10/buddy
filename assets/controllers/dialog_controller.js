import { Controller } from '@hotwired/stimulus';

/*
 * Ouvre un <dialog> natif (feuille de confirmation).
 */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }
}
