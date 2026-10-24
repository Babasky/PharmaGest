import { Controller } from '@hotwired/stimulus';

/*
 * Transfert entre l'officine et ses entrepôts : changer l'origine ou la destination recharge la page (comme
 * « Afficher »), pour que la liste « Vers » ne propose jamais l'origine et que les quantités disponibles suivent.
 */
export default class extends Controller {
    static targets = ['afficher'];

    recharger() {
        this.element.requestSubmit(this.afficherTarget);
    }
}
