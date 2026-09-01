### 1a) Symfony

- composer create-project symfony/skeleton:"6.4.*" tds-user-api3
- cd tds-user-api3
- php -S localhost:8000 -t public/
  
### 1b) Docker

```dockerfile
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    && docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    opcache

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . /var/www
```

```docker-compose.yml
services:
  php:
    build: .
    volumes:
      - .:/var/www
  mysql:
    image: mysql:8
    environment:
      MYSQL_ROOT_PASSWORD: password
      MYSQL_DATABASE: app
    ports:
      - "3306:3306"
  nginx:
    image: nginx:latest
    ports:
      - "8080:80"
    volumes:
      - .:/var/www
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php
```

```docker/nginx/default.conf
server {
    listen 80;
    server_name localhost;
    root /var/www/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

### 2 HealthController

```
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
```

### 3 a) getting Symfony to talk to the MySQL container

- `composer config platform.php 8.3.33`
- set MySQL connection in `.env.local`:
  - DATABASE_URL=mysql://root:password@mysql:3306/app
- composer require symfony/orm-pack
  - say `x` to whether you accept Doctrine injecting docker dependencies.
- composer require doctrine/doctrine-bundle doctrine/doctrine-migrations-bundle
- (the above 2 commands will add a Postgres DATABASE_URL to your .env. This would break the connection except the .env.local overrides it.
- verify which DATABASE_URL is in use:
  - `docker compose exec php php bin/console debug:dotenv | grep DATABASE_URL`
- rebuid the container:
  - docker compose build php
  - docker compose up -d
- docker compose exec php php bin/console dbal:run-sql "SELECT 1"
  - You might need to wait for 30 seconds before MySQL boots.

### 3 b) User Entity

- composer require symfony/maker-bundle --dev
- docker compose exec php php bin/console make:entity
  - User
  - email
  - firstName
  - lastName
  - createdAt
- docker compose exec php php bin/console make:migration
- change DATABASE_URL in .env.local to `DATABASE_URL=mysql://root:password@127.0.0.1:3306/app`
- php bin/console doctrine:migrations:migrate
- php bin/console dbal:run-sql "SHOW TABLES" --force-fetch

### 4  CRUD endpoints

- docker compose exec php php bin/console make:controller
  - UserController
  - `no` to experimental tests

###### UserController.php

```src/Controllers/UserController.php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
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
```
### module 5: Repository

###### UserRepository.php

```
<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }


    public function findLatestUsers(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }
}
```

### Module 6: Service layer

```src/Service/UserService.php
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
```
### 5 Repository 
### 6 Service
### 8 Testing
