import { Controller } from '@hotwired/stimulus';

/*
 * Liste de sous-formulaires (CollectionType) : « Ajouter » insère une copie du prototype avec un nouvel index,
 * la corbeille retire la ligne.
 */
export default class extends Controller {
    static targets = ['liste', 'ligne'];
    static values = { index: Number, prototype: String };

    ajouter() {
        const gabarit = document.createElement('template');
        gabarit.innerHTML = this.prototypeValue.replace(/__name__/g, String(this.indexValue++)).trim();
        const ligne = gabarit.content.firstElementChild;
        this.listeTarget.append(ligne);
        ligne.querySelector('select, input')?.focus();
    }

    retirer(event) {
        event.currentTarget.closest('[data-collection-target="ligne"]')?.remove();
    }
}
