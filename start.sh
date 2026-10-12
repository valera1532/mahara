#!/usr/bin/env sh
cd "$(dirname "$0")"
exec php -S localhost:8000 server/router.php
