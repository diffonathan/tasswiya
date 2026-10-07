<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dossier;
use App\Security\ResolveurDeRoleInterface;
use App\Security\RoleDansDossier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui a le droit de voir et de faire quoi sur un dossier.
 *
 * POURQUOI UN VOTER ET PAS DES `if` DANS LES CONTRÔLEURS. Les droits de ce
 * produit ne sont pas des droits d'interface, ce sont des règles : l'accord
 * du bénéficiaire ne peut être donné que par le bénéficiaire (art. 325 al. 8),
 * et aucune commodité d'ergonomie ne peut y changer quelque chose. Écrits
 * dans les contrôleurs, ces droits se dupliqueraient à chaque écran et
 * divergeraient au premier ajout. Écrits ici, ils sont à UN endroit, et le
 * même `is_granted()` les applique dans un contrôleur, dans un gabarit, et
 * — c'est le point intéressant — DANS UNE GARDE DE `workflow.yaml`.
 *
 * Ce dernier emploi est la raison principale de ce voter : la garde de
 * `proroger_delai` appelle `is_granted('DOSSIER_ACCORDER_PROLONGATION',
 * subject)`. L'autorisation entre ainsi DANS la machine à états, et un
 * débiteur ne peut pas franchir une transition qui demande l'accord de son
 * créancier, même en forgeant une requête. La règle est au même endroit que
 * le droit.
 *
 * TROIS RÔLES, TROIS LECTURES DU MÊME DOSSIER :
 *  - le TIREUR voit son échéance et les mesures de contrôle judiciaire qui le
 *    concernent, mais pas la décision de son créancier d'accepter ou non ;
 *  - le BÉNÉFICIAIRE donne ou refuse son accord, et voit ce que vaut sa
 *    créance, mais pas ce que l'avocat du tireur consigne ;
 *  - l'AVOCAT voit tout, y compris la carte des certitudes — parce que c'est
 *    lui qui est en mesure de trancher ce que ce logiciel laisse ouvert, et
 *    que le produit n'est pas un conseil juridique.
 *
 * @extends Voter<string, Dossier>
 */
final class DossierVoter extends Voter
{
    /** Consulter le dossier. */
    public const string VOIR = 'DOSSIER_VOIR';

    /**
     * Voir les montants estimés — amende de 2 %, pénalité de l'art. 314.
     *
     * Attribut distinct de VOIR parce qu'aucun montant affiché par ce
     * logiciel n'est opposable : ce sont des estimations d'aide à la
     * préparation, et l'amende est fixée par le tribunal ou par la banque.
     * Les séparer permet de n'ouvrir ces écrans qu'aux rôles capables de
     * lire l'avertissement qui les accompagne.
     */
    public const string VOIR_MONTANTS_ESTIMES = 'DOSSIER_VOIR_MONTANTS_ESTIMES';

    /** Voir la carte des certitudes : ce qui est établi, probable, incertain. */
    public const string VOIR_CARTE_DES_CERTITUDES = 'DOSSIER_VOIR_CARTE_DES_CERTITUDES';

    /** Demander une prorogation du délai (art. 325 al. 8). */
    public const string DEMANDER_PROLONGATION = 'DOSSIER_DEMANDER_PROLONGATION';

    /**
     * Donner l'accord du bénéficiaire à la prorogation.
     *
     * LE BÉNÉFICIAIRE SEUL. C'est une règle de droit, pas une règle
     * d'interface : l'article 325 al. 8 exige « موافقة المستفيد », l'accord
     * du bénéficiaire. Le tireur ne peut pas y suppléer, son avocat ne peut
     * pas y suppléer, et le greffe ne peut que le consigner.
     */
    public const string ACCORDER_PROLONGATION = 'DOSSIER_ACCORDER_PROLONGATION';

    /** Enregistrer la décision du ministère public prorogeant le délai. */
    public const string ENREGISTRER_DECISION_PARQUET = 'DOSSIER_ENREGISTRER_DECISION_PARQUET';

    /** Notifier l'écédar et enregistrer les mesures de contrôle judiciaire. */
    public const string NOTIFIER_ECEDAR = 'DOSSIER_NOTIFIER_ECEDAR';

    /**
     * Enregistrer le désistement de plainte.
     *
     * Le bénéficiaire seul, parce que c'est SA plainte. Et c'est
     * irréversible : on ne peut revenir sur le désistement (art. 325 al. 10).
     * L'interface doit le dire avant, pas après.
     */
    public const string ENREGISTRER_DESISTEMENT = 'DOSSIER_ENREGISTRER_DESISTEMENT';

