package ops

import (
	"strings"
	"time"
)

// CollectHostDebug gathers read-only host kernel debug output for support bundles.
func CollectHostDebug() map[string]any {
	out := map[string]any{
		"ok": true,
	}
	sections := map[string]string{}

	hostScript := `
set +e
echo "===== uname ====="
uname -a 2>/dev/null
echo "===== lsmod amneziawg ====="
lsmod 2>/dev/null | awk '$1=="amneziawg"{print; exit} END{if(NR==0) print "(not loaded)"}'
echo "===== modinfo amneziawg ====="
modinfo amneziawg 2>/dev/null || echo "(modinfo failed)"
echo "===== blacklist-amneziawg.conf ====="
if [ -f /etc/modprobe.d/blacklist-amneziawg.conf ]; then
  echo "(present)"
  cat /etc/modprobe.d/blacklist-amneziawg.conf 2>/dev/null
else
  echo "(absent)"
fi
echo "===== dmesg amneziawg (last 200) ====="
dmesg 2>/dev/null | grep -i amneziawg | tail -200 || echo "(dmesg unavailable or empty)"
`
	stdout, stderr, err := RunNsenterBashCapture(90*time.Second, hostScript)
	combined := strings.TrimSpace(stdout)
	if combined == "" && stderr != "" {
		combined = strings.TrimSpace(stderr)
	}
	if err != nil && combined == "" {
		combined = err.Error()
	}
	sections["host"] = combined
	out["sections"] = sections
	return out
}
