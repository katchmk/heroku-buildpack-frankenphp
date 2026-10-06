#!/usr/bin/env bash
# Utility functions for the FrankenPHP buildpack

# Build output functions
indent() {
    sed -u 's/^/       /'
}

puts_step() {
    echo "-----> $*"
}

puts_warn() {
    echo " !     $*" >&2
}

puts_error() {
    echo " !     ERROR: $*" >&2
}

# Export environment variables from env dir
export_env_dir() {
    local env_dir=$1
    local allowlist_regex=${2:-''}
    local denylist_regex=${3:-'^(PATH|GIT_DIR|CPATH|CPPATH|LD_PRELOAD|LIBRARY_PATH)$'}

    if [[ -d "$env_dir" ]]; then
        local file e
        for file in "$env_dir"/*; do
            [[ -f "$file" ]] || continue
            e=$(basename "$file")
            echo "$e" | grep -qE "$denylist_regex" && continue
            if [[ -z "$allowlist_regex" ]] || echo "$e" | grep -qE "$allowlist_regex"; then
                export "$e=$(cat "$file")"
            fi
        done
    fi
}

# Download a URL to a file. Fails on HTTP errors and retries transient errors.
download() {
    local url=$1
    local output=$2

    curl --fail --silent --show-error --location \
        --retry 3 --retry-connrefused --connect-timeout 10 \
        "$url" --output "$output"
}

# Print the SHA-256 checksum of a file
sha256_of() {
    sha256sum "$1" | cut -d' ' -f1
}
