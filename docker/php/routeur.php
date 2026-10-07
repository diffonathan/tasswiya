<?php

/*
 * Routeur du serveur intégré de PHP.
 *
 * Il existe pour une seule raison : `php -S -t public public/index.php` ne
 * marche pas. Passé en script de routage, index.php de Symfony répondrait à
 * TOUT, y compris aux requêtes de CSS et d'images, parce qu'il ne retourne
 * jamais `false` — la valeur par laquelle un routeur dit au serveur « sers le
 * fichier toi-même ». Sans routeur du tout, « / » rend 404 : le serveur
 * cherche un fichier et n'en trouve pas.
 *
 * Ce fichier vit dans l'image et non dans `public/`, pour qu'il ne soit jamais
 * joignable par le web.
 */

$racinePublique = '/app/public';

$chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fichier = $racinePublique.$chemin;

// `realpath` d'abord : sans lui, « /../.env » sortirait de public/ et un
// fichier de configuration deviendrait téléchargeable.
$reel = realpath($fichier);

if (false !== $reel && is_file($reel) && str_starts_with($reel, $racinePublique.\DIRECTORY_SEPARATOR)) {
    return false;
}

// Symfony lit SCRIPT_NAME pour construire les URL. Avec un routeur, le
// serveur y met le chemin du routeur : les liens générés pointeraient vers
// « /usr/local/share/... ».
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $racinePublique.'/index.php';

require $racinePublique.'/index.php';
