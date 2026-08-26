#!/bin/bash
WIN_GO="/mnt/c/Program Files/Go/bin/go.exe"
echo "=== GO SEARCH ==="
command -v go || echo "NO_GO_IN_PATH"
if [ -x "$WIN_GO" ]; then
  echo "windows_go_found=yes"
  "$WIN_GO" version
else
  echo "windows_go_found=no"
fi
echo "=== GO BUILD ==="
cd /home/altplus255/projects/awg-gui-go/src/backend
go build ./internal/diagnostics/ ./internal/resolver/
echo "go_build_exit=$?"
