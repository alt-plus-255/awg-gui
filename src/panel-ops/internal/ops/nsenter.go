package ops

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os/exec"
	"time"
)

const alpineImage = "alpine:3.20"

// RunNsenterBash runs a fixed host bash -lc script via privileged alpine + nsenter.
func RunNsenterBash(ctx context.Context, hostBashLC string, stdout, stderr io.Writer) error {
	helper := "set -eu; apk add --no-cache util-linux >/dev/null; " +
		"nsenter -t 1 -m -u -i -n -p -- /bin/bash -lc " + ShellQuote(hostBashLC)

	cmd := exec.CommandContext(ctx,
		"docker", "run", "--rm",
		"--privileged", "--pid=host", "--network", "host",
		alpineImage, "sh", "-lc", helper,
	)
	if stdout != nil {
		cmd.Stdout = stdout
	}
	if stderr != nil {
		cmd.Stderr = stderr
	}
	return cmd.Run()
}

func RunNsenterBashCapture(timeout time.Duration, hostBashLC string) (stdout, stderr string, err error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()

	var outBuf, errBuf bytes.Buffer
	err = RunNsenterBash(ctx, hostBashLC, &outBuf, &errBuf)
	stdout = outBuf.String()
	stderr = errBuf.String()
	if ctx.Err() == context.DeadlineExceeded {
		return stdout, stderr, fmt.Errorf("nsenter helper timed out after %s", timeout)
	}
	return stdout, stderr, err
}
