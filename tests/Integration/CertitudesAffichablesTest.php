<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\DegreCertitude;
use App\Workflow\CatalogueDesBlocages;
use App\Workflow\EcouteurDeGardesTemporelles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * LA CONTRAINTE N° 1, ÉPINGLÉE PAR DES ASSERTIONS.
 *
 * « Ce n'est pas un conseil juridique » n'est pas une phrase à mettre en pied
 * de page : c'est une propriété que chaque règle affichée doit porter. Une
 * règle que la loi 71.24 ne dit pas et qui s'affiche sous l'autorité de la loi
 * 71.24 est une FAUSSE CITATION — et un produit qui en contient une seule a
 * déjà perdu le droit de dire qu'il ne conseille pas.
 *
 * Ces tests sont donc les gardiens de l'honnêteté du produit, et non des
 * tests de confort. Ils tiennent trois invariants :
 *
 *  1. toute règle de degré autre qu'ÉTABLI porte sa SOURCE RÉELLE, et son
 *     attribution à l'écran ne se réclame jamais de la loi 71.24 ;
 *  2. toute règle de degré autre qu'ÉTABLI porte un RENVOI À `LOI.md`, parce
 *     que l'utilisateur à qui le logiciel refuse quelque chose doit pouvoir
 *     aller lire POURQUOI, et que le dossier d'arbitrage est là ;
 *  3. chaque code de blocage déclaré a une règle, et chaque garde temporelle
 *     bloque avec un code du catalogue — sinon un refus s'afficherait muet.
 */
final class CertitudesAffichablesTest extends TestCase
{
    /**
     * Les codes de blocage déclarés comme constantes, retrouvés par
     * réflexion.
     *
     * Par réflexion et non par une liste écrite à la main : une liste
     * recopiée ne contient jamais le code qu'on vient d'ajouter, et c'est
     * exactement celui qui risque de s'afficher sans source.
     *
     * @return iterable<string, array{string}>
     */
    public static function codesDeBlocage(): iterable
    {
        $reflexion = new \ReflectionClass(CatalogueDesBlocages::class);

        foreach ($reflexion->getConstants() as $nom => $valeur) {
            self::assertIsString($valeur);
            yield $nom => [$valeur];
        }
    }

    /** Aucun code déclaré sans règle : un refus sans source est interdit. */
    #[Test]
    #[DataProvider('codesDeBlocage')]
    public function chaqueCodeDeBlocageAUneRegle(string $code): void
    {
        $regle = CatalogueDesBlocages::regle($code);

        self::assertNotSame('', trim($regle->enonce));
        self::assertNotSame('', trim($regle->article));
    }

    /**
     * ★ L'INVARIANT CENTRAL : une règle non établie ne se réclame jamais de
     * la loi.
     */
    #[Test]
    #[DataProvider('codesDeBlocage')]
    public function uneRegleNonEtablieNeSeReclameJamaisDeLaLoi(string $code): void
    {
        $regle = CatalogueDesBlocages::regle($code);

        if (DegreCertitude::ETABLI === $regle->degre) {
            self::assertStringContainsString(
                'loi n° 71.24',
                $regle->attribution(),
                'Une règle ÉTABLIE doit au contraire citer la loi, et dire que seule la version '
                    .'arabe fait foi.'
            );

            // Et même établie, elle avertit que le français n'est pas
            // opposable : il n'existe aucune version française officielle.
            self::assertStringContainsString('non opposable', $regle->attribution());

            return;
        }

        self::assertNotNull(
            $regle->sourceReelle,
            \sprintf('La règle « %s » n\'est pas établie : elle doit porter sa source réelle.', $code)
        );

        self::assertStringNotContainsString(
            'loi n° 71.24',
            $regle->attribution(),
            \sprintf(
                'FAUSSE CITATION : la règle « %s » est de degré %s et s\'afficherait sous l\'autorité '
                    .'de la loi 71.24.',
                $code,
                $regle->degre->value
            )
        );
    }

