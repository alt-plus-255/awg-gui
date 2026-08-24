package config

import (
	"os"
	"strconv"
	"strings"
)

type Config struct {
	HTTPAddr          string
	AppKey            string
	AppURL            string
	AppLocale         string
	DBHost            string
	DBPort            string
	DBName            string
	DBUser            string
	DBPassword        string
	MigrationsDir     string
	SessionCookie     string
	SessionLifetimeMin  int
	SessionSecure     bool
	SessionSameSite   string
	PanelPort         string
	PanelHTTPSPort    string
	AdminPassword     string
	Version           string
	AWGConfigDir           string
	HostAWGConfigDir       string
	AWGContainer           string
	HostAWGGUIDir          string
	HostComposeDir         string
	ServerEndpoint         string
	AWGPort                int
	InternalSubnet         string
	PeerDNS                string
	AllowedIPs             string
	PersistentKeepalive    int
	TZ                     string
	SanctumStatefulDomains string
	DockerBin              string
	WSAddr                 string
	PanelOpsURL            string
	PanelOpsToken          string
	ACMEDirectoryURL       string
	GitHubRepo             string
	AWGProxyHost           string
}

func Load() Config {
	return Config{
		HTTPAddr:         env("HTTP_ADDR", ":8000"),
		AppKey:           env("APP_KEY", ""),
		AppURL:           env("APP_URL", "http://localhost:8877"),
		AppLocale:        env("APP_LOCALE", "en"),
		DBHost:           env("DB_HOST", "127.0.0.1"),
		DBPort:           env("DB_PORT", "3306"),
		DBName:           env("DB_DATABASE", "awggui"),
		DBUser:           env("DB_USERNAME", "awggui"),
		DBPassword:       env("DB_PASSWORD", ""),
		MigrationsDir:    env("MIGRATIONS_DIR", "migrations"),
		SessionCookie:    env("SESSION_COOKIE", "laravel_session"),
		SessionLifetimeMin: envInt("SESSION_LIFETIME", 120),
		SessionSecure:    envBool("SESSION_SECURE_COOKIE", false),
		SessionSameSite:  env("SESSION_SAME_SITE", "lax"),
		PanelPort:        env("PANEL_PORT", "8877"),
		PanelHTTPSPort:   env("PANEL_HTTPS_PORT", "7443"),
		AdminPassword:    env("ADMIN_PASSWORD", ""),
		Version:          env("APP_VERSION", readVersionFile()),
		AWGConfigDir:           strings.TrimRight(env("AWG_CONFIG_DIR", "/awg"), "/"),
		HostAWGConfigDir:       strings.TrimRight(env("HOST_AWG_CONFIG_DIR", "/var/lib/docker/volumes/awggui_awg_config/_data"), "/"),
		AWGContainer:           env("AWG_CONTAINER", "awggui-awg"),
		HostAWGGUIDir:          strings.TrimRight(env("HOST_AWG_GUI_DIR", "/host-awg-gui"), "/"),
		HostComposeDir:         strings.TrimRight(env("HOST_COMPOSE_DIR", "/compose"), "/"),
		ServerEndpoint:         env("SERVER_ENDPOINT", "auto"),
		AWGPort:                envInt("AWG_PORT", 51820),
		InternalSubnet:         env("INTERNAL_SUBNET", "10.66.66.0/24"),
		PeerDNS:                env("PEER_DNS", "1.1.1.1"),
		AllowedIPs:             env("ALLOWED_IPS", "0.0.0.0/0, ::/0"),
		PersistentKeepalive:    envInt("PERSISTENT_KEEPALIVE", 25),
		TZ:                     env("TZ", "UTC"),
		SanctumStatefulDomains: env("SANCTUM_STATEFUL_DOMAINS", ""),
		DockerBin:              env("DOCKER_BIN", "docker"),
		WSAddr:                 env("WS_ADDR", ":8081"),
		PanelOpsURL:            strings.TrimRight(env("PANEL_OPS_URL", "http://panel-ops:8090"), "/"),
		PanelOpsToken:          env("PANEL_OPS_TOKEN", ""),
		ACMEDirectoryURL:       env("ACME_DIRECTORY_URL", "https://acme-v02.api.letsencrypt.org/directory"),
		GitHubRepo:             env("AWG_GUI_GITHUB_REPO", "alt-plus-255/awg-gui"),
		AWGProxyHost:           env("AWG_PROXY_HOST", "awg"),
	}
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envInt(key string, fallback int) int {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func envBool(key string, fallback bool) bool {
	v := strings.TrimSpace(strings.ToLower(os.Getenv(key)))
	if v == "" {
		return fallback
	}
	switch v {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}

func readVersionFile() string {
	for _, p := range []string{"VERSION", "/app/VERSION", "../../VERSION"} {
		b, err := os.ReadFile(p)
		if err == nil {
			v := strings.TrimSpace(string(b))
			if v != "" {
				return v
			}
		}
	}
	return "0.0.0-dev"
}
