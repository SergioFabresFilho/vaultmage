#!/usr/bin/env bash
set -e

# Promote ./backend to repo root for Laravel Cloud deployment
echo "Promoting ./backend to root..."

# Move all backend contents to root (excluding already-copied composer files)
cp -rn backend/. .

# Remove the now-redundant backend directory
rm -rf backend/ mobile/

echo "Done. Running Laravel build..."
