<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {}

    public function createUser(array $data): User
    {
        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

public function deleteUser(int $id): bool
{
    $user = $this->userRepository->find($id);

    if (!$user) {
        return false;
    }

    $this->em->remove($user);
    $this->em->flush();

    return true;
}

public function updateUser(array $data, int $id): ?User
{
    $user = $this->userRepository->find($id);

    if (!$user) {
        return null;
    }

    $user->setEmail($data['email'] ?? $user->getEmail());
    $user->setFirstName($data['firstName'] ?? $user->getFirstName());
    $user->setLastName($data['lastName'] ?? $user->getLastName());

    $this->em->flush();

    return $user;
}

public function getUser(int $id): ?User
{
    return $this->userRepository->find($id);

}

}