import messageSound from '../sounds/message.wav';

/** localStorage key for the Sounds switch; `'off'` mutes, anything else (or nothing) plays. */
export const SOUND_STORAGE_KEY = 'unified-assistant.sounds';

/** Two replies close together chime once. */
export const SOUND_GAP_MS = 2000;

/** The asset is already quiet (about -12 dBFS). */
const SOUND_VOLUME = 0.6;

const UNLOCK_EVENTS = ['pointerdown', 'keydown', 'touchstart'] as const;

/**
 * The chime for a reply from our team. Browsers refuse audio before the person
 * has touched the page, so it stays silent (and creates nothing) until the
 * first pointer, key or touch anywhere on the page. A refused play is ignored.
 */
export class MessageSound {
    private unlocked = false;

    private lastPlayed = Number.NEGATIVE_INFINITY;

    private audio: HTMLAudioElement | null = null;

    /** What the switch was last set to on this page, for when storage is unavailable. */
    private mutedHere = false;

    private readonly unlock = (): void => {
        this.unlocked = true;

        for (const name of UNLOCK_EVENTS) {
            document.removeEventListener(name, this.unlock, true);
        }
    };

    constructor(private readonly src: string = messageSound) {
        for (const name of UNLOCK_EVENTS) {
            document.addEventListener(name, this.unlock, { capture: true, passive: true });
        }
    }

    setMuted(muted: boolean): void {
        this.mutedHere = muted;

        try {
            localStorage.setItem(SOUND_STORAGE_KEY, muted ? 'off' : 'on');
        } catch {
            /* the switch still holds for this page */
        }
    }

    isMuted(): boolean {
        try {
            const stored = localStorage.getItem(SOUND_STORAGE_KEY);

            if (stored !== null) {
                return stored === 'off';
            }
        } catch {
            /* fall through */
        }

        return this.mutedHere;
    }

    play(): void {
        if (!this.unlocked || this.isMuted()) {
            return;
        }

        const now = Date.now();

        if (now - this.lastPlayed < SOUND_GAP_MS) {
            return;
        }

        this.lastPlayed = now;

        try {
            this.audio ??= new Audio(this.src);
            this.audio.volume = SOUND_VOLUME;
            this.audio.currentTime = 0;
            const played = this.audio.play();

            if (played && typeof played.catch === 'function') {
                played.catch(() => undefined);
            }
        } catch {
            /* no audio here; the message still shows */
        }
    }
}
