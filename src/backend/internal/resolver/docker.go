package resolver

import (
	"bytes"
	"context"
	"os/exec"
	"strings"
	"time"
)

type ExecResult struct {
	Stdout   string
	Stderr   string
	ExitCode int
}

func (r ExecResult) Successful() bool { return r.ExitCode == 0 }
func (r ExecResult) Output() string   { return r.Stdout }
func (r ExecResult) ErrorOutput() string {
	return r.Stderr
}

type Docker interface {
	Exec(ctx context.Context, container string, command []string, timeout time.Duration) (ExecResult, error)
	Run(ctx context.Context, args []string, timeout time.Duration) (ExecResult, error)
	ContainerRunning(ctx context.Context, name string) bool
}

type CLIDocker struct {
	Bin string
}

func (d CLIDocker) bin() string {
	if d.Bin == "" {
		return "docker"
	}
	return d.Bin
}

func (d CLIDocker) Run(ctx context.Context, args []string, timeout time.Duration) (ExecResult, error) {
	if timeout > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, timeout)
		defer cancel()
	}
	cmd := exec.CommandContext(ctx, d.bin(), args...)
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	err := cmd.Run()
	res := ExecResult{Stdout: stdout.String(), Stderr: stderr.String()}
	if err != nil {
		if ee, ok := err.(*exec.ExitError); ok {
			res.ExitCode = ee.ExitCode()
			return res, nil
		}
		res.ExitCode = -1
		return res, err
	}
	return res, nil
}

func (d CLIDocker) Exec(ctx context.Context, container string, command []string, timeout time.Duration) (ExecResult, error) {
	args := append([]string{"exec", container}, command...)
	return d.Run(ctx, args, timeout)
}

func (d CLIDocker) ContainerRunning(ctx context.Context, name string) bool {
	r, err := d.Run(ctx, []string{"inspect", "-f", "{{.State.Running}}", name}, 8*time.Second)
	if err != nil {
		return false
	}
	return r.Successful() && strings.TrimSpace(r.Stdout) == "true"
}
