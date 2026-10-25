/*
 * Bouton « œil » des champs mot de passe et code PIN : affiche ou masque la saisie.
 * Les gabarits rendent déjà le bouton (thème de formulaire, connexion, caisse) ; les champs mot de passe rendus
 * ailleurs (EasyAdmin, contenu ajouté plus tard) sont équipés ici. Le clic passe par une délégation sur le document,
 * ce qui marche aussi après une restauration de page par Turbo.
 */
import './styles/mot_de_passe.css';

function mettreAJour(bouton, visible) {
    const libelle = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
    bouton.setAttribute('aria-pressed', visible ? 'true' : 'false');
    bouton.setAttribute('aria-label', libelle);
    bouton.title = libelle;
    bouton.querySelector('i')?.classList.replace(visible ? 'bi-eye' : 'bi-eye-slash', visible ? 'bi-eye-slash' : 'bi-eye');
}

function equiper(racine = document) {
    racine.querySelectorAll('input[type="password"]').forEach((champ) => {
        if (champ.closest('.pg-mdp')) {
            return;
        }
        const enveloppe = document.createElement('div');
        enveloppe.className = 'pg-mdp';
        champ.replaceWith(enveloppe);
        enveloppe.append(champ);
        enveloppe.insertAdjacentHTML('beforeend', '<button type="button" class="pg-mdp-bouton" data-pg-mdp><i class="bi bi-eye" aria-hidden="true"></i></button>');
        mettreAJour(enveloppe.lastElementChild, false);
    });
}

document.addEventListener('click', (evenement) => {
    const bouton = evenement.target.closest('[data-pg-mdp]');
    const champ = bouton?.closest('.pg-mdp')?.querySelector('input');
    if (!champ) {
        return;
    }
    const visible = champ.type === 'password';
    champ.type = visible ? 'text' : 'password';
    mettreAJour(bouton, visible);
    champ.focus();
});

// À l'envoi, la saisie redevient masquée (le gestionnaire de mots de passe du navigateur la reconnaît ainsi).
document.addEventListener('submit', (evenement) => {
    evenement.target.querySelectorAll('.pg-mdp input[type="text"]').forEach((champ) => {
        champ.type = 'password';
        const bouton = champ.closest('.pg-mdp').querySelector('[data-pg-mdp]');
        if (bouton) {
            mettreAJour(bouton, false);
        }
    });
}, true);

document.addEventListener('turbo:load', () => equiper());
document.addEventListener('turbo:frame-load', (evenement) => equiper(evenement.target));
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => equiper());
} else {
    equiper();
}
