#!/usr/bin/env bash
set -e

# Step 1: Create a temporary directory
mkdir /tmp/monorepo_tmp

# Step 2: Move subdirectories out of the deployment root
repos=("backend" "mobile")
for item in "${repos[@]}"; do
  mv "$item" /tmp/monorepo_tmp/
done

# Step 3: Promote the Laravel app into the deployment root
cp -Rf /tmp/monorepo_tmp/backend/. .

# Step 4: Clean up
rm -rf /tmp/monorepo_tmp

# Step 5: Build
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm install
npm run build
