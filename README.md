# Steps

## Module 1 a): Symfony

1. Check which version of PHP is running
   - `composer --version`
   - If there's no Composer:
     - `brew install composer`
2. Navigate to the directory you want to house your project and create the Symfony skeleton
   - `composer create-project symfony/skeleton:"6.4.*" tds-user-api2`
   - We want 6.4.* becasue that's the LTS version TDS Ultra uses
3. `cd` into the project. Run it :
   - `php -S localhost:8000 -t public/`
   - `-S` means start PHP's built-in dev server.
   - `-t` means set the document root as the following.
   - Check http://localhost:8000 in the browser. You should have a "Welcome to Symfony page"
   
## Module 1 b): Docker

- Next you'll want to containerise it so that it no longer depends on applications on your machine.
   - This is how the Symfony online community say you should do it: https://symfony.com/doc/current/setup/docker.html
   - but TDS don't use frankenphp or caddy, so don't do that.
   - Here's a helpful(?) article about this:
     - https://oneuptime.com/blog/post/2026-02-08-how-to-containerize-a-php-symfony-application-with-docker/view
      - This article is basically a list of configuration files for setting up. But, it is internally inconsistent and incomplete as written.

- `docker --version` => Docker version 28.3.2, build 578ccf6
- We are mking 3 containers:
   - mysql
   - php-fpm -> the engine
   - nginx -> the front door
- Two files orchestrate this:
   - Dockerfile -> the recipe
   - docker-compose.yml -> the conductor that starts and wires all 3 together

### Dockerfile

#### FROM 

`FROM php:8.3-fpm`

   - FROM is always the first line. This is the base image we're starting with. php-8.3-fpm is the official PHP image in it's FPM flavour.
   - 8.3 is what TDS are using now.
   
#### PHP Extensions 

```
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    && docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    opcache
```

- Notice this is 2 commands chained with a &&.
   - `apt-get install` grabs linux system libraries the extensions depend on:
      - `libicu-dev` for `icu`
      - `libzi-dev` for `zip`
      - Here is a sentence I don't understand:
      - "These aren't the PHP extensions themselves; they're the underlying C libraries those extensions compile against."
   - `docker-php-ext-install` is a helper baked into the official PHP image. It compiles and enables actuall PHP extensiions by name.
      - `pdo_mysql` : how PHP talks to MySQL (Doctrine's foundation)
      - `intl`
      - `zip`
      - `opcache` (bytecode caching for speed)
   - The odd one out is xdebug, which installs differently because it's not a core extension:
 
```
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug
````
 - pecl is PHP's other package library, for extensions not bundled into core
 - xdebug is a development tool. It let's you step through debugging and pause mid-execution. You'd never ship it to prodution because it slows everything way down.

#### WORK DIR

```
WORKDIR /var/www
```

- `WORKDIR /var/www` sets the container's working directory. The folder from which every command runs.
- It is where php-fpm will look for your app.
- `/var/www` is the conventional home for web apps on Linux.
- Docker will create the folder if it's not there.

#### COPY 

```
COPY . /var/www
```

- `COPY . /var/www` copies your project files from your machine into the image.
- The `.` is the build context, which is copied into `/var/www`.
- This is the moment your SSymfony app becomes part of the container rather than living only on your laptopn

#### complete Dockerfile

```
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

WORKDIR /var/www

COPY . /var/www
```

#### docker-compose.yml

- This is the conductor that starts all three containers and wires them together.

##### php 

```
services:
  php:
   build: .
```

- `services` is the top level list of containers compose will run. We have 3
 to do today.
- `build: .` means don't just use a ready made container here.
- I'm going to build my own. The nginx and sql containers we'll take straight from the shelf. For them we'll use `image` instead of `build`.
- Notice this container has no host port. This is because nothing from the outside is allowed to talk to it. Everything comes through proxys.

##### sql

```
  mysql:
    image: mysql:8
    environment:
      MYSQL_ROOT_PASSWORD: password
      MYSQL_DATABASE: app
    ports:
      - "3306:3306"
```
- `environment` sets variables inside the container. So the official MySQL image reads these on first boot and configures itself.
  - `MYSQL_ROOT_PASSWORD:` sets the root login
  - `MYSQL_DATABASE: app` auto creats an empty database called `app`.
  - These 2 values have to match the `.env` line the syllabus prescribed: `mysql://root:password@mysql:3306/app`

##### nginx

```
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

- The ports maps your mac's port 8080 on to nginx' internal port 80.
- `volumes` is the live-sync mechanism. It has 2 mounts:
   1) `.:/var/www` shares your project folder into the container so nginx can see your app.
   2) `./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf` injects a config file we'll write later. It's purpose is to tell nginx how to hand PHP requests to php-fpm.
- `depends_on`: - php` this is the start order control. It tells compose to boot the php container before nginx needs it there to talk to.

##### `docker/nginx/default.conf`

```
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

- The outer `server` block is one virtual server. It listens on port 80 insde the container. Your compose file maps that out to 8080 on your Mac.


- `root /var/www/public` is the crucial line. It means nginx serves from the `public/` folder not your project root. It's Symfony's web root; the only folder the ouside world is allowed to see. Everything else (`src/`, `.env`, `.config`) is kept hidden and private.
- It lines up with the `.:/ var/www` volume.
- This is confusing wiring stuff - i'm not sure I even need to ahve this stored in my brain.
- There's 3 location blocks:

###### location 1

   - The first one says when a request comes in see if the URL matches any files I have here. So if a request is a static file it gets serves right here and doesn't even go to the php container.

###### location 2

```
    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }
