import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

const formatFcfa = (valeur) =>
    `${Math.round(valeur).toLocaleString('fr-FR').replace(/\s/g, ' ')} FCFA`;

const entier = (texte) => {
    const nettoye = (texte ?? '').replace(/[\s  ]/g, '');
    return /^\d+$/.test(nettoye) ? parseInt(nettoye, 10) : null;
};

/** Jours calendaires entre deux dates « AAAA-MM-JJ », null si l'une est invalide. */
const joursEntre = (debut, fin) => {
    const [a, b] = [Date.parse(`${debut}T00:00:00Z`), Date.parse(`${fin}T00:00:00Z`)];
    return Number.isNaN(a) || Number.isNaN(b) ? null : Math.round((b - a) / 86400000);
};

/*
 * Confort de l'écran de caisse (le serveur reste seul juge) :
 *  - F2 place le curseur dans la recherche, F9 dans les espèces remises ;
 *  - la monnaie à rendre s'affiche pendant la saisie ;
 *  - le type de vente et l'ordonnance saisis mais pas encore appliqués sont enregistrés avant de choisir,
 *    retirer ou rechercher un client, pour ne pas être perdus au rechargement de l'écran ;
 *  - un nouveau client se crée dans une fenêtre, sans quitter la caisse ;
 *  - une ordonnance AMO prescrite depuis au moins une semaine est signalée dès la saisie de sa date.
 */
export default class extends Controller {
    static targets = ['recherche', 'remis', 'monnaie', 'du', 'autre', 'vente', 'fenetreClient', 'corpsClient', 'alerteOrdonnance'];

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
        this.venteInitiale = this.etatVente();
        if (this.hasFenetreClientTarget) {
            this.fenetreClientTarget.addEventListener('shown.bs.modal', () => this.fenetreClientTarget.querySelector('input:not([type=hidden])')?.focus());
        }
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

    /** Avertissement (non bloquant) : ordonnance AMO ancienne d'au moins une semaine au jour de sa présentation. */
    alerteOrdonnance() {
        if (!this.hasAlerteOrdonnanceTarget || !this.hasVenteTarget) {
            return;
        }
        const alerte = this.alerteOrdonnanceTarget;
        const date = this.venteTarget.querySelector('[name=ordonnance_date]')?.value;
        const amo = this.venteTarget.querySelector('[name=type]:checked')?.value === 'amo';
        const jours = date ? joursEntre(date, alerte.dataset.aujourdhui) : null;
        const ancienne = amo && jours !== null && jours >= parseInt(alerte.dataset.seuil, 10);
        alerte.classList.toggle('d-none', !ancienne);
        if (ancienne) {
            alerte.querySelector('[data-message]').textContent = `Attention : cette ordonnance AMO est ancienne de ${jours} jours (prescrite le ${date.split('-').reverse().join('/')}).`;
        }
    }

    etatVente() {
        if (!this.hasVenteTarget) {
            return null;
        }
        const donnees = new FormData(this.venteTarget);
        donnees.delete('_token');
        return JSON.stringify([...donnees.entries()].map(([nom, valeur]) => [nom, valeur instanceof File ? valeur.name : valeur]));
    }

    /** Enregistre le type de vente et l'ordonnance s'ils ont changé depuis l'affichage de l'écran. */
    async enregistrerVente() {
        if (!this.hasVenteTarget || this.etatVente() === this.venteInitiale) {
            return;
        }
        // Sans suivre la redirection : un éventuel message d'erreur s'affichera sur l'écran rechargé.
        await fetch(this.venteTarget.action, { method: 'POST', body: new FormData(this.venteTarget), redirect: 'manual', credentials: 'same-origin' });
        this.venteInitiale = this.etatVente();
    }

    async garderVente(event) {
        const formulaire = event.target;
        if (formulaire.dataset.venteGardee || this.etatVente() === this.venteInitiale) {
            return;
        }
        event.preventDefault();
        try {
            await this.enregistrerVente();
        } finally {
            formulaire.dataset.venteGardee = '1';
            formulaire.requestSubmit(event.submitter ?? undefined);
        }
    }

    async creerClient(event) {
        event.preventDefault();
        const formulaire = event.target;
        const bouton = formulaire.querySelector('[type=submit]');
        if (bouton) {
            bouton.disabled = true;
        }
        try {
            await this.enregistrerVente();
            const reponse = await fetch(formulaire.action, { method: 'POST', body: new FormData(formulaire), redirect: 'manual', credentials: 'same-origin' });
            if (reponse.status === 422) {
                // Saisie invalide : la fenêtre reste ouverte avec les erreurs.
                this.corpsClientTarget.innerHTML = await reponse.text();
                this.corpsClientTarget.querySelector('.is-invalid')?.focus();
                return;
            }
            if (reponse.type !== 'opaqueredirect' && !reponse.ok) {
                throw new Error(`Réponse ${reponse.status}`);
            }
            // Client créé et choisi : l'écran de caisse se recharge (le message de confirmation s'y affiche).
            Modal.getInstance(this.fenetreClientTarget)?.hide();
            window.location.assign(window.location.pathname);
        } catch {
            formulaire.submit();
        } finally {
            if (bouton) {
                bouton.disabled = false;
            }
        }
    }
}
