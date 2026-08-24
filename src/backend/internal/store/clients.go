package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"

	"github.com/awggui/backend/internal/models"
)

type Clients struct {
	DB *sql.DB
}

func NewClients(db *sql.DB) *Clients {
	return &Clients{DB: db}
}

func (s *Clients) List(ctx context.Context) ([]models.VpnClient, error) {
	rows, err := s.DB.QueryContext(ctx, `
SELECT id, name, comment, created_at, updated_at
FROM vpn_clients ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []models.VpnClient
	for rows.Next() {
		c, err := scanClient(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

func (s *Clients) Find(ctx context.Context, id int64) (*models.VpnClient, error) {
	row := s.DB.QueryRowContext(ctx, `
SELECT id, name, comment, created_at, updated_at
FROM vpn_clients WHERE id = ?`, id)
	c, err := scanClient(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func (s *Clients) Create(ctx context.Context, name string, comment *string) (*models.VpnClient, error) {
	res, err := s.DB.ExecContext(ctx, `
INSERT INTO vpn_clients (name, comment, created_at, updated_at)
VALUES (?, ?, NOW(), NOW())`, name, nullString(comment))
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.Find(ctx, id)
}

func (s *Clients) Update(ctx context.Context, c *models.VpnClient) error {
	_, err := s.DB.ExecContext(ctx, `
UPDATE vpn_clients SET name = ?, comment = ?, updated_at = NOW() WHERE id = ?`,
		c.Name, nullString(c.Comment), c.ID)
	return err
}

func (s *Clients) Delete(ctx context.Context, id int64) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM vpn_clients WHERE id = ?`, id)
	return err
}

type rowScanner interface {
	Scan(dest ...any) error
}

func scanClient(row rowScanner) (models.VpnClient, error) {
	var c models.VpnClient
	var comment sql.NullString
	err := row.Scan(&c.ID, &c.Name, &comment, &c.CreatedAt, &c.UpdatedAt)
	if err != nil {
		return c, err
	}
	c.Comment = ptrString(comment)
	return c, nil
}

func (s *Clients) Count(ctx context.Context) (int, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM vpn_clients`).Scan(&n)
	return n, err
}

func (s *Clients) Exists(ctx context.Context, id int64) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM vpn_clients WHERE id = ?`, id).Scan(&n)
	if err != nil {
		return false, fmt.Errorf("client exists: %w", err)
	}
	return n > 0, nil
}
