#!/bin/bash
set -e

# Ensure files directory exists
mkdir -p "${FILES_LOCATION:-/var/files}/home"
chown -R www-data:www-data "${FILES_LOCATION:-/var/files}"

# Start Apache
exec apache2-foreground
