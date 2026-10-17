import { Controller } from '@hotwired/stimulus';

/*
 * Mémorise l'état d'une case à cocher dans ce navigateur (préférence d'affichage).
 */
export default class extends Controller {
    static values = { key: String };

    connect() {
        try {
            this.element.checked = localStorage.getItem(this.keyValue) === '1';
        } catch (e) {}
    }

    save() {
        try {
            localStorage.setItem(this.keyValue, this.element.checked ? '1' : '0');
        } catch (e) {}
    }
}
