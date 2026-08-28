package resolver

const (
	KindProxy        = "proxy"
	KindSubscription = "subscription"
	ModeSingle       = "single"
	ModeURLTest      = "urltest"

	FakeIPCIDR        = "198.18.0.0/15"
	FakeIPRewriteTTL  = 60
	TProxyPort        = 1602
	TProxyInboundTag  = "tproxy-in"
	UDPTProxyPort     = 1603
	UDPTProxyInbound  = "tproxy-udp-in"
	TProxyListen      = "0.0.0.0"
	TProxyOnIP        = "0.0.0.0"
	EgressFallback    = "eth0"
	TProxyMark        = "0x1"
	TProxyTable       = 100
	DNSListenPort     = 53
	TunIface          = "sbox0"
	TunTable          = 101

	ClashAPIAddr      = "127.0.0.1:9090"
	ClashProbeAPIAddr = "127.0.0.1:9091"
	ClashSpeedAPIAddr = "127.0.0.1:9092"
	SpeedMixedListen  = "127.0.0.1"
	SpeedMixedPort    = 19091
	SpeedMixedTag     = "speedtest-in"
	SpeedTestBytes    = 25_000_000
	SpeedDownURL      = "https://speed.cloudflare.com/__down?bytes=25000000"
	SpeedUpURL        = "https://speed.cloudflare.com/__up"
	DelayTestURL      = "https://www.gstatic.com/generate_204"
	RulesetBaseURL    = "https://github.com/itdoginfo/allow-domains/releases/latest/download"

	MaxNodesPerSubscription = 80

	TelegramMixedPort    = 18088
	TelegramMixedTag     = "tg-in"
	TelegramOutboundTag  = "telegram-out"

	SettingInterval      = "resolver_lists_sync_interval_minutes"
	SettingLastSync      = "resolver_lists_last_sync_at"
	SettingListMeta      = "resolver_list_meta"
	SettingBootstrapDNS  = "resolver_bootstrap_dns"
	DefaultInterval      = 360
	DefaultBootstrapDNS  = "77.88.8.8"
	SettingEgress        = "singbox_egress_interface"

	PingIdleTimeoutSec = 600
)

var CommunityLists = []string{
	"russia_inside", "russia_outside", "ukraine_inside",
	"geoblock", "block", "porn", "news", "anime",
	"youtube", "hdrezka", "tiktok", "google_ai", "google_play",
	"hodca", "discord", "meta", "twitter", "cloudflare",
	"cloudfront", "digitalocean", "hetzner", "ovh", "telegram", "roblox",
}

var MutuallyExclusive = []string{"russia_inside", "russia_outside", "ukraine_inside"}

var CommunityLabels = map[string]string{
	"russia_inside":  "Russia inside",
	"russia_outside": "Russia outside",
	"ukraine_inside": "Ukraine inside",
	"geoblock":       "GEO Block",
	"block":          "Block",
	"porn":           "Porn",
	"news":           "News",
	"anime":          "Anime",
	"youtube":        "YouTube",
	"hdrezka":        "HDRezka",
	"tiktok":         "TikTok",
	"google_ai":      "Google AI",
	"google_play":    "Google Play",
	"hodca":          "H.O.D.C.A.",
	"discord":        "Discord",
	"meta":           "Meta*",
	"twitter":        "Twitter (X)",
	"cloudflare":     "Cloudflare",
	"cloudfront":     "CloudFront",
	"digitalocean":   "DigitalOcean",
	"hetzner":        "Hetzner",
	"ovh":            "OVH",
	"telegram":       "Telegram",
	"roblox":         "Roblox",
}

var ProtocolVersions = []string{"1.0", "1.5", "2.0", "3.1"}

func CommunitySourceURL(tag string) string {
	return RulesetBaseURL + "/" + tag + ".srs"
}

func CommunityListCatalog() []map[string]any {
	out := make([]map[string]any, 0, len(CommunityLists))
	excl := map[string]bool{}
	for _, t := range MutuallyExclusive {
		excl[t] = true
	}
	for _, tag := range CommunityLists {
		var group any
		if excl[tag] {
			group = "region"
		}
		label := CommunityLabels[tag]
		if label == "" {
			label = tag
		}
		out = append(out, map[string]any{
			"tag":             tag,
			"label":           label,
			"kind":            "community",
			"exclusive_group": group,
			"source_url":      CommunitySourceURL(tag),
		})
	}
	return out
}

func LatestProtocolVersion() string {
	return ProtocolVersions[len(ProtocolVersions)-1]
}

func HasProtocolVersion(id string) bool {
	for _, v := range ProtocolVersions {
		if v == id {
			return true
		}
	}
	return false
}

func IsCommunityTag(tag string) bool {
	for _, t := range CommunityLists {
		if t == tag {
			return true
		}
	}
	return false
}
