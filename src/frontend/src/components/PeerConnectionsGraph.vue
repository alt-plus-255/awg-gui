<template>
    <div class="graph-wrap" :class="{ 'graph-wrap--fill': fullHeight }" ref="graphWrap">
    <v-network-graph
      :key="`${theme.styleId}-${theme.resolvedScheme}`"
      ref="graph"
      v-model:zoom-level="viewZoom"
      :nodes="nodes"
      :edges="edges"
      :layouts="layouts"
      :configs="configs"
      :layers="graphLayers"
      :event-handlers="eventHandlers"
    >
      <template #zones>
        <g class="graph-zones">
          <g v-for="zone in zoneVisuals" :key="zone.configId" pointer-events="none">
            <rect
              :x="zone.x"
              :y="zone.y"
              :width="zone.width"
              :height="zone.height"
              :rx="ZONE_CORNER_RADIUS"
              :ry="ZONE_CORNER_RADIUS"
              :fill="g.zoneFill"
              :stroke="g.zoneStroke"
              stroke-width="1.5"
              pointer-events="none"
            />
            <path
              class="zone-drag-bar"
              :d="zoneHeaderPath(zone)"
              :fill="g.zoneHeaderFill"
              :stroke="g.zoneStroke"
              stroke-width="1"
              pointer-events="all"
              @pointerdown.prevent.stop="startZoneDrag(zone.configId, $event)"
            />
            <text
              class="zone-title"
              :x="zone.x + zone.width / 2"
              :y="zone.y + 18"
              text-anchor="middle"
              :fill="g.label"
              :font-size="screenFontSize(11)"
              font-weight="600"
              pointer-events="none"
            >{{ zone.name }}</text>
            <rect
              class="zone-header-spacer"
              :x="zone.x"
              :y="zone.y + ZONE_TITLE_HEIGHT"
              :width="zone.width"
              :height="ZONE_TITLE_GAP"
              fill="transparent"
            />
          </g>
        </g>
      </template>
      <template #override-node-label="{ text, x, y, config, textAnchor, dominantBaseline }">
        <text
          class="v-ng-node-label"
          :x="x"
          :y="y"
          :text-anchor="textAnchor"
          :dominant-baseline="dominantBaseline"
          :fill="config.color"
          :font-size="screenFontSize(config.fontSize || 12)"
          :font-family="config.fontFamily"
        >{{ text }}</text>
      </template>
      <template #edge-label="{ edge, ...slotProps }">
        <v-edge-label
          v-if="edgeRateLabel(edge)"
          v-bind="slotProps"
          :text="edgeRateLabel(edge)"
          align="center"
          :vertical-align="edge.trafficDir === 'down' ? 'below' : 'above'"
          :fill="g.label"
          :font-size="screenFontSize(10)"
        />
      </template>
    </v-network-graph>
    <div
      ref="tooltip"
      class="graph-tooltip"
      :style="{ ...tooltipPos, opacity: tooltipOpacity }"
    >
      <template v-if="tooltipNode">
        <div class="text-weight-bold">{{ tooltipNode.name }}</div>
        <div v-if="tooltipNode.isServer" class="text-grey-5">
          Сервер · {{ tooltipNode.iface }}
        </div>
        <template v-else>
          <div class="text-grey-5">{{ tooltipNode.address }}</div>
          <div v-if="tooltipNode.allowedIps" class="allowed-ips">
            AllowedIPs: {{ tooltipNode.allowedIps }}
          </div>
          <div>
            <span :class="tooltipNode.online ? 'text-positive' : 'text-grey-5'">
              {{ tooltipNode.online ? 'онлайн' : 'офлайн' }}
            </span>
            <span v-if="!tooltipNode.enabled" class="text-warning"> · выключен</span>
          </div>
          <div v-if="tooltipNode.rxRate + tooltipNode.txRate > 0">
            ↑ {{ formatRate(tooltipNode.rxRate) }} · ↓ {{ formatRate(tooltipNode.txRate) }}
          </div>
          <div class="text-grey-5">
            RX: {{ formatBytes(tooltipNode.transferRx) }} · TX: {{ formatBytes(tooltipNode.transferTx) }}
          </div>
        </template>
      </template>
    </div>
    <div class="graph-legend row items-center q-gutter-md">
      <span><span class="dot dot-server"></span> сервер</span>
      <span><span class="dot dot-online"></span> пир онлайн</span>
      <span><span class="dot dot-offline"></span> пир офлайн</span>
      <span><span class="line line-tunnel"></span> туннель (офлайн)</span>
      <span>
        <svg class="line-anim" width="18" height="10" aria-hidden="true">
          <line x1="0" y1="2" x2="18" y2="2" :stroke="g.tunnelOnline" stroke-width="2" stroke-dasharray="4 4" />
          <line class="line-anim-down" x1="0" y1="8" x2="18" y2="8" :stroke="g.tunnelOnline" stroke-width="2" stroke-dasharray="4 4" />
        </svg>
        трафик ↑/↓
      </span>
      <span>
        <svg class="line-peer-dir" width="28" height="8" aria-hidden="true">
          <defs>
            <marker :id="legendArrowId" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">
              <path d="M 0 0 L 10 5 L 0 10 z" :fill="g.peerLink" />
            </marker>
          </defs>
          <line x1="2" y1="4" x2="24" y2="4" :stroke="g.peerLink" stroke-width="1.5" stroke-dasharray="4 3" :marker-end="`url(#${legendArrowId})`" />
        </svg>
        пир→пир (стрелка: кто ходит к кому)
      </span>
      <span>
        <svg class="zone-legend" width="22" height="14" aria-hidden="true">
          <rect x="1" y="1" width="20" height="12" rx="3" :fill="g.zoneFill" :stroke="g.zoneStroke" stroke-width="1.5" />
        </svg>
        зона конфига
      </span>
      <span class="text-grey-6">тянуть заголовок</span>
    </div>
    <div class="graph-zoom-controls row items-center no-wrap q-gutter-xs">
      <q-btn dense flat round icon="remove" title="Уменьшить" @click="zoomOut" />
      <q-btn dense flat round icon="add" title="Увеличить" @click="zoomIn" />
      <q-btn dense flat round icon="fit_screen" title="Вписать в экран" @click="fitGraphToView" />
    </div>
    <div v-if="minimapModel" class="graph-minimap" title="Мини-карта зон">
      <div class="graph-minimap-header">Зоны · {{ minimapModel.count }}</div>
      <svg
        ref="minimapSvg"
        class="graph-minimap-svg"
        :viewBox="`0 0 ${MINIMAP_W} ${MINIMAP_H}`"
        :width="MINIMAP_W"
        :height="MINIMAP_H"
        @pointerdown.stop
      >
        <rect
          v-for="z in minimapModel.rects"
          :key="z.configId"
          class="graph-minimap-zone"
          :x="z.x"
          :y="z.y"
          :width="z.width"
          :height="z.height"
          :rx="3"
          :fill="g.zoneFill"
          :stroke="g.zoneStroke"
          stroke-width="1"
          @click.stop="focusZone(z.configId)"
        />
        <text
          v-for="z in minimapModel.rects"
          :key="`t-${z.configId}`"
          class="graph-minimap-label"
          :x="z.x + z.width / 2"
          :y="z.y + z.height / 2"
          text-anchor="middle"
          dominant-baseline="middle"
          :fill="g.label"
          font-size="9"
          pointer-events="none"
        >{{ z.shortName }}</text>
        <rect
          v-if="minimapModel.viewport"
          class="graph-minimap-viewport"
          :class="{ 'is-dragging': !!minimapDrag }"
          :x="minimapModel.viewport.x"
          :y="minimapModel.viewport.y"
          :width="minimapModel.viewport.width"
          :height="minimapModel.viewport.height"
          :fill="g.peerLink"
          fill-opacity="0.18"
          :stroke="g.peerLink"
          stroke-width="1.5"
          @pointerdown.stop="startMinimapViewportDrag"
        />
      </svg>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { VNetworkGraph, VEdgeLabel, defineConfigs } from 'v-network-graph'
