# Heroku Buildpack: FrankenPHP

Deploy PHP applications to Heroku using [FrankenPHP](https://frankenphp.dev/), a modern PHP application server built on top of Caddy.

## Features

- **FrankenPHP**: Modern PHP application server with built-in web server
- **Worker Mode**: Keep your application in memory for improved performance
- **HTTP/2 & HTTP/3**: Modern protocol support out of the box
- **Automatic Composer**: Installs dependencies from `composer.json`
- **Framework Support**: Pre-configured templates for Laravel and Symfony
- **Early Hints**: HTTP 103 support for faster page loads

## Requirements

- A Heroku account
- [Heroku CLI](https://devcenter.heroku.com/articles/heroku-cli) installed
- A PHP application with `composer.json` or `index.php`

## Quick Start

### 1. Set the Buildpack

```bash
heroku buildpacks:set https://github.com/heroku/heroku-buildpack-frankenphp.git
```

Or add to an existing app:

```bash
heroku buildpacks:add https://github.com/heroku/heroku-buildpack-frankenphp.git
```

### 2. Deploy

```bash
git push heroku main
```

That's it! Your PHP application will be served using FrankenPHP.

## Configuration

### Custom Caddyfile

Create a `Caddyfile` in your project root to customize the server configuration. If not provided, a default configuration will be generated.

Example `Caddyfile`:

```caddyfile
{
    auto_https off
    http_port {$PORT}

    frankenphp {
        worker /app/public/index.php
    }
}

:{$PORT} {
    root * /app/public
    encode zstd gzip
    php_server
}
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `FRANKENPHP_VERSION` | FrankenPHP version to install | `latest` |
| `FRANKENPHP_WORKERS` | Number of worker processes | `2` |

Set environment variables using:

```bash
heroku config:set FRANKENPHP_VERSION=v1.11.1
```

### Custom Procfile

The buildpack creates a default `Procfile` if one doesn't exist:

```procfile
web: .heroku/frankenphp/bin/frankenphp run --config Caddyfile
```

You can customize this to add additional process types or modify the startup command.

## Framework-Specific Setup

### Laravel

1. Copy `conf/Caddyfile.laravel` to your project root as `Caddyfile`
2. Install Laravel Octane (optional but recommended):

```bash
composer require laravel/octane
```

3. Configure Octane to use FrankenPHP:

```bash
php artisan octane:install --server=frankenphp
```

### Symfony

1. Copy `conf/Caddyfile.symfony` to your project root as `Caddyfile`
2. Install the Symfony Runtime component for FrankenPHP:

```bash
composer require runtime/frankenphp-symfony
```

## Worker Mode

FrankenPHP's worker mode keeps your application bootstrapped in memory, dramatically improving response times. Enable it in your `Caddyfile`:

```caddyfile
{
    frankenphp {
        worker /app/public/index.php {
            num 2
        }
    }
}
```

**Note**: Worker mode requires your application to be stateless between requests. Most modern frameworks (Laravel, Symfony) support this out of the box.

## Document Root Detection

The buildpack automatically detects your document root in this order:

1. `public/` directory (Laravel, Symfony)
2. `web/` directory (Drupal, some Symfony setups)
3. Project root (simple PHP apps)

## Troubleshooting

### Application not starting

Check the logs:

```bash
heroku logs --tail
```

### Composer dependencies not installing

Ensure you have a valid `composer.json` and `composer.lock` file committed to your repository.

### Custom PHP extensions

FrankenPHP comes with many common extensions built-in. If you need additional extensions, you may need to use a custom FrankenPHP build or Docker-based deployment.

### Worker mode memory issues

If your application consumes too much memory in worker mode, try:

1. Reducing the number of workers
2. Implementing proper cleanup in your application
3. Using the standard (non-worker) mode

## Local Development

Test the buildpack locally using the Heroku CLI:

```bash
# Create a temporary directory
mkdir /tmp/build && mkdir /tmp/cache && mkdir /tmp/env

# Run compile
./bin/compile /path/to/your/app /tmp/cache /tmp/env
```

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

MIT License - see [LICENSE](LICENSE) for details.

## Resources

- [FrankenPHP Documentation](https://frankenphp.dev/docs/)
- [Caddy Documentation](https://caddyserver.com/docs/)
- [Heroku Buildpack API](https://devcenter.heroku.com/articles/buildpack-api)
