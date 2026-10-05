import { Controller } from '@hotwired/stimulus';

/*
 * Réception d'une livraison : un même produit peut arriver en plusieurs lots.
 * « + lot » ajoute sous la ligne une copie vide (même produit, même prix) avec un nouvel index.
 */
export default class extends Controller {
    static values = { index: Number };

    ajouterLot(event) {
        const ligne = event.currentTarget.closest('tr');
        const copie = ligne.cloneNode(true);
        const index = this.indexValue++;

        copie.querySelectorAll('[name^="lignes["]').forEach((champ) => {
            champ.name = champ.name.replace(/^lignes\[\d+\]/, `lignes[${index}]`);
            if (champ.dataset.vider !== undefined) {
                champ.value = '';
            }
        });
        copie.querySelector('[data-libelle]')?.classList.add('text-body-secondary');
        copie.querySelector('[data-action~="reception#ajouterLot"]')?.remove();
        ligne.after(copie);
        copie.querySelector('[data-vider]')?.focus();
    }
}
