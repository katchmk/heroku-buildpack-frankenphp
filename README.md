# Heroku Buildpack: FrankenPHP

Deploy PHP applications to Heroku using [FrankenPHP](https://frankenphp.dev/), a modern PHP application server built on top of Caddy.

## Features

- **FrankenPHP**: Modern PHP application server with built-in web server
- **Worker Mode**: Keep your application in memory for improved performance
- **Automatic Composer**: Installs dependencies from `composer.json`
- **`php` and `composer` commands**: Run `php artisan`, `php bin/console` and Composer scripts as usual
- **Framework Support**: Pre-configured templates for Laravel and Symfony

## Requirements

- A Heroku account
- [Heroku CLI](https://devcenter.heroku.com/articles/heroku-cli) installed
- A PHP application with `composer.json` or `index.php`
- An app on the Cedar generation of Heroku (classic buildpacks). The Fir generation only supports Cloud Native Buildpacks, so it cannot use this buildpack.

## Quick Start

### 1. Set the Buildpack

```bash
heroku buildpacks:set https://github.com/katchmk/heroku-buildpack-frankenphp.git
```

Or add to an existing app:

```bash
heroku buildpacks:add https://github.com/katchmk/heroku-buildpack-frankenphp.git
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

Use `{$PORT}` (with the braces) for the port. Caddy does not read `$PORT`.

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `FRANKENPHP_VERSION` | FrankenPHP release to install, for example `v1.13.0` (the `v` is optional), or `latest` | `v1.13.0` |
| `FRANKENPHP_BINARY_TYPE` | Binary type: `gnu` (glibc, can load dynamic extensions) or `musl` (fully static) | `gnu` |
| `FRANKENPHP_CUSTOM_BINARY_URL` | URL to a custom FrankenPHP binary | - |
| `FRANKENPHP_WORKERS` | Number of worker threads (used in `conf/Caddyfile.symfony`) | `2` |

Set environment variables using:

```bash
heroku config:set FRANKENPHP_VERSION=v1.13.0
heroku config:set FRANKENPHP_BINARY_TYPE=musl
```

The buildpack caches the FrankenPHP binary between builds. With `latest`, each build asks GitHub for the newest release, and downloads it when it changes. A custom binary is cached by its URL: to deploy a new custom binary, use a new URL.

The default binary type is `gnu`, because the FrankenPHP project [recommends glibc builds for production](https://frankenphp.dev/docs/performance/): PHP is slower with musl, especially in the thread-safe (ZTS) mode that FrankenPHP uses.

### Custom Procfile

The buildpack creates a default `Procfile` if one doesn't exist:

```procfile
web: .heroku/frankenphp/bin/frankenphp run --config Caddyfile
```

You can customize this to add additional process types or modify the startup command.

### The `php` and `composer` Commands

The buildpack adds `frankenphp`, `php` and `composer` to the `PATH` of the build and of the dynos:

```bash
heroku run php artisan migrate
heroku run php bin/console cache:clear
heroku run composer show
```

FrankenPHP does not include a separate `php` binary. The `php` command runs `frankenphp php-cli`, and it also:

- gives scripts the same `$argv` as the `php` binary. FrankenPHP v1.13.0 puts its own name in `$argv[0]` ([php/frankenphp#2690](https://github.com/php/frankenphp/issues/2690)), so `frankenphp php-cli bin/console ...` fails with `Command "bin/console" is not defined`.
- accepts the common `php` options: `-r`, `-d`, `-f`, `-v`, `-m`, `-i` and `--ini`. It can also read a script from standard input.

The buildpack sets `PHP_BINARY` to this command, so Composer scripts with `@php` (for example `@php artisan package:discover`) and PHP subprocesses work.

Use `php` instead of `frankenphp php-cli` in your Procfile, release phase and scripts.

### PHP Configuration

Put `.ini` files in `.php-extensions/conf.d/` to change PHP settings, for example `.php-extensions/conf.d/custom.ini`:

```ini
memory_limit = 256M
upload_max_filesize = 20M
```

These settings apply to the web server, the `php` command and Composer, with the `gnu` and the `musl` binary. To change settings for the web server only, you can also use the `php_ini` option in the `frankenphp` block of your `Caddyfile`.

## Framework-Specific Setup

### Laravel

Copy `conf/Caddyfile.laravel` to your project root as `Caddyfile`. This file runs Laravel without worker mode: Laravel's `public/index.php` cannot run as a FrankenPHP worker.

For worker mode, use [Laravel Octane](https://laravel.com/docs/octane) instead:

1. Install Octane:

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
```

2. Start Octane from your `Procfile`. Octane creates its own Caddy configuration, so you do not need a `Caddyfile`:

```procfile
web: php artisan octane:frankenphp --host=0.0.0.0 --port=$PORT --admin-port=2019
```

Use `--workers` to set the number of workers (default: `auto`).

### Symfony

1. Copy `conf/Caddyfile.symfony` to your project root as `Caddyfile`. It enables worker mode.
2. Set the Symfony environment. Config vars are also available during the build, so Composer's `cache:clear` script uses them too:

```bash
heroku config:set APP_ENV=prod APP_SECRET=<a-random-secret>
```

Symfony 7.4 and later support FrankenPHP worker mode without more configuration. For older versions, install the FrankenPHP runtime and tell Symfony to use it:

```bash
composer require runtime/frankenphp-symfony
heroku config:set APP_RUNTIME='Runtime\FrankenPhpSymfony\Runtime'
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

**Note**: Worker mode requires your application to be stateless between requests, and the worker script must support worker mode. Symfony 7.4+ supports it out of the box. Laravel needs Octane (see above).

## Document Root Detection

The buildpack automatically detects your document root in this order:

1. `public/` directory (Laravel, Symfony)
2. `web/` directory (Drupal, some Symfony setups)
3. Project root (simple PHP apps)

The generated `Caddyfile` does not serve hidden files such as `.env`. When the project root is the document root, it also blocks `vendor/`, `composer.json`, `composer.lock`, `auth.json`, `Caddyfile`, `Procfile` and `php.ini`, but all other files in your project are public. Put the files for the web in `public/` to keep the other files private.

## PHP Extensions

FrankenPHP includes many common PHP extensions by default. However, if you need additional extensions, there are several options.

### Default Extensions

The standard FrankenPHP binaries (`gnu` and `musl`) include a comprehensive set of extensions:

**Databases:** `mysqli`, `mysqlnd`, `pdo`, `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `pgsql`, `sqlite3`, `dba`, `ldap`

**Caching/Queues:** `amqp`, `apcu`, `igbinary`, `memcached`, `redis`, `opcache`

**Compression:** `brotli`, `bz2`, `lz4`, `xz`, `zip`, `zlib`, `zstd`

**Text/XML:** `dom`, `lexbor`, `mbstring` (with `mbregex`), `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `xsl`, `yaml`, `tidy`

**Images:** `gd`, `imagick`, `exif`

**Crypto/Security:** `openssl`, `sodium` (and Argon2 password hashing), `ssh2`

**Other:** `ast`, `bcmath`, `calendar`, `ctype`, `curl`, `fileinfo`, `ftp`, `gettext`, `gmp`, `iconv`, `intl`, `parallel`, `pcntl`, `posix`, `protobuf`, `readline`, `shmop`, `soap`, `sockets`, `sysvmsg`, `sysvsem`, `sysvshm`, `tokenizer`, `xlswriter`

Run `php -m` to see the full list in your deployment.

### Option 1: Load Dynamic Extensions (gnu binary)

The default `gnu` binary can load dynamic PHP extensions at runtime. Add your compiled `.so` extensions to a `.php-extensions/` directory in your project:

```
your-app/
├── .php-extensions/
│   ├── xdebug.so
│   ├── myext.so
│   └── conf.d/
│       ├── xdebug.ini
│       └── custom.ini
├── composer.json
└── ...
```

The buildpack loads each `.so` file with `extension=<name>.so`. To load an extension in a different way, add an `.ini` file with the same name to `conf.d/`. For example, `conf.d/xdebug.ini` with `zend_extension=xdebug.so`.

The extensions are loaded before Composer runs, so Composer can see them.

**Important**: Extensions must be compiled with ZTS (Zend Thread Safety) enabled and match the PHP version in FrankenPHP. The `musl` binary cannot load dynamic extensions.

### Option 2: Build a Custom FrankenPHP Binary

For production use, building a custom FrankenPHP binary with your required extensions compiled in is recommended.

#### Using Docker (Recommended)

```bash
# Clone the FrankenPHP release that you want to use
git clone --branch v1.13.0 https://github.com/php/frankenphp
cd frankenphp

# Build with specific extensions for Heroku (linux/amd64)
docker buildx bake --load \
  --set '*.platform=linux/amd64' \
  --set static-builder-gnu.args.PHP_EXTENSIONS="opcache,pdo_mysql,redis,imagick,gd" \
  static-builder-gnu

# Extract the binary
docker cp $(docker create --name static-builder dunglas/frankenphp:static-builder-gnu):/go/src/app/dist/frankenphp-linux-x86_64 ./frankenphp
docker rm static-builder
```

See [support/build-custom-frankenphp.md](support/build-custom-frankenphp.md) for more options.

#### Common Extension Combinations

**Laravel/Symfony apps:**
```bash
PHP_EXTENSIONS="opcache,pdo_mysql,pdo_pgsql,redis,gd,intl,bcmath,mbstring,xml,zip,curl"
```

**WordPress:**
```bash
PHP_EXTENSIONS="opcache,mysqli,gd,imagick,intl,mbstring,xml,zip,curl,exif"
```

#### Host Your Custom Binary

1. Upload your custom binary to a public URL (GitHub Releases, S3, etc.)
2. Configure the buildpack to use it:

```bash
heroku config:set FRANKENPHP_CUSTOM_BINARY_URL="https://your-storage.com/frankenphp-custom"
```

### Option 3: Use Composer Polyfills

Some extensions have pure-PHP alternatives available via Composer:

```bash
# Instead of ext-intl
composer require symfony/polyfill-intl-icu

# Instead of ext-mbstring
composer require symfony/polyfill-mbstring

# Instead of ext-uuid
composer require ramsey/uuid
```

### Checking Available Extensions

To see which extensions are available in your deployment:

```bash
heroku run php -m
```

## Troubleshooting

### Application not starting

Check the logs:

```bash
heroku logs --tail
```

### Composer dependencies not installing

Ensure you have a valid `composer.json` and `composer.lock` file committed to your repository.

### `Command "..." is not defined`

You started a console script with `frankenphp php-cli`. With FrankenPHP v1.13.0, use the `php` command instead, for example `php bin/console` or `php artisan`.

### Extension not loading (gnu binary)

1. Verify the extension was compiled with ZTS enabled
2. Check the PHP version matches
3. Ensure the `.so` file is in `.php-extensions/`, and `FRANKENPHP_BINARY_TYPE` is not `musl`
4. Run `heroku run php --ini` to see which `.ini` files PHP loads
5. Check logs for specific error messages

### Worker mode memory issues

If your application consumes too much memory in worker mode, try:

1. Reducing the number of workers
2. Implementing proper cleanup in your application
3. Using the standard (non-worker) mode

## Local Development

Test the buildpack locally in the Heroku build image with Docker:

```bash
mkdir -p /tmp/cache /tmp/env

docker run --rm --platform linux/amd64 \
  -v "$PWD":/buildpack:ro \
  -v /path/to/your/app:/app \
  -v /tmp/cache:/cache \
  -v /tmp/env:/env \
  heroku/heroku:24-build /buildpack/bin/compile /app /cache /env
```

To set a config var for the build, create a file in `/tmp/env`, for example `echo -n musl > /tmp/env/FRANKENPHP_BINARY_TYPE`.

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
