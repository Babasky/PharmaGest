import { Controller } from '@hotwired/stimulus';

const formatFcfa = (valeur) =>
    `${Math.round(valeur).toLocaleString('fr-FR').replace(/\s/g, ' ')} FCFA`;

const entier = (texte) => {
    const nettoye = (texte ?? '').replace(/[\s  ]/g, '');
    return /^\d+$/.test(nettoye) ? parseInt(nettoye, 10) : null;
};

/*
 * Confort de l'écran de caisse (le serveur reste seul juge) :
 *  - F2 place le curseur dans la recherche, F9 dans les espèces remises ;
 *  - la monnaie à rendre s'affiche pendant la saisie.
 */
export default class extends Controller {
    static targets = ['recherche', 'remis', 'monnaie', 'du', 'autre'];

    connect() {
        this.raccourcis = (event) => {
            if (event.key === 'F2' && this.hasRechercheTarget) {
                event.preventDefault();
                this.rechercheTarget.focus();
                this.rechercheTarget.select();
            } else if (event.key === 'F9' && this.hasRemisTarget) {
                event.preventDefault();
                this.remisTarget.focus();
            }
        };
        document.addEventListener('keydown', this.raccourcis);
    }

    disconnect() {
        document.removeEventListener('keydown', this.raccourcis);
    }

    monnaie() {
        if (!this.hasMonnaieTarget || !this.hasDuTarget) {
            return;
        }
        const autres = this.autreTargets.reduce((total, champ) => total + (entier(champ.value) ?? 0), 0);
        const especes = Math.max(0, parseInt(this.duTarget.dataset.montant, 10) - autres);
        const remis = entier(this.remisTarget.value);
        if (remis === null) {
            this.monnaieTarget.textContent = '—';
            return;
        }
        this.monnaieTarget.textContent = remis >= especes ? formatFcfa(remis - especes) : `il manque ${formatFcfa(especes - remis)}`;
    }
}
