#!/bin/sh
set -eu
# Copy only code into the container's ephemeral filesystem; avoid slow repeated
# Windows bind-mount reads, and keep host source read-only throughout execution.
mkdir -p /tmp/hherm-suite
cp -R /plugin/includes /plugin/assets /plugin/tests /plugin/tools /tmp/hherm-suite/
cp /plugin/heart-hub-event-registration-manager.php /plugin/uninstall.php /tmp/hherm-suite/
cd /tmp/hherm-suite
exec "$@"
