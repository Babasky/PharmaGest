#!/usr/bin/env python3
"""Convertit la liste AMO de la CANAM (Excel, août 2021) au format du modèle d'import produits de PharmaGest.

Usage : python3 bin/liste-amo.py Liste_medicaments_AMO_Aout_2021.xlsx src/Import/liste-amo-2021-08.csv
Nécessite openpyxl. Voir docs/hypotheses.md (« Modèle d'import produits : liste AMO »).
"""
import csv, re, sys, unicodedata
import openpyxl

def dehy(s):
    s = re.sub(r'\s+', ' ', str(s or '').replace('­', '')).strip()
    return re.sub(r'(\w)- (?=[a-zàâäéèêëïîôöùûüç])', r'\1', s)

def cle(s):
    s = unicodedata.normalize('NFD', dehy(s).lower())
    return re.sub(r'[^a-z]', '', ''.join(c for c in s if not unicodedata.combining(c)))

CLASSES = [  # (préfixe de la clé, libellé)
    ('allerg', 'Allergologie'), ('anest', 'Anesthésie-réanimation'), ('reanim', 'Anesthésie-réanimation'),
    ('eaupourinjection', 'Anesthésie-réanimation'),
    ('angeo', 'Cardiologie-angéiologie'), ('cardio', 'Cardiologie-angéiologie'),
    ('antalgiquesodonto', 'Odontostomatologie'), ('antalgiquesantiinfl', 'Anti-inflammatoires'),
    ('antalgi', 'Antalgiques-antipyrétiques-antispasmodiques'),
    ('antiinfl', 'Anti-inflammatoires'), ('antinfl', 'Anti-inflammatoires'),
    ('cancer', 'Cancérologie'), ('oncolog', 'Cancérologie'),
    ('dermato', 'Dermatologie'), ('dietetique', 'Métabolisme-diabète-nutrition'),
    ('metabolism', 'Métabolisme-diabète-nutrition'), ('endocr', 'Endocrinologie'),
    ('gastro', 'Gastro-entéro-hépatologie'), ('procto', 'Gastro-entéro-hépatologie'),
    ('gyneco', 'Gynécologie-obstétrique'), ('hemostase', 'Hématologie'), ('hemat', 'Hématologie'),
    ('imagerie', 'Imagerie médicale'), ('infectio', 'Infectiologie-parasitologie'),
    ('neuro', 'Neurologie-psychiatrie'), ('psychiatr', 'Neurologie-psychiatrie'),
    ('odonto', 'Odontostomatologie'), ('ophtalm', 'Ophtalmologie'), ('oto', 'ORL'),
    ('pediatrie', 'Pédiatrie'), ('pneumo', 'Pneumologie'), ('prevention', 'Vaccins et prévention'),
    ('rhumato', 'Rhumatologie-traumatologie'), ('trauma', 'Rhumatologie-traumatologie'),
    ('urolog', 'Urologie-néphrologie'),
]

def categorie(classe):
    k = cle(classe)
    if k.startswith('dispositif'):
        return 'Dispositifs médicaux'
    for prefixe, libelle in CLASSES:
        if k.startswith(prefixe):
            return 'Médicaments > ' + libelle
    raise ValueError('Classe inconnue : ' + classe)

# Mot qui ouvre la forme galénique dans la désignation.
MOT_FORME = re.compile(r'\b(comprim|comp\b|cp\b|g[ée]lule|capsule|sirop|suspension|susp\b|solution|soluté|injectable|injection|'
                       r'perfusion|poudre|cr[èe]me|pommade|gel\b|collyre|gouttes?\b|suppositoire|ovule|spray|a[ée]rosol|inhal|'
                       r'patch|dispositif transdermique|sachet|granul|lotion|ampoule|émulsion|emulsion|pâte|pate\b|shampo|'
                       r'pastille|lyophilisat|film|seringue|stylo|cartouche|bain de bouche|lavement|implant|vaccin|ovules?|pdre|g[ée]l\b|dragée|suppo|inj\b|lactab|'
                       r'bo[iî]te|bte\b|b/|plaquette|flacon|fl\b|fl/|tube|étui|etui|blister|unidoses?)', re.I)

