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

## Module 2: Basic Symfony Application + php namespacing



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
