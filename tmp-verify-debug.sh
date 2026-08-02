#!/bin/bash
set -euo pipefail
cp /home/altplus255/projects/awg-gui/src/scripts/lib/install-i18n.sh /home/altplus255/projects/awg-gui/dist/install-i18n.sh
grep -nE 'usage_opt_debug|warn_debug_hint|^DEBUG=|--debug' \
  /home/altplus255/projects/awg-gui/src/scripts/release/bundle-install.sh \
  /home/altplus255/projects/awg-gui/src/scripts/release/run-header.sh \
  /home/altplus255/projects/awg-gui/dist/install.sh \
  /home/altplus255/projects/awg-gui/dist/install-i18n.sh \
  /home/altplus255/projects/awg-gui/src/scripts/lib/install-i18n.sh
# smoke: unknown without --debug shouldn't die on parse
bash -n /home/altplus255/projects/awg-gui/src/scripts/release/bundle-install.sh
bash -n /home/altplus255/projects/awg-gui/src/scripts/release/run-header.sh
bash -n /home/altplus255/projects/awg-gui/dist/install.sh
echo syntax_ok
