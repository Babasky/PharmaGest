<?php

/*
 * Routeur du serveur intégré de PHP, pour le développement seulement :
 *   php -S 127.0.0.1:8000 -t public bin/serveur-dev.php
 * Les fichiers présents dans public/ (assets d'EasyAdmin sous public/bundles) sont servis tels quels ; tout le reste,
 * pages et assets d'AssetMapper, passe par Symfony.
 */
$public = dirname(__DIR__).'/public';
if (is_file($public.parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH))) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
require $public.'/index.php';