    /**
     * ★ ET ELLE RENVOIE À `LOI.md`, là où l'arbitrage est écrit.
     *
     * Point explicite du cahier des charges : les règles incertaines doivent
     * être signalées LÀ OÙ ELLES S'APPLIQUENT et renvoyer au dossier de
     * règles. Un refus qui dit « ce point n'est pas tranché » sans dire où
     * lire l'arbitrage laisse l'utilisateur devant un mur.
     */
    #[Test]
    #[DataProvider('codesDeBlocage')]
    public function uneRegleNonEtablieRenvoieALoiMd(string $code): void
    {
        $regle = CatalogueDesBlocages::regle($code);

        if (DegreCertitude::ETABLI === $regle->degre) {
            self::assertTrue(true, 'Une règle établie n\'a pas besoin de renvoi : elle a son article.');

            return;
        }

        self::assertNotNull(
            $regle->renvoiLoiMd,
            \sprintf(
                'La règle « %s » est de degré %s : l\'écran doit pouvoir renvoyer à la section de '
                    .'LOI.md où l\'arbitrage est écrit.',
                $code,
                $regle->degre->value
            )
        );

        self::assertStringContainsString(
            'LOI.md',
            (string) $regle->renvoiLoiMd,
            'Le renvoi doit nommer le fichier, pour qu\'il reste trouvable hors du code.'
        );
    }

    /**
     * TROIS TRAITEMENTS À L'ÉCRAN, ET UN SEUL EST UNE ALERTE.
     *
     * Le test a d'abord été écrit avec l'assertion « non établi ⟹ alerte »,
     * et il a rougi sur `CHOIX_PRODUIT`. La lecture de
     * {@see DegreCertitude::exigeUneAlerte()} montre que ce n'est pas un
     * oubli mais une distinction, et qu'elle est juste :
     *
     *  - INCERTAIN alerte : le point n'est pas tranché, le produit prévient
     *    au lieu de conclure ;
     *  - PROBABLE n'alerte pas mais CHANGE D'ATTRIBUTION : il s'affiche sous
     *    « instruction du ministère public » ou « doctrine », jamais sous la
     *    loi. Ce n'est pas un doute, c'est une autre autorité ;
     *  - CHOIX_PRODUIT n'alerte pas non plus : il va dans l'encart
     *    « hypothèses de calcul », séparé des règles sourcées. Alerter sur
     *    une hypothèse assumée banaliserait les alertes qui comptent.
     *
     * Ce test épingle donc la distinction telle qu'elle est conçue, et la
     * rend non régressable : la noyer en alertant partout, ou la perdre en
     * n'alertant nulle part, casse une assertion.
     */
    #[Test]
    #[DataProvider('codesDeBlocage')]
    public function seulesLesReglesIncertainesAlertent(string $code): void
    {
        $regle = CatalogueDesBlocages::regle($code);

        self::assertSame(
            DegreCertitude::INCERTAIN === $regle->degre,
            $regle->exigeUneAlerte(),
            \sprintf(
                'Seul le degré INCERTAIN alerte ; « %s » est de degré %s.',
                $code,
                $regle->degre->value
            )
        );

        // Et les deux autres degrés non établis portent, à défaut d'alerte,
        // une attribution qui NOMME leur source réelle. Sans alerte et sans
        // attribution juste, une règle non légale s'afficherait comme la loi.
        if (DegreCertitude::PROBABLE === $regle->degre || DegreCertitude::CHOIX_PRODUIT === $regle->degre) {
            self::assertStringContainsString(
                (string) $regle->sourceReelle,
                $regle->attribution(),
                'À défaut d\'alerte, l\'attribution doit nommer la source réelle.'
            );
        }
    }

