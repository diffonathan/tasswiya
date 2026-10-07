<?php

declare(strict_types=1);

use App\Kernel;

// `autoload_runtime.php` et non `autoload.php` : symfony/runtime remplace le
// « $kernel->handle($request)->send() » habituel. Le fichier renvoyé ci-dessous
// est une FABRIQUE, et le composant décide seul s'il faut construire une
// requête HTTP ou une application console.
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel(
    $context['APP_ENV'],
    (bool) $context['APP_DEBUG'],
);

// Note de piège : l'argument de la fabrique DOIT s'appeler `$context`.
// `SymfonyRuntime` le résout PAR SON NOM et non par son type ; renommé en
// français, il rend un 500 « supports only arguments "array $context" » qui
// n'a l'air d'avoir aucun rapport avec un nom de variable.