import { ForceLayout } from 'v-network-graph/lib/force-layout'
import 'v-network-graph/lib/style.css'
import { useThemeStore } from '@/stores/theme'

const props = defineProps({
  peers: { type: Array, default: () => [] },
  links: { type: Array, default: () => [] },
  fullHeight: { type: Boolean, default: false }
})

const theme = useThemeStore()
const { styleId, resolvedScheme } = storeToRefs(theme)
const g = computed(() => theme.current.graph)
const legendArrowId = computed(() => `lg-arrow-${styleId.value}-${resolvedScheme.value}`)

const NODE_RADIUS = 14
const SERVER_RADIUS = 20
const PEER_RING_INNER = 92
const PEER_RING_OUTER = 140
const ZONE_GAP = 24
const ZONE_PADDING = 20
const LABEL_MARGIN = 28
const ZONE_CORNER_RADIUS = 10
const ZONE_TITLE_HEIGHT = 26
const ZONE_TITLE_GAP = 12
const ZONE_HEADER_TOTAL = ZONE_TITLE_HEIGHT + ZONE_TITLE_GAP
const FIT_MARGIN = 48
const MINIMAP_W = 180
const MINIMAP_H = 110
const MINIMAP_PAD = 8
const ZONE_FOCUS_MARGIN = 32

const nodes = reactive({})
const edges = reactive({})
const layouts = reactive({ nodes: {} })
/** zoneLayouts[configId] = { x, y, width, height } — top-left визуального rect */
const zoneLayouts = reactive({})
const graphLayers = { zones: 'base' }

let activeSimulation = null
let hasInitialFit = false
const layoutTick = ref(0)
const peerCountsByConfig = new Map()
const peerDragNodeId = ref(null)
let peerDragRaf = 0

function dragNodeIdFromPositions (positions) {
  const ids = Object.keys(positions || {})
  return ids.length === 1 ? ids[0] : null
}

function stopPeerDragZoneSync () {
  if (peerDragRaf) {
    cancelAnimationFrame(peerDragRaf)
    peerDragRaf = 0
  }
}

function startPeerDragZoneSync () {
  stopPeerDragZoneSync()
  const tick = () => {
    const id = peerDragNodeId.value
    if (!id) {
      peerDragRaf = 0
      return
    }
    const cfg = nodes[id]?.configId
    if (cfg !== undefined) {
      syncZoneToNodes(cfg)
      layoutTick.value++
    }
    peerDragRaf = requestAnimationFrame(tick)
  }
  peerDragRaf = requestAnimationFrame(tick)
}

function zoneHeaderPath (zone) {
  const x = zone.x
  const y = zone.y
  const w = zone.width
  const h = ZONE_TITLE_HEIGHT
  const r = Math.min(ZONE_CORNER_RADIUS, w / 2, h)
  return [
    `M ${x + r} ${y}`,
    `H ${x + w - r}`,
    `Q ${x + w} ${y} ${x + w} ${y + r}`,
    `V ${y + h}`,
    `H ${x}`,
    `V ${y + r}`,
    `Q ${x} ${y} ${x + r} ${y}`,
    'Z'
  ].join(' ')
}

function zoneMinSize () {
  return {
    width: SERVER_RADIUS * 2 + ZONE_PADDING * 2,
    height: ZONE_HEADER_TOTAL + SERVER_RADIUS * 2 + ZONE_PADDING * 2
  }
}

function zoneRadius (peerCount) {
  const ring = peerCount <= 4 ? PEER_RING_INNER : PEER_RING_OUTER
  return ring + SERVER_RADIUS + NODE_RADIUS + ZONE_PADDING
}

function defaultZoneSize (peerCount) {
  const r = zoneRadius(peerCount)
  const min = zoneMinSize()
  return {
    width: Math.max(min.width, r * 2),
    height: Math.max(min.height, r * 2 + ZONE_HEADER_TOTAL)
  }
}

function ensureZoneLayout (configId, x, y, width, height) {
  const prev = zoneLayouts[configId]
  const min = zoneMinSize()
  const fallback = defaultZoneSize(peerCountsByConfig.get(configId) ?? 0)
  zoneLayouts[configId] = {
    x: x ?? prev?.x ?? 0,
    y: y ?? prev?.y ?? 0,
    width: Math.max(min.width, width ?? prev?.width ?? fallback.width),
    height: Math.max(min.height, height ?? prev?.height ?? fallback.height)
  }
}

function zoneContentCenter (z) {
  return {
    x: z.x + z.width / 2,
    y: z.y + ZONE_HEADER_TOTAL + (z.height - ZONE_HEADER_TOTAL) / 2
  }
}

function zoneAnchor (configId) {
  const sid = serverNodeId(configId)
  const server = layouts.nodes[sid]
  if (server) return { x: server.x, y: server.y }
  const z = zoneLayouts[configId]
  if (z) return zoneContentCenter(z)
  return { x: 0, y: 0 }
}

function computeZoneBBox (configId) {
  let minX = Infinity
  let minY = Infinity
  let maxX = -Infinity
  let maxY = -Infinity
  let hasNodes = false

  for (const [id, node] of Object.entries(nodes)) {
    if (node.configId !== configId) continue
    const layout = layouts.nodes[id]
    if (!layout) continue
    hasNodes = true
    const r = node.isServer ? SERVER_RADIUS : NODE_RADIUS
    minX = Math.min(minX, layout.x - r - LABEL_MARGIN * 0.35)
    maxX = Math.max(maxX, layout.x + r + LABEL_MARGIN * 0.35)
    minY = Math.min(minY, layout.y - r - LABEL_MARGIN * 0.25)
    maxY = Math.max(maxY, layout.y + r + LABEL_MARGIN)
  }

  if (!hasNodes) return null
  return { minX, minY, maxX, maxY }
}

