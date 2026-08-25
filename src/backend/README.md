# awggui backend (Go)

Replaces the former Laravel API. Binaries:

- `awggui` — HTTP API (`:8000`), WebSocket stats (`:8081`), scheduler, Telegram poller
- `awgctl` — CLI: `migrate`, `ping`, `bootstrap`, `admin ensure|reset-password|2fa-status|disable-2fa`, `set-endpoint`, `panel info`, `version`

Build:

```bash
go build -o awggui ./cmd/awggui
go build -o awgctl ./cmd/awgctl
```

Container entrypoint waits for MariaDB, runs migrations, bootstraps admin/AWG defaults, then starts `awggui`. Health: `GET /up`.
