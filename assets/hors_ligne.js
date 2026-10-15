/*
 * Caisse hors ligne : ce que le poste garde pour vendre pendant une coupure d'internet, et l'envoi des ventes
 * au retour du réseau. Tout est rangé dans le stockage local du navigateur :
 *  - l'identité de la dernière personne connectée (effacée à la déconnexion) ;
 *  - la copie du catalogue (prix, stock vendable, organismes AMO), rafraîchie régulièrement quand le réseau est là ;
 *  - les ventes faites hors ligne, jusqu'à ce que le serveur confirme les avoir reçues.
 * Le serveur reste seul juge : il recontrôle et numérote chaque vente à la réception.
 */

const CLE_IDENTITE = 'pg-hl-identite';
const CLE_DONNEES = 'pg-hl-donnees';
const CLE_VENTES = 'pg-hl-ventes';
const CLE_POSTE = 'pg-hl-poste';
const CLE_SEQUENCE = 'pg-hl-sequence';

export const URL_PAGE = '/caisse/hors-ligne';
export const URL_DONNEES = '/caisse/hors-ligne/donnees';
export const URL_SYNCHRONISER = '/caisse/hors-ligne/synchroniser';

/** Le catalogue est rafraîchi en arrière-plan au-delà de cet âge. */
export const RAFRAICHIR_APRES_MS = 10 * 60 * 1000;

const lire = (cle, defaut = null) => {
    try {
        const valeur = localStorage.getItem(cle);
        return valeur === null ? defaut : JSON.parse(valeur);
    } catch (e) {
        return defaut;
    }
};

const ecrire = (cle, valeur) => {
    try {
        localStorage.setItem(cle, JSON.stringify(valeur));
        return true;
    } catch (e) {
        return false;
    }
};

// --- Identité --------------------------------------------------------------------------------------

export const identite = () => lire(CLE_IDENTITE);

export const memoriserIdentite = (utilisateurId, pharmacieId) => ecrire(CLE_IDENTITE, { utilisateurId, pharmacieId });

export const oublierIdentite = () => {
    try {
        localStorage.removeItem(CLE_IDENTITE);
        localStorage.removeItem(CLE_DONNEES);
    } catch (e) {
        // Rien à faire : le stockage est indisponible.
    }
};

const memeIdentite = (a, b) => a && b && a.utilisateurId === b.utilisateurId && a.pharmacieId === b.pharmacieId;

// --- Catalogue -------------------------------------------------------------------------------------

/** Copie du catalogue de la personne connectée, ou null si le poste n'en a pas. */
export const donnees = () => {
    const copie = lire(CLE_DONNEES);
    const qui = identite();
    if (!copie || !qui || !memeIdentite(qui, { utilisateurId: copie.utilisateur.id, pharmacieId: copie.pharmacie.id })) {
        return null;
    }
    return copie;
};

export const ageDonnees = () => {
    const copie = donnees();
    return copie ? Date.now() - (copie.recuLe ?? 0) : Infinity;
};

/**
 * Télécharge une copie fraîche du catalogue. Lève une erreur si le serveur est injoignable ou si la
 * session a expiré (le serveur renvoie alors la page de connexion au lieu des données).
 */
