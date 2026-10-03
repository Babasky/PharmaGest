<?php

namespace App\Command;

use App\Entity\Utilisateur;
use App\Form\NouveauMotDePasseType;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le super admin est créé à l'installation (§ 2), jamais depuis l'interface.
 */
#[AsCommand(name: 'app:super-admin:creer', description: 'Crée un compte super admin (éditeur de la plateforme)')]
final class CreerSuperAdminCommand
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email de connexion')] string $email,
        #[Argument('Nom affiché')] string $nom,
    ): int {
        if (null !== $this->utilisateurs->parEmail($email)) {
            $io->error('Un compte existe déjà avec cet email.');

            return Command::FAILURE;
        }

        $motDePasse = (string) $io->askHidden('Mot de passe', static function (?string $valeur): string {
            if (null === $valeur || mb_strlen($valeur) < NouveauMotDePasseType::LONGUEUR_MIN) {
                throw new \RuntimeException(\sprintf('Au moins %d caractères.', NouveauMotDePasseType::LONGUEUR_MIN));
            }

            return $valeur;
        });

        $admin = (new Utilisateur())->setEmail($email)->setNom($nom)->setRole(Utilisateur::ROLE_SUPER_ADMIN);
        $admin->setPassword($this->hasher->hashPassword($admin, $motDePasse));
        $this->em->persist($admin);
        $this->em->flush();

        $io->success(\sprintf('Super admin %s créé.', $admin->getEmail()));

        return Command::SUCCESS;
    }
}
