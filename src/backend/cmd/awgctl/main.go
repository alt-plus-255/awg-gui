package main

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"strconv"
	"strings"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/db"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/migrate"
	"github.com/awggui/backend/internal/settings"
	"github.com/awggui/backend/internal/store"
)

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	cfg := config.Load()
	cmd := os.Args[1]

	switch cmd {
	case "version":
		fmt.Println(cfg.Version)
	case "ping":
		sqlDB, err := openDB(cfg)
		if err != nil {
			fail(err)
		}
		defer sqlDB.Close()
		if err := sqlDB.Ping(); err != nil {
			fail(err)
		}
		fmt.Println("ok")
	case "migrate":
		sqlDB, err := openDB(cfg)
		if err != nil {
			fail(err)
		}
		defer sqlDB.Close()
		if err := migrate.Up(sqlDB, cfg.MigrationsDir); err != nil {
			fail(err)
		}
		fmt.Println("migrate: ok")
	case "bootstrap":
		if err := bootstrap(cfg); err != nil {
			fail(err)
		}
	case "admin":
		if len(os.Args) < 3 {
			fmt.Fprintln(os.Stderr, "usage: awgctl admin <ensure|reset-password|2fa-status|disable-2fa> [flags]")
			os.Exit(2)
		}
		switch os.Args[2] {
		case "ensure":
			if err := adminEnsure(cfg, os.Args[3:]); err != nil {
				fail(err)
			}
		case "reset-password":
			if err := adminResetPassword(cfg, os.Args[3:]); err != nil {
				fail(err)
			}
		case "2fa-status":
			if err := adminTwoFactorStatus(cfg, os.Args[3:]); err != nil {
				fail(err)
			}
		case "disable-2fa":
			if err := adminDisable2FA(cfg, os.Args[3:]); err != nil {
				fail(err)
			}
		default:
			fmt.Fprintf(os.Stderr, "unknown admin command: %s\n", os.Args[2])
			os.Exit(2)
		}
	case "set-endpoint":
		if err := setEndpoint(cfg, os.Args[2:]); err != nil {
			fail(err)
		}
	case "panel":
		if len(os.Args) < 3 || os.Args[2] != "info" {
			fmt.Fprintln(os.Stderr, "usage: awgctl panel info [--json]")
			os.Exit(2)
		}
		if err := panelInfo(cfg, os.Args[3:]); err != nil {
			fail(err)
		}
	default:
		fmt.Fprintf(os.Stderr, "unknown command: %s\n", cmd)
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "usage: awgctl <migrate|ping|bootstrap|admin|set-endpoint|panel info|version>")
}

func openDB(cfg config.Config) (*sql.DB, error) {
	return db.Open(cfg)
}

func fail(err error) {
	fmt.Fprintln(os.Stderr, err)
	os.Exit(1)
}

func newAWG(cfg config.Config, sqlDB *sql.DB) *awg.Service {
	return awg.New(
		cfg,
		docker.NewWithBin(cfg.DockerBin),
		settings.New(sqlDB),
		store.NewCache(sqlDB),
		store.NewConfigs(sqlDB),
		store.NewPeers(sqlDB),
		store.NewClients(sqlDB),
		store.NewHandshakes(sqlDB),
	)
}

func adminEnsure(cfg config.Config, args []string) error {
	fs := flag.NewFlagSet("admin ensure", flag.ContinueOnError)
	username := fs.String("username", "admin", "Admin username")
	email := fs.String("email", "admin@localhost", "Admin email")
	password := fs.String("password", "", "Admin password (or ADMIN_PASSWORD)")
	forcePassword := fs.Bool("force-password", false, "Overwrite password for existing admin")
	if err := fs.Parse(args); err != nil {
		return err
	}
	pass := *password
	if pass == "" {
		pass = cfg.AdminPassword
	}

	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()

	ctx := context.Background()
	var (
		id       int64
		hash     string
		name     string
		existing bool
	)
	err = sqlDB.QueryRowContext(ctx, `
SELECT id, password, name FROM users WHERE username = ? OR email = ? LIMIT 1`,
		*username, *email).Scan(&id, &hash, &name)
	switch {
	case err == sql.ErrNoRows:
		existing = false
	case err != nil:
		return err
	default:
		existing = true
	}

	protection := auth.NewLoginProtectionService(sqlDB)

	if !existing {
		if pass == "" {
			return fmt.Errorf("password required via --password or ADMIN_PASSWORD env")
		}
		hashed, err := auth.HashPassword(pass)
		if err != nil {
			return err
		}
		_, err = sqlDB.ExecContext(ctx, `
INSERT INTO users (username, name, email, password, created_at, updated_at)
VALUES (?, ?, ?, ?, NOW(), NOW())`, *username, *username, *email, hashed)
		if err != nil {
			return err
		}
		_ = protection.ClearAll(ctx)
		fmt.Printf("Admin user '%s' created.\n", *username)
		return nil
	}

	if !*forcePassword {
		_, err = sqlDB.ExecContext(ctx, `
UPDATE users SET username = ?, email = ?, name = COALESCE(NULLIF(name, ''), ?), updated_at = NOW()
WHERE id = ?`, *username, *email, *username, id)
		if err != nil {
			return err
		}
		fmt.Printf("Admin user '%s' already exists (password preserved).\n", *username)
		return nil
	}

	if pass == "" {
		return fmt.Errorf("password required via --password or ADMIN_PASSWORD env")
	}
	passwordChanged := !auth.CheckPassword(hash, pass)
	hashed, err := auth.HashPassword(pass)
	if err != nil {
		return err
	}
	_, err = sqlDB.ExecContext(ctx, `
UPDATE users SET username = ?, email = ?, password = ?, updated_at = NOW() WHERE id = ?`,
		*username, *email, hashed, id)
	if err != nil {
		return err
	}
	if passwordChanged {
		_ = protection.ClearAll(ctx)
	}
	fmt.Printf("Admin user '%s' password updated.\n", *username)
	return nil
}

