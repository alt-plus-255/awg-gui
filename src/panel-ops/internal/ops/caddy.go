package ops

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"
)

func RecreateCaddy() map[string]any {
	project := Env("COMPOSE_PROJECT", "awggui")
	composeFile := Env("COMPOSE_FILE", "/compose/docker-compose.yml")
	envFile := Env("COMPOSE_ENV_FILE", "/compose/.env")

	if _, err := os.Stat(composeFile); err != nil {
		return map[string]any{"ok": false, "error": "Compose file not found: " + composeFile}
	}
	if _, err := os.Stat(envFile); err != nil {
		return map[string]any{"ok": false, "error": "Env file not found: " + envFile}
	}

	ctx, cancel := context.WithTimeout(context.Background(), 180*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx,
		"docker", "compose",
		"-p", project,
		"--env-file", envFile,
		"-f", composeFile,
		"up", "-d", "--force-recreate", "--no-deps", "caddy",
	)
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	err := cmd.Run()
	if ctx.Err() == context.DeadlineExceeded {
		return map[string]any{"ok": false, "error": "docker compose timed out after 180s"}
	}
	if err != nil {
		msg := strings.TrimSpace(stderr.String())
		if msg == "" {
			msg = strings.TrimSpace(stdout.String())
		}
		if msg == "" {
			msg = fmt.Sprintf("docker compose exited with error: %v", err)
		}
		return map[string]any{"ok": false, "error": msg}
	}
	return map[string]any{"ok": true}
}
