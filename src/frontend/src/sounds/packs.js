/**
 * Procedural UI sound packs per theme (Web Audio oscillators).
 * Original synthesis only — no game audio samples.
 */

function tone (ctx, {
  type = 'sine',
  freq = 440,
  duration = 0.08,
  gain = 0.1,
  attack = 0.005,
  decay = 0.06,
  freqEnd = null,
  delay = 0
} = {}) {
  const t0 = ctx.currentTime + delay
  const osc = ctx.createOscillator()
  const g = ctx.createGain()
  osc.type = type
  osc.frequency.setValueAtTime(freq, t0)
  if (freqEnd != null) {
    osc.frequency.exponentialRampToValueAtTime(Math.max(freqEnd, 1), t0 + duration)
  }
  g.gain.setValueAtTime(0.0001, t0)
  g.gain.exponentialRampToValueAtTime(gain, t0 + attack)
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + Math.max(attack + decay, duration))
  osc.connect(g)
  g.connect(ctx.destination)
  osc.start(t0)
  osc.stop(t0 + duration + 0.02)
}

function noiseBurst (ctx, { duration = 0.04, gain = 0.05, delay = 0 } = {}) {
  const sampleRate = ctx.sampleRate
  const length = Math.max(1, Math.floor(sampleRate * duration))
  const buffer = ctx.createBuffer(1, length, sampleRate)
  const data = buffer.getChannelData(0)
  for (let i = 0; i < length; i++) {
    data[i] = (Math.random() * 2 - 1) * (1 - i / length)
  }
  const src = ctx.createBufferSource()
  const g = ctx.createGain()
  const t0 = ctx.currentTime + delay
  src.buffer = buffer
  g.gain.setValueAtTime(gain, t0)
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + duration)
  src.connect(g)
  g.connect(ctx.destination)
  src.start(t0)
  src.stop(t0 + duration + 0.01)
}

/** Soft desktop UI — sine/triangle */
const classic = {
  click (ctx) {
    tone(ctx, { type: 'triangle', freq: 720, duration: 0.05, gain: 0.07, decay: 0.04 })
  },
  navigate (ctx) {
    tone(ctx, { type: 'sine', freq: 520, duration: 0.06, gain: 0.06, decay: 0.05 })
    tone(ctx, { type: 'sine', freq: 680, duration: 0.07, gain: 0.05, decay: 0.05, delay: 0.05 })
  },
  success (ctx) {
    tone(ctx, { type: 'sine', freq: 523, duration: 0.08, gain: 0.08, decay: 0.06 })
    tone(ctx, { type: 'sine', freq: 659, duration: 0.1, gain: 0.07, decay: 0.08, delay: 0.07 })
  },
  error (ctx) {
    tone(ctx, { type: 'triangle', freq: 280, duration: 0.12, gain: 0.09, decay: 0.1, freqEnd: 180 })
  },
  toggle (ctx) {
    tone(ctx, { type: 'sine', freq: 640, duration: 0.045, gain: 0.06, decay: 0.035 })
  },
  theme (ctx) {
    tone(ctx, { type: 'sine', freq: 440, duration: 0.08, gain: 0.07, decay: 0.06 })
    tone(ctx, { type: 'sine', freq: 554, duration: 0.1, gain: 0.06, decay: 0.08, delay: 0.08 })
    tone(ctx, { type: 'sine', freq: 659, duration: 0.12, gain: 0.05, decay: 0.1, delay: 0.16 })
  }
}

/** 8-bit CRT / chiptune */
const crt = {
  click (ctx) {
    tone(ctx, { type: 'square', freq: 880, duration: 0.04, gain: 0.06, attack: 0.001, decay: 0.03 })
  },
  navigate (ctx) {
    tone(ctx, { type: 'square', freq: 440, duration: 0.05, gain: 0.055, attack: 0.001, decay: 0.04 })
    tone(ctx, { type: 'square', freq: 660, duration: 0.05, gain: 0.05, attack: 0.001, decay: 0.04, delay: 0.045 })
  },
  success (ctx) {
    // coin-like arpeggio
    ;[784, 988, 1175].forEach((f, i) => {
      tone(ctx, { type: 'square', freq: f, duration: 0.06, gain: 0.055, attack: 0.001, decay: 0.045, delay: i * 0.05 })
    })
  },
  error (ctx) {
    tone(ctx, { type: 'sawtooth', freq: 160, duration: 0.14, gain: 0.07, attack: 0.001, decay: 0.12, freqEnd: 90 })
  },
  toggle (ctx) {
    tone(ctx, { type: 'square', freq: 1100, duration: 0.035, gain: 0.05, attack: 0.001, decay: 0.025 })
  },
  theme (ctx) {
    ;[330, 392, 494, 659].forEach((f, i) => {
      tone(ctx, { type: 'square', freq: f, duration: 0.07, gain: 0.05, attack: 0.001, decay: 0.05, delay: i * 0.06 })
    })
  }
}

