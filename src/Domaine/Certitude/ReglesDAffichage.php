<?php

declare(strict_types=1);

namespace App\Domaine\Certitude;

use App\Enum\DegreCertitude;

/**
 * Les règles que les écrans affichent SANS qu'une transition soit en cause.
 *
 * ═══ POURQUOI ELLES NE SONT PAS DANS LE CATALOGUE DES BLOCAGES ════════════
 *
 * {@see \App\Workflow\CatalogueDesBlocages} répond à une question précise :
 * « pourquoi cette transition est-elle refusée ». Chacune de ses entrées est
 * attachée à un code de blocage, et l'interface s'en sert pour griser le bon
 * bouton.
 *
 * Les règles ci-dessous ne refusent rien. Elles ÉNONCENT : le tireur est sous
 * contrôle judiciaire, aucun montant n'est opposable, l'interdiction bancaire
 * n'est pas l'interdiction judiciaire. Les ranger parmi les blocages
 * laisserait croire qu'elles barrent quelque chose, et le catalogue cesserait
 * d'être une carte des refus.
 *
 * ═══ MAIS ELLES PASSENT PAR LE MÊME OBJET, ET C'EST LE POINT ══════════════
 *
 * Toutes sont des {@see RegleAppliquee}. Elles arrivent donc à l'écran avec
 * leur degré de certitude, leur attribution autorisée et leur renvoi à
 * `LOI.md`, rendues par le même fragment Twig que les règles de blocage. Une
 * phrase écrite à la main dans un gabarit n'aurait ni degré, ni source, ni
 * garde-fou — et c'est exactement par là que « instruction du ministère
 * public » se transforme en « loi 71-24 ».
 */
final class ReglesDAffichage
{
    public const string CONTROLE_JUDICIAIRE = 'controle_judiciaire';
    public const string MESURES_DETAILLEES = 'mesures_detaillees';
    public const string MONTANTS_NON_OPPOSABLES = 'montants_non_opposables';
    public const string DEUX_INTERDICTIONS = 'deux_interdictions';
    public const string PROROGATION_SANS_PLAFOND = 'prorogation_sans_plafond';
    public const string TIREUR_INTROUVABLE = 'tireur_introuvable';

    /** @var array<string, RegleAppliquee>|null */
    private static ?array $regles = null;

    public static function par(string $nom): RegleAppliquee
    {
        $regles = self::toutes();

        return $regles[$nom] ?? throw new \InvalidArgumentException(
            'Aucune règle d\'affichage sous le nom « '.$nom.' ».'
        );
    }

