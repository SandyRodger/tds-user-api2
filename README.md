# Steps

## Module 1: Symfony & Docker

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
4. Next you'll want to containerise it so that it no longer depends on applications on your machine.
   - This is how the Symfony online community say you should do it: https://symfony.com/doc/current/setup/docker.html
   - but TDS don't use frankenphp or caddy, so don't do that.
   - Here's a helpful(?) article about this:
     - https://oneuptime.com/blog/post/2026-02-08-how-to-containerize-a-php-symfony-application-with-docker/view
      - This article is basically a list of configuration files for setting up. But, it is internally inconsistent and incomplete as written.
      