function buildZoneRect (configId) {
  const z = zoneLayouts[configId] ?? zoneLayouts[Number(configId)]
  if (!z || !(z.width > 0) || !(z.height > 0)) return null
  return { x: z.x, y: z.y, width: z.width, height: z.height }
}

function zoneCollisionRadius (configId) {
  const z = zoneLayouts[configId] ?? zoneLayouts[Number(configId)]
  if (!z) {
    const size = defaultZoneSize(peerCountsByConfig.get(configId) ?? 0)
    return Math.hypot(size.width, size.height) / 2
  }
  return Math.hypot(z.width, z.height) / 2
}

/** Подогнать зону под bbox всех узлов конфига (расширение и сжатие). */
function syncZoneToNodes (configId) {
  const cid = Number(configId)
  if (!Number.isFinite(cid)) return
  const bbox = computeZoneBBox(cid)
  if (!bbox) return
  const pad = ZONE_PADDING
  const min = zoneMinSize()
  const x = bbox.minX - pad
  const y = bbox.minY - pad - ZONE_HEADER_TOTAL
  const width = Math.max(min.width, bbox.maxX - bbox.minX + pad * 2)
  const height = Math.max(min.height, bbox.maxY - bbox.minY + pad * 2 + ZONE_HEADER_TOTAL)
  const z = zoneLayouts[cid] ?? zoneLayouts[configId]
  if (!z) {
    ensureZoneLayout(cid, x, y, width, height)
    return
  }
  z.x = x
  z.y = y
  z.width = width
  z.height = height
}

function syncAllZonesToNodes () {
  for (const configId of Object.keys(zoneLayouts)) {
    syncZoneToNodes(Number(configId))
  }
}

function resolveZoneOverlaps (configIds) {
  const zones = configIds.map((cid) => {
    const z = zoneLayouts[cid]
    if (!z) return null
    return {
      configId: cid,
      cx: z.x + z.width / 2,
      cy: z.y + z.height / 2,
      hw: z.width / 2,
      hh: z.height / 2
    }
  }).filter(Boolean)

  for (let iter = 0; iter < 80; iter++) {
    let moved = false
    for (let i = 0; i < zones.length; i++) {
      for (let j = i + 1; j < zones.length; j++) {
        const a = zones[i]
        const b = zones[j]
        const dx = b.cx - a.cx
        const dy = b.cy - a.cy
        const overlapX = a.hw + b.hw + ZONE_GAP - Math.abs(dx)
        const overlapY = a.hh + b.hh + ZONE_GAP - Math.abs(dy)
        if (overlapX > 0 && overlapY > 0) {
          if (overlapX < overlapY) {
            const dir = dx === 0 ? 1 : Math.sign(dx)
            const push = (overlapX / 2) * dir
            a.cx -= push
            b.cx += push
          } else {
            const dir = dy === 0 ? 1 : Math.sign(dy)
            const push = (overlapY / 2) * dir
            a.cy -= push
            b.cy += push
          }
          moved = true
        }
      }
    }
    if (!moved) break
  }

  for (const z of zones) {
    const layout = zoneLayouts[z.configId]
    if (!layout) continue
    const newX = z.cx - layout.width / 2
    const newY = z.cy - layout.height / 2
    moveZoneByDelta(z.configId, newX - layout.x, newY - layout.y)
  }
}

function countPeersByConfig (nodeMap) {
  const counts = new Map()
  for (const node of Object.values(nodeMap)) {
    if (!node.isServer) {
      counts.set(node.configId, (counts.get(node.configId) || 0) + 1)
    }
  }
  return counts
}

function placeNewZones (nodeMap) {
  const configIds = [...new Set(
    Object.values(nodeMap).map((n) => n.configId).filter((v) => v !== undefined)
  )].sort((a, b) => a - b)
  const peerCounts = countPeersByConfig(nodeMap)
  const missing = configIds.filter((id) => !zoneLayouts[id])
  if (missing.length === 0) return

  if (Object.keys(zoneLayouts).length === 0) {
    let x = 0
    configIds.forEach((cid) => {
      const size = defaultZoneSize(peerCounts.get(cid) || 0)
      ensureZoneLayout(cid, x, 0, size.width, size.height)
      x += size.width + ZONE_GAP
    })
    resolveZoneOverlaps(configIds)
    return
  }

  const placed = configIds
    .filter((id) => zoneLayouts[id])
    .map((id) => {
      const z = zoneLayouts[id]
      return {
        id,
        cx: z.x + z.width / 2,
        cy: z.y + z.height / 2,
        r: zoneCollisionRadius(id)
      }
    })

  for (const cid of missing) {
    const size = defaultZoneSize(peerCounts.get(cid) || 0)
    const r = Math.hypot(size.width, size.height) / 2
    let cx = 0
    let cy = 0
    let found = placed.length === 0
    for (let attempt = 0; attempt < 200 && !found; attempt++) {
      const angle = attempt * 0.85
      const dist = 80 + attempt * 18
      cx = Math.cos(angle) * dist
      cy = Math.sin(angle) * dist
      found = placed.every((p) => {
        const dx = cx - p.cx
        const dy = cy - p.cy
        return Math.hypot(dx, dy) >= r + p.r + ZONE_GAP
      })
    }
    ensureZoneLayout(cid, cx - size.width / 2, cy - size.height / 2, size.width, size.height)
    placed.push({ id: cid, cx, cy, r })
  }
  resolveZoneOverlaps(configIds)
}

function computeClusterAnchors (nodeMap) {
  placeNewZones(nodeMap)
  const map = new Map()
  for (const node of Object.values(nodeMap)) {
    if (node.configId === undefined || !zoneLayouts[node.configId]) continue
    const sid = serverNodeId(node.configId)
    const server = layouts.nodes[sid]
    if (server) {
      map.set(node.configId, { x: server.x, y: server.y })
    } else {
      map.set(node.configId, zoneContentCenter(zoneLayouts[node.configId]))
    }
  }
  return map
}

function moveZoneByDelta (configId, dx, dy) {
  if (!dx && !dy) return
  const zone = zoneLayouts[configId]
  if (zone) {
    zone.x += dx
    zone.y += dy
  }
  for (const [id, node] of Object.entries(nodes)) {
    if (node.configId !== configId) continue
    const layout = layouts.nodes[id]
    if (!layout) continue
    layout.x += dx
    layout.y += dy
  }
}

