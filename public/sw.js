/*
 * Service worker de PharmaGest : garde sur le poste l'écran de caisse hors ligne et ses fichiers (scripts, styles,
 * icônes) pour qu'il s'ouvre pendant une coupure d'internet. Rien d'autre n'est mis en cache : les autres pages
 * passent toujours par le serveur, et quand il est injoignable on est renvoyé vers la caisse hors ligne.
 */

const CACHE = 'pharmagest-hors-ligne-v1';
const PAGE = '/caisse/hors-ligne';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const nom of await caches.keys()) {
            if (nom !== CACHE) {
                await caches.delete(nom);
            }
        }
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'precharger') {
        event.waitUntil(precharger());
    }
});

/**
 * Met en cache la page de caisse hors ligne et tout ce qu'elle charge : modules de l'importmap, feuilles de style
 * et polices qu'elles référencent. Les anciennes versions des fichiers sont retirées.
 */
async function precharger() {
    const reponse = await fetch(PAGE, { credentials: 'same-origin', cache: 'no-store' });
    if (!reponse.ok || reponse.redirected) {
        return; // Session expirée : on garde la copie précédente.
    }
    const html = await reponse.clone().text();
    const urls = new Set();
    const importmap = html.match(/<script type="importmap"[^>]*>([\s\S]*?)<\/script>/);
    if (importmap) {
        try {
            for (const url of Object.values(JSON.parse(importmap[1]).imports ?? {})) {
                urls.add(url);
            }
        } catch (e) {
            // Importmap illisible : seules les balises <link> seront gardées.
        }
    }
    for (const lien of html.matchAll(/<link[^>]+href="([^"]+)"/g)) {
        urls.add(lien[1].replace(/&amp;/g, '&'));
    }

    const cache = await caches.open(CACHE);
    const gardees = new Set([new URL(PAGE, self.location.origin).href]);
    await cache.put(PAGE, reponse);
    const aTraiter = [...urls].map((u) => new URL(u, self.location.origin)).filter((u) => u.origin === self.location.origin && u.pathname.startsWith('/assets/'));
    while (aTraiter.length > 0) {
        const url = aTraiter.shift();
        if (gardees.has(url.href)) {
            continue;
        }
        gardees.add(url.href);
        try {
            const fichier = (await cache.match(url)) ?? await fetch(url, { credentials: 'same-origin' });
            if (!fichier.ok) {
                continue;
            }
            await cache.put(url, fichier.clone());
            if (url.pathname.endsWith('.css')) {
                // Polices des icônes, référencées par la feuille de style.
                for (const [, cible] of (await fichier.text()).matchAll(/url\(["']?([^"')]+)["']?\)/g)) {
                    if (!cible.startsWith('data:')) {
                        aTraiter.push(new URL(cible, url));
                    }
                }
            }
        } catch (e) {
            // Fichier injoignable : il sera repris au prochain préchargement.
        }
    }
    for (const requete of await cache.keys()) {
        if (!gardees.has(requete.url)) {
            await cache.delete(requete);
        }
    }
}

self.addEventListener('fetch', (event) => {
    const requete = event.request;
    const url = new URL(requete.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (requete.method === 'GET' && url.pathname.startsWith('/assets/')) {
        // Fichiers versionnés : la copie gardée est identique à celle du serveur.
        event.respondWith(caches.match(requete).then((copie) => copie ?? fetch(requete)));
        return;
    }

    if (requete.method === 'GET' && url.pathname === PAGE) {
        event.respondWith(fetch(requete).catch(async () => (await caches.match(PAGE)) ?? horsLigneSansCopie()));
        return;
    }

    const page = requete.mode === 'navigate' || (requete.headers.get('Accept') ?? '').includes('text/html');
    if (!page || url.pathname.startsWith(PAGE + '/')) {
        return;
    }
    // Serveur injoignable pendant une navigation ou un formulaire : direction la caisse hors ligne.
    event.respondWith(fetch(requete).catch(async () => {
        if (requete.method !== 'GET') {
            return Response.redirect(PAGE, 303);
        }
        return (await caches.match(PAGE)) ?? horsLigneSansCopie();
    }));
});

function horsLigneSansCopie() {
    return new Response(
        '<!DOCTYPE html><html lang="fr"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        + '<title>Hors ligne · PharmaGest</title><body style="font-family:sans-serif;padding:2rem;max-width:40rem">'
        + '<h1 style="font-size:1.4rem">Pas de connexion internet</h1>'
        + '<p>La caisse hors ligne n\'est pas encore prête sur ce poste : ouvrez PharmaGest une fois avec internet pour qu\'elle s\'installe.</p>'
        + '<p><a href="/">Réessayer</a></p></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
}
