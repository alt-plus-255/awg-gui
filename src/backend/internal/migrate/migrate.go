package migrate

import (
	"database/sql"
	"errors"
	"fmt"
	"path/filepath"

	"github.com/golang-migrate/migrate/v4"
	mysqlmigrate "github.com/golang-migrate/migrate/v4/database/mysql"
	_ "github.com/golang-migrate/migrate/v4/source/file"
)

func Up(db *sql.DB, dir string) error {
	abs, err := filepath.Abs(dir)
	if err != nil {
		return fmt.Errorf("migrations dir: %w", err)
	}

	driver, err := mysqlmigrate.WithInstance(db, &mysqlmigrate.Config{})
	if err != nil {
		return fmt.Errorf("migrate mysql driver: %w", err)
	}

	m, err := migrate.NewWithDatabaseInstance("file://"+abs, "mysql", driver)
	if err != nil {
		return fmt.Errorf("migrate source: %w", err)
	}

	if err := prepareExistingSchema(m, db); err != nil {
		return err
	}

	if err := m.Up(); err != nil && !errors.Is(err, migrate.ErrNoChange) {
		var dirty migrate.ErrDirty
		if errors.As(err, &dirty) {
			if schemaReady(db) {
				if ferr := m.Force(dirty.Version); ferr != nil {
					return fmt.Errorf("migrate up: %w (force: %v)", err, ferr)
				}
				fmt.Printf("migrate: recovered dirty version %d (schema already present)\n", dirty.Version)
				return nil
			}
			if ferr := m.Force(-1); ferr != nil {
				return fmt.Errorf("migrate up: %w (rewind: %v)", err, ferr)
			}
			if err2 := m.Up(); err2 != nil && !errors.Is(err2, migrate.ErrNoChange) {
				return fmt.Errorf("migrate up: %w", err2)
			}
			return nil
		}
		if schemaReady(db) {
			if ferr := m.Force(1); ferr != nil {
				return fmt.Errorf("migrate up: %w (mark applied: %v)", err, ferr)
			}
			fmt.Println("migrate: existing schema detected, marked version 1 applied")
			return nil
		}
		return fmt.Errorf("migrate up: %w", err)
	}
	return nil
}

func prepareExistingSchema(m *migrate.Migrate, db *sql.DB) error {
	version, dirty, err := m.Version()
	if errors.Is(err, migrate.ErrNilVersion) {
		if schemaReady(db) {
			if ferr := m.Force(1); ferr != nil {
				return fmt.Errorf("migrate mark existing schema: %w", ferr)
			}
			fmt.Println("migrate: existing database detected, skipped baseline SQL")
		}
		return nil
	}
	if err != nil {
		return fmt.Errorf("migrate version: %w", err)
	}
	if !dirty {
		return nil
	}
	if schemaReady(db) {
		if ferr := m.Force(int(version)); ferr != nil {
			return fmt.Errorf("migrate clear dirty version %d: %w", version, ferr)
		}
		fmt.Printf("migrate: cleared dirty flag at version %d\n", version)
		return nil
	}
	if ferr := m.Force(-1); ferr != nil {
		return fmt.Errorf("migrate rewind dirty version %d: %w", version, ferr)
	}
	fmt.Printf("migrate: rewound dirty version %d for retry\n", version)
	return nil
}

func schemaReady(db *sql.DB) bool {
	required := []string{"users", "settings", "vpn_clients", "awg_configs", "awg_config_peers"}
	for _, table := range required {
		var name string
		err := db.QueryRow(`SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?`, table).Scan(&name)
		if err != nil {
			return false
		}
	}
	return true
}
