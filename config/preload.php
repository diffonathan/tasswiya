<?php

declare(strict_types=1);

// Préchargement opcache : les classes du conteneur compilé sont chargées une
// fois dans la mémoire partagée au démarrage du serveur, et non à chaque
// requête. Sans effet en développement (le fichier n'existe qu'après un
// `cache:warmup` en production).
if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}
