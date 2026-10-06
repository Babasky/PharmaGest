import { Controller } from '@hotwired/stimulus';

/*
 * Bascule thème clair / sombre (Bootstrap 5.3, attribut data-bs-theme sur <html>), mémorisé dans le navigateur.
 */
export default class extends Controller {
    basculer() {
        const theme = document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.bsTheme = theme;
        try {
            localStorage.setItem('pg-theme', theme);
        } catch (e) {
            // Stockage indisponible : le thème vaut pour la page en cours.
        }
    }
}
