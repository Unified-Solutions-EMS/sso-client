#!/usr/bin/env python3
"""Generates message.wav: the platform's soft two-tone message sound.

Original work, synthesized here (no samples, nothing licensed in): two sine
notes, E5 (659.25 Hz) then A5 (880 Hz), each with a 6 ms attack and an
exponential decay, a quiet peak (-12 dBFS), 16 kHz mono 16-bit PCM, 0.36 s.
Run: python3 make-message-sound.py [out.wav]. Same output every run.
"""
import math, struct, sys, wave

RATE = 16000
PEAK = 0.25  # about -12 dBFS
NOTES = [(0.000, 659.25, 0.20), (0.130, 880.00, 0.23)]  # (start s, Hz, length s)
LENGTH = 0.36

def sample(t: float) -> float:
    total = 0.0
    for start, freq, dur in NOTES:
        local = t - start
        if 0 <= local < dur:
            attack = min(1.0, local / 0.006)
            decay = math.exp(-local * 14.0)
            tail = min(1.0, (dur - local) / 0.02)
            total += math.sin(2 * math.pi * freq * local) * attack * decay * tail
    return total

out = sys.argv[1] if len(sys.argv) > 1 else 'message.wav'
frames = bytearray()
for i in range(int(RATE * LENGTH)):
    value = max(-1.0, min(1.0, sample(i / RATE) * PEAK))
    frames += struct.pack('<h', int(value * 32767))
with wave.open(out, 'wb') as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(RATE); w.writeframes(bytes(frames))
