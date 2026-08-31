<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;


class HealthController
{

    #[Route('/health')]
    public function show(): JsonResponse
        {
            $status = ['status' => 'ok'];
            return new JsonResponse($status);
        }
}