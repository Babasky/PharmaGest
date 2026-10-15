import { Controller } from '@hotwired/stimulus';
import {
    URL_PAGE, RAFRAICHIR_APRES_MS, ageDonnees, donnees, identite, memoriserIdentite, oublierIdentite,
    rafraichirDonnees, synchroniser, ventesAEnvoyer,
} from '../hors_ligne.js';

const CLE_PRECHARGE = 'pg-hl-precharge';
const PRECHARGER_APRES_MS = 6 * 60 * 60 * 1000;

/*
 * Prépare la caisse hors ligne sur le poste d'un vendeur, depuis n'importe quelle page :
 *  - installe le service worker qui garde l'écran de caisse hors ligne, et le précharge de temps en temps ;
 *  - garde une copie fraîche du catalogue (prix, stock) ;
 *  - envoie les ventes faites hors ligne dès que le réseau revient ;
 *  - affiche un bandeau quand la connexion est perdue, ou quand des ventes attendent d'être envoyées.
 */
export default class extends Controller {
    // session : caisse ouverte (1) ou fermée (0) d'après l'écran de caisse, -1 ailleurs.
    static values = { utilisateur: Number, pharmacie: Number, session: { type: Number, default: -1 } };
    static targets = ['bandeau', 'message', 'envoyer'];

    connect() {
        const precedente = identite();
        memoriserIdentite(this.utilisateurValue, this.pharmacieValue);
        this.enLigne = () => this.surEnLigne();
        this.horsLigne = () => this.afficher();
        this.clic = (event) => event.target.closest?.('[data-pg-deconnexion]') && this.deconnexion();
        window.addEventListener('online', this.enLigne);
        window.addEventListener('offline', this.horsLigne);
        document.addEventListener('click', this.clic);

        this.installer();
        const autrePersonne = !precedente || precedente.utilisateurId !== this.utilisateurValue || precedente.pharmacieId !== this.pharmacieValue;
        if (navigator.onLine) {
            if (ventesAEnvoyer().length > 0) {
                this.envoyer();
            } else if (autrePersonne || !donnees() || ageDonnees() > RAFRAICHIR_APRES_MS || this.sessionChangee()) {
                rafraichirDonnees().catch(() => {});
            }
        }
        this.afficher();
    }

    /** La copie dit si la caisse est ouverte : elle est reprise quand la caisse vient d'être ouverte ou clôturée. */
    sessionChangee() {
        return this.sessionValue >= 0 && donnees()?.sessionOuverte !== (this.sessionValue === 1);
    }

    disconnect() {
        window.removeEventListener('online', this.enLigne);
        window.removeEventListener('offline', this.horsLigne);
        document.removeEventListener('click', this.clic);
    }

    /** La déconnexion retire l'accès à la caisse hors ligne ; les ventes non envoyées restent sur le poste. */
    deconnexion() {
        oublierIdentite();
    }

    async envoyer() {
        if (this.hasEnvoyerTarget) {
            this.envoyerTarget.disabled = true;
        }
        try {
            await synchroniser();
            this.erreur = null;
        } catch (e) {
            this.erreur = e.message === 'connexion'
                ? 'Session expirée : reconnectez-vous pour envoyer les ventes faites hors ligne.'
                : 'Envoi impossible pour le moment, nouvel essai au retour du réseau.';
        }
        this.afficher();
    }

    surEnLigne() {
        this.afficher();
        ventesAEnvoyer().length > 0 ? this.envoyer() : rafraichirDonnees().catch(() => {});
    }

    afficher() {
        if (!this.hasBandeauTarget) {
            return;
        }
        const enAttente = ventesAEnvoyer().length;
        let message = null;
        if (!navigator.onLine) {
            message = `Connexion internet perdue. Continuez à vendre avec la <a href="${URL_PAGE}" data-turbo="false" class="alert-link">caisse hors ligne</a>.`;
        } else if (enAttente > 0) {
            message = this.erreur ?? `${enAttente} vente(s) faite(s) hors ligne en cours d'envoi.`;
        }
        // Classes posées seulement quand le bandeau s'affiche : aucune alerte dans la page sinon.
        this.bandeauTarget.classList.toggle('d-none', message === null);
        this.bandeauTarget.classList.toggle('d-flex', message !== null);
        this.bandeauTarget.classList.toggle('alert', message !== null);
        this.bandeauTarget.classList.toggle('alert-danger', !navigator.onLine);
        this.bandeauTarget.classList.toggle('alert-warning', navigator.onLine);
        if (message !== null) {
            this.messageTarget.innerHTML = message;
        }
        if (this.hasEnvoyerTarget) {
            this.envoyerTarget.hidden = !navigator.onLine || enAttente === 0;
            this.envoyerTarget.disabled = false;
        }
    }

    async installer() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) {
            return;
        }
        try {
            const enregistrement = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const dernier = Number(localStorage.getItem(CLE_PRECHARGE) ?? 0);
            if (!navigator.onLine || Date.now() - dernier < PRECHARGER_APRES_MS) {
                return;
            }
            const actif = enregistrement.active ?? (await navigator.serviceWorker.ready).active;
            actif?.postMessage({ type: 'precharger' });
            localStorage.setItem(CLE_PRECHARGE, String(Date.now()));
        } catch (e) {
            // Navigateur sans service worker : la caisse hors ligne ne s'ouvrira pas sans réseau.
        }
    }
}
