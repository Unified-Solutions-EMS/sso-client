import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MessageSound, SOUND_GAP_MS, SOUND_STORAGE_KEY } from '../src/sound';

/**
 * A plain stand-in for play(), not a vi.fn: a spy attaches its own handler to
 * the promise it returns, which would hide the unhandled rejection the last
 * test looks for.
 */
type Play = () => Promise<void>;
let refuse: boolean;
let calls: HTMLMediaElement[];
let created: number;
const RealAudio = window.Audio;
const realPlay = HTMLMediaElement.prototype.play;

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    created = 0;
    calls = [];
    refuse = false;
    HTMLMediaElement.prototype.play = function (this: HTMLMediaElement) {
        calls.push(this);

        return refuse ? Promise.reject(new DOMException('play() failed', 'NotAllowedError')) : Promise.resolve();
    } as Play;
    vi.stubGlobal(
        'Audio',
        class extends RealAudio {
            constructor(src?: string) {
                super(src);
                created++;
            }
        },
    );
});

afterEach(() => {
    HTMLMediaElement.prototype.play = realPlay;
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

function interact(event = 'pointerdown'): void {
    document.dispatchEvent(new Event(event));
}

describe('MessageSound', () => {
    it('stays silent and creates nothing before the person touches the page', () => {
        const errors = vi.spyOn(console, 'error');
        const sound = new MessageSound('chime.wav');

        sound.play();

        expect(created).toBe(0);
        expect(calls).toHaveLength(0);
        expect(errors).not.toHaveBeenCalled();
    });

    it.each(['pointerdown', 'keydown', 'touchstart'])('plays after the first %s, from the start, at a quiet volume', (event) => {
        const sound = new MessageSound('chime.wav');
        interact(event);

        sound.play();

        expect(calls).toHaveLength(1);
        const audio = calls[0];
        expect(audio.volume).toBeCloseTo(0.6);
        expect(audio.currentTime).toBe(0);
    });

    it('plays at most once every 2 seconds and reuses one audio element', () => {
        const sound = new MessageSound('chime.wav');
        interact();

        sound.play();
        sound.play();
        vi.advanceTimersByTime(SOUND_GAP_MS - 1);
        sound.play();
        expect(calls).toHaveLength(1);

        vi.advanceTimersByTime(1);
        sound.play();
        expect(calls).toHaveLength(2);
        expect(created).toBe(1);
    });

    it('is muted by the stored switch, and the switch is remembered', () => {
        const sound = new MessageSound('chime.wav');
        interact();

        expect(sound.isMuted()).toBe(false);
        sound.setMuted(true);
        expect(localStorage.getItem(SOUND_STORAGE_KEY)).toBe('off');
        sound.play();
        expect(calls).toHaveLength(0);

        expect(new MessageSound('chime.wav').isMuted()).toBe(true);

        sound.setMuted(false);
        expect(localStorage.getItem(SOUND_STORAGE_KEY)).toBe('on');
        sound.play();
        expect(calls).toHaveLength(1);
    });

    it('keeps working when storage throws', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const sound = new MessageSound('chime.wav');
        interact();

        expect(sound.isMuted()).toBe(false);
        sound.setMuted(true);
        expect(sound.isMuted()).toBe(true);
        sound.play();
        expect(calls).toHaveLength(0);
    });

    it('swallows a refused play without an unhandled rejection or a console error', async () => {
        vi.useRealTimers();
        const errors = vi.spyOn(console, 'error');
        const unhandled: unknown[] = [];
        const onUnhandled = (reason: unknown): void => {
            unhandled.push(reason);
        };
        process.on('unhandledRejection', onUnhandled);
        refuse = true;

        const sound = new MessageSound('chime.wav');
        interact();
        sound.play();
        await new Promise((resolve) => setTimeout(resolve, 20));
        process.off('unhandledRejection', onUnhandled);

        expect(calls).toHaveLength(1);
        expect(unhandled).toEqual([]);
        expect(errors).not.toHaveBeenCalled();
    });
});
