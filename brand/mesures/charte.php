<?php

declare(strict_types=1);

/**
 * Les mesures de la charte Tasswiya — la commande qui imprime les nombres.
 *
 *   docker compose exec php php brand/mesures/charte.php
 *
 * Pourquoi ce fichier existe : la règle de la COMMANDE du projet veut
 * qu'aucun nombre publié ne le soit sans la commande qui l'imprime. CHARTE.md
 * cite des écarts de teinte, des rapports de contraste et des épaisseurs en
 * pixels. Aucun de ces nombres n'est estimé : ils sortent d'ici, et quiconque
 * reprend le projet peut les recalculer avant de me croire.
 *
 * Volontairement sans dépendance : PHP nu, pas d'extension, pas de Composer.
 * Il doit tourner dans le conteneur `tasswiya-php` tel qu'il est.
 *
 * Il se termine par un verdict et un code de sortie : un jeton de texte sous
 * le seuil WCAG AA fait ÉCHOUER la commande. La charte est donc vérifiable,
 * pas seulement racontée.
 */

// ═══ Outils de couleur ════════════════════════════════════════════════════

/** @return array{0:int,1:int,2:int} */
function rvb(string $hexa): array
{
    $hexa = ltrim($hexa, '#');

    return [
        (int) hexdec(substr($hexa, 0, 2)),
        (int) hexdec(substr($hexa, 2, 2)),
        (int) hexdec(substr($hexa, 4, 2)),
    ];
}

function hexa(int $r, int $v, int $b): string
{
    return sprintf('#%02x%02x%02x', $r, $v, $b);
}

/**
 * La teinte au sens HSL, en degrés.
 *
 * C'est la grandeur sur laquelle se joue l'argument du choix de palette :
 * deux projets de la série ne doivent pas porter la même teinte, et l'accent
 * ne doit pas se confondre avec le rouge d'alarme.
 */
function teinte(string $couleur): float
{
    [$r, $v, $b] = array_map(static fn (int $c): float => $c / 255, rvb($couleur));
    $max = max($r, $v, $b);
    $min = min($r, $v, $b);
    $d = $max - $min;

    if (0.0 === $d) {
        return 0.0; // achromatique : pas de teinte. C'est le cas du degré de certitude.
    }

    $h = match (true) {
        $max === $r => fmod(($v - $b) / $d, 6.0),
        $max === $v => ($b - $r) / $d + 2.0,
        default => ($r - $v) / $d + 4.0,
    };

    return fmod($h * 60.0 + 360.0, 360.0);
}

/** L'écart de teinte sur le cercle : jamais plus de 180°. */
function ecartDeTeinte(string $a, string $b): float
{
    $d = abs(teinte($a) - teinte($b));

    return min($d, 360.0 - $d);
}

/** Luminance relative, au sens WCAG 2.1. */
function luminance(string $couleur): float
{
    $canal = static function (int $c): float {
        $s = $c / 255;

        return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    };

    [$r, $v, $b] = rvb($couleur);

    return 0.2126 * $canal($r) + 0.7152 * $canal($v) + 0.0722 * $canal($b);
}

