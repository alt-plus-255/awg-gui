#!/bin/bash
cd /home/altplus255/projects/awg-gui-go
eval "$(sed -n '62,79p' src/awg/entrypoint.sh)"
r1=$(ipv4_network_cidr 10.66.66.1 24)
r2=$(ipv4_network_cidr 10.66.67.1 24)
echo "r1=$r1"
echo "r2=$r2"
if [ "$r1" = "10.66.66.0/24" ] && [ "$r2" = "10.66.67.0/24" ]; then
  echo CIDR_TESTS_PASS
  exit 0
fi
echo CIDR_TESTS_FAIL
exit 1