function relayoutZonePeers (configId) {
  const anchor = zoneAnchor(configId)
  const peerIds = Object.entries(nodes)
    .filter(([, node]) => node.configId === configId && !node.isServer)
    .map(([id]) => id)
    .sort()
  peerIds.forEach((id, index) => {
    const layout = layouts.nodes[id]
    if (layout?.fixed) return
    const pos = peerRingPosition(anchor, index, peerIds.length)
    if (layout) {
      layout.x = pos.x
      layout.y = pos.y
    } else {
      layouts.nodes[id] = pos
    }
  })
}

/** Пиры на одном кольце при n≤4; иначе два кольца — меньше пересечений хорд mesh. */
function peerRingPosition (anchor, index, total) {
  const inner = PEER_RING_INNER
  const outer = PEER_RING_OUTER
  if (total <= 4) {
    const angle = (2 * Math.PI * index) / total - Math.PI / 2
    return {
      x: anchor.x + Math.cos(angle) * inner,
      y: anchor.y + Math.sin(angle) * inner
    }
  }
  const innerCount = Math.ceil(total / 2)
  const onInner = index < innerCount
  const ringIndex = onInner ? index : index - innerCount
  const ringTotal = onInner ? innerCount : total - innerCount
  const radius = onInner ? inner : outer
  const angleOffset = onInner ? 0 : Math.PI / ringTotal
  const angle = (2 * Math.PI * ringIndex) / ringTotal - Math.PI / 2 + angleOffset
  return {
    x: anchor.x + Math.cos(angle) * radius,
    y: anchor.y + Math.sin(angle) * radius
  }
}

function relayoutAllZones () {
  for (const configId of Object.keys(zoneLayouts)) {
    const cid = Number(configId)
    const z = zoneLayouts[cid]
    const sid = serverNodeId(cid)
    if (z && layouts.nodes[sid]) {
      const center = zoneContentCenter(z)
      layouts.nodes[sid].x = center.x
      layouts.nodes[sid].y = center.y
      layouts.nodes[sid].fixed = true
    }
    relayoutZonePeers(cid)
  }
  syncAllZonesToNodes()
  layoutTick.value++
}

function arrangeZonesHorizontally () {
  const configIds = Object.keys(zoneLayouts)
    .map(Number)
    .sort((a, b) => a - b)
  if (configIds.length === 0) return

  // Сброс к состоянию как при загрузке: дефолтные размеры, пиры снова на кольце
  for (const [id, node] of Object.entries(nodes)) {
    const layout = layouts.nodes[id]
    if (!layout) continue
    if (node.isServer) {
      layout.fixed = true
    } else {
      layout.fixed = false
    }
  }

  let x = 0
  for (const cid of configIds) {
    const size = defaultZoneSize(peerCountsByConfig.get(cid) ?? 0)
    const min = zoneMinSize()
    zoneLayouts[cid] = {
      x,
      y: 0,
      width: Math.max(min.width, size.width),
      height: Math.max(min.height, size.height)
    }
    x += zoneLayouts[cid].width + ZONE_GAP
  }
  resolveZoneOverlaps(configIds)
  relayoutAllZones()
  syncAllZonesToNodes()
}

function updateNodeLayouts (nodeMap) {
  const anchors = computeClusterAnchors(nodeMap)
  const peersByConfig = new Map()

  for (const [id, node] of Object.entries(nodeMap)) {
    if (node.isServer) {
      const anchor = anchors.get(node.configId) || { x: 0, y: 0 }
      const prev = layouts.nodes[id]
      if (!prev) {
        layouts.nodes[id] = { x: anchor.x, y: anchor.y, fixed: true }
      }
      continue
    }
    const list = peersByConfig.get(node.configId) || []
    list.push(id)
    peersByConfig.set(node.configId, list)
  }

  for (const id of Object.keys(layouts.nodes)) {
    if (!nodeMap[id]) delete layouts.nodes[id]
  }

  const activeConfigIds = new Set(
    Object.values(nodeMap).map((n) => n.configId).filter((v) => v !== undefined)
  )
  for (const id of Object.keys(zoneLayouts)) {
    if (!activeConfigIds.has(Number(id))) delete zoneLayouts[id]
  }

  for (const [configId, peerIds] of peersByConfig) {
    const anchor = anchors.get(configId) || { x: 0, y: 0 }
    const sorted = [...peerIds].sort()
    const prevCount = peerCountsByConfig.get(configId) ?? -1
    const countChanged = prevCount !== sorted.length
    peerCountsByConfig.set(configId, sorted.length)

    sorted.forEach((id, index) => {
      const prev = layouts.nodes[id]
      if (prev?.fixed) return
      const pos = peerRingPosition(anchor, index, sorted.length)
      const collapsed = prev && Math.hypot(prev.x - anchor.x, prev.y - anchor.y) < 30
      if (prev && !countChanged && !collapsed) return
      if (prev) {
        prev.x = pos.x
        prev.y = pos.y
      } else {
        layouts.nodes[id] = pos
      }
    })
  }

  for (const cid of activeConfigIds) {
    syncZoneToNodes(cid)
  }

  for (const key of peerCountsByConfig.keys()) {
    if (!activeConfigIds.has(key)) peerCountsByConfig.delete(key)
  }
}

// Предыдущие значения счётчиков трафика по membership_id (вне реактивности)
const prevStats = new Map()

function serverNodeId (configId) {
  return `srv-${configId}`
}

function peerNodeId (membershipId) {
  return `m-${membershipId}`
}

function peerRates (p) {
  const now = Date.now()
  const rx = Number(p.transfer_rx) || 0
  const tx = Number(p.transfer_tx) || 0
  const prev = prevStats.get(p.membership_id)
  // Слишком частый пересчёт (< 1с) — оставляем прошлые скорости
  if (prev && now - prev.t < 1000) {
    return { rxRate: prev.rxRate, txRate: prev.txRate }
  }
  let rxRate = 0
  let txRate = 0
  if (prev) {
    const dt = (now - prev.t) / 1000
    // кламп в 0 — счётчики сбрасываются при рестарте AWG
    rxRate = Math.max(0, rx - prev.rx) / dt
    txRate = Math.max(0, tx - prev.tx) / dt
  }
  prevStats.set(p.membership_id, { rx, tx, t: now, rxRate, txRate })
  return { rxRate, txRate }
}

