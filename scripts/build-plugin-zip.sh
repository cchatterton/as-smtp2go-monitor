#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
slug=as-smtp2go-monitor
mkdir -p dist
rm -rf "dist/$slug" "dist/$slug.zip" "$slug.zip"
cp -R "$slug" "dist/$slug"
find "dist/$slug" -name .DS_Store -delete
( cd dist && zip -qr "$slug.zip" "$slug" )
cp "dist/$slug.zip" "$slug.zip"
