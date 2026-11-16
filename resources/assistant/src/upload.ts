import { ApiError } from './api';
import type { UploadStatus } from './types';

/**
 * SSO reads an added file in the background (2026-11-16: reading it inside
 * the upload request ran past the server's 30 s limit). The upload answers
 * `status: 'reading'` and the widget asks for the file's status until it is
 * ready, then sends the "I added a file (file N)" message as before.
 */
export const UPLOAD_COPY = {
    reading: 'Reading file…',
    ready: 'Read',
    tooSlow: 'That file took too long to read. Try a smaller file.',
    failed: 'That file could not be read. Try a clearer copy, or a PDF, picture, spreadsheet, document or text file.',
    gone: 'That file is no longer here. Add it again.',
};

/** Every 2 s for the first 30 s, then every 5 s; give up after 5 minutes. */
export const POLL_FAST_MS = 2000;
export const POLL_SLOW_MS = 5000;
export const POLL_FAST_FOR_MS = 30_000;
export const POLL_GIVE_UP_MS = 300_000;

export type FileOutcome = { status: 'ready'; upload: UploadStatus } | { status: 'failed'; error: string };

export function nextPollDelay(elapsedMs: number): number | null {
    if (elapsedMs >= POLL_GIVE_UP_MS) {
        return null;
    }

    return Math.min(elapsedMs < POLL_FAST_FOR_MS ? POLL_FAST_MS : POLL_SLOW_MS, POLL_GIVE_UP_MS - elapsedMs);
}

/** An answer from an SSO that reads in the background; an older one has already read it. */
export function stillReading(upload: { status?: string | null } | null | undefined): boolean {
    return upload?.status === 'reading';
}

export interface PollOptions {
    now?: () => number;
    sleep?: (ms: number) => Promise<void>;
}

/**
 * Asks for the file's status until it is ready or failed. A network blip or a
 * server error is asked again; a refusal (404 and the like) ends the wait.
 */
export async function waitUntilRead(check: () => Promise<{ upload?: UploadStatus | null }>, options: PollOptions = {}): Promise<FileOutcome> {
    const now = options.now ?? (() => Date.now());
    const sleep = options.sleep ?? ((ms: number) => new Promise<void>((resolve) => window.setTimeout(resolve, ms)));
    const started = now();

    for (;;) {
        const delay = nextPollDelay(now() - started);

        if (delay === null) {
            return { status: 'failed', error: UPLOAD_COPY.tooSlow };
        }

        await sleep(delay);

        try {
            const upload = (await check()).upload;

            if (upload?.status === 'ready') {
                return { status: 'ready', upload };
            }

            if (upload?.status === 'failed') {
                return { status: 'failed', error: upload.error || UPLOAD_COPY.failed };
            }
        } catch (error) {
            if (error instanceof ApiError && error.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429) {
                return { status: 'failed', error: error.status === 404 ? UPLOAD_COPY.gone : error.message };
            }
        }
    }
}
