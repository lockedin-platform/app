<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\UserConnectionRepository::class)]
#[ORM\Table(name: 'user_connection')]
#[ORM\UniqueConstraint(name: 'unique_connection', columns: ['follower_id', 'followed_id'])]
class UserConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'follower_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $follower = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'followed_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $followed = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct() { $this->createdAt = new \DateTime(); }

    public function getId(): ?int { return $this->id; }
    public function getFollower(): ?User { return $this->follower; }
    public function setFollower(?User $v): static { $this->follower = $v; return $this; }
    public function getFollowed(): ?User { return $this->followed; }
    public function setFollowed(?User $v): static { $this->followed = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
}
