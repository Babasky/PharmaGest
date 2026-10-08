<?php

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte de connexion. Il n'est pas rattaché directement à une pharmacie :
 * l'accès passe par {@see Affectation} (un propriétaire Premium peut détenir plusieurs pharmacies).
 */
#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_PROPRIETAIRE = 'ROLE_PROPRIETAIRE';
    public const ROLE_ADJOINT = 'ROLE_ADJOINT';
    public const ROLE_VENDEUR = 'ROLE_VENDEUR';
    public const ROLE_CAISSIER = 'ROLE_CAISSIER';

    /** Libellés des rôles d'officine, du plus élevé au plus bas. */
    public const LIBELLES_ROLES = [
        self::ROLE_PROPRIETAIRE => 'Propriétaire',
        self::ROLE_ADJOINT => 'Pharmacien adjoint',
        self::ROLE_VENDEUR => 'Vendeur',
        self::ROLE_CAISSIER => 'Caissier',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message:"Ce champ ne doit pas être vide")]
    #[Assert\Email(message: "Email invalide")]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: "Ce champ ne doit pas être vide")]
    #[Assert\Length(max: 120)]
    private string $nom = '';

    /** Null tant que le compte n'est pas activé (lien envoyé par email). */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Code PIN à 4 chiffres, haché (PH-04) : changement rapide de vendeur, autorisations du propriétaire. */
    #[ORM\Column(nullable: true)]
    private ?string $codePin = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('Un utilisateur doit avoir un email.');
        }

        return $this->email;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * Un utilisateur a un seul rôle métier.
     */
    public function setRole(string $role): static
    {
        if (self::ROLE_SUPER_ADMIN !== $role && !isset(self::LIBELLES_ROLES[$role])) {
            throw new \InvalidArgumentException(\sprintf('Rôle inconnu : %s', $role));
        }
        $this->roles = [$role];

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->roles[0] ?? null;
    }

    public function getLibelleRole(): string
    {
        $role = $this->getRole();

        return self::ROLE_SUPER_ADMIN === $role ? 'Super admin' : (self::LIBELLES_ROLES[$role] ?? '—');
    }

    public function isSuperAdmin(): bool
    {
        return \in_array(self::ROLE_SUPER_ADMIN, $this->roles, true);
    }

    public function isProprietaire(): bool
    {
        return \in_array(self::ROLE_PROPRIETAIRE, $this->roles, true);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCodePin(): ?string
    {
        return $this->codePin;
    }

    public function aUnCodePin(): bool
    {
        return null !== $this->codePin;
    }

    /**
     * @param string $codePinHache code déjà haché ({@see \App\Security\CodePin})
     */
    public function setCodePin(string $codePinHache): static
    {
        $this->codePin = $codePinHache;

        return $this;
    }

    public function isActive(): bool
    {
        return null !== $this->password;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getDerniereConnexion(): ?\DateTimeImmutable
    {
        return $this->derniereConnexion;
    }

    public function setDerniereConnexion(\DateTimeImmutable $derniereConnexion): static
    {
        $this->derniereConnexion = $derniereConnexion;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /**
     * Empreinte qui change dès que le mot de passe change : rend les liens d'activation et de
     * réinitialisation à usage unique.
     */
    public function getEmpreinteMotDePasse(): string
    {
        return substr(hash('sha256', ($this->password ?? 'compte-non-active').'|'.$this->email), 0, 16);
    }

    /**
     * Le hash du mot de passe ne doit pas finir dans la session.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        if (null !== $this->password) {
            $data["\0".self::class."\0password"] = hash('crc32c', $this->password);
        }
        if (null !== $this->codePin) {
            $data["\0".self::class."\0codePin"] = hash('crc32c', $this->codePin);
        }

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