def forme(texte):
    t = texte.lower()
    regles = [
        ('effervescent', 'Comprimé effervescent'),
        ('collyre', 'Collyre'), ('ophtalmique', 'Collyre'), ('oculaire', 'Collyre'),
        ('poudre pour suspension buvable', 'Poudre pour suspension'), ('poudre pour susp', 'Poudre pour suspension'),
        ('injectable', 'Solution injectable'), ('injection', 'Solution injectable'), ('perfusion', 'Solution injectable'),
        ('pdre pour susp', 'Poudre pour suspension'), ('pdre pr susp', 'Poudre pour suspension'), ('inj', 'Solution injectable'),
        ('suppo', 'Suppositoire'),
        ('comprim', 'Comprimé'), ('comp', 'Comprimé'), ('dragée', 'Comprimé'), ('lactab', 'Comprimé'),
        ('gélule', 'Gélule'), ('gelule', 'Gélule'), ('capsule', 'Gélule'), ('gél', 'Gélule'),
        ('sirop', 'Sirop'), ('suspension', 'Suspension buvable'), ('susp', 'Suspension buvable'),
        ('solution buvable', 'Solution buvable'), ('solution orale', 'Solution buvable'),
        ('gouttes buvables', 'Gouttes'), ('goutte', 'Gouttes'),
        ('sachet', 'Sachet'), ('granul', 'Sachet'),
        ('pommade', 'Pommade'), ('crème', 'Crème'), ('creme', 'Crème'), ('gel', 'Gel'),
        ('suppositoire', 'Suppositoire'), ('ovule', 'Ovule'),
        ('inhal', 'Inhalateur'), ('aérosol', 'Inhalateur'), ('aerosol', 'Inhalateur'),
        ('spray', 'Spray'), ('pulvéris', 'Spray'),
        ('patch', 'Patch'), ('transdermique', 'Patch'),
    ]
    for motif, nom in regles:
        if re.search(r'(?<![a-zàâéèêëîïôöûüç])' + re.escape(motif), t):
            return nom
    return ''

def majuscule(s):
    return s[:1].upper() + s[1:] if s else s

def decouper(designation, est_dispositif):
    """Nom commercial, dosage, forme PharmaGest et conditionnement (le reste de la désignation, forme comprise)."""
    d = dehy(designation).replace('®', ' ').replace('™', ' ')
    d = re.sub(r'\s+', ' ', d).strip().rstrip('.').strip()
    d = re.sub(r'\s+,', ',', d)
    if est_dispositif:
        return d, '', 'Dispositif médical', ''
    m = MOT_FORME.search(d)
    fin = m.start() if m and m.start() > 0 else len(d)
    v = re.search(r',(?!\d)', d)
    virgule = v.start() if v else -1
    if 0 < virgule < fin:
        fin = virgule
    avant, reste = d[:fin].strip(), d[fin:].strip(' ,')
    # Le dosage commence au premier mot qui débute par un chiffre (après le premier mot du nom).
    mots = avant.split(' ')
    nom, dosage = avant, ''
    for i, mot in enumerate(mots[1:], start=1):
        if re.match(r'^[\d.,]', mot):
            nom, dosage = ' '.join(mots[:i]), ' '.join(mots[i:])
            break
    return nom.strip(' -:'), dosage, forme(reste), majuscule(reste)

def couper(s, longueur):
    """Respecte la longueur des champs de PharmaGest."""
    return s if len(s) <= longueur else s[:longueur - 1].rstrip(' ,/') + '…'

# Sous-classes délivrées sur ordonnance : estimation (la liste AMO ne le précise pas), à faire relire par un pharmacien.
ORDONNANCE = ['antibac', 'antibat', 'antbac', 'antibiotique', 'antiinfectieux', 'antihypert', 'antihypotenseur',
    'antidiabet', 'medicamentdudiabete', 'hypolip', 'hypolem', 'hypolid', 'hypochol', 'antilipid', 'antihyperlip',
    'neuroleptique', 'antipsychotique', 'anxiolyt', 'hypnotique', 'antidepress', 'antiepilept', 'anticonvuls',
    'opio', 'corticoid', 'corticost', 'glucocortico', 'steroid', 'sterod', 'ais',
    'hormone', 'thyroid', 'progestatif', 'antiandrogene', 'anticoagulant', 'antivitaminek', 'thrombolytique',
    'trombolytique', 'antiagregant', 'antiarythmique', 'cardiotonique', 'insuffisancecardiaque', 'antiangoreux',
    'angine', 'betabloquant', 'bloquant', 'diuretique', 'diueretique', 'antiviral', 'anticancereux', 'immunosuppresseur',
    'antiparkinson', 'antiglaucomateux', 'ocytocique', 'prostaglandine', 'antithyroid', 'bisphosphonate',
    'produitdecontraste', 'psychostimulant', 'antigoutteux', 'inhibiteurcalcique', 'antagonistecalcique',
    'antimigraineux', 'tetanos', 'hypoglyc', 'quinolone', 'anthypert', 'antiagreg', 'narcotique', 'sommeil',
    'vigilance', 'analeptique', 'cyclemenstruel', 'contaception', 'contraception', 'progesta', 'hyperprolactin', 'sclerose',
    'immunoglob', 'immunserum', 'vaccin', 'substitutsdusang', 'uterot', 'urgence', 'cardiovasculaire', 'catecholamine', 'anesthesique', 'anesthesie', 'anesthesie', 'osteoporose', 'medecamentdelosteoporose']