func adminResetPassword(cfg config.Config, args []string) error {
	fs := flag.NewFlagSet("admin reset-password", flag.ContinueOnError)
	username := fs.String("username", "admin", "Admin username")
	password := fs.String("password", "", "Set this password")
	random := fs.Bool("random", false, "Generate a random password (default when --password is omitted)")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if *random && strings.TrimSpace(*password) != "" {
		return fmt.Errorf("use either --password or --random, not both")
	}

	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()

	ctx := context.Background()
	users := auth.NewUserStore(sqlDB)
	user, err := users.FindByLogin(ctx, *username)
	if err != nil {
		if err == sql.ErrNoRows {
			return fmt.Errorf("user '%s' not found", *username)
		}
		return err
	}

	pass := strings.TrimSpace(*password)
	if pass == "" {
		generated, err := randomPassword(20)
		if err != nil {
			return err
		}
		pass = generated
	}
	hashed, err := auth.HashPassword(pass)
	if err != nil {
		return err
	}
	_, err = sqlDB.ExecContext(ctx, `UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?`, hashed, user.ID)
	if err != nil {
		return err
	}
	_ = auth.NewLoginProtectionService(sqlDB).ClearAll(ctx)
	fmt.Printf("Password updated for '%s'.\n", *username)
	fmt.Printf("New password: %s\n", pass)
	fmt.Println("Login attempt counters and IP lockouts cleared.")
	return nil
}

func adminTwoFactorStatus(cfg config.Config, args []string) error {
	fs := flag.NewFlagSet("admin 2fa-status", flag.ContinueOnError)
	username := fs.String("username", "admin", "Admin username")
	if err := fs.Parse(args); err != nil {
		return err
	}
	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()
	twoFactor, user, err := loadUserTwoFactor(cfg, sqlDB, *username)
	if err != nil {
		return err
	}
	if twoFactor.IsEnabled(user) {
		fmt.Printf("2FA: enabled (%s)\n", *username)
	} else if user.TwoFactorSecret.Valid && user.TwoFactorSecret.String != "" {
		fmt.Printf("2FA: pending setup (%s)\n", *username)
	} else {
		fmt.Printf("2FA: disabled (%s)\n", *username)
	}
	return nil
}

func adminDisable2FA(cfg config.Config, args []string) error {
	fs := flag.NewFlagSet("admin disable-2fa", flag.ContinueOnError)
	username := fs.String("username", "admin", "Admin username")
	if err := fs.Parse(args); err != nil {
		return err
	}
	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()
	twoFactor, user, err := loadUserTwoFactor(cfg, sqlDB, *username)
	if err != nil {
		return err
	}
	if !twoFactor.IsEnabled(user) && !(user.TwoFactorSecret.Valid && user.TwoFactorSecret.String != "") {
		fmt.Printf("2FA is already disabled for '%s'.\n", *username)
		return nil
	}
	if err := twoFactor.Disable(context.Background(), user); err != nil {
		return err
	}
	fmt.Printf("2FA disabled for '%s'.\n", *username)
	return nil
}

func loadUserTwoFactor(cfg config.Config, sqlDB *sql.DB, username string) (*auth.TwoFactorService, *auth.User, error) {
	users := auth.NewUserStore(sqlDB)
	user, err := users.FindByLogin(context.Background(), username)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil, nil, fmt.Errorf("user '%s' not found", username)
		}
		return nil, nil, err
	}
	sessions, err := auth.NewManager(sqlDB, cfg)
	if err != nil {
		return nil, nil, err
	}
	return auth.NewTwoFactorService(users, sessions.Key()), user, nil
}

