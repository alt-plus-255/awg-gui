package system

import (
	"context"
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
	"unicode"

	"github.com/awggui/backend/internal/docker"
)

const projectContainerPrefix = "awggui-"

var projectProcessNeedles = []string{
	"awggui", "awg-gui", "amneziawg", "awg-kernel", "awg-quick", "/etc/awg-gui",
}

var diskPaths = []string{"/compose", "/host-awg-gui", "/"}
var procRoots = []string{"/host/proc", "/proc"}

type HostMetrics struct {
	Docker *docker.Runtime

	mu       sync.Mutex
	cpuSnap  *cpuSnapshot
	procSnap *procCPUSnapshot
}

type cpuSnapshot struct {
	Total float64
	Idle  float64
}

type procCPUSnapshot struct {
	SystemTotal float64
	Procs       map[int]float64
}

func NewHostMetrics(d *docker.Runtime) *HostMetrics {
	return &HostMetrics{Docker: d}
}

func (h *HostMetrics) Collect() map[string]any {
	return map[string]any{
		"cpu":            h.cpu(),
		"memory":         h.memory(),
		"disk":           h.disk(),
		"uptime_seconds": h.uptimeSeconds(),
		"loadavg":        h.loadavg(),
	}
}

func (h *HostMetrics) ProcessMonitor(sort string, limit int) map[string]any {
	if sort != "mem" {
		sort = "cpu"
	}
	if limit < 1 {
		limit = 1
	}
	if limit > 100 {
		limit = 100
	}
	ids := h.projectContainerIDs()
	procs := h.hostProcesses(limit, sort, true, ids)
	containers := []map[string]any{}
	for _, row := range h.dockerStats() {
		name, _ := row["name"].(string)
		if isProjectContainerName(name) {
			containers = append(containers, row)
		}
	}
	sortRows(containers, sort)
	return map[string]any{
		"processes":  procs,
		"containers": containers,
	}
}

func (h *HostMetrics) cpu() map[string]any {
	current := h.readCPUSnapshot()
	if current == nil {
		return map[string]any{"percent": nil}
	}
	h.mu.Lock()
	previous := h.cpuSnap
	h.cpuSnap = current
	h.mu.Unlock()
	if previous == nil {
		time.Sleep(120 * time.Millisecond)
		second := h.readCPUSnapshot()
		if second == nil {
			return map[string]any{"percent": nil}
		}
		h.mu.Lock()
		h.cpuSnap = second
		h.mu.Unlock()
		previous = current
		current = second
	}
	totalDelta := current.Total - previous.Total
	idleDelta := current.Idle - previous.Idle
	if totalDelta <= 0 {
		return map[string]any{"percent": 0.0}
	}
	usage := (1 - (idleDelta / totalDelta)) * 100
	if usage < 0 {
		usage = 0
	}
	if usage > 100 {
		usage = 100
	}
	return map[string]any{"percent": math.Round(usage*10) / 10}
}

func (h *HostMetrics) readCPUSnapshot() *cpuSnapshot {
	stat := h.readProcFile("stat")
	if stat == "" {
		return nil
	}
	for _, line := range strings.Split(stat, "\n") {
		if !strings.HasPrefix(line, "cpu ") {
			continue
		}
		parts := strings.Fields(strings.TrimSpace(line))
		if len(parts) < 5 {
			return nil
		}
		var total, idle float64
		for i, p := range parts[1:] {
			v, _ := strconv.ParseFloat(p, 64)
			total += v
			if i == 3 || i == 4 {
				idle += v
			}
		}
		return &cpuSnapshot{Total: total, Idle: idle}
	}
	return nil
}

func (h *HostMetrics) memory() map[string]any {
	empty := map[string]any{"used": nil, "total": nil, "percent": nil}
	meminfo := h.readProcFile("meminfo")
	if meminfo == "" {
		return empty
	}
	var totalKb, availableKb *int64
	for _, line := range strings.Split(meminfo, "\n") {
		if strings.HasPrefix(line, "MemTotal:") {
			n := parseMemKb(line)
			totalKb = &n
		} else if strings.HasPrefix(line, "MemAvailable:") {
			n := parseMemKb(line)
			availableKb = &n
		}
		if totalKb != nil && availableKb != nil {
			break
		}
	}
	if totalKb == nil || *totalKb == 0 || availableKb == nil {
		return empty
	}
	total := *totalKb * 1024
	used := (*totalKb - *availableKb) * 1024
	if used < 0 {
		used = 0
	}
	percent := math.Round((float64(used)/float64(total))*1000) / 10
	return map[string]any{"used": used, "total": total, "percent": percent}
}