    /**
     * Le mot « prorogation » n'est jamais présenté comme limité PAR LA LOI.
     *
     * Balayage du catalogue entier à la recherche de la faute précise que le
     * dépouillement a identifiée : la presse écrit « renouvelable une seule
     * fois », la loi ne le dit pas, et l'info-bulle prévue par le brief du
     * projet aurait repris la presse.
     */
    #[Test]
    public function aucuneRegleNAttribueALaLoiUneLimiteDuNombreDeProrogations(): void
    {
        $catalogue = CatalogueDesBlocages::catalogue();

        // Le catalogue est bien parcouru : sans cette assertion, un catalogue
        // vide ferait passer le test sans rien vérifier.
        self::assertGreaterThan(15, \count($catalogue));

        foreach ($catalogue as $code => $regle) {
            $texte = $regle->enonce.' '.$regle->attribution();

            if (!str_contains($texte, 'une seule fois') && !str_contains($texte, 'une fois')) {
                continue;
            }

            self::assertNotSame(
                DegreCertitude::ETABLI,
                $regle->degre,
                \sprintf(
                    'La règle « %s » parle d\'une limite « une fois » avec un degré ÉTABLI : '
                        .'la loi 71.24 ne limite pas le nombre de prorogations.',
                    $code
                )
            );
        }
    }

    /**
     * Les gardes temporelles bloquent toutes avec un code DU CATALOGUE.
     *
     * Le lien est fait par lecture du code source de l'écouteur : les codes
     * y sont passés en constantes, et ce test vérifie qu'aucun n'est une
     * chaîne littérale inventée sur place — qui s'afficherait sans article ni
     * degré.
     */
    #[Test]
    public function lesGardesTemporellesNeBloquentQuAvecDesCodesDuCatalogue(): void
    {
        $source = file_get_contents(
            \dirname(__DIR__, 2).'/src/Workflow/EcouteurDeGardesTemporelles.php'
        );
        self::assertIsString($source);

        // Toute occurrence de `CatalogueDesBlocages::XXX` passée à `bloquer()`.
        preg_match_all('/CatalogueDesBlocages::([A-Z_]+)\b/', $source, $trouvailles);

        $codes = array_unique($trouvailles[1]);
        self::assertNotEmpty($codes, 'L\'écouteur doit bloquer avec des codes du catalogue.');

        foreach ($codes as $nomDeConstante) {
            if ('regle' === $nomDeConstante) {
                continue;
            }

            self::assertTrue(
                \defined(CatalogueDesBlocages::class.'::'.$nomDeConstante),
                \sprintf('« %s » n\'est pas une constante du catalogue.', $nomDeConstante)
            );
        }

        // Et aucun `addTransitionBlocker` construit ailleurs que dans la
        // méthode privée `bloquer()`, qui est la seule à joindre la règle.
        self::assertSame(
            1,
            substr_count($source, 'new TransitionBlocker('),
            'Un seul point de construction : sinon un blocage pourrait s\'afficher sans sa source.'
        );

        // Témoin : les transitions gardées temporellement sont déclarées, et
        // le test du graphe vérifie qu'elles existent.
        self::assertNotEmpty(EcouteurDeGardesTemporelles::transitionsGardeesTemporellement());
    }

    /**
     * ★ LE PARAMÈTRE PRODUIT RESTE DANS LE FICHIER DES PARAMÈTRES PRODUIT.
     *
     * `config/packages/tasswiya.yaml` ne doit contenir AUCUNE règle de droit,
     * et `config/packages/workflow.yaml` ne doit porter aucun nombre qui
     * ferait passer un choix d'implémentation pour un article. Le test lit le
     * fichier de paramètres et vérifie qu'un quota y est bien déclaré comme
     * une hypothèse, avec la commande de vérification par absence.
     */
    #[Test]
    public function lesChoixDImplementationSontDeclaresCommeTelsEtNonCommeDuDroit(): void
    {
        $chemin = \dirname(__DIR__, 2).'/config/packages/tasswiya.yaml';
        $texte = file_get_contents($chemin);
        self::assertIsString($texte);

        // L'avertissement de nature, en tête du paramètre du quota.
        self::assertStringContainsString('CE N\'EST PAS UNE RÈGLE DE DROIT', $texte);

        // La vérification par absence est REPRODUCTIBLE : la commande qui
        // produit le zéro est écrite à côté du chiffre. C'est la règle de la
        // commande, appliquée à une absence.
        self::assertStringContainsString('grep -c', $texte);

        // Et le paramètre existe bien, avec la valeur 1.
        $parametres = Yaml::parseFile($chemin)['parameters'] ?? [];
        self::assertSame(1, $parametres['tasswiya.quota_prolongations_par_defaut'] ?? null);
    }
}
