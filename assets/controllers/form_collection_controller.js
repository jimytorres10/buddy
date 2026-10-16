import { Controller } from '@hotwired/stimulus';

/*
 * Ajout / suppression de lignes dans un CollectionType Symfony.
 */
export default class extends Controller {
    static targets = ['container', 'item'];
    static values = { index: Number, prototype: String };

    add() {
        const html = this.prototypeValue.replace(/__name__/g, this.indexValue);
        this.containerTarget.insertAdjacentHTML('beforeend', html);
        this.indexValue++;
        this.itemTargets[this.itemTargets.length - 1].querySelector('input')?.focus();
    }

    remove(event) {
        event.currentTarget.closest('[data-form-collection-target="item"]').remove();
    }
}