func parseMemKb(line string) int64 {
	fields := strings.Fields(line)
	if len(fields) < 2 {
		return 0
	}
	n, _ := strconv.ParseInt(fields[1], 10, 64)
	return n
}

func (h *HostMetrics) disk() map[string]any {
	empty := map[string]any{"used": nil, "total": nil, "percent": nil}
	path := resolveDiskPath()
	if path == "" {
		return empty
	}
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil || st.Blocks == 0 {
		return empty
	}
	bsize := int64(st.Bsize)
	total := int64(st.Blocks) * bsize
	free := int64(st.Bavail) * bsize
	used := total - free
	if used < 0 {
		used = 0
	}
	percent := math.Round((float64(used)/float64(total))*1000) / 10
	return map[string]any{"used": used, "total": total, "percent": percent}
}

func (h *HostMetrics) uptimeSeconds() any {
	raw := h.readProcFile("uptime")
	if raw == "" {
		return nil
	}
	parts := strings.Fields(strings.TrimSpace(raw))
	if len(parts) == 0 {
		return nil
	}
	v, err := strconv.ParseFloat(parts[0], 64)
	if err != nil {
		return nil
	}
	n := int(math.Floor(v))
	if n < 0 {
		n = 0
	}
	return n
}

func (h *HostMetrics) loadavg() map[string]any {
	empty := map[string]any{"1": nil, "5": nil, "15": nil}
	raw := h.readProcFile("loadavg")
	if raw == "" {
		return empty
	}
	parts := strings.Fields(strings.TrimSpace(raw))
	if len(parts) < 3 {
		return empty
	}
	return map[string]any{
		"1":  round2ptr(parts[0]),
		"5":  round2ptr(parts[1]),
		"15": round2ptr(parts[2]),
	}
}

func round2ptr(s string) any {
	v, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return nil
	}
	return math.Round(v*100) / 100
}

func resolveDiskPath() string {
	for _, p := range diskPaths {
		if st, err := os.Stat(p); err == nil && st.IsDir() {
			return p
		}
	}
	return ""
}

func (h *HostMetrics) procRoot() string {
	for _, root := range procRoots {
		if _, err := os.Stat(filepath.Join(root, "stat")); err == nil {
			return root
		}
	}
	return ""
}

func (h *HostMetrics) readProcFile(relative string) string {
	root := h.procRoot()
	if root == "" {
		return ""
	}
	b, err := os.ReadFile(filepath.Join(root, relative))
	if err != nil {
		return ""
	}
	return string(b)
}

func (h *HostMetrics) dockerStats() []map[string]any {
	if h.Docker == nil {
		return nil
	}
	r := h.Docker.Stats(context.Background(), 8*time.Second)
	if !r.Successful() {
		return nil
	}
	rows := []map[string]any{}
	for _, line := range splitNL(strings.TrimSpace(r.Stdout)) {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		var data map[string]any
		if json.Unmarshal([]byte(line), &data) != nil {
			continue
		}
		name := strVal(data["Name"], data["name"])
		if name == "" {
			continue
		}
		memUsage := strVal(data["MemUsage"], data["mem_usage"])
		rows = append(rows, map[string]any{
			"name":        name,
			"used":        parseDockerMemUsage(memUsage),
			"mem_percent": parsePercent(data["MemPerc"], data["mem_perc"]),
			"cpu_percent": parsePercent(data["CPUPerc"], data["cpu_perc"]),
		})
	}
	return rows
}

