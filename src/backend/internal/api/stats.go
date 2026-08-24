package api

import (
	"net/http"
	"strconv"
	"strings"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/system"
)

type StatsController struct {
	Stats *stats.Service
	Host  *system.HostMetrics
	Peers *store.Peers
	Clients *store.Clients
}

func (c *StatsController) Index(w http.ResponseWriter, r *http.Request) {
	configID := parseSingleConfigID(r)
	includeLinks := true
	if v := r.URL.Query().Get("include_links"); v != "" {
		includeLinks = v == "1" || strings.EqualFold(v, "true")
	}
	peers, err := c.Stats.PeersFromDB(r.Context(), configID)
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	online := 0
	for _, p := range peers {
		if on, ok := p["online"].(bool); ok && on {
			online++
		} else if onp, ok := p["online"].(*bool); ok && onp != nil && *onp {
			online++
		}
	}
	var ids []int64
	if configID != nil {
		ids = []int64{*configID}
	}
	membershipsTotal, _ := c.Peers.Count(r.Context(), ids)
	membershipsEnabled, _ := c.Peers.CountEnabled(r.Context(), ids)
	clientsTotal, _ := c.Clients.Count(r.Context())
	links := []map[string]any{}
	if includeLinks {
		links = c.Stats.PeerLinks(r.Context(), configID)
	}
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"stats_available": true,
		"summary": map[string]any{
			"clients_total":       clientsTotal,
			"memberships_total":   membershipsTotal,
			"memberships_enabled": membershipsEnabled,
			"online":              online,
		},
		"peers": peers,
		"links": links,
		"host":  c.Host.Collect(),
	})
}

func (c *StatsController) Refresh(w http.ResponseWriter, r *http.Request) {
	ids := parseConfigIDs(r)
	result := c.Stats.RefreshFromDocker(r.Context(), ids)
	online := 0
	for _, p := range result.Peers {
		if on, ok := p["online"].(bool); ok && on {
			online++
		}
	}
	membershipsTotal, _ := c.Peers.Count(r.Context(), ids)
	membershipsEnabled, _ := c.Peers.CountEnabled(r.Context(), ids)
	clientsTotal, _ := c.Clients.Count(r.Context())
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"stats_available": result.StatsAvailable,
		"synced_at":       result.SyncedAt,
		"by_public_key":   result.ByPublicKey,
		"summary": map[string]any{
			"clients_total":       clientsTotal,
			"memberships_total":   membershipsTotal,
			"memberships_enabled": membershipsEnabled,
			"online":              online,
		},
		"peers": result.Peers,
		"host":  c.Host.Collect(),
	})
}

func (c *StatsController) Live(w http.ResponseWriter, r *http.Request) {
	ids := parseConfigIDs(r)
	result := c.Stats.RefreshFromDocker(r.Context(), ids)
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"stats_available": result.StatsAvailable,
		"by_public_key":   result.ByPublicKey,
	})
}

func parseSingleConfigID(r *http.Request) *int64 {
	raw := r.URL.Query().Get("config_id")
	if raw == "" {
		return nil
	}
	n, err := strconv.ParseInt(raw, 10, 64)
	if err != nil {
		return nil
	}
	return &n
}

func parseConfigIDs(r *http.Request) []int64 {
	raw := r.URL.Query().Get("config_ids")
	if raw != "" {
		parts := strings.Split(raw, ",")
		seen := map[int64]bool{}
		var ids []int64
		for _, p := range parts {
			n, err := strconv.ParseInt(strings.TrimSpace(p), 10, 64)
			if err != nil || n <= 0 || seen[n] {
				continue
			}
			seen[n] = true
			ids = append(ids, n)
		}
		return ids
	}
	if single := parseSingleConfigID(r); single != nil {
		return []int64{*single}
	}
	return nil
}
