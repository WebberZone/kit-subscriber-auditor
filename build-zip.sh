#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    echo "Usage: ./build-zip.sh VERSION" >&2
    echo "Example: ./build-zip.sh 1.0.0" >&2
    exit 64
fi

if [[ ! "$VERSION" =~ ^[[:alnum:]][[:alnum:]_.-]*$ ]]; then
    echo "Version must contain only letters, numbers, dots, underscores, and hyphens." >&2
    exit 64
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "The working tree must be clean before building a release ZIP." >&2
    exit 1
fi

if [[ -n "$(git ls-files --others --exclude-standard)" ]]; then
    echo "Untracked files are present. Commit or remove them before building a release ZIP." >&2
    exit 1
fi

SOURCE_REF="${SOURCE_REF:-HEAD}"
if ! git rev-parse --verify "${SOURCE_REF}^{commit}" >/dev/null 2>&1; then
    echo "Unable to resolve source ref: $SOURCE_REF" >&2
    exit 1
fi

PACKAGE_NAME="kit-subscriber-auditor"
OUTPUT_DIR="$ROOT_DIR/build"
OUTPUT_PATH="$OUTPUT_DIR/${PACKAGE_NAME}-${VERSION}.zip"
mkdir -p "$OUTPUT_DIR"
rm -f "$OUTPUT_PATH"

git archive \
    --format=zip \
    --prefix="${PACKAGE_NAME}/" \
    --output="$OUTPUT_PATH" \
    "$SOURCE_REF"

if ! unzip -t "$OUTPUT_PATH" >/dev/null; then
    echo "The generated ZIP failed validation." >&2
    rm -f "$OUTPUT_PATH"
    exit 1
fi

echo "Distribution ZIP created: $OUTPUT_PATH"
unzip -l "$OUTPUT_PATH" | tail -n 1