```

- But if it doesn't it falls through to the 2nd location block, which is a regex matching ________ not sure. But this is where nginx hands over to php-fpm. 
- `fastcgi_pass php:9000` is the key line. `php` is the name of the container. 9000 is t
he port it listens on.
This is the arrow from nginx to php-frm, nginx can't run PHP, it speaks a protocol called FastCGI to php-fpm, which executes the code and returns a result.
- The lines under this are the details of the hand-off.

###### location 3

```
    location ~ \.php$ {
        return 404;
    }
```

- This is "security hardening". It blocks any attempt to run a `.php` file directly. So only `index.php` reached via the controlled path above is allowed to execute.
[lost my notes lines 23 - 276 & 306 - 400ish)

## Module 2: Basic Symfony Application + php namespacing -> the healthcheck endpoint

### Health controller

```src/Controller/HealthController.php
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

- be aware Symfony is case-sensitive, so `localhost:8080/Health` won't work



## Module 3 a) getting Symfony to talk to the MySQL container: 

- in the `.env` file replace `DATABASE=URL=` with:
   - DATABASE_URL=mysql://root:password@mysql:3306/app
- Test with `docker compose exec php php bin/console dbal:run-sql "SELECT 1"`
- `composer require symfony/orm-pack`
- `composer require doctrine/doctrine-bundle:"^2.12" doctrine/doctrine-migrations-bundle:"^3.3" symfony/orm-pack --with-all-dependencies` -> this is because the oackages were requiring PHP 8.4.
- make sure you've cleared away the other unwanted variable auto generated in the .env file

### PHP version conflict

: docker was running a stale build. Fix with:
   - `docker compose build php`
   -  `docker compose up -d php`
-  Bug  dependencies still stuck to PHP 8.4:
   - composer update --with-platform-php=8.3.33
   - docker compose build php
   - docker compose up -d php

make sure composer.json looks like this:

```composer.json
{
    "type": "project",
    "license": "proprietary",
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require": {
        "php": ">=8.1",
        "ext-ctype": "*",
        "ext-iconv": "*",
        "doctrine/doctrine-bundle": "^2.12",
        "doctrine/doctrine-migrations-bundle": "^3.3",
        "doctrine/orm": "^3.6",
        "phpdocumentor/reflection-docblock": "^5.2",
        "phpstan/phpdoc-parser": "^2.3",
        "symfony/console": "6.4.*",
        "symfony/dotenv": "6.4.*",
        "symfony/flex": "^2",
        "symfony/framework-bundle": "6.4.*",
        "symfony/property-access": "6.4.*",
        "symfony/property-info": "6.4.*",
        "symfony/runtime": "6.4.*",
        "symfony/serializer": "6.4.*",
        "symfony/yaml": "6.4.*"
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true,
            "symfony/flex": true,
            "symfony/runtime": true
        },
        "sort-packages": true,
        "platform": {
            "php": "8.3.33"
        }
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    },
    "replace": {
        "symfony/polyfill-ctype": "*",
        "symfony/polyfill-iconv": "*",
        "symfony/polyfill-php72": "*",
        "symfony/polyfill-php73": "*",
        "symfony/polyfill-php74": "*",
        "symfony/polyfill-php80": "*",
        "symfony/polyfill-php81": "*"
    },
    "scripts": {
        "auto-scripts": {
            "cache:clear": "symfony-cmd",
            "assets:install %PUBLIC_DIR%": "symfony-cmd"
        },
        "post-install-cmd": [
            "@auto-scripts"
        ],
        "post-update-cmd": [
            "@auto-scripts"
        ]
    },
    "conflict": {
        "symfony/symfony": "*"
    },
    "extra": {
        "symfony": {
            "allow-contrib": false,
            "require": "6.4.*"
        }
    },
    "require-dev": {
        "mockery/mockery": "^1.6",
        "phpunit/phpunit": "^12.5",
        "symfony/browser-kit": "6.4.*",
        "symfony/css-selector": "6.4.*",
        "symfony/maker-bundle": "^1.67"
    }
}
```

