#!/bin/sh
# Wrapper that runs PHP setup then execs Apache

# Run PHP setup script
php /custom-entrypoint.php

# Replace this shell process with Apache
exec /usr/sbin/httpd -D FOREGROUND
