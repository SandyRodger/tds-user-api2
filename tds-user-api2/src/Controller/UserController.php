<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use App\Service\UserService;

final class UserController extends AbstractController
{
    #[Route('/user', name: 'app_user_all', methods: ['GET'])]
    public function index(UserRepository $repo): JsonResponse
    {
        return $this->json($repo->findLatestUsers());
    }

    #[Route('/user/{id}', name: 'app_user_one', methods: ['GET'])]
    public function showUser(int $id, UserService $userService): JsonResponse
    {
        $user = $userService->getUser($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $this->json($user);
    }

    #[Route('/user', name: 'app_user_create', methods: ['POST'])] 
    public function create(Request $request, UserService $userService): JsonResponse
    {
        $data = $request->toArray();
        $user = $userService->createUser($data);

        return $this->json($user, 201);
    }

    #[Route('/user/{id}', name: 'app_user_delete', methods: ['DELETE'])]
    public function delete(UserService $userService, int $id): JsonResponse
    {
        $deleted = $userService->deleteUser($id);

        if (!$deleted) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $this->json(['status' => 'deleted']);
    }

    #[Route('/user/{id}', name: 'app_user_update', methods: ['PATCH'])]
    public function update(int $id, Request $request, UserService $userService): JsonResponse
    {
        $data = $request->toArray();
        $updated = $userService->updateUser($data, $id);

        if (!$updated) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $this->json($updated);
    }
}