    /** @return array<string, RegleAppliquee> */
    public static function toutes(): array
    {
        return self::$regles ??= [
            /*
             * CE QUI DISTINGUE CES TRENTE JOURS D'UN DÉLAI DE GRÂCE.
             *
             * L'article 325 al. 7 est rédigé à l'impératif. Un écran qui
             * annonce « 30 jours pour régulariser » sans afficher cette mesure
             * décrit mal la situation de son propre utilisateur débiteur — et
             * c'est le débiteur qui lit cet écran.
             */
            self::CONTROLE_JUDICIAIRE => new RegleAppliquee(
                enonce: 'Pendant le délai, le tireur est soumis à UNE OU PLUSIEURS mesures de contrôle '
                    .'judiciaire, bracelet électronique compris, et le contrôle CONTINUE pendant la '
                    .'prorogation. Ces trente jours ne sont pas un délai de grâce.',
                article: 'art. 325 al. 7, et al. 8 pour la continuation pendant la prorogation',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'لواحد أو أكثر من تدابير المراقبة القضائية بما فيها السوار الإلكتروني',
            ),

            /*
             * Le détail des mesures, qui n'a PAS le même degré que
             * l'obligation elle-même. L'obligation est lue au Bulletin
             * officiel ; la liste des mesures vient d'un renvoi de la
             * circulaire du parquet à l'article 161 du Code de procédure
             * pénale, lu sur une copie tierce OCRisée.
             */
            self::MESURES_DETAILLEES => new RegleAppliquee(
                enonce: 'La liste des mesures applicables vient d\'un renvoi à l\'article 161 du Code de '
                    .'procédure pénale. La circulaire écrit « l\'UNE des mesures » là où la loi écrit '
                    .'« une ou plusieurs » : la loi autorise le cumul, et c\'est elle qui a été suivie '
                    .'ici — un booléen unique aurait effacé la pluralité que le texte permet.',
                article: 'art. 161 du Code de procédure pénale, par renvoi',
                degre: DegreCertitude::PROBABLE,
                sourceReelle: 'circulaire de la Présidence du ministère public (apparemment n° 2/2026 du '
                    .'3 février 2026), lue sur une copie tierce OCRisée. À afficher comme instruction du '
                    .'ministère public, jamais comme la loi n° 71.24.',
                renvoiLoiMd: 'LOI.md § 3.B et § 7 point 10',
            ),

            /*
             * CONTRAINTE N° 1 DU PROJET, posée à côté des montants.
             */
            self::MONTANTS_NON_OPPOSABLES => new RegleAppliquee(
                enonce: 'Aucun montant de cet écran n\'est opposable. Les calculs de 2 %, de 0,5/1/1,5 % '
                    .'et de 6 % sont des estimations d\'aide à la préparation, jamais des décomptes : '
                    .'l\'amende est fixée par le tribunal, la pénalité par la banque. Et quand une '
                    .'assiette est indéterminée, ce logiciel n\'affiche AUCUN montant plutôt qu\'un '
                    .'montant approché.',
                article: 'aucun',
                degre: DegreCertitude::CHOIX_PRODUIT,
                sourceReelle: 'conduite de ce logiciel. Il aide à préparer un dossier ; il ne décide '
                    .'d\'aucune situation réelle.',
                renvoiLoiMd: 'LOI.md § 6 H6 et H7',
            ),

            /*
             * La confusion la plus facile du circuit bancaire.
             */
            self::DEUX_INTERDICTIONS => new RegleAppliquee(
                enonce: 'L\'interdiction bancaire d\'émettre dure CINQ ANS à compter de l\'incident de '
                    .'paiement — contre dix ans avant la réforme. Elle n\'est PAS l\'interdiction '
                    .'judiciaire de l\'article 317, qui est une autre interdiction, prononcée par le '
                    .'tribunal. Et son expiration par épuisement du délai ne produit PAS la purge des '
                    .'effets que seule la régularisation de l\'article 313 produit.',
                article: 'art. 312 et 313 nouveaux, à distinguer de l\'art. 317',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'خمس سنوات',
            ),

            /*
             * ⚠ LA RÈGLE À NE JAMAIS ÉCRIRE À L'ENVERS.
             *
             * Elle est affichée partout où la prorogation est en cause, et
             * pas seulement là où le quota bloque : un écran qui ne parlerait
             * du plafond qu'au moment de refuser laisserait croire, le reste
             * du temps, que la loi en pose un.
             */
            self::PROROGATION_SANS_PLAFOND => new RegleAppliquee(
                enonce: 'La loi n° 71.24 ne limite NI la durée NI le nombre des prorogations. '
                    .'L\'article 325 al. 8 ouvre une prorogation « pour une durée égale ou supérieure » '
                    .'au délai initial, par décision du ministère public et après accord du '
                    .'bénéficiaire. C\'est un PLANCHER de trente jours, pas un plafond. Les mots '
                    .'« une seule fois » n\'y figurent pas, et la valeur de 60 jours que l\'on lit dans '
                    .'la presse n\'est écrite nulle part.',
                article: 'art. 325 al. 8',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'لمدة مماثلة أو أكثر، بعد موافقة المستفيد',
                renvoiLoiMd: 'LOI.md § 4.1 et § 4.12',
            ),

            /*
             * L'état `convocation_sans_effet` : pratique de praticien, pas
             * règle de droit.
             */
            self::TIREUR_INTROUVABLE => new RegleAppliquee(
                enonce: 'L\'article 325 n\'organise pas le cas du tireur introuvable ou qui ne défère '
                    .'pas à la convocation. Conséquence directe : aucun écédar n\'ayant pu être '
                    .'notifié, LE DÉLAI DE TRENTE JOURS NE COURT PAS. La pratique rapportée — avis de '
                    .'recherche, pas de garde à vue à l\'interpellation, audition valant écédar, puis '
                    .'réémission de l\'avis si le délai expire — est de la doctrine de praticien.',
                article: 'art. 325 — angle mort du texte',
                degre: DegreCertitude::PROBABLE,
                sourceReelle: 'pratique décrite par le juge Saïd Bouttouil (source unique, signée et '
                    .'spécialisée). Doctrine, et non règle de droit.',
                renvoiLoiMd: 'LOI.md § 4.15 et § 6 H5',
            ),
        ];
    }
}
