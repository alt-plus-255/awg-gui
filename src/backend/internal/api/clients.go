package api

import (
	"net/http"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/store"
)

type ClientController struct {
	AWG     *awg.Service
	Clients *store.Clients
	Configs *store.Configs
	Peers   *store.Peers
}

func (c *ClientController) Index(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	clients, err := c.Clients.List(ctx)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	out := make([]map[string]any, 0, len(clients))
	for i := range clients {
		out = append(out, c.serialize(r, &clients[i], false))
	}
	writeJSON(w, http.StatusOK, map[string]any{"clients": out})
}

func (c *ClientController) Store(w http.ResponseWriter, r *http.Request) {
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	name := strings.TrimSpace(asString(req["name"]))
	if name == "" || utf8.RuneCountInString(name) > 64 {
		writeValidation(w, r, "name", "api.http_422", nil)
		return
	}
	var comment *string
	if _, ok := req["comment"]; ok && req["comment"] != nil {
		s := strings.TrimSpace(asString(req["comment"]))
		if utf8.RuneCountInString(s) > 255 {
			writeValidation(w, r, "comment", "api.http_422", nil)
			return
		}
		if s != "" {
			comment = &s
		}
	}
	client, err := c.Clients.Create(r.Context(), name, comment)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	writeJSON(w, http.StatusCreated, map[string]any{"client": c.serialize(r, client, true)})
}

func (c *ClientController) Update(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(r, "clientID")
	if !ok {
		writeNotFound(w, r)
		return
	}
	client, err := c.Clients.Find(r.Context(), id)
	if err != nil || client == nil {
		writeNotFound(w, r)
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	if _, ok := req["name"]; ok {
		name := strings.TrimSpace(asString(req["name"]))
		if name == "" || utf8.RuneCountInString(name) > 64 {
			writeValidation(w, r, "name", "api.http_422", nil)
			return
		}
		client.Name = name
	}
	if _, ok := req["comment"]; ok {
		if req["comment"] == nil {
			client.Comment = nil
		} else {
			s := strings.TrimSpace(asString(req["comment"]))
			if utf8.RuneCountInString(s) > 255 {
				writeValidation(w, r, "comment", "api.http_422", nil)
				return
			}
			if s == "" {
				client.Comment = nil
			} else {
				client.Comment = &s
			}
		}
	}
	if err := c.Clients.Update(r.Context(), client); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyAfterClientChange(r.Context(), client.ID)
	fresh, _ := c.Clients.Find(r.Context(), client.ID)
	if fresh == nil {
		fresh = client
	}
	writeJSON(w, http.StatusOK, map[string]any{"client": c.serialize(r, fresh, true)})
}

func (c *ClientController) Destroy(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(r, "clientID")
	if !ok {
		writeNotFound(w, r)
		return
	}
	client, err := c.Clients.Find(r.Context(), id)
	if err != nil || client == nil {
		writeNotFound(w, r)
		return
	}
	configIDs, _ := c.Peers.ConfigIDsForClient(r.Context(), id)
	if err := c.Clients.Delete(r.Context(), id); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	for _, cid := range configIDs {
		if cfg, err := c.Configs.Find(r.Context(), cid); err == nil && cfg != nil {
			_ = c.AWG.ApplyConfig(r.Context(), cfg, false, false)
		}
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (c *ClientController) serialize(r *http.Request, client *models.VpnClient, includeAllowedIPs bool) map[string]any {
	ctx := r.Context()
	memberships, _ := c.Peers.ListByClient(ctx, client.ID)
	rows := make([]map[string]any, 0, len(memberships))
	for i := range memberships {
		m := &memberships[i]
		cfg, _ := c.Configs.Find(ctx, m.AwgConfigID)
		row := map[string]any{
			"membership_id":         m.ID,
			"config_id":             m.AwgConfigID,
			"config_name":           nil,
			"config_type":           nil,
			"enabled":               m.Enabled,
			"address":               m.Address,
			"extra_allowed_ips":     nonNil(m.ExtraAllowedIPs),
			"forward_policy":        awg.NormalizeForwardPolicy(m.ForwardPolicy),
			"forward_allowed_cidrs": nonNil(m.ForwardAllowedCIDRs),
			"split_tunnel":          m.SplitTunnel,
		}
		if cfg != nil {
			row["config_name"] = cfg.Name
			row["config_type"] = cfg.Type
			if includeAllowedIPs {
				row["client_allowed_ips"] = c.AWG.ClientAllowedIPsString(ctx, cfg, m)
			}
		} else if includeAllowedIPs {
			row["client_allowed_ips"] = nil
		}
		rows = append(rows, row)
	}
	return map[string]any{
		"id":          client.ID,
		"name":        client.Name,
		"comment":     client.Comment,
		"memberships": rows,
		"created_at":  client.CreatedAt.Format(time.RFC3339),
		"updated_at":  client.UpdatedAt.Format(time.RFC3339),
	}
}

func nonNil(in []string) []string {
	if in == nil {
		return []string{}
	}
	return in
}