function buildGraph () {
  const nextNodes = {}
  const nextEdges = {}

  for (const p of props.peers) {
    const sid = serverNodeId(p.config_id)
    if (!nextNodes[sid]) {
      nextNodes[sid] = {
        name: p.config_name,
        iface: p.config_iface || '',
        configId: p.config_id,
        isServer: true
      }
    }
    const pid = peerNodeId(p.membership_id)
    const { rxRate, txRate } = peerRates(p)
    nextNodes[pid] = {
      name: p.name || `peer ${p.membership_id}`,
      address: p.address,
      allowedIps: p.client_allowed_ips,
      online: !!p.online,
      enabled: !!p.enabled,
      configId: p.config_id,
      isServer: false,
      rxRate,
      txRate,
      transferRx: Number(p.transfer_rx) || 0,
      transferTx: Number(p.transfer_tx) || 0
    }
    const base = {
      source: pid,
      target: sid,
      peerLink: false,
      online: !!p.online,
      enabled: !!p.enabled
    }
    const hasUp = rxRate > 0
    const hasDown = txRate > 0
    if (!hasUp && !hasDown) {
      nextEdges[`t-${p.membership_id}`] = { ...base, trafficDir: null, rate: 0 }
    } else {
      if (hasUp) {
        nextEdges[`t-${p.membership_id}-up`] = {
          ...base,
          trafficDir: 'up',
          rate: rxRate,
          rxRate,
          txRate: 0
        }
      }
      if (hasDown) {
        nextEdges[`t-${p.membership_id}-down`] = {
          ...base,
          trafficDir: 'down',
          rate: txRate,
          rxRate: 0,
          txRate
        }
      }
    }
  }

  for (const l of props.links) {
    // Совместимость: старый формат a/b или новый from/to
    const fromId = l.from_membership_id ?? l.a_membership_id
    const toId = l.to_membership_id ?? l.b_membership_id
    const a = peerNodeId(fromId)
    const b = peerNodeId(toId)
    if (nextNodes[a] && nextNodes[b]) {
      nextEdges[`l-${fromId}-${toId}`] = {
        source: a,
        target: b,
        peerLink: true,
        bidirectional: !!l.bidirectional
      }
    }
  }

  // Обновляем на месте, чтобы не сбрасывать позиции узлов при поллинге
  for (const id of Object.keys(nodes)) {
    if (!nextNodes[id]) delete nodes[id]
  }
  for (const [id, node] of Object.entries(nextNodes)) {
    if (nodes[id]) {
      Object.assign(nodes[id], node)
    } else {
      nodes[id] = node
    }
  }
  for (const id of Object.keys(edges)) {
    if (!nextEdges[id]) delete edges[id]
  }
  for (const [id, edge] of Object.entries(nextEdges)) {
    if (edges[id]) {
      Object.assign(edges[id], edge)
    } else {
      edges[id] = edge
    }
  }

  updateNodeLayouts(nextNodes)
  scheduleFitIfNeeded()

  const memberIds = new Set(props.peers.map((p) => p.membership_id))
  for (const id of prevStats.keys()) {
    if (!memberIds.has(id)) prevStats.delete(id)
  }
}

watch(() => [props.peers, props.links], buildGraph, { immediate: true, deep: true })

/** Пока тянем пир — зона следует за bbox каждый кадр (rAF в startPeerDragZoneSync). */
watch(
  () => {
    const id = peerDragNodeId.value
    if (!id) return null
    const l = layouts.nodes[id]
    if (!l) return null
    return `${id}:${l.x}:${l.y}`
  },
  () => {
    const id = peerDragNodeId.value
    if (!id) return
    const cfg = nodes[id]?.configId
    if (cfg === undefined) return
    syncZoneToNodes(cfg)
    layoutTick.value++
  },
  { flush: 'sync' }
)

function topologySignature () {
  const configs = [...new Set(props.peers.map((p) => p.config_id))].sort((a, b) => a - b).join(',')
  const members = props.peers.map((p) => p.membership_id).sort((a, b) => a - b).join(',')
  const linkKeys = props.links.map((l) => {
    const from = l.from_membership_id ?? l.a_membership_id
    const to = l.to_membership_id ?? l.b_membership_id
    return `${from}-${to}`
  }).sort().join(',')
  return `${configs}|${members}|${linkKeys}`
}

let lastTopologySig = ''
watch(topologySignature, (sig) => {
  if (!lastTopologySig) {
    lastTopologySig = sig
    return
  }
  if (sig !== lastTopologySig) {
    lastTopologySig = sig
    relayoutAllZones()
    activeSimulation?.alpha(0)
    fitGraphToView()
  }
})

function nodeColor (node) {
  const colors = g.value
  if (node.isServer) return colors.server
  if (!node.enabled) return colors.disabled
  return node.online ? colors.online : colors.offline
}

function tunnelActive (edge) {
  return !edge.peerLink && !!edge.trafficDir && (edge.rate || 0) > 0
}

function edgeColor (edge) {
  const colors = g.value
  if (edge.peerLink) return colors.peerLink
  if (!edge.enabled) return colors.tunnelDisabled
  return edge.online ? colors.tunnelOnline : colors.tunnelOffline
}

function edgeAnimationSpeed (edge) {
  const rate = edge.rate || 0
  if (rate <= 0 || !edge.trafficDir) return 0
  // Медленная анимация — стрелки ↑/↓ на одном туннеле не налезают друг на друга
  const speed = Math.min(18, Math.max(3, 3 + 3 * Math.log10(1 + rate / 2048)))
  // source=пир, target=сервер: up (rx) → к серверу; down (tx) → к пиру
  return edge.trafficDir === 'down' ? -speed : speed
}

function formatRate (bps) {
  const v = Number(bps) || 0
  if (v < 1024) return `${Math.round(v)} B/s`
  if (v < 1024 ** 2) return `${(v / 1024).toFixed(1)} KB/s`
  if (v < 1024 ** 3) return `${(v / 1024 ** 2).toFixed(1)} MB/s`
  return `${(v / 1024 ** 3).toFixed(2)} GB/s`
}

function formatBytes (n) {
  const v = Number(n) || 0
  if (v < 1024) return `${v} B`
  if (v < 1024 ** 2) return `${(v / 1024).toFixed(1)} KB`
  if (v < 1024 ** 3) return `${(v / 1024 ** 2).toFixed(1)} MB`
  return `${(v / 1024 ** 3).toFixed(2)} GB`
}

function edgeRateLabel (edge) {
  if (!tunnelActive(edge)) return ''
  // Стрелки с точки зрения клиента: ↑ upload (rx сервера), ↓ download (tx сервера)
  if (edge.trafficDir === 'up') return `↑ ${formatRate(edge.rate)}`
  if (edge.trafficDir === 'down') return `↓ ${formatRate(edge.rate)}`
  return ''
}