/** Rapport de contraste WCAG 2.1, de 1 à 21. */
function contraste(string $a, string $b): float
{
    $la = luminance($a);
    $lb = luminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Mélange vers le blanc — la fonction `eclaircir()` de la charte maison. */
function eclaircir(string $couleur, float $t): string
{
    [$r, $v, $b] = rvb($couleur);

    return hexa(
        (int) round($r + (255 - $r) * $t),
        (int) round($v + (255 - $v) * $t),
        (int) round($b + (255 - $b) * $t),
    );
}

/** Mélange vers le noir — la fonction `assombrir()` de la charte maison. */
function assombrir(string $couleur, float $t): string
{
    [$r, $v, $b] = rvb($couleur);

    return hexa(
        (int) round($r * (1 - $t)),
        (int) round($v * (1 - $t)),
        (int) round($b * (1 - $t)),
    );
}

function titre(string $texte): void
{
    echo "\n", str_repeat('=', 74), "\n  ", mb_strtoupper($texte), "\n", str_repeat('=', 74), "\n\n";
}

// ═══ Les données : le catalogue maison, et ce qui est déjà pris ═══════════

/** Les 24 palettes de `../charte/CHARTE-24-PALETTES.md`. */
const CATALOGUE = [
    '01 vert-hope' => '#00b67a', '02 emeraude' => '#10b981',
    '03 sarcelle' => '#0d9488', '04 turquoise' => '#14b8a6',
    '05 vert-foret' => '#16a34a', '06 vert-neon' => '#03dc5d',
    '07 or-ambre' => '#f0b429', '08 or-antique' => '#d4a017',
    '09 jaune-soleil' => '#facc15', '10 orange-cf' => '#f68b2e',
    '11 orange-vif' => '#ff7600', '12 bronze' => '#c2703d',
    '13 bleu-ftmo' => '#0781fe', '14 bleu-roi' => '#1f6bff',
    '15 bleu-ciel' => '#38bdf8', '16 cyan' => '#06b6d4',
    '17 indigo' => '#635bff', '18 violet-royal' => '#a855f7',
    '19 violet-profond' => '#7c3aed', '20 lavande' => '#a78bfa',
    '21 magenta' => '#ec4899', '22 fuchsia' => '#f0397e',
    '23 corail' => '#fb7185', '24 bordeaux' => '#b91c3c',
];

/** Les palettes déjà attribuées à un projet de la série. */
const ATTRIBUEES = [
    '13 bleu-ftmo' => 'vip.hopetraders.fr',
    '01 vert-hope' => 'rdv-sante',
    '08 or-antique' => 'factura',
    '03 sarcelle' => 'artisans',
    '19 violet-profond' => 'mizan',
];

const ACCENT = '#635bff';

/**
 * Les trois rôles qui ne se négocient pas, parce qu'ils ne sont pas une
 * identité mais un langage : « ce qu'un thème ne réécrit jamais » de la
 * charte maison. Leur teinte est donnée, et c'est l'accent qui doit se
 * placer autour d'eux — pas l'inverse.
 */
const ROLES_FIXES = [
    'or (montants)' => '#f9a825',
    'vert (regularise)' => '#1f6f4a',
    'rouge (expire)' => '#a52714',
];

/** Le rôle « perdu » — délai expiré, poursuite engagée. Substrat papier. */
const ROUGE_PAPIER = '#a52714';

/** Le même rôle sur substrat nuit. */
const ROUGE_NUIT = '#ff8a73';

/**
 * Au-delà de cet écart à la teinte idéale, une candidate n'est plus dans
 * l'arc libre : elle vient s'entasser contre l'or et le rouge.
 */
const ARC_TOLERE = 30.0;

// ═══ 1. La carte des teintes ═════════════════════════════════════════════

titre('1. la carte des teintes du catalogue maison');

echo "Teinte HSL de chaque palette, et ce qui est deja pris par la serie :\n\n";
foreach (CATALOGUE as $cle => $couleur) {
    printf(
        "  %-18s %s  %6.1f deg%s\n",
        $cle,
        $couleur,
        teinte($couleur),
        isset(ATTRIBUEES[$cle]) ? '   <- prise par '.ATTRIBUEES[$cle] : '',
    );
}

// ═══ 2. Où l'accent DOIT tomber : l'arc que les rôles laissent libre ════

titre("2. la teinte ideale de l'accent - l'arc que les roles laissent libre");

echo "Les trois roles fixes sont poses sur le cercle. L'accent doit tomber\n";
echo "au MILIEU DU PLUS GRAND ARC VIDE : c'est la seule position ou il ne\n";
echo "vient s'entasser contre aucun des trois signaux de sens.\n\n";

$teintesRoles = [];
foreach (ROLES_FIXES as $nom => $couleur) {
    $teintesRoles[$nom] = teinte($couleur);
    printf("  %-20s %s  %6.1f deg\n", $nom, $couleur, $teintesRoles[$nom]);
}

$ordonnees = $teintesRoles;
asort($ordonnees);
$noms = array_keys($ordonnees);
$valeurs = array_values($ordonnees);

echo "\n  Les arcs vides entre deux roles consecutifs :\n\n";
$plusGrand = [0.0, ''];
$n = count($valeurs);
for ($i = 0; $i < $n; ++$i) {
    $depart = $valeurs[$i];
    $arrivee = $valeurs[($i + 1) % $n];
    $largeur = fmod($arrivee - $depart + 360.0, 360.0);
    $milieu = fmod($depart + $largeur / 2.0, 360.0);
    printf(
        "  %-20s -> %-20s %6.1f deg   milieu %6.1f deg\n",
        $noms[$i],
        $noms[($i + 1) % $n],
        $largeur,
        $milieu,
    );
    if ($largeur > $plusGrand[0]) {
        $plusGrand = [$largeur, $milieu];
    }
}

$teinteIdeale = $plusGrand[1];
printf("\n  Le plus grand arc vide fait %.1f deg. Son milieu est a %.1f deg.\n", $plusGrand[0], $teinteIdeale);
printf("  C'est la TEINTE IDEALE de l'accent de ce produit : %.1f deg.\n", $teinteIdeale);

$ideale = null;
foreach (CATALOGUE as $cle => $couleur) {
    $e = abs(teinte($couleur) - $teinteIdeale);
    $e = min($e, 360.0 - $e);
    if (null === $ideale || $e < $ideale[0]) {
        $ideale = [$e, $cle];
    }
}
printf(
    "  La palette du catalogue la plus proche de cet ideal est %s,\n  a %.1f deg -- et elle est prise par %s.\n",
    $ideale[1],
    $ideale[0],
    ATTRIBUEES[$ideale[1]] ?? 'personne',
);

// ═══ 3. L'arbitrage, à deux critères, sur les palettes libres ═══════════

titre("3. l'arbitrage - dans l'arc, et le plus loin possible de la serie");

echo "Deux criteres, et ils tirent en sens inverse :\n";
echo "  (a) etre dans l'arc libre     -> ecart a la teinte ideale <= ".ARC_TOLERE." deg\n";
echo "  (b) ne pas ressembler a un autre projet de la serie -> ecart MAXIMAL\n";
echo "      a la palette deja attribuee la plus proche.\n\n";
printf("  %-18s %10s %10s %12s\n", 'Palette libre', 'd(ideal)', 'd(serie)', 'dans arc');
echo '  ', str_repeat('-', 54), "\n";

$eligibles = [];
foreach (CATALOGUE as $cle => $couleur) {
    if (isset(ATTRIBUEES[$cle])) {
        continue;
    }

    $dIdeal = abs(teinte($couleur) - $teinteIdeale);
    $dIdeal = min($dIdeal, 360.0 - $dIdeal);

    $dSerie = 360.0;
    foreach (ATTRIBUEES as $priseCle => $projet) {
        $dSerie = min($dSerie, ecartDeTeinte($couleur, CATALOGUE[$priseCle]));
    }

    $dansArc = $dIdeal <= ARC_TOLERE;
    if (!$dansArc) {
        continue;
    }

    $eligibles[$cle] = [$dIdeal, $dSerie];
    printf("  %-18s %9.1f %9.1f %12s\n", $cle, $dIdeal, $dSerie, 'oui');
}

uasort($eligibles, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
$gagnante = array_key_first($eligibles);
printf(
    "\n  Dans l'arc : %d palettes libres. Celle qui s'eloigne le plus de la\n  serie est %s, a %.1f deg -- contre %.1f et %.1f pour les deux autres.\n",
    count($eligibles),
    $gagnante,
    $eligibles[$gagnante][1],
    ...array_map(static fn (array $v): float => $v[1], array_slice(array_values($eligibles), 1, 2)),
);
printf("  Retenue : %s. Aucune n'est a plus de %.1f deg de la serie :\n", $gagnante, max(array_map(static fn (array $v): float => $v[1], $eligibles)));
echo "  c'est un choix le moins mauvais, pas un choix confortable.\n";

// ═══ 4. Et pourquoi pas magenta ni fuchsia ═════════════════════════════

titre('4. pourquoi pas magenta ni fuchsia - elles entassent le rouge');

echo "Elles sont les seules palettes vraiment LOIN de la serie. Mais elles\n";
echo "tombent hors de l'arc, du cote du rouge. La mesure qui tranche n'est\n";
echo "pas l'ecart au rouge seul : c'est le DEUXIEME plus petit ecart du\n";
echo "quatuor {accent, or, vert, rouge}. Le premier, or <-> rouge, est fixe\n";
echo "et subi ; le deuxieme est celui que le choix de l'accent decide.\n\n";

printf("  %-18s %10s %10s %14s\n", 'Candidate', 'd(ideal)', 'd(rouge)', '2e ecart min');
echo '  ', str_repeat('-', 56), "\n";

foreach (['17 indigo', '21 magenta', '22 fuchsia', '24 bordeaux'] as $cle) {
    $couleur = CATALOGUE[$cle];

    $dIdeal = abs(teinte($couleur) - $teinteIdeale);
    $dIdeal = min($dIdeal, 360.0 - $dIdeal);

    // Tous les ecarts deux a deux du quatuor, tries.
    $quatuor = ROLES_FIXES + ['accent' => $couleur];
    $ecarts = [];
    $clesQ = array_keys($quatuor);
    for ($i = 0; $i < count($clesQ); ++$i) {
        for ($j = $i + 1; $j < count($clesQ); ++$j) {
            $ecarts[] = ecartDeTeinte($quatuor[$clesQ[$i]], $quatuor[$clesQ[$j]]);
        }
    }
    sort($ecarts);

    printf(
        "  %-18s %9.1f %9.1f %13.1f\n",
        $cle,
        $dIdeal,
        ecartDeTeinte($couleur, ROUGE_PAPIER),
        $ecarts[1],
    );
}

echo "\n  Le premier ecart est toujours le meme : or <-> rouge. Le deuxieme\n";
echo "  s'effondre des que l'accent passe du cote chaud du cercle, et c'est\n";
echo "  exactement le signal qu'on ne peut pas se permettre de brouiller.\n";

// ═══ 3. La dérivation de l'accent, par la recette maison ════════════════

titre("5. derivation de l'accent - recette de la charte maison");

[$ar, $av, $ab] = rvb(ACCENT);

printf("  --indigo              %s   rgb(%d, %d, %d)\n", ACCENT, $ar, $av, $ab);
printf("  --indigo-sourd        %s   assombrir(accent, 0.18)\n", assombrir(ACCENT, 0.18));
printf("  --indigo-encre        %s   assombrir(accent, 0.30)   texte sur papier\n", assombrir(ACCENT, 0.30));
printf("  --indigo-clair        %s   eclaircir(accent, 0.45)   texte sur nuit\n", eclaircir(ACCENT, 0.45));
printf("  --indigo-doux         rgba(%d, %d, %d, 0.10)\n", $ar, $av, $ab);
printf("  --indigo-bordure      rgba(%d, %d, %d, 0.30)\n", $ar, $av, $ab);
printf("  --indigo-halo         rgba(%d, %d, %d, 0.18)\n", $ar, $av, $ab);
printf(
    "  --grad-indigo         linear-gradient(-15deg, %s, %s)\n",
    eclaircir(ACCENT, 0.45),
    assombrir(ACCENT, 0.22),
);

// ═══ 6. Les quatre rôles ne se confondent pas ═══════════════════════════

titre('6. les quatre roles - separation de teinte, deux a deux');

$roles = ['indigo (action)' => ACCENT] + ROLES_FIXES;

$cles = array_keys($roles);
$separationMin = 360.0;
for ($i = 0; $i < count($cles); ++$i) {
    for ($j = $i + 1; $j < count($cles); ++$j) {
        $e = ecartDeTeinte($roles[$cles[$i]], $roles[$cles[$j]]);
        $separationMin = min($separationMin, $e);
        printf("  %-20s <-> %-20s %6.1f deg\n", $cles[$i], $cles[$j], $e);
    }
}
printf("\n  Separation la plus faible entre deux roles : %.1f deg\n", $separationMin);
echo "  C'est or <-> rouge, et elle est subie : les deux teintes sont des\n";
echo "  roles de la charte maison, pas des choix de ce projet. La parade\n";
echo "  n'est donc pas chromatique -- l'or ne porte QUE des nombres, et un\n";
echo "  nombre est toujours en police mono : la forme separe ce que la\n";
echo "  teinte ne separe pas assez.\n";

echo "\n  Le CINQUIEME role - le degre de certitude - n'a AUCUNE teinte :\n";
echo "  il est porte par le style de filet et la texture. C'est ce qui lui\n";
echo "  permet de ne jamais entrer en collision avec les quatre autres,\n";
echo "  et de survivre au noir et blanc par construction.\n";

// ═══ 7. Les contrastes, sur les deux substrats ══════════════════════════

titre('7. contrastes wcag - chaque jeton sur son fond');

/** @var array<string, array{fond: string, jetons: array<string, string>}> */
$substrats = [
    'PAPIER (defaut)' => [
        'fond' => '#fbfaf7',
        'jetons' => [
            '--encre         ' => '#16202c',
            '--encre-sourde  ' => '#55606e',
            '--indigo-encre  ' => '#4540b3',
            '--or-encre      ' => '#7a5600',
            '--vert-encre    ' => '#1f6f4a',
            '--rouge-encre   ' => ROUGE_PAPIER,
            '--filet         ' => '#cfc8b6',
        ],
    ],
    'NUIT' => [
        'fond' => '#111318',
        'jetons' => [
            '--encre         ' => '#e8e6e1',
            '--encre-sourde  ' => '#9aa3ad',
            '--indigo-encre  ' => '#a9a5ff',
            '--or-encre      ' => '#f9a825',
            '--vert-encre    ' => '#6fbf95',
            '--rouge-encre   ' => ROUGE_NUIT,
            '--filet         ' => '#333a44',
        ],
    ],
];

$echecs = 0;
foreach ($substrats as $nom => $s) {
    echo "  -- $nom - fond {$s['fond']}\n";
    foreach ($s['jetons'] as $jeton => $couleur) {
        $c = contraste($couleur, $s['fond']);
        // Le filet n'est pas du texte : il n'a pas a porter un glyphe, il a
        // a se voir comme surface. Le plancher de 1,5:1 est celui d'un filet
        // d'un pixel, et il est tenu pour un seuil dur ici parce qu'un filet
        // invisible transforme un tableau de dates en bouillie.
        $texte = !str_contains($jeton, 'filet');
        $seuil = $texte ? 4.5 : 1.5;
        if ($c < $seuil) {
            ++$echecs;
        }
        printf(
            "     %s %s  %5.2f:1  (seuil %.1f)  %s\n",
            $jeton,
            $couleur,
            $c,
            $seuil,
            $c >= $seuil ? 'ok' : 'ECHEC',
        );
    }
    echo "\n";
}

// Le bouton est le seul endroit ou l'accent devient un APLAT sous du texte.
// Si le texte blanc n'y passe pas, c'est l'aplat qu'il faut assombrir, pas la
// regle qu'il faut baisser.
echo "  -- APLAT D'ACTION - texte blanc #ffffff sur fond indigo\n";
foreach (['--indigo        ' => ACCENT, '--indigo-sourd  ' => assombrir(ACCENT, 0.18)] as $jeton => $couleur) {
    $c = contraste('#ffffff', $couleur);
    if ($c < 4.5) {
        ++$echecs;
    }
    printf(
        "     %s %s  %5.2f:1  (seuil 4.5)  %s\n",
        $jeton,
        $couleur,
        $c,
        $c >= 4.5 ? 'ok' : 'ECHEC -> ne pas poser de texte dessus',
    );
}
echo "\n";

// ═══ 6. Le logo — lisibilité mesurée, pas supposée ══════════════════════

titre('8. logo - epaisseurs et vides, en unites et en pixels');

$geometrie = [
    "epaisseur d'une barre" => 7.0,
    'vide vertical entre les deux barres' => 10.0,
    "vide horizontal avant la piece d'or" => 6.0,
    "longueur de la piece d'or" => 13.0,
    'marge au cadre' => 12.0,
];

echo "  viewBox 64 x 64 - le plancher maison est de 3 unites (cf. Mizan).\n\n";
printf("  %-38s %7s %9s %9s %9s\n", 'Grandeur', 'unites', '@16 px', '@24 px', '@200 px');
echo '  ', str_repeat('-', 76), "\n";
foreach ($geometrie as $quoi => $u) {
    printf("  %-38s %7.1f %7.2fpx %7.2fpx %7.1fpx\n", $quoi, $u, $u * 16 / 64, $u * 24 / 64, $u * 200 / 64);
}

echo "\n  La lecon de Mizan est reprise telle quelle : c'est le VIDE entre les\n";
echo "  formes qui decide de la lisibilite, pas leur epaisseur. Le vide\n";
echo "  horizontal - celui qui dit qu'il MANQUE quelque chose - est donc la\n";
echo "  grandeur critique, et c'est lui qui est mesure en premier.\n";

// ═══ 9. Les fichiers de marque sont-ils seulement valides ? ════════════

titre('9. les svg de marque - bien formes, et conformes aux roles');

/*
 * Pourquoi ce controle existe : un commentaire XML ne peut pas contenir deux
 * tirets consecutifs. Citer le nom d'un jeton CSS tel quel dans un
 * commentaire de SVG rend le fichier MALFORME, et le navigateur s'arrete a la
 * premiere erreur SANS rien dire a la page qui l'inclut. Le logotype complet
 * s'est affiche vide pendant une passe entiere pour cette raison : le symbole
 * sortait, le nom disparaissait, et rien ne le signalait.
 *
 * Et tant qu'on ouvre les fichiers : on verifie aussi que l'or n'y apparait
 * qu'une fois. Dans le logo, la piece d'or EST un montant, et c'est tout le
 * propos du symbole. Une seconde occurrence voudrait dire que l'or a servi a
 * decorer -- et une faute de charte dans le logo se propage partout.
 */

$svg = glob(__DIR__.'/../*.svg') ?: [];
sort($svg);

$anciensRapports = libxml_use_internal_errors(true);

foreach ($svg as $chemin) {
    $contenu = (string) file_get_contents($chemin);

    libxml_clear_errors();
    $bienForme = false !== simplexml_load_string($contenu);
    $erreurs = libxml_get_errors();

    // On ne compte l'or que hors commentaire : un commentaire qui EXPLIQUE
    // l'or n'en pose pas.
    $sansCommentaires = preg_replace('/<!--.*?-->/s', '', $contenu) ?? '';
    $occurrencesOr = preg_match_all('/#f9a825/i', $sansCommentaires);

    $orDeTrop = $occurrencesOr > 1;
    if (!$bienForme || $orDeTrop) {
        ++$echecs;
    }

    printf(
        "  %-20s %-12s or : %d occurrence(s)%s\n",
        basename($chemin),
        $bienForme ? 'bien forme' : 'MALFORME',
        $occurrencesOr,
        $orDeTrop ? "   <- ECHEC : l'or a servi a decorer" : '',
    );

    foreach ($erreurs as $e) {
        printf("       ligne %d : %s", $e->line, $e->message);
    }
}

libxml_use_internal_errors($anciensRapports);

echo "\n  `logo-mono.svg` n'a AUCUNE occurrence d'or, et c'est voulu : sa\n";
echo "  piece distinctive est evidee, pas doree. Quand la couleur n'est plus\n";
echo "  disponible, c'est la forme qui porte le sens.\n";

// ═══ 10. Le verdict ═════════════════════════════════════════════════════

titre('10. verdict');

if (0 === $echecs) {
    echo "  Tous les jetons de texte passent le seuil WCAG AA sur leur\n";
    echo "  substrat, et tous les SVG de marque sont bien formes.\n";
    exit(0);
}

printf("  %d echec(s). La charte n'est pas publiable en l'etat.\n", $echecs);
exit(1);
