import { describe, expect, it } from 'vitest';
import { ApiError } from '../src/api';
import { POLL_FAST_MS, POLL_GIVE_UP_MS, POLL_SLOW_MS, UPLOAD_COPY, nextPollDelay, stillReading, waitUntilRead } from '../src/upload';
import type { UploadStatus } from '../src/types';

/** A clock that only moves when the poller sleeps. */
function fakeClock(): { now: () => number; sleep: (ms: number) => Promise<void>; slept: number[] } {
    let time = 0;
    const slept: number[] = [];

    return {
        now: () => time,
        sleep: async (ms: number) => {
            slept.push(ms);
            time += ms;
        },
        slept,
    };
}

function answers(...steps: Array<UploadStatus | Error>): () => Promise<{ upload: UploadStatus }> {
    let i = 0;

    return async () => {
        const step = steps[Math.min(i++, steps.length - 1)];

        if (step instanceof Error) {
            throw step;
        }

        return { upload: step };
    };
}

describe('file reading status', () => {
    it('polls every 2 s for 30 s, then every 5 s, and gives up at 5 minutes', () => {
        expect(nextPollDelay(0)).toBe(POLL_FAST_MS);
        expect(nextPollDelay(29_999)).toBe(POLL_FAST_MS);
        expect(nextPollDelay(30_000)).toBe(POLL_SLOW_MS);
        expect(nextPollDelay(POLL_GIVE_UP_MS - 1000)).toBe(1000);
        expect(nextPollDelay(POLL_GIVE_UP_MS)).toBeNull();
    });

    it('waits only for an answer that says the file is still being read', () => {
        expect(stillReading({ status: 'reading' })).toBe(true);
        expect(stillReading({ status: 'ready' })).toBe(false);
        // An older SSO read the file in the request and sends no status.
        expect(stillReading({})).toBe(false);
        expect(stillReading(null)).toBe(false);
    });

    it('returns the read file once the status is ready', async () => {
        const clock = fakeClock();
        const outcome = await waitUntilRead(answers({ id: 7, status: 'reading' }, { id: 7, status: 'reading' }, { id: 7, status: 'ready', summary: 'A roster.' }), clock);

        expect(outcome).toEqual({ status: 'ready', upload: { id: 7, status: 'ready', summary: 'A roster.' } });
        expect(clock.slept).toEqual([2000, 2000, 2000]);
    });

    it('returns the reason a file could not be read', async () => {
        const outcome = await waitUntilRead(answers({ id: 7, status: 'failed', error: 'We could not open that iPhone photo.' }), fakeClock());

        expect(outcome).toEqual({ status: 'failed', error: 'We could not open that iPhone photo.' });
    });

    it('keeps asking through a network blip or a server error', async () => {
        const outcome = await waitUntilRead(answers(new ApiError(0, 'unreachable'), new ApiError(502, 'down'), { id: 7, status: 'ready' }), fakeClock());

        expect(outcome.status).toBe('ready');
    });

    it('stops when the file is not found', async () => {
        const outcome = await waitUntilRead(answers(new ApiError(404, 'Not found')), fakeClock());

        expect(outcome).toEqual({ status: 'failed', error: UPLOAD_COPY.gone });
    });

    it('gives up after 5 minutes with the too-long message', async () => {
        const clock = fakeClock();
        const outcome = await waitUntilRead(answers({ id: 7, status: 'reading' }), clock);

        expect(outcome).toEqual({ status: 'failed', error: UPLOAD_COPY.tooSlow });
        expect(clock.slept.reduce((a, b) => a + b, 0)).toBe(POLL_GIVE_UP_MS);
    });

    it('uses no em dashes in what it shows', () => {
        for (const text of Object.values(UPLOAD_COPY)) {
            expect(text).not.toContain('—');
        }
    });
});