func (h *HostMetrics) hostProcesses(limit int, sort string, allowResample bool, containerIDs []string) []map[string]any {
	root := h.procRoot()
	if root == "" {
		return []map[string]any{}
	}
	memTotal, _ := h.memory()["total"].(int64)
	cpuSnap := h.readCPUSnapshot()
	var systemTotal *float64
	if cpuSnap != nil {
		systemTotal = &cpuSnap.Total
	}
	h.mu.Lock()
	prev := h.procSnap
	h.mu.Unlock()
	var prevSystemTotal *float64
	prevProcs := map[int]float64{}
	if prev != nil {
		prevSystemTotal = &prev.SystemTotal
		prevProcs = prev.Procs
	}

	currentProcs := map[int]float64{}
	rows := []map[string]any{}
	entries, _ := os.ReadDir(root)
	for _, e := range entries {
		name := e.Name()
		if !isDigits(name) {
			continue
		}
		pid, _ := strconv.Atoi(name)
		statB, err := os.ReadFile(filepath.Join(root, name, "stat"))
		if err != nil {
			continue
		}
		utime, stime, comm, ok := parseProcStat(string(statB))
		if !ok {
			continue
		}
		jiffies := utime + stime
		currentProcs[pid] = jiffies
		command := readCmdline(filepath.Join(root, name, "cmdline"), comm)
		cgroup := readFile(filepath.Join(root, name, "cgroup"))
		if !isProjectRelatedProcess(command, cgroup, containerIDs) {
			continue
		}
		rssKb := readVmRSS(filepath.Join(root, name, "status"))
		used := rssKb * 1024
		var memPercent any
		if memTotal > 0 {
			memPercent = math.Round((float64(used)/float64(memTotal))*1000) / 10
		}
		cpuPercent := 0.0
		if systemTotal != nil && prevSystemTotal != nil {
			if prevJ, ok := prevProcs[pid]; ok {
				sysDelta := *systemTotal - *prevSystemTotal
				procDelta := jiffies - prevJ
				if sysDelta > 0 && procDelta >= 0 {
					cpuPercent = math.Min(100*float64(h.nproc()), math.Max(0, (procDelta/sysDelta)*100))
					cpuPercent = math.Round(cpuPercent*10) / 10
				}
			}
		}
		rows = append(rows, map[string]any{
			"pid":         pid,
			"command":     command,
			"used":        used,
			"mem_percent": memPercent,
			"cpu_percent": cpuPercent,
		})
	}
	if systemTotal != nil {
		h.mu.Lock()
		h.procSnap = &procCPUSnapshot{SystemTotal: *systemTotal, Procs: currentProcs}
		h.mu.Unlock()
	}
	if allowResample && len(prevProcs) == 0 && sort == "cpu" {
		time.Sleep(120 * time.Millisecond)
		return h.hostProcesses(limit, sort, false, containerIDs)
	}
	sortRows(rows, sort)
	if len(rows) > limit {
		rows = rows[:limit]
	}
	return rows
}

func (h *HostMetrics) projectContainerIDs() []string {
	if h.Docker == nil {
		return nil
	}
	r := h.Docker.Run(context.Background(), []string{"ps", "--filter", "name=" + projectContainerPrefix, "--format", "{{.ID}}"}, 5*time.Second, "")
	if !r.Successful() {
		return nil
	}
	var ids []string
	for _, line := range splitNL(strings.TrimSpace(r.Stdout)) {
		id := strings.TrimSpace(line)
		if id != "" {
			ids = append(ids, id)
		}
	}
	return ids
}

func isProjectContainerName(name string) bool {
	return strings.HasPrefix(strings.TrimLeft(name, "/"), projectContainerPrefix)
}

func isProjectRelatedProcess(command, cgroup string, containerIDs []string) bool {
	hay := strings.ToLower(command)
	for _, n := range projectProcessNeedles {
		if strings.Contains(hay, n) {
			return true
		}
	}
	for _, id := range containerIDs {
		if id == "" {
			continue
		}
		if strings.Contains(cgroup, id) || strings.Contains(command, id) {
			return true
		}
	}
	return false
}

func (h *HostMetrics) nproc() int {
	root := h.procRoot()
	if root == "" {
		return 1
	}
	stat := readFile(filepath.Join(root, "stat"))
	count := 0
	for _, line := range strings.Split(stat, "\n") {
		if strings.HasPrefix(line, "cpu") && len(line) > 3 && unicode.IsDigit(rune(line[3])) {
			count++
		}
	}
	if count > 0 {
		return count
	}
	return 1
}

var procStatRE = regexp.MustCompile(`(?s)^(\d+)\s+\((.*)\)\s+(\S+)\s+(.*)$`)

