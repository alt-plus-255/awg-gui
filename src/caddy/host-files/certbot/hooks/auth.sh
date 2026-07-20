#!/bin/sh
set -eu
printf '%s' "${CERTBOT_DOMAIN}" > /challenge/domain
printf '%s' "${CERTBOT_VALIDATION}" > /challenge/validation
rm -f /challenge/done /challenge/abort /challenge/failed
touch /challenge/ready
i=0
while [ ! -f /challenge/done ]; do
	if [ -f /challenge/abort ]; then
		echo "DNS challenge aborted by user" >&2
		exit 1
	fi
	sleep 2
	i=$((i + 2))
	if [ "$i" -ge 1800 ]; then
		echo "Timeout waiting for DNS TXT confirmation" >&2
		exit 1
	fi
done