# « non stéroïdien », « non opioïde », « non narcotique » ne valent pas ordonnance.
SAUF = ['nonsteroidien', 'nonsterodien', 'nonstereoidien', 'nonsteroid', 'nonopioide', 'nonnarcotique']

def ordonnance(sous_classe, forme):
    if forme == 'Solution injectable':
        return True
    k = cle(sous_classe)
    for s in SAUF:
        k = k.replace(s, '')
    return any(m in k for m in ORDONNANCE)

def entier(v):
    return float(str(v).replace(' ', '').replace(' ', '').replace(',', '.'))

def main(source, sortie_csv):
    wb = openpyxl.load_workbook(source, read_only=True)
    lignes = list(wb.active.iter_rows(values_only=True))
    assert lignes[0][:6] == ('Désignation', 'Composition', 'Sous-classe pharmacothérapeutique', 'Classe pharmacothérapeutique',
                              'Prix base de remboursement (G) x 70%', 'Prix public'), lignes[0]
    produits, vus, remarques, accords = [], {}, [], []
    for n, (designation, composition, sous_classe, classe, part, public, accord) in enumerate(lignes[1:], start=2):
        if not designation or len(str(designation).strip()) < 2:
            remarques.append(f'Ligne {n} ignorée : désignation illisible (« {designation} », {composition}, {public} FCFA).')
            continue
        cat = categorie(classe)
        nom, dosage, frm, cond = decouper(designation, cat == 'Dispositifs médicaux')
        dci = dehy(composition)
        if dci.upper().startswith('NA'):
            dci = ''
        prix_public = round(entier(public))
        tarif = round(entier(part) / 0.7)  # prix base de remboursement
        if abs(tarif - prix_public) > 1:
            remarques.append(f'Ligne {n} ({designation}) : prix public {prix_public}, base de remboursement {tarif}.')
        nom, dci, cond = couper(nom, 150), couper(dci, 150), couper(cond, 100)
        cle_produit = (nom.lower(), dosage.lower(), cond.lower())
        if cle_produit in vus:
            remarques.append(f'Ligne {n} ignorée : même produit que la ligne {vus[cle_produit]} ({designation}, {prix_public} FCFA).')
            continue
        vus[cle_produit] = n
        produits.append({
            'nom_commercial': nom, 'dci': dci, 'forme': frm, 'dosage': dosage, 'conditionnement': cond,
            'categorie': cat, 'prix_vente': str(prix_public), 'remboursable_amo': 'oui', 'prix_vente_amo': str(tarif),
            'ordonnance_obligatoire': 'non' if cat == 'Dispositifs médicaux' or not ordonnance(sous_classe, frm) else 'oui',
            # Médicaments exonérés de TVA ; seuils de départ, à ajuster par chaque pharmacie.
            'tva': '0', 'seuil_alerte': '5', 'stock_max': '20',
            'accord_prealable_amo': 'oui' if str(accord or '').strip().upper() == 'AP' else 'non',
        })
        if str(accord or '').strip().upper() == 'AP':
            accords.append(designation)
    with open(sortie_csv, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=list(produits[0]))
        w.writeheader()
        w.writerows(produits)
    print(len(produits), 'produits,', sum(1 for p in produits if p['ordonnance_obligatoire'] == 'oui'), 'sur ordonnance,', sum(1 for p in produits if not p['forme']), 'sans forme reconnue,', len(accords), 'avec accord préalable')
    print('\n'.join(remarques))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