func parseProcStat(stat string) (utime, stime float64, comm string, ok bool) {
	m := procStatRE.FindStringSubmatch(stat)
	if m == nil {
		return 0, 0, "", false
	}
	rest := strings.Fields(strings.TrimSpace(m[4]))
	if len(rest) < 13 {
		return 0, 0, "", false
	}
	utime, _ = strconv.ParseFloat(rest[11], 64)
	stime, _ = strconv.ParseFloat(rest[12], 64)
	return utime, stime, m[2], true
}

func readVmRSS(statusPath string) int64 {
	status := readFile(statusPath)
	for _, line := range strings.Split(status, "\n") {
		if strings.HasPrefix(line, "VmRSS:") {
			fields := strings.Fields(line)
			if len(fields) >= 2 {
				n, _ := strconv.ParseInt(fields[1], 10, 64)
				return n
			}
		}
	}
	return 0
}

func readCmdline(path, fallback string) string {
	raw := readFile(path)
	if raw != "" {
		cmd := strings.TrimSpace(strings.ReplaceAll(raw, "\x00", " "))
		if cmd != "" {
			if len(cmd) > 200 {
				cmd = cmd[:200]
			}
			return cmd
		}
	}
	if fallback != "" {
		return fallback
	}
	return "?"
}

func parsePercent(vals ...any) any {
	for _, v := range vals {
		if v == nil {
			continue
		}
		switch t := v.(type) {
		case float64:
			return math.Round(t*10) / 10
		case json.Number:
			f, _ := t.Float64()
			return math.Round(f*10) / 10
		case string:
			if t == "" {
				continue
			}
			re := regexp.MustCompile(`([\d.]+)`)
			m := re.FindStringSubmatch(t)
			if m != nil {
				f, _ := strconv.ParseFloat(m[1], 64)
				return math.Round(f*10) / 10
			}
		}
	}
	return nil
}

func parseDockerMemUsage(memUsage string) int64 {
	left := strings.TrimSpace(strings.SplitN(memUsage, "/", 2)[0])
	return parseSizeToBytes(left)
}

func parseSizeToBytes(value string) int64 {
	value = strings.TrimSpace(value)
	if value == "" || value == "--" {
		return 0
	}
	re := regexp.MustCompile(`(?i)^([\d.]+)\s*([KMGTPE]?i?B?)$`)
	m := re.FindStringSubmatch(value)
	if m == nil {
		return 0
	}
	num, _ := strconv.ParseFloat(m[1], 64)
	unit := strings.ToUpper(m[2])
	if unit == "" {
		unit = "B"
	}
	factors := map[string]float64{
		"B": 1, "KB": 1e3, "MB": 1e6, "GB": 1e9, "TB": 1e12,
		"KIB": 1024, "MIB": 1024 * 1024, "GIB": 1024 * 1024 * 1024, "TIB": 1024 * 1024 * 1024 * 1024,
		"K": 1024, "M": 1024 * 1024, "G": 1024 * 1024 * 1024, "T": 1024 * 1024 * 1024 * 1024,
	}
	factor := factors[unit]
	if factor == 0 {
		factor = 1
	}
	return int64(math.Round(num * factor))
}

func sortRows(rows []map[string]any, sort string) {
	for i := 0; i < len(rows); i++ {
		for j := i + 1; j < len(rows); j++ {
			if lessRow(rows[j], rows[i], sort) {
				rows[i], rows[j] = rows[j], rows[i]
			}
		}
	}
}

func lessRow(a, b map[string]any, sort string) bool {
	if sort == "mem" {
		return numVal(a["used"]) > numVal(b["used"])
	}
	ca, cb := numVal(a["cpu_percent"]), numVal(b["cpu_percent"])
	if ca != cb {
		return ca > cb
	}
	return numVal(a["used"]) > numVal(b["used"])
}

func numVal(v any) float64 {
	switch t := v.(type) {
	case int:
		return float64(t)
	case int64:
		return float64(t)
	case float64:
		return t
	default:
		return 0
	}
}

func strVal(vals ...any) string {
	for _, v := range vals {
		if s, ok := v.(string); ok && s != "" {
			return s
		}
	}
	return ""
}

func isDigits(s string) bool {
	if s == "" {
		return false
	}
	for _, r := range s {
		if !unicode.IsDigit(r) {
			return false
		}
	}
	return true
}

func splitNL(s string) []string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	s = strings.ReplaceAll(s, "\r", "\n")
	return strings.Split(s, "\n")
}

func readFile(path string) string {
	b, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	return string(b)
}
