package models

import (
	"encoding/json"
	"strings"
	"time"
)

type VpnClient struct {
	ID          int64
	Name        string
	Comment     *string
	CreatedAt   time.Time
	UpdatedAt   time.Time
	Memberships []AwgConfigPeer
}

type VnRule struct {
	SrcClientIDs  []int64 `json:"src_client_ids"`
	DestClientIDs []int64 `json:"dest_client_ids"`
}

type VnZones struct {
	Rules []VnRule `json:"rules"`
}

type AwgConfig struct {
	ID                    int64
	Name                  string
	Type                  string
	ProtocolVersion       string
	ClientImportNameStyle string
	VnPolicy              string
	VnZonesRaw            []byte
	Iface                 string
	ListenPort            int
	InternalSubnet        string
	ServerAddress         string
	ServerPrivateKey      string
	ServerPublicKey       string
	PeerDNS               string
	ResolverDNS           *string
	ClientAllowedIPs      string
	PersistentKeepalive   int
	Enabled               bool
	HandshakeLoggingEnabled bool
	HandshakeLogBytes     int64
	ResolverEnabled       bool
	ResolverRejectQuic    bool
	CommunityLists        []string
	UserDomains           []string
	UserSubnets           []string
	ResolverUpdatedAt     *time.Time
	ResolverLastError     *string
	ConnectionID          *int64
	Jc, Jmin, Jmax        string
	S1, S2, S3, S4        string
	H1, H2, H3, H4        string
	I1, I2, I3, I4, I5    *string
	CreatedAt             time.Time
	UpdatedAt             time.Time
	PeersCount            int
}

func (c *AwgConfig) IsVirtualNetwork() bool {
	return c.Type == "virtual_network"
}

func (c *AwgConfig) IsResolverEnabled() bool {
	return c.Type == "server" && c.ResolverEnabled
}

func (c *AwgConfig) VnZones() VnZones {
	z := VnZones{Rules: []VnRule{}}
	if len(c.VnZonesRaw) == 0 || string(c.VnZonesRaw) == "null" {
		return z
	}
	_ = json.Unmarshal(c.VnZonesRaw, &z)
	if z.Rules == nil {
		z.Rules = []VnRule{}
	}
	return z
}

func (c *AwgConfig) SetVnZones(z VnZones) {
	if z.Rules == nil {
		z.Rules = []VnRule{}
	}
	b, err := json.Marshal(z)
	if err != nil {
		c.VnZonesRaw = []byte(`{"rules":[]}`)
		return
	}
	c.VnZonesRaw = b
}

func (c *AwgConfig) JunkField(name string) string {
	switch name {
	case "jc":
		return c.Jc
	case "jmin":
		return c.Jmin
	case "jmax":
		return c.Jmax
	case "s1":
		return c.S1
	case "s2":
		return c.S2
	case "s3":
		return c.S3
	case "s4":
		return c.S4
	case "h1":
		return c.H1
	case "h2":
		return c.H2
	case "h3":
		return c.H3
	case "h4":
		return c.H4
	case "i1":
		return deref(c.I1)
	case "i2":
		return deref(c.I2)
	case "i3":
		return deref(c.I3)
	case "i4":
		return deref(c.I4)
	case "i5":
		return deref(c.I5)
	default:
		return ""
	}
}

func (c *AwgConfig) SetJunkField(name, value string) {
	switch name {
	case "jc":
		c.Jc = value
	case "jmin":
		c.Jmin = value
	case "jmax":
		c.Jmax = value
	case "s1":
		c.S1 = value
	case "s2":
		c.S2 = value
	case "s3":
		c.S3 = value
	case "s4":
		c.S4 = value
	case "h1":
		c.H1 = value
	case "h2":
		c.H2 = value
	case "h3":
		c.H3 = value
	case "h4":
		c.H4 = value
	case "i1":
		c.I1 = nullable(value)
	case "i2":
		c.I2 = nullable(value)
	case "i3":
		c.I3 = nullable(value)
	case "i4":
		c.I4 = nullable(value)
	case "i5":
		c.I5 = nullable(value)
	}
}

func (c *AwgConfig) ApplyJunk(params map[string]string) {
	for k, v := range params {
		c.SetJunkField(k, v)
	}
}

type AwgConfigPeer struct {
	ID                 int64
	AwgConfigID        int64
	VpnClientID        int64
	Enabled            bool
	PrivateKey         string
	PublicKey          string
	PresharedKey       *string
	Address            string
	ExtraAllowedIPs      []string
	ExcludedClientIDs    []int64
	ExclusionsMutual     bool
	Keepalive            *int
	ForwardPolicy        string
	ForwardAllowedCIDRs  []string
	RuntimeEndpoint    *string
	LatestHandshake    *int64
	TransferRx         int64
	TransferTx         int64
	TrafficRxTotal     int64
	TrafficTxTotal     int64
	TrafficRxBaseline  int64
	TrafficTxBaseline  int64
	TrafficResetAt     *time.Time
	Online             *bool
	StatsSyncedAt      *time.Time
	CreatedAt          time.Time
	UpdatedAt          time.Time
	Client             *VpnClient
	Config             *AwgConfig
}

type Setting struct {
	ID        int64
	Key       string
	Value     *string
	CreatedAt time.Time
	UpdatedAt time.Time
}

type AwgHandshakeLog struct {
	ID              int64
	AwgConfigID     int64
	AwgConfigPeerID *int64
	VpnClientID     *int64
	PublicKey       string
	Endpoint        *string
	HandshakeAt     int64
	ByteSize        int
	CreatedAt       time.Time
	PeerName        *string
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func nullable(s string) *string {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return &s
}
