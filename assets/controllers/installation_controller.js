import { Controller } from '@hotwired/stimulus';

/*
 * Bouton « Installer l'application » (PWA).
 *
 * Le navigateur émet « beforeinstallprompt » quand l'application peut être installée. On l'intercepte
 * (il est aussi capturé tôt dans base.html.twig, au cas où il arrive avant ce contrôleur), on affiche
 * le bouton, et au clic on déclenche l'invite native. Le bouton se masque une fois l'application installée.
 *
 * Sur iOS, l'événement n'existe pas : le bouton reste masqué (installation via Partager → « Sur l'écran d'accueil »).
 */
export default class extends Controller {
    connect() {
        this._onInvite = this._onInvite.bind(this);
        this._onInstallee = this._onInstallee.bind(this);
        this.invite = window.__pgInstallPrompt ?? null;
        if (this.invite) {
            this.element.hidden = false;
        }
        window.addEventListener('beforeinstallprompt', this._onInvite);
        window.addEventListener('appinstalled', this._onInstallee);
    }

    disconnect() {
        window.removeEventListener('beforeinstallprompt', this._onInvite);
        window.removeEventListener('appinstalled', this._onInstallee);
    }

    _onInvite(evenement) {
        evenement.preventDefault();
        this.invite = evenement;
        window.__pgInstallPrompt = evenement;
        this.element.hidden = false;
    }

    _onInstallee() {
        this.invite = null;
        window.__pgInstallPrompt = null;
        this.element.hidden = true;
    }

    async installer() {
        if (null === this.invite) {
            return;
        }
        this.invite.prompt();
        await this.invite.userChoice;
        // Une invite ne sert qu'une fois.
        this.invite = null;
        window.__pgInstallPrompt = null;
        this.element.hidden = true;
    }
}
