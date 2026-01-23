#!/usr/bin/env bash
# Utility functions for the FrankenPHP buildpack

# Output formatting
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Log functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $*"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $*" >&2
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*" >&2
}

# Check if a command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Download a file with retry logic
download_with_retry() {
    local url=$1
    local output=$2
    local max_attempts=${3:-3}
    local attempt=1

    while [[ $attempt -le $max_attempts ]]; do
        if curl -sL "$url" -o "$output"; then
            return 0
        fi
        log_warn "Download attempt $attempt failed, retrying..."
        ((attempt++))
        sleep 2
    done

    log_error "Failed to download after $max_attempts attempts"
    return 1
}

# Calculate checksum of a file
file_checksum() {
    local file=$1
    if command_exists sha256sum; then
        sha256sum "$file" | cut -d' ' -f1
    elif command_exists shasum; then
        shasum -a 256 "$file" | cut -d' ' -f1
    else
        md5sum "$file" | cut -d' ' -f1
    fi
}

# Get JSON value from string (basic parsing)
json_value() {
    local json=$1
    local key=$2
    echo "$json" | grep -o "\"$key\": *\"[^\"]*\"" | head -1 | sed 's/.*: *"\([^"]*\)"/\1/'
}
