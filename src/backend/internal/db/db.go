package db

import (
	"database/sql"
	"fmt"
	"net"

	"github.com/awggui/backend/internal/config"
	"github.com/go-sql-driver/mysql"
)

func Open(cfg config.Config) (*sql.DB, error) {
	mc := mysql.NewConfig()
	mc.User = cfg.DBUser
	mc.Passwd = cfg.DBPassword
	mc.Net = "tcp"
	mc.Addr = net.JoinHostPort(cfg.DBHost, cfg.DBPort)
	mc.DBName = cfg.DBName
	mc.ParseTime = true
	mc.Params = map[string]string{
		"charset":         "utf8mb4",
		"collation":       "utf8mb4_unicode_ci",
		"multiStatements": "true",
	}

	db, err := sql.Open("mysql", mc.FormatDSN())
	if err != nil {
		return nil, fmt.Errorf("mysql open: %w", err)
	}
	return db, nil
}
