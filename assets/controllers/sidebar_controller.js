import { Controller } from '@hotwired/stimulus';

/*
 * Menu vertical réductible (grand écran) : l'état est mémorisé dans le navigateur et porté par <html>
 * (classe « pg-sidebar-reduit »), qui persiste entre les visites Turbo. Menu réduit : le survol le déplie.
 */
export default class extends Controller {
    basculer() {
        const reduit = document.documentElement.classList.toggle('pg-sidebar-reduit');
        document.documentElement.classList.remove('pg-sidebar-survol');
        try {
            localStorage.setItem('pg-sidebar', reduit ? 'reduit' : 'deplie');
        } catch (e) {
            // Stockage indisponible (navigation privée) : l'état ne sera simplement pas mémorisé.
        }
    }

    survol() {
        if (document.documentElement.classList.contains('pg-sidebar-reduit')) {
            document.documentElement.classList.add('pg-sidebar-survol');
        }
    }

    finSurvol() {
        document.documentElement.classList.remove('pg-sidebar-survol');
    }
}