BUG_PERSISTS:   

1. `docker compose exec php php bin/console dbal:run-sql "SELECT 1"`
   - returns: Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= 8.4.0". You are running 8.3.33.
   - That is set in `/var/www/vendor/composer/platform_check.php` on line 22
   - This means the command never even reaches Symfony's console logic. It dies inside a file called platform_check.php, which Composer auto-generates and runs on every request, before your application code executes. So the real bug has nothing to do with dbal:run-sql, SQL, or your database, it's a PHP version check failing at the very first step.

2. Read file: vendor/composer/platform_check.php
   - Relevant line:
  ```
  if (!(PHP_VERSION_ID >= 80400)) {
      $issues[] = 'Your Composer dependencies require a PHP version ">= 8.4.0". You are 
  running ' . PHP_VERSION . '.';
  }
   ```
   - This means this file is auto-generated by Composer (it's not something a developer writes by hand). Composer generates it based on what versions of PHP your installed dependencies (in vendor/) actually need. 80400 is Composer's internal way of writing "PHP 8.4.0". So somewhere, one or more packages in your vendor/ folder demand PHP 8.4+, but the number in the error (8.3.33) is what PHP version is actually running when you execute the command.
- Question this raised: where is PHP 8.3.33 coming from, and where did a PHP-8.4-requiring package come from?

3. I checked what PHP version is inside the Docker container
   - Command: `docker compose exec php php -r 'echo PHP_VERSION;'`
   - Result: 8.3.33
   - What this told me: confirmed — the container itself genuinely runs PHP 8.3.33. That matches the second half of the error message. So the container is not lying; it really is 8.3.33.

4. I checked what installed Composer/PHP on your host machine
   - Command: `composer --version`
   - Result: `Composer version 2.10.2 2026-07-01 11:24:45`
   - PHP version 8.5.9 (/usr/local/Cellar/php/8.5.9/bin/php)
   - What this told me: your Mac (the host, outside Docker) has its own separate PHP installed via Homebrew, and it's version 8.5.9 — a different, newer version than the container's 8.3.33.
   - Why this mattered: I already knew from reading your docker-compose.yml (specifically the php service's volumes: - .:/var/www line) that the entire project folder — including vendor/ — is shared between your Mac and the container. It's the same vendor/ folder in both places, not two separate copies. So whichever machine last ran composer install or composer update is the one whose PHP version determined what's inside vendor/.

5. I identified which specific package required PHP 8.4+

- I searched composer.lock (the file that records the exact version of every installed package) for any package listing a PHP requirement of 8.4 or higher.
- Result: one match — doctrine/instantiator  ^8.4
- What this told me: the package doctrine/instantiator — a small internal dependency pulled in indirectly by phpunit/phpunit (your testing library) — had been resolved to a version that requires PHP 8.4+. That package's requirement is what platform_check.php was reflecting in Step 2.
- Why this happened: when composer install/update was last run, it was run using your host's PHP 8.5.9 (Step 4), not the container's 8.3.33. Composer picks package versions that are compatible with whatever PHP is running Composer itself, unless told otherwise. Since 8.5.9 can use a newer doctrine/instantiator, Composer happily picked one — one that's too new for the container's 8.3.33.

6. I checked whether Composer could just be run inside the container instead

- Command: `docker compose exec php composer --version`
- Result: OCI runtime exec failed: exec failed: unable to start container process: exec: "composer": executable file not found in $PATH: unknown
- What this told me: Composer isn't installed inside the container at all — I confirmed this by also reading your Dockerfile, which only installs PHP extensions (pdo_mysql, intl, zip, opcache, xdebug) and never installs the composer binary. So composer install/update can physically only ever be run from your host machine. That's not a mistake I could route around — it's a structural fact about the current Docker setup.

7. I applied the fix — told Composer to pretend it's running on the container's PHP version 
- Command 1: `composer config platform.php 8.3.33`
- What this does: it adds a line to your composer.json file, inside the "config" section, that looks like:
```
  "config": {
      "platform": {
          "php": "8.3.33"
      },
      ...
  }
```
- This tells Composer: "even though you (Composer) are physically running under PHP 8.5.9 on this Mac, resolve all package versions as if PHP were 8.3.33." This is the standard Composer mechanism for exactly this situation — developing on one PHP version while targeting another (the container's).
- Command 2: `composer update --with-all-dependencies`
- What this does: re-resolves every package in composer.lock from scratch, now constrained to versions compatible with PHP 8.3.33 instead of 8.5.9. In the output I watched it install a different, older set of packages — for example phpunit/phpunit (12.5.34) and its dependency chain — chosen specifically because they satisfy PHP 8.3.33, not 8.4+.

8. I verified the fix by re-reading the same file from Step 2

- File: vendor/composer/platform_check.php (now regenerated by the composer update in Step 7)
- New relevant line:
```
  if (!(PHP_VERSION_ID >= 80200)) {
      $issues[] = 'Your Composer dependencies require a PHP version ">= 8.2.0". You are 
  running ' . PHP_VERSION . '.';
  }
```

- What this told me: the requirement dropped from >= 8.4.0 down to >= 8.2.0. Since the container runs 8.3.33, and 8.3.33 ≥ 8.2.0, this check will now pass instead of throwing.

9. I re-ran your original command to confirm `docker compose exec php php bin/console dbal:run-sql "SELECT 1"`
- Result:
```
   ---
    1
   ---
    1
   ---
```

- What this told me: the command now runs all the way through — past the platform check, through Symfony's console, and actually executes the SQL query against the database, returning 1 as expected. The original problem is resolved.

### Module 2 b) User Entity

- an entity is a PHP class that maps to a database table.
- Each property on the class becomes a column in the table.
- special `#[ORM\...]` attributes tell Doctrine how to do the mapping.
- `docker compose exec php php bin/console make:entity`
   - class name: `User`
   - property: email, firstName, lastName :
      - type: string
      - filed length: 255
      - nullable: no
   - createdAt: `datetimeimmutable`
- `docker compose exec php php bin/console make:migration`
- **end of Chat 1 notes**
- `php bin/console doctrine:migrations:migrate`
- change `DATABASE_URL` in `.env` to `DATABASE_URL=mysql://root:password@127.0.0.1:3306/app`
- check with `php bin/console dbal:run-sql "SHOW TABLES" --force-fetch`

### Module 4: CRUD API

- docker compose exec php php bin/console make:controller
   - UserController
   - bug: can't connect to db, change .env line to:
   - `DATABASE_URL=mysql://root:password@mysql:3306/app`
   - Also Symfony Flex auto-added a database when doctrine/doctrine-bundle was
  installed via composer — it appended a Postgres database service to your
  docker-compose.yml by default. Plus `compose.override.yaml` references this db, so that has to be removed.
      - `rm compose.override.yaml`

### Read

- start with R because it's simpler to verify. "smoke test"
- Rememeber your controller can't talk to the db directly. It goes through a repository.

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

- test with Postmate
- weird upsert bug where patch was duplicating entries. I turned postmate off and on again.

### Module 8 - Unit testing

- Do we have PHPUnit? `docker compose exec php php bin/phpunit --version`
- If not install: `docker compose exec php composer require --dev symfony/test-pack`
- `composer: not found`:
   - `docker compose exec php php /usr/bin/composer require --dev symfony/test-pack`

### Questions I should be able to answer:

1. Walk me through building a complete Symfony API app from scratch.
2. How are Symfony apps deployed and run inside Docker?
3. Tell me about the docker-compose.yml file
4. The Dockerfile is like a recipe for building your custom container. The extensions you want...
5. Tell me about the Dockerfile
   - Like a conductor that starts al three containers and wires them together. It reads the Dockerfile and gets to work. We trigger it with the command: ...
6. What's a volume mount?

### Questions for Claude:

1. Clarification: You say "/var/www. This is the point your app becomes part of the container rather than just living on your laptop." , but isn't the container also running on my laptop?
2. The syllabus mentions a SQL GUI. I didn't mess with that. Should we have a look now. I think yes.
3. Of the PHP container I wrote: "- Notice this container has no host port. This is because nothing from the outside is allowed to talk to it. Everything comes through proxys." is that ok?
4. You seem to use the rems `service` and `container` interchangeably?