export const rafraichirDonnees = async () => {
    const reponse = await fetch(URL_DONNEES, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
    if (!reponse.ok || !(reponse.headers.get('Content-Type') ?? '').includes('application/json')) {
        throw new Error(reponse.redirected || reponse.status === 401 || reponse.status === 403 ? 'connexion' : 'serveur');
    }
    const copie = await reponse.json();
    copie.recuLe = Date.now();
    memoriserIdentite(copie.utilisateur.id, copie.pharmacie.id);
    if (!ecrire(CLE_DONNEES, copie)) {
        throw new Error('stockage');
    }
    return copie;
};

// --- Ventes hors ligne -----------------------------------------------------------------------------

/** Toutes les ventes gardées sur le poste, quelle que soit la personne. */
const toutesLesVentes = () => lire(CLE_VENTES, []);

/** Ventes gardées sur le poste pour la personne connectée. */
export const ventes = () => {
    const qui = identite();
    return qui ? toutesLesVentes().filter((v) => memeIdentite(qui, v)) : [];
};

export const ventesAEnvoyer = () => ventes().filter((v) => v.statut === 'a_envoyer');

export const enregistrerVente = (vente) => ecrire(CLE_VENTES, [...toutesLesVentes(), vente]);

export const supprimerVente = (uuid) => ecrire(CLE_VENTES, toutesLesVentes().filter((v) => v.uuid !== uuid));

/** Quantités vendues hors ligne et pas encore reçues par le serveur : à retirer du stock de la copie. */
export const quantitesNonEnvoyees = () => {
    const quantites = new Map();
    for (const vente of ventesAEnvoyer()) {
        for (const ligne of vente.lignes) {
            quantites.set(ligne.produit, (quantites.get(ligne.produit) ?? 0) + ligne.quantite);
        }
    }
    return quantites;
};

/** Numéro du ticket provisoire : HL-<code du poste>-<compteur du poste>. */
export const numeroProvisoire = () => {
    let poste = lire(CLE_POSTE);
    if (!poste) {
        const lettres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        const hasard = crypto.getRandomValues(new Uint8Array(4));
        poste = Array.from(hasard, (o) => lettres[o % lettres.length]).join('');
        ecrire(CLE_POSTE, poste);
    }
    const suivant = (lire(CLE_SEQUENCE, 0) ?? 0) + 1;
    ecrire(CLE_SEQUENCE, suivant);
    return `HL-${poste}-${suivant}`;
};

/** Identifiant unique de la vente (crypto.randomUUID n'existe qu'en HTTPS). */
export const uuid = () => {
    const o = crypto.getRandomValues(new Uint8Array(16));
    o[6] = (o[6] & 0x0f) | 0x40;
    o[8] = (o[8] & 0x3f) | 0x80;
    const h = Array.from(o, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
};

let envoiEnCours = null;

/**
 * Envoie au serveur les ventes faites hors ligne. Une vente n'est effacée du poste qu'une fois que le serveur
 * l'a reçue (importée ou en anomalie) ; une vente refusée reste affichée avec le motif.
 *
 * @returns {Promise<{envoyees: number, importees: number, anomalies: number, refusees: number}>}
 */
export const synchroniser = () => {
    envoiEnCours ??= (async () => {
        try {
            const copie = await rafraichirDonnees();
            const aEnvoyer = ventesAEnvoyer();
            const bilan = { envoyees: 0, importees: 0, anomalies: 0, refusees: 0 };
            if (aEnvoyer.length === 0) {
                return bilan;
            }
            const reponse = await fetch(URL_SYNCHRONISER, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': copie.jeton },
                body: JSON.stringify({ ventes: aEnvoyer.slice(0, 200) }),
            });
            if (!reponse.ok || !(reponse.headers.get('Content-Type') ?? '').includes('application/json')) {
                throw new Error(reponse.status === 403 ? 'connexion' : 'serveur');
            }
            const { resultats } = await reponse.json();
            const restantes = toutesLesVentes().map((vente) => {
                const resultat = resultats[vente.uuid];
                if (!resultat) {
                    return vente;
                }
                bilan.envoyees++;
                if (resultat.statut === 'refusee') {
                    bilan.refusees++;
                    return { ...vente, statut: 'refusee', message: resultat.message };
                }
                resultat.statut === 'importee' ? bilan.importees++ : bilan.anomalies++;
                return null;
            });
            ecrire(CLE_VENTES, restantes.filter((v) => v !== null));
            // Le stock du serveur tient maintenant compte des ventes importées.
            await rafraichirDonnees();
            return bilan;
        } finally {
            envoiEnCours = null;
        }
    })();
    return envoiEnCours;
};

// --- Calculs (mêmes règles que le serveur, RG-07 et RG-10) -------------------------------------------

export const formatFcfa = (valeur) => `${Math.round(valeur).toLocaleString('fr-FR').replace(/\s/g, ' ')} FCFA`;

/**
 * Prix facturé d'une ligne : sur une vente AMO avec un organisme AMO, un médicament remboursable qui a un prix AMO
 * est vendu au plus bas de ce prix et du prix de la pharmacie (LigneVente::appliquerPrix).
 */
export const prixFacture = (ligne, type, organisme) => (type === 'amo' && organisme?.tarifAmo && ligne.remboursable && ligne.prixAmo !== null && ligne.prixAmo !== undefined
    ? Math.min(ligne.prix, ligne.prixAmo)
    : ligne.prix);

/**
 * Montants d'un panier hors ligne. La part AMO se calcule sur le prix facturé des médicaments remboursables
 * et ne dépasse jamais ce prix.
 */
export const calculer = (lignes, type, organisme) => {
    let total = 0;
    let base = 0;
    for (const ligne of lignes) {
        const brut = prixFacture(ligne, type, organisme) * ligne.quantite;
        total += brut;
        if (type === 'amo' && organisme && ligne.remboursable) {
            base += brut;
        }
    }
    const brutRemboursable = base;
    const partAmo = type === 'amo' && organisme ? Math.min(brutRemboursable, Math.round((base * organisme.taux) / 100)) : 0;
    return { total, partAmo, aPayer: total - partAmo };
};