function createForceSimulation (d3, forceNodes) {
  const isServerNode = (d) => !!nodes[d.id]?.isServer

  for (const d of forceNodes) {
    const layout = layouts.nodes[d.id]
    const x = layout?.x ?? 0
    const y = layout?.y ?? 0
    d.x = x
    d.y = y
    if (isServerNode(d) || layout?.fixed) {
      d.fx = x
      d.fy = y
    } else {
      delete d.fx
      delete d.fy
    }
  }

  const sim = d3.forceSimulation(forceNodes).alpha(0)
  sim.stop()

  activeSimulation = sim
  return sim
}

const forceLayoutHandler = new ForceLayout({
  positionFixedByDrag: true,
  noAutoRestartSimulation: true,
  createSimulation: createForceSimulation
})

const configs = reactive(defineConfigs({
  view: {
    scalingObjects: true,
    autoPanAndZoomOnLoad: false,
    minZoomLevel: 0.4,
    maxZoomLevel: 4,
    builtInLayerOrder: ['paths', 'node-labels', 'nodes', 'focusring', 'edge-labels', 'edges'],
    layoutHandler: forceLayoutHandler
  },
  node: {
    selectable: false,
    normal: {
      radius: (node) => (node.isServer ? SERVER_RADIUS : NODE_RADIUS),
      color: nodeColor
    },
    hover: {
      radius: (node) => (node.isServer ? SERVER_RADIUS + 2 : NODE_RADIUS + 2),
      color: nodeColor
    },
    label: {
      visible: true,
      color: () => g.value.label,
      fontSize: 12,
      directionAutoAdjustment: true
    }
  },
  edge: {
    selectable: false,
    type: 'straight',
    gap: 18,
    summarize: false,
    normal: {
      width: (edge) => (edge.peerLink ? 2.5 : (tunnelActive(edge) ? 2.5 : 2)),
      color: edgeColor,
      dasharray: (edge) => (edge.peerLink ? '6 4' : (tunnelActive(edge) ? '6 14' : '0')),
      animate: tunnelActive,
      animationSpeed: edgeAnimationSpeed
    },
    hover: {
      width: (edge) => (edge.peerLink ? 3.5 : 3),
      color: (edge) => {
        const colors = g.value
        return edge.peerLink ? colors.peerLinkHover : (edge.online ? colors.tunnelOnline : colors.offline)
      },
      dasharray: (edge) => (edge.peerLink ? '6 4' : (tunnelActive(edge) ? '6 14' : '0')),
      animate: tunnelActive,
      animationSpeed: edgeAnimationSpeed
    },
    // Стрелка на target = «from ходит к to»; при bidirectional — стрелки с обеих сторон.
    // Callback маркера получает [edge, stroke] одним аргументом.
    marker: {
      source: {
        type: ([edge]) => (edge.peerLink && edge.bidirectional ? 'arrow' : 'none'),
        width: 5,
        height: 5,
        margin: -1,
        offset: 0,
        units: 'strokeWidth',
        color: null
      },
      target: {
        type: ([edge]) => (edge.peerLink ? 'arrow' : 'none'),
        width: 5,
        height: 5,
        margin: -1,
        offset: 0,
        units: 'strokeWidth',
        color: null
      }
    }
  }
}))

const graph = ref(null)
const graphWrap = ref(null)
const tooltip = ref(null)
const targetNodeId = ref('')
const tooltipOpacity = ref(0)
const tooltipPos = ref({ left: '0px', top: '0px' })
const viewportBox = ref(null)
const viewZoom = ref(1)
const minimapSvg = ref(null)
const minimapDrag = ref(null)

function screenFontSize (base) {
  const z = viewZoom.value
  if (!Number.isFinite(z) || z <= 0) return base
  return base / z
}

const tooltipNode = computed(() => nodes[targetNodeId.value] || null)

const zoneVisuals = computed(() => {
  layoutTick.value
  const names = new Map()
  for (const node of Object.values(nodes)) {
    if (node.isServer) names.set(node.configId, node.name)
  }

  return Object.keys(zoneLayouts).map((cid) => {
    const configId = Number(cid)
    const rect = buildZoneRect(configId)
    if (!rect || rect.width <= 0 || rect.height <= 0) return null
    return {
      configId,
      name: names.get(configId) || `config ${configId}`,
      x: rect.x,
      y: rect.y,
      width: rect.width,
      height: rect.height
    }
  }).filter(Boolean)
})

const minimapModel = computed(() => {
  layoutTick.value
  const zones = zoneVisuals.value
  if (!zones.length) return null

  let minX = Infinity
  let minY = Infinity
  let maxX = -Infinity
  let maxY = -Infinity
  for (const z of zones) {
    minX = Math.min(minX, z.x)
    minY = Math.min(minY, z.y)
    maxX = Math.max(maxX, z.x + z.width)
    maxY = Math.max(maxY, z.y + z.height)
  }

  const worldW = Math.max(1, maxX - minX)
  const worldH = Math.max(1, maxY - minY)
  const sx = (MINIMAP_W - MINIMAP_PAD * 2) / worldW
  const sy = (MINIMAP_H - MINIMAP_PAD * 2) / worldH
  const s = Math.min(sx, sy)
  const ox = MINIMAP_PAD + ((MINIMAP_W - MINIMAP_PAD * 2) - worldW * s) / 2
  const oy = MINIMAP_PAD + ((MINIMAP_H - MINIMAP_PAD * 2) - worldH * s) / 2

  const toMini = (x, y) => ({
    x: ox + (x - minX) * s,
    y: oy + (y - minY) * s
  })

  const rects = zones.map((z) => {
    const p = toMini(z.x, z.y)
    const shortName = z.name.length > 10 ? `${z.name.slice(0, 9)}…` : z.name
    return {
      configId: z.configId,
      name: z.name,
      shortName,
      x: p.x,
      y: p.y,
      width: Math.max(4, z.width * s),
      height: Math.max(4, z.height * s)
    }
  })

  // Fixed screen-sized indicator: cancel zoom so the block does not
  // shrink/grow when zooming; only its position follows the camera.
  let viewport = null
  const vb = viewportBox.value
  const zoom = viewZoom.value
  if (
    vb &&
    Number.isFinite(vb.left) &&
    Number.isFinite(vb.top) &&
    Number.isFinite(zoom) &&
    zoom > 0
  ) {
    const cx = (vb.left + vb.right) / 2
    const cy = (vb.top + vb.bottom) / 2
    const center = toMini(cx, cy)
    const vw = Math.max(8, (vb.right - vb.left) * zoom * s)
    const vh = Math.max(8, (vb.bottom - vb.top) * zoom * s)
    viewport = {
      x: center.x - vw / 2,
      y: center.y - vh / 2,
      width: vw,
      height: vh
    }
  }

  return {
    rects,
    viewport,
    count: zones.length,
    transform: { minX, minY, s, ox, oy }
  }
})

const zoneDrag = ref(null)
const zoneInteraction = ref(null)

