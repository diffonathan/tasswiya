<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

/**
 * Le décalage, en jours, que l'écran de démonstration applique au temps.
 *
 * ⚠ CECI N'EST PAS UNE FONCTIONNALITÉ DU PRODUIT. C'est l'instrument de la
 * démonstration : un bouton « avancer de N jours » qui pousse l'horloge
 * injectée, pour qu'un spectateur voie une règle de droit s'appliquer au
 * seul passage du temps au lieu de la lire dans un test.
 *
 * ─── POURQUOI UN FICHIER ET NON LA SESSION ─────────────────────────────────
 *
 * Parce que la bascule doit être faite par le MÊME code qu'en production.
 * `App\Workflow\ExpirateurDeDelais` est appelé par l'écran de démonstration et
 * par la commande `tasswiya:faire-expirer-les-delais`, et la seconde tourne
 * dans un processus console qui n'a pas de session HTTP. Un décalage rangé en
 * session donnerait deux temps différents selon la porte d'entrée : la page
 * verrait le dossier expiré et la commande ne le verrait pas. Le fichier est
 * lu par les deux.
 *
 * Conséquence assumée : le décalage est GLOBAL à l'installation, pas par
 * visiteur. Pour une démonstration filmée c'est ce qu'on veut — l'écran et le
 * terminal doivent raconter la même histoire. Pour un produit ce serait
 * inacceptable, et c'est l'une des raisons pour lesquelles cet écran dit
 * partout qu'il n'en est pas un.
 *
 * ─── POURQUOI PAS DE REDIS, PAS DE CACHE PARTAGÉ ───────────────────────────
 *
 * Contrainte de déploiement : 512 Mo. Un fichier de quelques octets dans
 * `var/` suffit, et `var/` est un volume nommé dans `docker-compose.yml`,
 * donc partagé entre le serveur web et la console du même conteneur.
 */
final class DecalageDeDemonstration
{
    /**
     * Plafond du décalage, en jours.
     *
     * Dix ans : de quoi dépasser la plus longue horloge du dossier (H5,
     * cinq ans) et sa somme avec H4, sans laisser une saisie absurde pousser
     * une date hors du domaine de `DateTimeImmutable`.
     */
    public const int DECALAGE_MAXIMAL_EN_JOURS = 3650;

    public function __construct(
        /*
         * Injecté depuis `config/services.yaml` : `%kernel.project_dir%/var`.
         * Pas de chemin codé en dur — le conteneur et le poste de
         * développement ne montent pas `var/` au même endroit.
         */
        private readonly string $cheminDuFichier,
    ) {
    }

    /** Le décalage courant, en jours. Zéro quand rien n'a été poussé. */
    public function enJours(): int
    {
        if (!is_file($this->cheminDuFichier)) {
            return 0;
        }

        $contenu = @file_get_contents($this->cheminDuFichier);
        if (false === $contenu || '' === trim($contenu)) {
            return 0;
        }

        // Un fichier illisible ou corrompu rend ZÉRO et ne lève pas : la seule
        // conséquence doit être que la démonstration reparte du temps réel.
        // Jeter une exception ici ferait tomber en 500 un écran de
        // consultation qui n'a rien à voir avec la démonstration, puisque
        // c'est l'horloge de TOUT le produit qui passe par là.
        return $this->borner((int) trim($contenu));
    }

    /** Pousse le décalage de `$jours` jours de plus. Rend le nouveau décalage. */
    public function avancerDe(int $jours): int
    {
        return $this->ecrire($this->enJours() + max(0, $jours));
    }

    /** Remet la démonstration au temps réel. */
    public function reinitialiser(): int
    {
        return $this->ecrire(0);
    }

    private function ecrire(int $jours): int
    {
        $jours = $this->borner($jours);

        $dossier = \dirname($this->cheminDuFichier);
        if (!is_dir($dossier) && !@mkdir($dossier, 0o775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Impossible de créer '.$dossier.' pour le décalage de démonstration.');
        }

        if (false === @file_put_contents($this->cheminDuFichier, (string) $jours, \LOCK_EX)) {
            throw new \RuntimeException('Impossible d\'écrire le décalage de démonstration dans '.$this->cheminDuFichier.'.');
        }

        return $jours;
    }

    /** Le décalage ne recule jamais sous zéro et ne dépasse pas le plafond. */
    private function borner(int $jours): int
    {
        return max(0, min(self::DECALAGE_MAXIMAL_EN_JOURS, $jours));
    }
}
