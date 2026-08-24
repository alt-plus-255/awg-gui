package docker

import (
	"bytes"
	"context"
	"os/exec"
	"strings"
	"time"
)

type Result struct {
	ExitCode int
	Stdout   string
	Stderr   string
}

func (r Result) Successful() bool { return r.ExitCode == 0 }
func (r Result) Output() string   { return r.Stdout }
func (r Result) ErrorOutput() string {
	return r.Stderr
}

type Runtime struct {
	Bin string
}

func New() *Runtime {
	return &Runtime{Bin: "docker"}
}

func NewWithBin(bin string) *Runtime {
	if bin == "" {
		bin = "docker"
	}
	return &Runtime{Bin: bin}
}

func (rt *Runtime) Run(ctx context.Context, args []string, timeout time.Duration, input string) Result {
	if timeout <= 0 {
		timeout = 60 * time.Second
	}
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	cmd := exec.CommandContext(cctx, rt.bin(), args...)
	if input != "" {
		cmd.Stdin = strings.NewReader(input)
	}
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	err := cmd.Run()
	res := Result{Stdout: stdout.String(), Stderr: stderr.String(), ExitCode: 0}
	if err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			res.ExitCode = ee.ExitCode()
		} else {
			res.ExitCode = 1
			if res.Stderr == "" {
				res.Stderr = err.Error()
			}
		}
	}
	return res
}

func (rt *Runtime) ContainerRunning(ctx context.Context, name string) bool {
	r := rt.Run(ctx, []string{"inspect", "-f", "{{.State.Running}}", name}, 10*time.Second, "")
	return r.Successful() && strings.TrimSpace(r.Stdout) == "true"
}

func (rt *Runtime) Exec(ctx context.Context, container string, command []string, timeout time.Duration, input string) Result {
	args := append([]string{"exec", container}, command...)
	return rt.Run(ctx, args, timeout, input)
}

func (rt *Runtime) ExecInteractive(ctx context.Context, container string, command []string, timeout time.Duration, input string) Result {
	args := append([]string{"exec", "-i", container}, command...)
	return rt.Run(ctx, args, timeout, input)
}

func (rt *Runtime) ExecDetached(ctx context.Context, container string, command []string) Result {
	args := append([]string{"exec", "-d", container}, command...)
	return rt.Run(ctx, args, 15*time.Second, "")
}

func (rt *Runtime) Restart(ctx context.Context, container string, timeout time.Duration) Result {
	return rt.Run(ctx, []string{"restart", container}, timeout, "")
}

// Start runs docker args without waiting (Laravel DockerRuntime::start).
func (rt *Runtime) Start(args []string) error {
	cmd := exec.Command(rt.bin(), args...)
	return cmd.Start()
}

func (rt *Runtime) Logs(ctx context.Context, container string, tail int, timeout time.Duration) Result {
	return rt.Run(ctx, []string{"logs", "--tail", itoa(tail), container}, timeout, "")
}

func (rt *Runtime) Stats(ctx context.Context, timeout time.Duration) Result {
	return rt.Run(ctx, []string{"stats", "--no-stream", "--format", "{{json .}}"}, timeout, "")
}

func (rt *Runtime) bin() string {
	if rt != nil && rt.Bin != "" {
		return rt.Bin
	}
	return "docker"
}

func itoa(n int) string {
	if n < 0 {
		n = 0
	}
	s := make([]byte, 0, 6)
	if n == 0 {
		return "0"
	}
	var buf [12]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	return string(append(s, buf[i:]...))
}