func setEndpoint(cfg config.Config, args []string) error {
	fs := flag.NewFlagSet("set-endpoint", flag.ContinueOnError)
	show := fs.Bool("show", false, "Display current public endpoint and AWG port")
	endpoint := fs.String("endpoint", "", "Public IP or hostname (use \"auto\" for auto-detect)")
	portStr := fs.String("port", "", "AmneziaWG UDP listen port (51820-51839)")
	noRestart := fs.Bool("no-restart", false, "Do not restart AWG after port change")
	if err := fs.Parse(args); err != nil {
		return err
	}
	endpointSet, portSet := false, false
	fs.Visit(func(f *flag.Flag) {
		switch f.Name {
		case "endpoint":
			endpointSet = true
		case "port":
			portSet = true
		}
	})

	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()
	svc := newAWG(cfg, sqlDB)
	ctx := context.Background()

	if *show || (!endpointSet && !portSet) {
		status, err := svc.EndpointStatus(ctx, "")
		if err != nil {
			return err
		}
		printEndpointStatus(status, false)
		return nil
	}

	var endpointPtr *string
	if endpointSet {
		ep := *endpoint
		endpointPtr = &ep
	}
	var portPtr *int
	if portSet {
		if strings.TrimSpace(*portStr) != "" {
			n, err := strconv.Atoi(strings.TrimSpace(*portStr))
			if err != nil {
				return fmt.Errorf("invalid port")
			}
			portPtr = &n
		}
	}
	status, err := svc.UpdateServerEndpoint(ctx, endpointPtr, portPtr, !*noRestart)
	if err != nil {
		return err
	}
	fmt.Println("Endpoint updated.")
	printEndpointStatus(status, true)
	return nil
}

func printEndpointStatus(status map[string]any, afterUpdate bool) {
	fmt.Printf("server_endpoint=%v\n", status["server_endpoint"])
	fmt.Printf("display_endpoint=%v\n", status["display_endpoint"])
	if !afterUpdate {
		fmt.Printf("awg_port=%v\n", status["awg_port"])
		listen := status["listen_port"]
		if listen == nil {
			fmt.Println("listen_port=")
		} else {
			fmt.Printf("listen_port=%v\n", listen)
		}
	}
	fmt.Printf("endpoint=%v\n", status["endpoint"])
	if afterUpdate {
		if restarted, _ := status["restarted"].(bool); restarted {
			fmt.Println("restarted=true")
		}
	}
}

func panelInfo(cfg config.Config, args []string) error {
	fs := flag.NewFlagSet("panel info", flag.ContinueOnError)
	asJSON := fs.Bool("json", false, "Output machine-readable JSON")
	if err := fs.Parse(args); err != nil {
		return err
	}
	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()
	ctx := context.Background()
	svc := newAWG(cfg, sqlDB)
	st := settings.New(sqlDB)
	users := auth.NewUserStore(sqlDB)
	username, _ := users.FirstUsername(ctx)
	if username == "" {
		username = "admin"
	}
	info := map[string]any{
		"host":        svc.ResolvePanelHost(ctx, ""),
		"port":        st.GetValue(ctx, "panel_port", cfg.PanelPort),
		"https_port":  svc.ResolvePanelHTTPSPort(ctx),
		"panel_url":   svc.ResolvePanelURL(ctx, ""),
		"ssl_enabled": settings.AsBool(st.GetValue(ctx, "ssl_enabled", "0")),
		"username":    username,
	}
	if *asJSON {
		enc := json.NewEncoder(os.Stdout)
		enc.SetEscapeHTML(false)
		return enc.Encode(info)
	}
	port := info["port"]
	if info["ssl_enabled"].(bool) {
		port = info["https_port"]
	}
	fmt.Println("Panel access info:")
	fmt.Printf("  URL:      %v\n", info["panel_url"])
	fmt.Printf("  Host:     %v\n", info["host"])
	fmt.Printf("  Port:     %v\n", port)
	fmt.Printf("  Login:    %v\n", info["username"])
	return nil
}

func randomPassword(n int) (string, error) {
	const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_"
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	out := make([]byte, n)
	for i := range b {
		out[i] = alphabet[int(b[i])%len(alphabet)]
	}
	return string(out), nil
}

func bootstrap(cfg config.Config) error {
	if err := adminEnsure(cfg, nil); err != nil {
		return err
	}

	sqlDB, err := openDB(cfg)
	if err != nil {
		return err
	}
	defer sqlDB.Close()

	svc := newAWG(cfg, sqlDB)
	ctx := context.Background()
	if _, err := svc.EnsureDBDefaults(ctx); err != nil {
		return err
	}
	if err := svc.BootstrapRuntime(ctx); err != nil {
		return err
	}
	fmt.Println("AmneziaWG defaults ensured and config written.")
	return nil
}