/** Low, soft metallic / chirp — DS-inspired (original) */
const ds = {
  click (ctx) {
    tone(ctx, { type: 'sine', freq: 210, duration: 0.07, gain: 0.08, decay: 0.055 })
    tone(ctx, { type: 'triangle', freq: 840, duration: 0.04, gain: 0.035, decay: 0.03, delay: 0.01 })
  },
  navigate (ctx) {
    tone(ctx, { type: 'sine', freq: 180, duration: 0.09, gain: 0.07, decay: 0.07, freqEnd: 260 })
    noiseBurst(ctx, { duration: 0.03, gain: 0.02, delay: 0.02 })
  },
  success (ctx) {
    tone(ctx, { type: 'sine', freq: 320, duration: 0.1, gain: 0.07, decay: 0.08 })
    tone(ctx, { type: 'sine', freq: 480, duration: 0.14, gain: 0.05, decay: 0.12, delay: 0.08 })
    tone(ctx, { type: 'triangle', freq: 960, duration: 0.06, gain: 0.03, decay: 0.05, delay: 0.12 })
  },
  error (ctx) {
    tone(ctx, { type: 'sine', freq: 140, duration: 0.16, gain: 0.09, decay: 0.14, freqEnd: 90 })
    noiseBurst(ctx, { duration: 0.06, gain: 0.035, delay: 0.02 })
  },
  toggle (ctx) {
    tone(ctx, { type: 'sine', freq: 280, duration: 0.05, gain: 0.06, decay: 0.04, freqEnd: 360 })
  },
  theme (ctx) {
    tone(ctx, { type: 'sine', freq: 160, duration: 0.12, gain: 0.07, decay: 0.1 })
    tone(ctx, { type: 'sine', freq: 240, duration: 0.14, gain: 0.055, decay: 0.12, delay: 0.1 })
    tone(ctx, { type: 'triangle', freq: 720, duration: 0.1, gain: 0.03, decay: 0.08, delay: 0.2 })
  }
}

/** Sharp menu-select / radio-tune — SA-inspired (original) */
const sa = {
  click (ctx) {
    tone(ctx, { type: 'square', freq: 520, duration: 0.035, gain: 0.065, attack: 0.001, decay: 0.025 })
    tone(ctx, { type: 'triangle', freq: 1040, duration: 0.025, gain: 0.03, attack: 0.001, decay: 0.02, delay: 0.01 })
  },
  navigate (ctx) {
    tone(ctx, { type: 'sawtooth', freq: 380, duration: 0.05, gain: 0.05, attack: 0.001, decay: 0.04 })
    tone(ctx, { type: 'square', freq: 760, duration: 0.04, gain: 0.04, attack: 0.001, decay: 0.03, delay: 0.04 })
  },
  success (ctx) {
    // radio-like tune sweep
    tone(ctx, { type: 'square', freq: 400, duration: 0.08, gain: 0.06, attack: 0.001, decay: 0.06, freqEnd: 600 })
    tone(ctx, { type: 'triangle', freq: 600, duration: 0.1, gain: 0.05, decay: 0.08, freqEnd: 900, delay: 0.07 })
  },
  error (ctx) {
    tone(ctx, { type: 'sawtooth', freq: 220, duration: 0.08, gain: 0.08, attack: 0.001, decay: 0.06 })
    tone(ctx, { type: 'sawtooth', freq: 180, duration: 0.1, gain: 0.07, attack: 0.001, decay: 0.08, delay: 0.09 })
  },
  toggle (ctx) {
    tone(ctx, { type: 'square', freq: 700, duration: 0.04, gain: 0.055, attack: 0.001, decay: 0.03 })
  },
  theme (ctx) {
    ;[349, 440, 523, 698].forEach((f, i) => {
      tone(ctx, {
        type: i % 2 === 0 ? 'square' : 'triangle',
        freq: f,
        duration: 0.07,
        gain: 0.05,
        attack: 0.001,
        decay: 0.05,
        delay: i * 0.055
      })
    })
  }
}

export const SOUND_PACKS = {
  classic,
  crt,
  ds,
  sa
}

export function getSoundPack (themeId) {
  return SOUND_PACKS[themeId] || SOUND_PACKS.classic
}
