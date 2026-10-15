/*
 * Écran de caisse hors ligne : vend à partir de la copie du catalogue gardée sur le poste, imprime un ticket
 * provisoire et garde la vente jusqu'à son envoi au serveur. Mêmes contrôles que la caisse en ligne, sauf ce qui
 * demande le serveur (client, remise, code PIN) : le serveur recontrôle tout à la réception.
 */
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/app.css';
import './styles/hors_ligne.css';
import {
    calculer, donnees, prixFacture, enregistrerVente, formatFcfa, numeroProvisoire, quantitesNonEnvoyees, rafraichirDonnees,
    supprimerVente, synchroniser, uuid, ventes,
} from './hors_ligne.js';

const $ = (id) => document.getElementById(id);
const echapper = (texte) => String(texte ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const entier = (texte) => {
    const nettoye = String(texte ?? '').replace(/[\s  ]/g, '');
    return /^\d+$/.test(nettoye) ? parseInt(nettoye, 10) : null;
};
const sansAccents = (texte) => String(texte ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const aujourdhui = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const heure = (iso) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

const etat = {
    copie: null,
    produits: new Map(),
    panier: [], // { produit, quantite }
    derniere: null,
    envoi: '',
};

// --- Catalogue -------------------------------------------------------------------------------------

const charger = (copie) => {
    etat.copie = copie;
    etat.produits = new Map(copie.produits.map(([id, nom, details, codeBarres, prix, prixAmo, remboursable, ordonnance, stock]) => [id, {
        id, nom, details, codeBarres, prix, prixAmo, remboursable: remboursable === 1, ordonnance: ordonnance === 1, stock,
        recherche: sansAccents(`${nom} ${details}`),
    }]));
    $('hl-identite').textContent = `${copie.pharmacie.nom} · ${copie.utilisateur.nom}`;
    const recu = new Date(copie.recuLe);
    $('hl-catalogue').textContent = `${etat.produits.size} produits · copie du catalogue du ${recu.toLocaleDateString('fr-FR')} à ${recu.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
    $('hl-session').classList.toggle('d-none', copie.sessionOuverte);
    const organisme = $('hl-organisme').value;
    $('hl-organisme').innerHTML = '<option value="">Choisir…</option>'
        + copie.organismes.map((o) => `<option value="${o.id}">${echapper(o.nom)} (${o.taux} %)</option>`).join('');
    $('hl-organisme').value = organisme;
    if ($('hl-modes').childElementCount === 0) {
        $('hl-modes').innerHTML = copie.modes.map((m) => `
            <div class="col-6"><label class="form-label small mb-1" for="hl-mode-${m.code}">${echapper(m.libelle)}</label>
            <input class="form-control form-control-sm text-end hl-mode" data-mode="${m.code}" id="hl-mode-${m.code}" inputmode="numeric" placeholder="Montant"></div>
            <div class="col-6"><label class="form-label small mb-1" for="hl-ref-${m.code}">Référence</label>
            <input class="form-control form-control-sm" data-reference="${m.code}" id="hl-ref-${m.code}" maxlength="60"></div>`).join('');
    }
    // Les produits du panier suivent la nouvelle copie (prix et stock à jour).
    etat.panier = etat.panier.filter((l) => etat.produits.has(l.produit));
    $('hl-indisponible').classList.add('d-none');
    $('hl-caisse').classList.remove('d-none');
    afficherPanier();
    rechercher();
};

const disponible = (produit) => produit.stock - (quantitesNonEnvoyees().get(produit.id) ?? 0);

// --- Recherche et panier ---------------------------------------------------------------------------

const rechercher = () => {
    const q = sansAccents($('hl-q').value.trim());
    const zone = $('hl-resultats');
    if (q.length < 2) {
        zone.innerHTML = '';
        return;
    }
    const mots = q.split(/\s+/);
    const trouves = [];
    for (const p of etat.produits.values()) {
        if (p.codeBarres === q.replace(/\s/g, '') || mots.every((m) => p.recherche.includes(m))) {
            trouves.push(p);
            if (trouves.length >= 30) {
                break;
            }
        }
    }
    zone.innerHTML = trouves.length === 0
        ? '<div class="text-body-secondary small py-2">Aucun produit trouvé.</div>'
        : trouves.map((p) => {
            const stock = disponible(p);
            return `<button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2" data-ajouter="${p.id}"${stock <= 0 ? ' disabled' : ''}>
                <span class="flex-grow-1 min-w-0"><span class="fw-semibold">${echapper(p.nom)}</span>
                ${p.ordonnance ? '<span class="badge text-bg-warning ms-1">Ordonnance</span>' : ''}
                <br><span class="small text-body-secondary">${echapper(p.details)}</span></span>
                <span class="text-end small"><span class="fw-semibold">${formatFcfa(p.prix)}</span><br>
                <span class="${stock > 0 ? 'text-body-secondary' : 'text-danger'}">Stock ${stock}</span></span></button>`;
        }).join('');
};

const ajouter = (id) => {
    const produit = etat.produits.get(id);
    if (!produit) {
        return;
    }
    const ligne = etat.panier.find((l) => l.produit === id);
    const quantite = (ligne?.quantite ?? 0) + 1;
    if (quantite > disponible(produit)) {
        erreur(`Stock insuffisant pour ${produit.nom} : ${disponible(produit)} disponible(s).`);
        return;
    }
    ligne ? ligne.quantite = quantite : etat.panier.push({ produit: id, quantite: 1 });
    $('hl-faite').classList.add('d-none');
    erreur(null);
    afficherPanier();
};

const lignesPanier = () => etat.panier.map((l) => {
    const p = etat.produits.get(l.produit);
    return { produit: p.id, quantite: l.quantite, nom: p.nom, prix: p.prix, prixAmo: p.prixAmo, remboursable: p.remboursable };
});

const organismeChoisi = () => etat.copie?.organismes.find((o) => String(o.id) === $('hl-organisme').value) ?? null;

const afficherPanier = () => {
    const type = $('hl-type').value;
    document.querySelectorAll('.hl-amo').forEach((e) => e.classList.toggle('d-none', type !== 'amo'));
    document.querySelectorAll('.hl-ordonnance').forEach((e) => e.classList.toggle('d-none', type === 'sans_ordonnance'));
    $('hl-panier').innerHTML = etat.panier.length === 0
        ? '<tr><td colspan="4" class="text-body-secondary small">Panier vide : recherchez un produit.</td></tr>'
        : etat.panier.map((l) => {
            const p = etat.produits.get(l.produit);
            const nonRembourse = type === 'amo' && !p.remboursable ? ' <span class="badge text-bg-secondary">NR</span>' : '';
            const prix = prixFacture(p, type, organismeChoisi());
            const auPrixAmo = prix < p.prix ? ` (prix AMO, au lieu de <s>${formatFcfa(p.prix)}</s>)` : '';
            return `<tr><td>${echapper(p.nom)}${nonRembourse}<br><span class="small text-body-secondary">${formatFcfa(prix)}${auPrixAmo}</span></td>
                <td><input class="form-control form-control-sm text-end" inputmode="numeric" value="${l.quantite}" data-quantite="${p.id}" aria-label="Quantité"></td>
                <td class="text-end">${formatFcfa(prix * l.quantite)}</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-retirer="${p.id}" aria-label="Retirer"><i class="bi bi-x-lg"></i></button></td></tr>`;
        }).join('');
    const totaux = calculer(lignesPanier(), type, organismeChoisi());
    $('hl-totaux').innerHTML = `<dt class="col-7">Total</dt><dd class="col-5 text-end mb-0">${formatFcfa(totaux.total)}</dd>`
        + (type === 'amo' ? `<dt class="col-7">Part AMO / assurance</dt><dd class="col-5 text-end mb-0">− ${formatFcfa(totaux.partAmo)}</dd>` : '')
        + `<dt class="col-7 fs-5">À payer</dt><dd class="col-5 text-end fs-5 fw-semibold mb-0">${formatFcfa(totaux.aPayer)}</dd>`;
    monnaie();
};

const autresPaiements = () => [...document.querySelectorAll('.hl-mode')].reduce((somme, champ) => somme + (entier(champ.value) ?? 0), 0);

const monnaie = () => {
    const { aPayer } = calculer(lignesPanier(), $('hl-type').value, organismeChoisi());
    const remis = entier($('hl-remis').value);
    const especes = Math.max(0, aPayer - autresPaiements());
    $('hl-monnaie').textContent = formatFcfa(remis !== null && remis > especes ? remis - especes : 0);
};

const erreur = (message) => {
    $('hl-erreur').textContent = message ?? '';
    $('hl-erreur').classList.toggle('d-none', !message);
};

// --- Encaissement ----------------------------------------------------------------------------------

/** Contrôles de la caisse en ligne (VentesService::controler) faisables sans le serveur. */
const controler = (type, lignes, organisme, totaux) => {
    if (lignes.length === 0) {
        return 'Le panier est vide.';
    }
    for (const l of lignes) {
        const p = etat.produits.get(l.produit);
        if (l.quantite > disponible(p)) {
            return `Stock insuffisant pour ${p.nom} : ${disponible(p)} disponible(s).`;
        }
    }
    if (type !== 'sans_ordonnance') {
        const date = $('hl-ord-date').value;
        if (!date) {
            return 'Renseignez la date de l\'ordonnance.';
        }
        if (date > aujourdhui()) {
            return 'La date de l\'ordonnance ne peut pas être dans le futur.';
        }
    }
    if (type === 'amo' && !organisme) {
        return 'Vente AMO / assurance : choisissez l\'organisme.';
    }
    if (type === 'sans_ordonnance') {
        const exigeant = lignes.filter((l) => etat.produits.get(l.produit).ordonnance).map((l) => l.nom);
        if (exigeant.length > 0 && (etat.copie.politiqueSansOrdonnance === 'blocage' || !etat.copie.utilisateur.proprietaire)) {
            return `Ordonnance obligatoire pour ${exigeant.join(', ')} : choisissez « Ordonnance classique » ou « Ordonnance AMO / assurance » (le code PIN du propriétaire ne peut pas être saisi hors ligne).`;
        }
    }
    const autres = autresPaiements();
    if (autres > totaux.aPayer) {
        return `Les paiements hors espèces (${formatFcfa(autres)}) dépassent le montant à payer (${formatFcfa(totaux.aPayer)}).`;
    }
    const remis = entier($('hl-remis').value);
    if ($('hl-remis').value.trim() !== '' && remis === null) {
        return 'Espèces remises : montant invalide.';
    }
    if (remis !== null && remis < totaux.aPayer - autres) {
        return `Espèces insuffisantes : ${formatFcfa(remis)} remis pour ${formatFcfa(totaux.aPayer - autres)} à payer.`;
    }
    return null;
};

const encaisser = (event) => {
    event.preventDefault();
    const type = $('hl-type').value;
    const lignes = lignesPanier();
    const organisme = organismeChoisi();
    const totaux = calculer(lignes, type, organisme);
    const probleme = controler(type, lignes, organisme, totaux);
    if (probleme) {
        erreur(probleme);
        return;
    }

    const paiements = {};
    document.querySelectorAll('.hl-mode').forEach((champ) => {
        const montant = entier(champ.value);
        if (montant) {
            paiements[champ.dataset.mode] = { montant, reference: document.querySelector(`[data-reference="${champ.dataset.mode}"]`).value.trim() };
        }
    });
    const especes = totaux.aPayer - autresPaiements();
    const remis = entier($('hl-remis').value) ?? especes;
    if (especes > 0) {
        paiements.especes = { remis };
    }

    const vente = {
        uuid: uuid(),
        numero: numeroProvisoire(),
        venduLe: new Date().toISOString(),
        utilisateurId: etat.copie.utilisateur.id,
        pharmacieId: etat.copie.pharmacie.id,
        type,
        ordonnance: type === 'sans_ordonnance' ? null : {
            date: $('hl-ord-date').value, numero: $('hl-ord-numero').value.trim(), prescripteur: $('hl-ord-prescripteur').value.trim(),
        },
        organisme: type === 'amo' ? organisme.id : null,
        organismeNom: type === 'amo' ? organisme.nom : null,
        numeroAssure: type === 'amo' ? $('hl-assure').value.trim() : null,
        // Prix facturé (prix AMO s'il est plus bas) : c'est lui qui figure sur le ticket provisoire.
        lignes: lignes.map((l) => ({ ...l, prix: prixFacture(l, type, organisme) })),
        total: totaux.total,
        partAmo: totaux.partAmo,
        montant: totaux.aPayer,
        paiements,
        monnaie: Math.max(0, remis - Math.max(0, especes)),
        statut: 'a_envoyer',
    };
    if (!enregistrerVente(vente)) {
        erreur('Impossible d\'enregistrer la vente sur ce poste (stockage du navigateur plein ou bloqué).');
        return;
    }

    etat.derniere = vente;
    etat.panier = [];
    ['hl-remis', 'hl-assure', 'hl-ord-date', 'hl-ord-numero', 'hl-ord-prescripteur'].forEach((id) => { $(id).value = ''; });
    document.querySelectorAll('.hl-mode, [data-reference]').forEach((champ) => { champ.value = ''; });
    $('hl-type').value = 'sans_ordonnance';
    $('hl-q').value = '';
    erreur(null);
    $('hl-faite-texte').textContent = `Vente ${vente.numero} enregistrée sur ce poste : ${formatFcfa(vente.montant)}${vente.monnaie > 0 ? `, monnaie à rendre ${formatFcfa(vente.monnaie)}` : ''}.`;
    $('hl-faite').classList.remove('d-none');
    afficherPanier();
    rechercher();
    afficherVentes();
    $('hl-q').focus();
    envoyer(true);
};

// --- Ticket ----------------------------------------------------------------------------------------

const imprimer = (vente) => {
    const p = etat.copie.pharmacie;
    const modes = Object.fromEntries(etat.copie.modes.map((m) => [m.code, m.libelle]));
    const lignes = vente.lignes.map((l) => `<tr><td colspan="2">${echapper(l.nom)}</td></tr>
        <tr><td>&nbsp;&nbsp;${l.quantite} × ${formatFcfa(l.prix)}</td><td class="droite">${formatFcfa(l.prix * l.quantite)}</td></tr>`).join('');
    const paiements = Object.entries(vente.paiements).map(([mode, detail]) => mode === 'especes'
        ? `<tr><td>Espèces remises</td><td class="droite">${formatFcfa(detail.remis)}</td></tr>`
        : `<tr><td>${echapper(modes[mode] ?? mode)}${detail.reference ? ` (${echapper(detail.reference)})` : ''}</td><td class="droite">${formatFcfa(detail.montant)}</td></tr>`).join('');
    $('hl-ticket').innerHTML = `
        <div class="centre"><strong>${echapper(p.nom)}</strong><br>${echapper(p.adresse)}${p.telephone ? `<br>Tél. ${echapper(p.telephone)}` : ''}</div>
        <hr><div>Ticket provisoire <strong>${echapper(vente.numero)}</strong></div>
        <div>${heure(vente.venduLe)} · ${echapper(etat.copie.utilisateur.nom)}</div>
        ${vente.ordonnance ? `<div>Ordonnance${vente.ordonnance.numero ? ` n° ${echapper(vente.ordonnance.numero)}` : ''} du ${new Date(vente.ordonnance.date).toLocaleDateString('fr-FR')}</div>` : ''}
        ${vente.type === 'amo' ? `<div>Assuré ${echapper(vente.organismeNom)}${vente.numeroAssure ? ` n° ${echapper(vente.numeroAssure)}` : ''}</div>` : ''}
        <hr><table>${lignes}</table><hr>
        <table><tr><td>Total</td><td class="droite">${formatFcfa(vente.total)}</td></tr>
        ${vente.partAmo > 0 ? `<tr><td>Part AMO / assurance</td><td class="droite">− ${formatFcfa(vente.partAmo)}</td></tr>` : ''}
        <tr class="gras"><td>Payé</td><td class="droite">${formatFcfa(vente.montant)}</td></tr>${paiements}
        ${vente.monnaie > 0 ? `<tr><td>Monnaie rendue</td><td class="droite">${formatFcfa(vente.monnaie)}</td></tr>` : ''}</table>
        <hr><div class="centre">Vente faite sans connexion internet.<br>Le numéro définitif est attribué à l'envoi.</div>`;
    window.print();
};

// --- Ventes du poste et envoi ----------------------------------------------------------------------

const afficherVentes = () => {
    const liste = ventes().slice().reverse();
    $('hl-ventes').innerHTML = liste.length === 0
        ? '<tr><td colspan="5" class="text-body-secondary small">Aucune vente en attente sur ce poste.</td></tr>'
        : liste.map((v) => `<tr>
            <td class="text-nowrap">${echapper(v.numero)}</td><td class="text-nowrap">${heure(v.venduLe)}</td>
            <td class="text-end text-nowrap">${formatFcfa(v.montant)}</td>
            <td>${v.statut === 'refusee'
                ? `<span class="badge text-bg-danger">Refusée</span> <span class="small">${echapper(v.message)}</span>`
                : '<span class="badge text-bg-warning">À envoyer</span>'}</td>
            <td class="text-end text-nowrap">
                <button type="button" class="btn btn-sm btn-link" data-reimprimer="${v.uuid}" title="Réimprimer le ticket"><i class="bi bi-printer"></i></button>
                ${v.statut === 'refusee' ? `<button type="button" class="btn btn-sm btn-link text-danger" data-supprimer="${v.uuid}" title="Retirer du poste"><i class="bi bi-trash"></i></button>` : ''}
            </td></tr>`).join('');
    const enLigne = navigator.onLine;
    const etiquette = $('hl-etat');
    etiquette.textContent = enLigne ? 'En ligne' : 'Hors ligne';
    etiquette.className = `badge ${enLigne ? 'text-bg-success' : 'text-bg-danger'}`;
    $('hl-envoyer').disabled = !enLigne || liste.every((v) => v.statut !== 'a_envoyer');
    $('hl-envoi-etat').textContent = etat.envoi;
};

const envoyer = async (silencieux = false) => {
    if (!navigator.onLine) {
        afficherVentes();
        return;
    }
    $('hl-envoyer').disabled = true;
    try {
        const bilan = await synchroniser();
        const copie = donnees();
        if (copie) {
            charger(copie);
        }
        if (bilan.envoyees > 0) {
            etat.envoi = `${bilan.envoyees} vente(s) envoyée(s)`
                + (bilan.anomalies > 0 ? `, dont ${bilan.anomalies} à reprendre dans « Ventes hors ligne »` : '')
                + (bilan.refusees > 0 ? `, ${bilan.refusees} refusée(s)` : '') + '.';
        }
    } catch (e) {
        if (!silencieux || e.message === 'connexion') {
            etat.envoi = e.message === 'connexion'
                ? 'Session expirée : reconnectez-vous (caisse en ligne) pour envoyer les ventes.'
                : 'Serveur injoignable : les ventes restent sur ce poste.';
        }
    }
    afficherVentes();
};

// --- Démarrage -------------------------------------------------------------------------------------

const demarrer = async () => {
    const copie = donnees();
    if (copie) {
        charger(copie);
    }
    if (navigator.onLine) {
        try {
            charger(await rafraichirDonnees());
        } catch (e) {
            // Serveur injoignable malgré le réseau : on garde la copie du poste.
        }
    }
    if (!etat.copie) {
        $('hl-indisponible').textContent = 'La caisse hors ligne n\'est pas prête sur ce poste : connectez-vous une fois à PharmaGest avec internet pour copier le catalogue.';
        $('hl-indisponible').classList.remove('d-none');
        return;
    }
    afficherVentes();
    envoyer(true);
};

$('hl-recherche').addEventListener('submit', (event) => {
    event.preventDefault();
    const q = $('hl-q').value.replace(/\s/g, '');
    const exact = [...etat.produits.values()].find((p) => p.codeBarres && p.codeBarres === q);
    const premier = $('hl-resultats').querySelector('[data-ajouter]:not([disabled])');
    if (exact || premier) {
        ajouter(exact ? exact.id : Number(premier.dataset.ajouter));
        if (exact) {
            $('hl-q').value = '';
            rechercher();
        }
    }
});
$('hl-q').addEventListener('input', rechercher);
$('hl-resultats').addEventListener('click', (event) => {
    const bouton = event.target.closest('[data-ajouter]');
    if (bouton) {
        ajouter(Number(bouton.dataset.ajouter));
    }
});
$('hl-panier').addEventListener('change', (event) => {
    const id = Number(event.target.dataset.quantite);
    const ligne = etat.panier.find((l) => l.produit === id);
    if (!ligne) {
        return;
    }
    const quantite = entier(event.target.value) ?? ligne.quantite;
    const produit = etat.produits.get(id);
    if (quantite > disponible(produit)) {
        erreur(`Stock insuffisant pour ${produit.nom} : ${disponible(produit)} disponible(s).`);
    } else {
        erreur(null);
        ligne.quantite = quantite;
    }
    etat.panier = etat.panier.filter((l) => l.quantite > 0);
    afficherPanier();
});
$('hl-panier').addEventListener('click', (event) => {
    const bouton = event.target.closest('[data-retirer]');
    if (bouton) {
        etat.panier = etat.panier.filter((l) => l.produit !== Number(bouton.dataset.retirer));
        afficherPanier();
    }
});
$('hl-type').addEventListener('change', afficherPanier);
$('hl-organisme').addEventListener('change', afficherPanier);
$('hl-remis').addEventListener('input', monnaie);
$('hl-modes').addEventListener('input', monnaie);
$('hl-paiement').addEventListener('submit', encaisser);
$('hl-vider').addEventListener('click', () => {
    etat.panier = [];
    erreur(null);
    afficherPanier();
});
$('hl-imprimer').addEventListener('click', () => etat.derniere && imprimer(etat.derniere));
$('hl-envoyer').addEventListener('click', () => envoyer());
$('hl-ventes').addEventListener('click', (event) => {
    const reimprimer = event.target.closest('[data-reimprimer]');
    const supprimer = event.target.closest('[data-supprimer]');
    if (reimprimer) {
        const vente = ventes().find((v) => v.uuid === reimprimer.dataset.reimprimer);
        vente && imprimer(vente);
    } else if (supprimer && window.confirm('Retirer cette vente refusée du poste ? Elle ne sera jamais enregistrée.')) {
        supprimerVente(supprimer.dataset.supprimer);
        afficherVentes();
    }
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'F2') {
        event.preventDefault();
        $('hl-q').focus();
        $('hl-q').select();
    } else if (event.key === 'F9') {
        event.preventDefault();
        $('hl-remis').focus();
    }
});
window.addEventListener('online', () => envoyer(true));
window.addEventListener('offline', afficherVentes);

demarrer();