function domToSvg (clientX, clientY) {
  const g = graph.value
  if (!g) return { x: 0, y: 0 }
  return g.translateFromDomToSvgCoordinates({ x: clientX, y: clientY })
}

function applyZoneMove (configId, dx, dy) {
  moveZoneByDelta(configId, dx, dy)
  layoutTick.value++
}

function startZoneDrag (configId, event) {
  const zone = zoneLayouts[configId]
  if (!zone) return
  zoneInteraction.value = {
    mode: 'move',
    configId,
    pointerId: event.pointerId,
    lastClientX: event.clientX,
    lastClientY: event.clientY
  }
  zoneDrag.value = { configId }
  event.target.setPointerCapture?.(event.pointerId)
}

function onZonePointerMove (event) {
  const zi = zoneInteraction.value
  if (!zi || zi.pointerId !== event.pointerId) return
  if (zi.mode !== 'move') return

  const prev = domToSvg(zi.lastClientX, zi.lastClientY)
  const cur = domToSvg(event.clientX, event.clientY)
  applyZoneMove(zi.configId, cur.x - prev.x, cur.y - prev.y)
  zi.lastClientX = event.clientX
  zi.lastClientY = event.clientY
}

function onZonePointerUp (event) {
  const zi = zoneInteraction.value
  if (!zi || zi.pointerId !== event.pointerId) return
  zoneInteraction.value = null
  zoneDrag.value = null
}

function refreshViewportBox () {
  const box = graph.value?.getViewBox?.()
  if (box) {
    viewportBox.value = {
      top: box.top,
      left: box.left,
      bottom: box.bottom,
      right: box.right
    }
  }
}

function clientToMinimap (clientX, clientY) {
  const svg = minimapSvg.value
  if (!svg) return { x: 0, y: 0 }
  const rect = svg.getBoundingClientRect()
  if (!rect.width || !rect.height) return { x: 0, y: 0 }
  return {
    x: ((clientX - rect.left) / rect.width) * MINIMAP_W,
    y: ((clientY - rect.top) / rect.height) * MINIMAP_H
  }
}

function minimapToWorld (mx, my) {
  const t = minimapModel.value?.transform
  if (!t || !t.s) return { x: 0, y: 0 }
  return {
    x: t.minX + (mx - t.ox) / t.s,
    y: t.minY + (my - t.oy) / t.s
  }
}

function panViewPreservingZoom (worldCx, worldCy) {
  const gInst = graph.value
  const vb = viewportBox.value
  if (!gInst || !vb) return
  const halfW = (vb.right - vb.left) / 2
  const halfH = (vb.bottom - vb.top) / 2
  if (!Number.isFinite(halfW) || !Number.isFinite(halfH) || halfW <= 0 || halfH <= 0) return
  gInst.setViewBox({
    left: worldCx - halfW,
    right: worldCx + halfW,
    top: worldCy - halfH,
    bottom: worldCy + halfH
  })
  refreshViewportBox()
}

function startMinimapViewportDrag (event) {
  if (event.button != null && event.button !== 0) return
  const vb = viewportBox.value
  if (!vb) return
  const mini = clientToMinimap(event.clientX, event.clientY)
  minimapDrag.value = {
    pointerId: event.pointerId,
    lastMiniX: mini.x,
    lastMiniY: mini.y,
    worldCx: (vb.left + vb.right) / 2,
    worldCy: (vb.top + vb.bottom) / 2
  }
  event.currentTarget?.setPointerCapture?.(event.pointerId)
}

function onMinimapViewportMove (event) {
  const drag = minimapDrag.value
  if (!drag || drag.pointerId !== event.pointerId) return
  const mini = clientToMinimap(event.clientX, event.clientY)
  const prev = minimapToWorld(drag.lastMiniX, drag.lastMiniY)
  const cur = minimapToWorld(mini.x, mini.y)
  drag.worldCx += cur.x - prev.x
  drag.worldCy += cur.y - prev.y
  drag.lastMiniX = mini.x
  drag.lastMiniY = mini.y
  panViewPreservingZoom(drag.worldCx, drag.worldCy)
}

function onMinimapViewportUp (event) {
  const drag = minimapDrag.value
  if (!drag || drag.pointerId !== event.pointerId) return
  minimapDrag.value = null
}

async function fitGraphToView () {
  arrangeZonesHorizontally()
  await nextTick()
  await new Promise((resolve) => requestAnimationFrame(resolve))
  const gInst = graph.value
  if (!gInst) return
  const box = computeContentViewBox(FIT_MARGIN)
  if (!box) return
  gInst.setViewBox(box)
  refreshViewportBox()
}

function focusZone (configId) {
  const zone = zoneVisuals.value.find((z) => z.configId === configId)
  const gInst = graph.value
  if (!zone || !gInst) return
  const m = ZONE_FOCUS_MARGIN
  gInst.setViewBox({
    top: zone.y - m,
    left: zone.x - m,
    bottom: zone.y + zone.height + m,
    right: zone.x + zone.width + m
  })
  refreshViewportBox()
}

function computeContentViewBox (margin = FIT_MARGIN) {
  let minX = Infinity
  let minY = Infinity
  let maxX = -Infinity
  let maxY = -Infinity

  for (const [id, layout] of Object.entries(layouts.nodes)) {
    const node = nodes[id]
    if (!layout || !node) continue
    const r = node.isServer ? SERVER_RADIUS : NODE_RADIUS
    minX = Math.min(minX, layout.x - r - LABEL_MARGIN * 0.35)
    maxX = Math.max(maxX, layout.x + r + LABEL_MARGIN * 0.35)
    minY = Math.min(minY, layout.y - r - ZONE_HEADER_TOTAL)
    maxY = Math.max(maxY, layout.y + r + LABEL_MARGIN)
  }

  if (!Number.isFinite(minX)) return null
  return {
    top: minY - margin,
    left: minX - margin,
    bottom: maxY + margin,
    right: maxX + margin
  }
}

function zoomIn () {
  graph.value?.zoomIn?.()
  nextTick(refreshViewportBox)
}

function zoomOut () {
  graph.value?.zoomOut?.()
  nextTick(refreshViewportBox)
}

function scheduleFitIfNeeded () {
  if (hasInitialFit) return
  nextTick(() => {
    requestAnimationFrame(() => {
      if (hasInitialFit) return
      hasInitialFit = true
      fitGraphToView()
    })
  })
}

defineExpose({ fitGraphToView })

onMounted(() => {
  window.addEventListener('pointermove', onZonePointerMove)
  window.addEventListener('pointerup', onZonePointerUp)
  window.addEventListener('pointercancel', onZonePointerUp)
  window.addEventListener('pointermove', onMinimapViewportMove)
  window.addEventListener('pointerup', onMinimapViewportUp)
  window.addEventListener('pointercancel', onMinimapViewportUp)
})

