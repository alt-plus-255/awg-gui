#!/bin/bash
echo '======== CMD1 awggui-awg tools ========'
docker exec awggui-awg sh -c 'which jq sed awk node php python python3 2>/dev/null; ls /usr/bin/jq /usr/bin/sed 2>/dev/null; command -v jq; command -v sed'
echo '======== CMD2 awggui-app tools ========'
docker exec awggui-app sh -c 'which awggui awgctl curl; awgctl version; curl -fsS http://127.0.0.1:8000/up'
echo '======== CMD3 sniff_override ========'
docker exec awggui-awg sh -c 'grep -n sniff_override /config/sing-box.json | head -20'
echo '======== DONE ========'
