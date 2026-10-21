# Building a Custom FrankenPHP Binary for Heroku

This guide explains how to build a custom FrankenPHP binary with specific PHP extensions for deployment on Heroku.

## Prerequisites

- Docker with BuildKit support
- ~10GB of disk space for the build process
- Git

## Quick Start

```bash
# Clone the FrankenPHP release that you want to use
git clone --branch v1.13.2 https://github.com/php/frankenphp
cd frankenphp

# Build the builder image
docker buildx bake --load --set '*.platform=linux/amd64' static-builder-gnu

# Build the binary with your extensions
docker run --rm --platform linux/amd64 \
  -v "$PWD/output:/output" -e OUTPUT_DIR=/output -e SPC_LOGS_DIR=/output/log \
  -e PHP_EXTENSIONS="opcache,pdo_mysql,pdo_pgsql,redis,gd,intl" \
  dunglas/frankenphp:static-builder-gnu

cp output/frankenphp-linux-x86_64 ./frankenphp
```

Heroku dynos use `linux/amd64`. The `--set '*.platform=linux/amd64'` and `--platform linux/amd64` options make sure that you get an x86_64 binary, also on an Apple Silicon Mac.

The build runs when the container starts. You give the build options (`PHP_EXTENSIONS`, `PHP_EXTENSION_LIBS`, `XCADDY_ARGS`, ...) to `docker run` with `-e`. You do not have to build the image again when you change them. If the build fails, the static-php-cli logs are in `output/log/`.

FrankenPHP v1.13.1 and earlier used Docker build args (`--set static-builder-gnu.args.PHP_EXTENSIONS=...`) and wrote the binary into the image. To build one of these releases, follow the documentation of that release.

## Available Extensions

For a full list of supported extensions, see:
https://static-php.dev/en/guide/extensions.html

The default extensions are in [`craft.yml`](https://github.com/php/frankenphp/blob/main/craft.yml).

### Common Extension Sets

#### Web Applications (Laravel, Symfony)
```
opcache,pdo_mysql,pdo_pgsql,pdo_sqlite,redis,gd,intl,bcmath,mbstring,xml,zip,curl,sodium,fileinfo
```

#### WordPress
```
opcache,mysqli,pdo_mysql,gd,imagick,intl,mbstring,xml,zip,curl,exif,fileinfo
```

#### API/Microservices
```
opcache,pdo_mysql,pdo_pgsql,redis,curl,json,mbstring,sodium
```

## Build Options

### glibc-Based (Mostly Static)
- Requires glibc 2.28+ (all Heroku stacks have it)
- Can load dynamic extensions at runtime
- Faster than musl, especially with many threads
- Best for production ([FrankenPHP performance docs](https://frankenphp.dev/docs/performance/))

```bash
docker buildx bake --load --set '*.platform=linux/amd64' static-builder-gnu
```

### musl-Based (Fully Static)
- No runtime dependencies
- Cannot load dynamic extensions
- PHP is slower with musl in thread-safe (ZTS) mode, which FrankenPHP uses

```bash
docker buildx bake --load --set '*.platform=linux/amd64' static-builder-musl
```

The commands in this guide use `static-builder-gnu`. For a musl build, use `static-builder-musl` as the target name and the image name. Also add `--ulimit nofile=8192:8192` to `docker run`: the musl build opens many files.

## Adding Extra Libraries

Some extensions need additional libraries for full functionality:

```bash
docker run --rm --platform linux/amd64 \
  -v "$PWD/output:/output" -e OUTPUT_DIR=/output -e SPC_LOGS_DIR=/output/log \
  -e PHP_EXTENSIONS=gd \
  -e PHP_EXTENSION_LIBS=libjpeg,libpng,libwebp,freetype \
  dunglas/frankenphp:static-builder-gnu
```

## Adding Caddy Modules

Include additional Caddy modules like caching:

```bash
docker run --rm --platform linux/amd64 \
  -v "$PWD/output:/output" -e OUTPUT_DIR=/output -e SPC_LOGS_DIR=/output/log \
  -e XCADDY_ARGS="--with github.com/darkweak/souin/plugins/caddy --with github.com/dunglas/caddy-cbrotli --with github.com/dunglas/mercure/caddy --with github.com/dunglas/vulcain/caddy" \
  dunglas/frankenphp:static-builder-gnu
```

The default build includes the cbrotli, Mercure and Vulcain modules. When you set `XCADDY_ARGS`, include them again (as above) if you need them.

## Hosting Your Binary

### GitHub Releases (Recommended)

1. Create a new repository or use your app's repo
2. Create a new release
3. Upload the binary as a release asset
4. Use the download URL in your Heroku config:

```bash
heroku config:set FRANKENPHP_CUSTOM_BINARY_URL="https://github.com/YOUR_USER/YOUR_REPO/releases/download/v1.0.0/frankenphp-linux-x86_64"
```

The buildpack caches the binary by its URL. To deploy a new binary, upload it with a new URL (for example, in a new release).

### Amazon S3

```bash
# Upload to S3
aws s3 cp frankenphp s3://your-bucket/frankenphp-custom --acl public-read

# Configure Heroku
heroku config:set FRANKENPHP_CUSTOM_BINARY_URL="https://your-bucket.s3.amazonaws.com/frankenphp-custom"
```

## Verifying Your Build

Test the binary locally (requires Linux or Docker):

```bash
# Check version
./frankenphp version

# List extensions
./frankenphp php-cli -r 'echo implode(PHP_EOL, get_loaded_extensions()), PHP_EOL;'

# Check specific extension
./frankenphp php-cli -r "echo extension_loaded('redis') ? 'redis loaded' : 'redis not loaded';"
```

On Heroku, the buildpack also adds a `php` command, so you can use `heroku run php -m`.

## Troubleshooting

### Build fails with memory errors
Increase Docker's memory limit to at least 4GB.

### Build fails
Read the static-php-cli logs in `output/log/`.

### Extension not available
Check if the extension is supported: https://static-php.dev/en/guide/extensions.html

### Binary too large
- Use fewer extensions
- Enable UPX compression with `-e COMPRESS=1` in `docker run` (UPX is off by default)

### Missing library functions
Add required libraries via `PHP_EXTENSION_LIBS`.

## Example: Full Build Script

```bash
#!/bin/bash
set -e

EXTENSIONS="opcache,pdo_mysql,pdo_pgsql,redis,gd,intl,bcmath,mbstring,xml,zip,curl,sodium,fileinfo"
LIBS="libjpeg,libpng,libwebp,freetype,icu"

git clone --branch v1.13.2 https://github.com/php/frankenphp
cd frankenphp

docker buildx bake --load --set '*.platform=linux/amd64' static-builder-gnu

docker run --rm --platform linux/amd64 \
  -v "$PWD/output:/output" -e OUTPUT_DIR=/output -e SPC_LOGS_DIR=/output/log \
  -e PHP_EXTENSIONS="$EXTENSIONS" \
  -e PHP_EXTENSION_LIBS="$LIBS" \
  dunglas/frankenphp:static-builder-gnu

cp output/frankenphp-linux-x86_64 ../frankenphp-custom

echo "Build complete: ../frankenphp-custom"
```