    /** Enregistrer une quittance : amende de 2 %, amende de l'art. 316, pénalité de l'art. 314. */
    public const string ENREGISTRER_QUITTANCE = 'DOSSIER_ENREGISTRER_QUITTANCE';

    public function __construct(
        private readonly ResolveurDeRoleInterface $resolveurDeRole,
        private readonly Security $securite,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Dossier && \in_array($attribute, [
            self::VOIR,
            self::VOIR_MONTANTS_ESTIMES,
            self::VOIR_CARTE_DES_CERTITUDES,
            self::DEMANDER_PROLONGATION,
            self::ACCORDER_PROLONGATION,
            self::ENREGISTRER_DECISION_PARQUET,
            self::NOTIFIER_ECEDAR,
            self::ENREGISTRER_DESISTEMENT,
            self::ENREGISTRER_QUITTANCE,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof Dossier);

        $role = $this->resolveurDeRole->roleDans($subject, $token->getUser());

        if (RoleDansDossier::AUCUN === $role) {
            // Aucun rapport avec ce dossier : pas même son existence.
            // Un refus franc ici évite que l'interface révèle, par la
            // différence entre « interdit » et « introuvable », qu'un dossier
            // existe pour un chèque donné.
            return false;
        }

        return match ($attribute) {
            self::VOIR => true, // tout rôle rattaché voit le dossier

            self::VOIR_MONTANTS_ESTIMES => match ($role) {
                // Les deux parties et l'avocat voient les estimations ; le
                // greffe n'en a pas besoin, il enregistre des quittances dont
                // le montant est fixé ailleurs.
                RoleDansDossier::BENEFICIAIRE,
                RoleDansDossier::TIREUR,
                RoleDansDossier::AVOCAT => true,
                default => false,
            },

            self::VOIR_CARTE_DES_CERTITUDES => match ($role) {
                // L'avocat, parce qu'il peut trancher ce que ce logiciel
                // laisse ouvert. Le tireur, parce que c'est lui que les points
                // non tranchés exposent au pénal, et qu'on ne lui cache pas
                // ce qu'on ne sait pas.
                RoleDansDossier::AVOCAT, RoleDansDossier::TIREUR => true,
                default => false,
            },

            self::DEMANDER_PROLONGATION => match ($role) {
                // Le tireur la demande, son avocat peut la demander pour lui.
                // Le bénéficiaire ne demande pas une prorogation contre
                // lui-même ; il consent ou refuse.
                RoleDansDossier::TIREUR, RoleDansDossier::AVOCAT => true,
                default => false,
            },

            // LA RÈGLE DE DROIT, pas une règle d'interface : art. 325 al. 8,
            // « بعد موافقة المستفيد ». Cet attribut est aussi appelé depuis la
            // garde de `proroger_delai` dans workflow.yaml.
            self::ACCORDER_PROLONGATION => RoleDansDossier::BENEFICIAIRE === $role,

            // Le greffe consigne la décision du parquet. Le parquet lui-même
            // n'est pas un utilisateur de ce logiciel : la décision entre ici
            // comme une pièce, pas comme un acte.
            self::ENREGISTRER_DECISION_PARQUET,
            self::NOTIFIER_ECEDAR => RoleDansDossier::GREFFE === $role,

            // Sa plainte, son désistement. Irréversible (art. 325 al. 10).
            self::ENREGISTRER_DESISTEMENT => RoleDansDossier::BENEFICIAIRE === $role,

            self::ENREGISTRER_QUITTANCE => match ($role) {
                RoleDansDossier::GREFFE, RoleDansDossier::TIREUR => true,
                default => false,
            },

            default => false,
        };
    }

    /**
     * Le rôle de l'utilisateur courant, pour que l'interface puisse adapter
     * ce qu'elle MONTRE et non seulement ce qu'elle autorise.
     *
     * Deux choses distinctes : interdire une action et ne pas la proposer.
     * Un bouton grisé avec son info-bulle vaut mieux qu'un bouton absent,
     * parce qu'il explique une règle ; mais un écran entier destiné au greffe
     * n'a rien à faire devant un débiteur.
     */
    public function roleCourantDans(Dossier $dossier): RoleDansDossier
    {
        return $this->resolveurDeRole->roleDans($dossier, $this->securite->getUser());
    }
}
