<?php

namespace App\Entity;

use App\Repository\SupportClientRepository;
use App\Util\Telephone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Service support client de la plateforme, réglé par le super admin : les pharmacies contactent l'éditeur par
 * WhatsApp, appel téléphonique ou email. Une seule ligne pour toute la plateforme ; chaque canal s'active à part.
 */
#[ORM\Entity(repositoryClass: SupportClientRepository::class)]
class SupportClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $whatsappActif = false;

    /** Numéro WhatsApp normalisé (« +22376123456 »). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $whatsapp = null;

    #[ORM\Column]
    private bool $telephoneActif = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column]
    private bool $emailActif = false;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** Jours et heures d'ouverture affichés aux pharmacies, par exemple « Du lundi au samedi, de 8 h à 18 h ». */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $horaires = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isWhatsappActif(): bool
    {
        return $this->whatsappActif;
    }

    public function setWhatsappActif(bool $actif): static
    {
        $this->whatsappActif = $actif;

        return $this;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $numero): static
    {
        $this->whatsapp = self::numero($numero);

        return $this;
    }

    public function isTelephoneActif(): bool
    {
        return $this->telephoneActif;
    }

    public function setTelephoneActif(bool $actif): static
    {
        $this->telephoneActif = $actif;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $numero): static
    {
        $this->telephone = self::numero($numero);

        return $this;
    }

    public function isEmailActif(): bool
    {
        return $this->emailActif;
    }

    public function setEmailActif(bool $actif): static
    {
        $this->emailActif = $actif;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = null !== $email ? trim($email) : null;
        $this->email = '' === $email ? null : $email;

        return $this;
    }

    public function getHoraires(): ?string
    {
        return $this->horaires;
    }

    public function setHoraires(?string $horaires): static
    {
        $horaires = null !== $horaires ? trim($horaires) : null;
        $this->horaires = '' === $horaires ? null : $horaires;

        return $this;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function setModifieLe(?\DateTimeImmutable $modifieLe): static
    {
        $this->modifieLe = $modifieLe;

        return $this;
    }

    public function aUnCanalActif(): bool
    {
        return $this->whatsappActif || $this->telephoneActif || $this->emailActif;
    }

    /**
     * Lien wa.me qui ouvre la conversation, avec un premier message déjà écrit.
     */
    public function lienWhatsapp(?string $message = null): ?string
    {
        if (!$this->whatsappActif || null === $this->whatsapp) {
            return null;
        }

        $lien = 'https://wa.me/'.ltrim($this->whatsapp, '+');

        return null !== $message && '' !== $message ? $lien.'?text='.rawurlencode($message) : $lien;
    }

    public function lienTelephone(): ?string
    {
        return $this->telephoneActif && null !== $this->telephone ? 'tel:'.$this->telephone : null;
    }

    public function lienEmail(?string $sujet = null): ?string
    {
        if (!$this->emailActif || null === $this->email) {
            return null;
        }

        return 'mailto:'.$this->email.(null !== $sujet && '' !== $sujet ? '?subject='.rawurlencode($sujet) : '');
    }

    /**
     * Valeurs suivies par le journal d'audit de la plateforme.
     *
     * @return array<string, bool|string|null>
     */
    public function valeursJournalisees(): array
    {
        return [
            'whatsappActif' => $this->whatsappActif,
            'whatsapp' => $this->whatsapp,
            'telephoneActif' => $this->telephoneActif,
            'telephone' => $this->telephone,
            'emailActif' => $this->emailActif,
            'email' => $this->email,
            'horaires' => $this->horaires,
        ];
    }

    #[Assert\Callback]
    public function valider(ExecutionContextInterface $contexte): void
    {
        foreach (['whatsapp' => 'WhatsApp', 'telephone' => 'téléphone'] as $champ => $libelle) {
            $numero = $this->{$champ};
            if (null !== $numero && null === Telephone::normaliser($numero)) {
                $contexte->buildViolation('Numéro de téléphone malien attendu : +223 XX XX XX XX.')->atPath($champ)->addViolation();
            } elseif (null === $numero && $this->{$champ.'Actif'}) {
                $contexte->buildViolation(\sprintf('Indiquez le numéro %s ou désactivez ce canal.', $libelle))->atPath($champ)->addViolation();
            }
        }
        if (null === $this->email && $this->emailActif) {
            $contexte->buildViolation('Indiquez l\'adresse email ou désactivez ce canal.')->atPath('email')->addViolation();
        }
        if (!$this->aUnCanalActif()) {
            $contexte->buildViolation('Activez au moins un canal : les pharmacies doivent pouvoir vous joindre.')->addViolation();
        }
    }

    private static function numero(?string $saisie): ?string
    {
        $saisie = null !== $saisie ? trim($saisie) : null;
        if (null === $saisie || '' === $saisie) {
            return null;
        }

        return Telephone::normaliser($saisie) ?? $saisie;
    }
}
