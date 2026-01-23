# Building a Custom FrankenPHP Binary for Heroku

This guide explains how to build a custom FrankenPHP binary with specific PHP extensions for deployment on Heroku.

## Prerequisites

- Docker with BuildKit support
- ~10GB of disk space for the build process
- Git

## Quick Start

```bash
# Clone FrankenPHP
git clone https://github.com/php/frankenphp
cd frankenphp

# Build with your extensions
docker buildx bake --load \
  --set static-builder-musl.args.PHP_EXTENSIONS="opcache,pdo_mysql,pdo_pgsql,redis,gd,intl" \
  static-builder-musl

# Extract the binary
docker cp $(docker create --name sb dunglas/frankenphp:static-builder-musl):/go/src/app/dist/frankenphp-linux-x86_64 ./frankenphp
docker rm sb
```

## Available Extensions

For a full list of supported extensions, see:
https://static-php.dev/en/guide/extensions.html

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

### musl-Based (Fully Static)
- No runtime dependencies
- Cannot load dynamic extensions
- Smaller binary size
- Best for production

```bash
docker buildx bake --load static-builder-musl
```

### glibc-Based (Mostly Static)
- Requires glibc 2.17+
- Can load dynamic extensions at runtime
- Larger binary size
- Good for development or when you need runtime extension loading

```bash
docker buildx bake --load static-builder-gnu
```

## Adding Extra Libraries

Some extensions need additional libraries for full functionality:

```bash
docker buildx bake \
  --load \
  --set static-builder-musl.args.PHP_EXTENSIONS=gd \
  --set static-builder-musl.args.PHP_EXTENSION_LIBS=libjpeg,libpng,libwebp,freetype \
  static-builder-musl
```

## Adding Caddy Modules

Include additional Caddy modules like caching or Mercure:

```bash
docker buildx bake \
  --load \
  --set static-builder-musl.args.XCADDY_ARGS="--with github.com/darkweak/souin/plugins/caddy" \
  static-builder-musl
```

## Hosting Your Binary

### GitHub Releases (Recommended)

1. Create a new repository or use your app's repo
2. Create a new release
3. Upload the binary as a release asset
4. Use the download URL in your Heroku config:

```bash
heroku config:set FRANKENPHP_CUSTOM_BINARY_URL="https://github.com/YOUR_USER/YOUR_REPO/releases/download/v1.0.0/frankenphp-linux-x86_64"
```

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
./frankenphp php-cli -m

# Check specific extension
./frankenphp php-cli -r "echo extension_loaded('redis') ? 'redis loaded' : 'redis not loaded';"
```

## Troubleshooting

### Build fails with memory errors
Increase Docker's memory limit to at least 4GB.

### Extension not available
Check if the extension is supported: https://static-php.dev/en/guide/extensions.html

### Binary too large
- Use fewer extensions
- Enable UPX compression (default)
- Use musl instead of glibc

### Missing library functions
Add required libraries via `PHP_EXTENSION_LIBS`.

## Example: Full Build Script

```bash
#!/bin/bash
set -e

EXTENSIONS="opcache,pdo_mysql,pdo_pgsql,redis,gd,intl,bcmath,mbstring,xml,zip,curl,sodium,fileinfo"
LIBS="libjpeg,libpng,libwebp,freetype,icu"

git clone https://github.com/php/frankenphp
cd frankenphp

docker buildx bake --load \
  --set static-builder-musl.args.PHP_EXTENSIONS="$EXTENSIONS" \
  --set static-builder-musl.args.PHP_EXTENSION_LIBS="$LIBS" \
  static-builder-musl

docker cp $(docker create --name sb dunglas/frankenphp:static-builder-musl):/go/src/app/dist/frankenphp-linux-x86_64 ../frankenphp-custom
docker rm sb

echo "Build complete: ../frankenphp-custom"
../frankenphp-custom php-cli -m
```
