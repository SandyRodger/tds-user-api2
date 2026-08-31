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

[lost my notes lines 23 - 276 & 306 - 400ish)

```
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
2. 
