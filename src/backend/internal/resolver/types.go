package resolver

import (
	"fmt"
	"strings"
	"time"
)

type Connection struct {
	ID                   int64
	Name                 string
	Comment              *string
	Kind                 string
	ConfigType           string
	ShareURL             *string
	SubscriptionURL      *string
	SubscriptionBody     *string
	SubscriptionMode     *string
	SubscriptionSelected *string
	SubscriptionNodes    []map[string]any
	SubscriptionFetchedAt *time.Time
	LatencyCache         map[string]any
	SubscriptionActive   map[string]any
	PingCheckIntervalMin int
	PingLastCheckedAt    *time.Time
	Outbound             map[string]any
	AWGConf              *string
	ProtocolVersion      *string
	Enabled              bool
	LastLatencyMS        *int
	LastTestedAt         *time.Time
	LastTestOK           *bool
	LastTestError        *string
	LastTSPUStatus       *string
	LastTSPULikely       *bool
	LastTSPUDetail       *string
	LastTSPUMeta         map[string]any
	CreatedAt            *time.Time
	UpdatedAt            *time.Time
	ConfigsCount         int
}

func (c *Connection) OutboundTag() string { return fmt.Sprintf("conn_%d", c.ID) }
func (c *Connection) ChildOutboundTag(i int) string {
	return fmt.Sprintf("%s_%d", c.OutboundTag(), i)
}
func (c *Connection) IsSubscription() bool {
	kind := c.Kind
	if kind == "" {
		kind = KindProxy
	}
	return kind == KindSubscription
}
func (c *Connection) IsAWG() bool {
	return !c.IsSubscription() && c.ConfigType == "awg"
}
func (c *Connection) AWGClientIface() string { return fmt.Sprintf("awgc%d", c.ID) }
func (c *Connection) IsURLTestMode() bool {
	return c.IsSubscription() && c.SubscriptionMode != nil && *c.SubscriptionMode == ModeURLTest
}
func (c *Connection) ValidSubscriptionNodes() []map[string]any {
	out := make([]map[string]any, 0)
	for _, n := range c.SubscriptionNodes {
		if n == nil {
			continue
		}
		ob, _ := n["outbound"].(map[string]any)
		if ob == nil {
			continue
		}
		if strings.TrimSpace(strVal(ob["type"])) == "" {
			continue
		}
		out = append(out, n)
	}
	return out
}
func (c *Connection) ChildTagForNodeKey(nodeKey string) *string {
	i := 0
	for _, n := range c.ValidSubscriptionNodes() {
		i++
		if strVal(n["key"]) == nodeKey {
			t := c.ChildOutboundTag(i)
			return &t
		}
	}
	return nil
}
func (c *Connection) NodeForChildTag(tag string) map[string]any {
	prefix := c.OutboundTag() + "_"
	if !strings.HasPrefix(tag, prefix) {
		return nil
	}
	idx := atoiDef(strings.TrimPrefix(tag, prefix), 0)
	if idx < 1 {
		return nil
	}
	i := 0
	for _, n := range c.ValidSubscriptionNodes() {
		i++
		if i == idx {
			return n
		}
	}
	return nil
}
func (c *Connection) PingCheckInterval() int {
	v := c.PingCheckIntervalMin
	if v < 0 {
		v = 0
	}
	if v > 1440 {
		v = 1440
	}
	return v
}
func (c *Connection) URLTestIntervalDuration() string {
	min := c.PingCheckInterval()
	if min <= 0 {
		min = 5
	}
	return fmt.Sprintf("%dm", min)
}
func (c *Connection) IsPingCheckDue(now time.Time) bool {
	interval := c.PingCheckInterval()
	if interval <= 0 || !c.Enabled {
		return false
	}
	if c.PingLastCheckedAt == nil {
		return true
	}
	return !c.PingLastCheckedAt.After(now.Add(-time.Duration(interval) * time.Minute))
}
func (c *Connection) TSPUProbeOutbound() map[string]any {
	if !c.IsSubscription() {
		return c.Outbound
	}
	if c.SubscriptionMode != nil && *c.SubscriptionMode == ModeSingle {
		return c.Outbound
	}
	if len(c.SubscriptionNodes) == 0 {
		return map[string]any{}
	}
	ob, _ := c.SubscriptionNodes[0]["outbound"].(map[string]any)
	if ob == nil {
		return map[string]any{}
	}
	return ob
}

type CustomList struct {
	ID        int64
	Name      string
	Slug      string
	Domains   []string
	CIDRs     []string
	SourceURL *string
	CreatedAt *time.Time
	UpdatedAt *time.Time
}

func (l *CustomList) IsRemote() bool {
	return l.SourceURL != nil && strings.TrimSpace(*l.SourceURL) != ""
}

func CustomSlug(id int64) string { return fmt.Sprintf("custom_%d", id) }

type AWGConfig struct {
	ID                   int64
	Name                 string
	Type                 string
	Enabled              bool
	Iface                string
	InternalSubnet       string
	ServerAddress        string
	PeerDNS              string
	ResolverDNS          *string
	ClientAllowedIPs     string
	ResolverEnabled      bool
	ResolverRejectQUIC   bool
	ConnectionID         *int64
	CommunityLists       []string
	UserDomains          []string
	UserSubnets          []string
	ResolverUpdatedAt    *time.Time
	ResolverLastError    *string
	HasPeerExtraAllowed  bool
}

type Node struct {
	Key      string
	Name     string
	Type     string
	Server   string
	Port     int
	Outbound map[string]any
}

type DelayResult struct {
	OK        bool
	LatencyMS *int
	Error     *string
}

type PingResult struct {
	Key       string
	LatencyMS *int
	OK        bool
	Error     *string
	Source    string
}

type ClashResp struct {
	OK     bool
	Status int
	Body   map[string]any
	Raw    string
	Error  *string
}