onUnmounted(() => {
  stopPeerDragZoneSync()
  window.removeEventListener('pointermove', onZonePointerMove)
  window.removeEventListener('pointerup', onZonePointerUp)
  window.removeEventListener('pointercancel', onZonePointerUp)
  window.removeEventListener('pointermove', onMinimapViewportMove)
  window.removeEventListener('pointerup', onMinimapViewportUp)
  window.removeEventListener('pointercancel', onMinimapViewportUp)
})

function placeTooltip (clientX, clientY, show = false) {
  const wrap = graphWrap.value
  const tip = tooltip.value
  if (!wrap || !tip) return
  const rect = wrap.getBoundingClientRect()
  nextTick(() => {
    const tw = tip.offsetWidth || 160
    const th = tip.offsetHeight || 40
    let x = clientX - rect.left - tw / 2
    let y = clientY - rect.top - th - 14
    x = Math.min(Math.max(8, x), Math.max(8, rect.width - tw - 8))
    if (y < 8) y = clientY - rect.top + 18
    tooltipPos.value = { left: `${x}px`, top: `${y}px` }
    if (show) tooltipOpacity.value = 1
  })
}

const eventHandlers = {
  'view:zoom': (level) => {
    if (Number.isFinite(level) && level > 0) viewZoom.value = level
    refreshViewportBox()
  },
  'view:pan': () => {
    refreshViewportBox()
  },
  'node:dragstart': (positions) => {
    const node = dragNodeIdFromPositions(positions)
    if (!node) return
    const n = nodes[node]
    if (!n) return
    peerDragNodeId.value = node
    startPeerDragZoneSync()
  },
  'node:pointerover': ({ node, event }) => {
    targetNodeId.value = node
    if (event) placeTooltip(event.clientX, event.clientY, true)
  },
  'node:pointermove': (positions) => {
    const peerId = peerDragNodeId.value
    if (peerId && positions[peerId]) {
      const layout = layouts.nodes[peerId]
      if (layout) {
        layout.x = positions[peerId].x
        layout.y = positions[peerId].y
      }
      const cfg = nodes[peerId]?.configId
      if (cfg !== undefined) {
        syncZoneToNodes(cfg)
        layoutTick.value++
      }
    }
  },
  'node:dragend': (positions) => {
    const peerId = peerDragNodeId.value
    if (peerId) {
      const pos = positions[peerId]
      if (pos) {
        const layout = layouts.nodes[peerId]
        if (layout) {
          layout.x = pos.x
          layout.y = pos.y
        }
      }
      const cfg = nodes[peerId]?.configId
      peerDragNodeId.value = null
      stopPeerDragZoneSync()
      if (cfg !== undefined) {
        syncZoneToNodes(cfg)
        layoutTick.value++
      } else {
        layoutTick.value++
      }
    }
  },
  'node:pointerout': () => {
    tooltipOpacity.value = 0
    targetNodeId.value = ''
  }
}
</script>

<style scoped>
.graph-wrap {
  position: relative;
  width: 100%;
  height: 700px;
}

.graph-wrap--fill {
  height: 100%;
  min-height: 0;
}

.graph-wrap :deep(.v-network-graph) {
  width: 100%;
  height: 100%;
}

.graph-wrap :deep(.v-ng-viewport) {
  background: transparent;
}

.graph-tooltip {
  position: absolute;
  top: 0;
  left: 0;
  z-index: 10;
  max-width: 360px;
  padding: 8px 10px;
  border-radius: var(--surface-radius);
  background: var(--surface-bg);
  border: 1px solid var(--surface-border);
  color: var(--surface-text-soft);
  font-size: 12px;
  pointer-events: none;
  transition: opacity 0.12s;
  will-change: left, top, opacity;
}

.graph-tooltip .allowed-ips {
  word-break: break-all;
  color: var(--surface-text-muted);
}

.graph-legend {
  position: absolute;
  left: 12px;
  bottom: 8px;
  color: var(--surface-text-muted);
  font-size: 12px;
  pointer-events: none;
}

.graph-zoom-controls {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 6;
  padding: 2px 4px;
  border-radius: var(--surface-radius);
  background: var(--surface-panel);
  border: 1px solid var(--surface-border);
  pointer-events: auto;
}

.graph-minimap {
  position: absolute;
  right: 8px;
  bottom: 8px;
  z-index: 6;
  width: 180px;
  padding: 6px 6px 4px;
  border-radius: var(--surface-radius);
  background: var(--surface-panel);
  border: 1px solid var(--surface-border);
  pointer-events: auto;
  user-select: none;
}

.graph-minimap-header {
  font-size: 11px;
  color: var(--surface-text-muted);
  margin-bottom: 4px;
  padding: 0 2px;
}

.graph-minimap-svg {
  display: block;
  width: 100%;
  height: auto;
  border-radius: 4px;
  background: color-mix(in srgb, var(--surface-bg) 70%, transparent);
}

.graph-minimap-zone {
  cursor: pointer;
}

.graph-minimap-zone:hover {
  stroke-width: 2;
  filter: brightness(1.08);
}

.graph-minimap-label {
  pointer-events: none;
  opacity: 0.85;
}

.graph-minimap-viewport {
  opacity: 0.95;
  cursor: grab;
  pointer-events: all;
}

.graph-minimap-viewport.is-dragging {
  cursor: grabbing;
}

.dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-right: 4px;
  vertical-align: middle;
}

.dot-server { background: var(--graph-server); }
.dot-online { background: var(--graph-online); }
.dot-offline { background: var(--graph-offline); }

.line {
  display: inline-block;
  width: 18px;
  height: 0;
  margin-right: 4px;
  vertical-align: middle;
}

.line-tunnel { border-top: 2px solid var(--graph-tunnel-offline); }
.line-peer { border-top: 2px dashed var(--graph-peer-link); }

.line-peer-dir {
  margin-right: 4px;
  vertical-align: middle;
}

.line-anim {
  margin-right: 4px;
  vertical-align: middle;
}

.zone-legend {
  margin-right: 4px;
  vertical-align: middle;
}

.graph-wrap :deep(.zone-drag-bar) {
  cursor: grab;
}

.graph-wrap :deep(.zone-drag-bar:active) {
  cursor: grabbing;
}

.graph-wrap :deep(.zone-title) {
  user-select: none;
  pointer-events: none;
}

.line-anim line:not(.line-anim-down) {
  animation: legend-dash 1s linear infinite;
}

.line-anim-down {
  animation: legend-dash-rev 1s linear infinite;
}

@keyframes legend-dash {
  to { stroke-dashoffset: -16; }
}

@keyframes legend-dash-rev {
  to { stroke-dashoffset: 16; }
}
</style>
