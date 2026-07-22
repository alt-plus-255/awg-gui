#!/bin/sh
set -eu

rm -f /challenge/exit_code

exec certbot "$@"
status=$?

echo "${status}" > /challenge/exit_code
exit "${status}"
