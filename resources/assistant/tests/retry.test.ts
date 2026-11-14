import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../src/api';
import {
    BusyPoller,
    RETRY_COPY,
    SlowTurnTimer,
    clearDraftIfSent,
    draftName,
    failedTurnError,
    hasNewerAnswer,
    isBusy,
    readDraft,
    renderFailedTurn,
    saveDraft,
    showRetryButtons,
    slowLabel,
} from '../src/retry';
import type { BootstrapResponse, Message } from '../src/types';

const shown: Message[] = [
    { id: 10, role: 'user', content: 'Add Jordan' },
    { id: 11, role: 'assistant', content: 'Added Jordan.' },
    { id: 'u-1', role: 'user', content: 'Add Sam' },
];

function thread(messages: Message[]): BootstrapResponse {
    return { enabled: true, conversation: { id: 7, title: null, messages } };
}

beforeEach(() => sessionStorage.clear());

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('failed turns', () => {
    it('treats an error with no answer as a failed turn, and an answered error as a reply', () => {
        expect(failedTurnError({ reply: null, error: 'The assistant is not available right now.' })).toBe('The assistant is not available right now.');
        expect(failedTurnError({ reply: 'Done, with one problem.', error: 'One row was skipped.' })).toBeNull();
        expect(failedTurnError({ reply: 'Done.' })).toBeNull();
    });

    it('knows a 409 is the busy answer', () => {
        expect(isBusy(new ApiError(409, 'Still working'))).toBe(true);
        expect(isBusy(new ApiError(504, 'Not reachable'))).toBe(false);
        expect(isBusy(new Error('x'))).toBe(false);
    });

    it('renders Try again and Dismiss under the error and calls back', () => {
        const onRetry = vi.fn();
        const onDismiss = vi.fn();
        const el = renderFailedTurn('The assistant is not reachable right now.', { onRetry, onDismiss });

        expect(el.querySelector('.bubble.error')?.textContent).toContain('not reachable');
        el.querySelector<HTMLButtonElement>('.retry')!.click();
        el.querySelector<HTMLButtonElement>('.dismiss')!.click();

        expect(el.querySelector('.retry')?.textContent).toBe(RETRY_COPY.tryAgain);
        expect(onRetry).toHaveBeenCalledOnce();
        expect(onDismiss).toHaveBeenCalledOnce();
    });

    it('shows Checking again while busy, and the buttons once it gives up', () => {
        const el = renderFailedTurn('Still working on your last message.', { onRetry: vi.fn(), onDismiss: vi.fn() }, true);

        expect(el.querySelector('.checking')?.textContent).toBe(RETRY_COPY.checkingAgain);
        expect(el.querySelector('.retry')).toBeNull();

        showRetryButtons(el, { onRetry: vi.fn(), onDismiss: vi.fn() });

        expect(el.querySelector('.checking')).toBeNull();
        expect(el.querySelector('.retry')).not.toBeNull();
    });
});

describe('drafts', () => {
    it('keeps a draft per thread and clears it only once that text was sent', () => {
        saveDraft(draftName(7), 'Add Sam to C shift');
        saveDraft(draftName(8), 'Other thread');

        expect(readDraft(draftName(7))).toBe('Add Sam to C shift');

        clearDraftIfSent(draftName(8), 'Something else');
        clearDraftIfSent(draftName(7), 'Add Sam to C shift');

        expect(readDraft(draftName(7))).toBe('');
        expect(readDraft(draftName(8))).toBe('Other thread');
        expect(draftName(null)).toBe('draft:new');
    });

    it('survives storage that throws', () => {
        vi.spyOn(window, 'sessionStorage', 'get').mockImplementation(() => {
            throw new Error('blocked');
        });

        expect(() => saveDraft('k', 'text')).not.toThrow();
        expect(readDraft('k')).toBe('');
    });
});

describe('slow turns', () => {
    it('moves the waiting line on at 8 and 20 seconds', () => {
        expect(slowLabel(0)).toBe(RETRY_COPY.working);
        expect(slowLabel(8000)).toBe(RETRY_COPY.stillWorking);
        expect(slowLabel(20000)).toBe(RETRY_COPY.almostThere);

        vi.useFakeTimers();
        const labels: string[] = [];
        const timer = new SlowTurnTimer();
        timer.start((label) => labels.push(label));

        vi.advanceTimersByTime(7999);
        expect(labels).toEqual([]);
        vi.advanceTimersByTime(1);
        expect(labels).toEqual([RETRY_COPY.stillWorking]);
        vi.advanceTimersByTime(12000);
        expect(labels).toEqual([RETRY_COPY.stillWorking, RETRY_COPY.almostThere]);

        timer.start((label) => labels.push(label));
        timer.stop();
        vi.advanceTimersByTime(30000);
        expect(labels).toHaveLength(2);
    });

    it('keeps the copy free of em dashes', () => {
        Object.values(RETRY_COPY).forEach((text) => expect(text).not.toContain('—'));
    });
});

describe('busy poller', () => {
    it('spots an answer the drawer has not shown', () => {
        expect(hasNewerAnswer([...shown.slice(0, 2)], shown)).toBe(false);
        expect(hasNewerAnswer([...shown.slice(0, 2), { id: 12, role: 'user', content: 'Add Sam' }], shown)).toBe(false);
        expect(hasNewerAnswer([...shown.slice(0, 2), { id: 12, role: 'user', content: 'x' }, { id: 13, role: 'assistant', content: 'Added Sam.' }], shown)).toBe(true);
    });

    it('reads the thread every 5 seconds and hands over the new answer', async () => {
        vi.useFakeTimers();
        const load = vi
            .fn<() => Promise<BootstrapResponse | null>>()
            .mockResolvedValueOnce(thread(shown.slice(0, 2)))
            .mockResolvedValueOnce(thread([...shown.slice(0, 2), { id: 13, role: 'assistant', content: 'Added Sam.' }]));
        const onNewer = vi.fn();
        const onGiveUp = vi.fn();
        const poller = new BusyPoller(load, () => shown, onNewer, onGiveUp);

        poller.start();
        await vi.advanceTimersByTimeAsync(4999);
        expect(load).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(load).toHaveBeenCalledOnce();
        expect(onNewer).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(5000);

        expect(load).toHaveBeenCalledTimes(2);
        expect(onNewer).toHaveBeenCalledOnce();
        expect(onGiveUp).not.toHaveBeenCalled();
        expect(poller.running).toBe(false);
    });

    it('gives up after a minute, skipping reads that fail', async () => {
        vi.useFakeTimers();
        const load = vi.fn<() => Promise<BootstrapResponse | null>>().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(thread(shown.slice(0, 2)));
        const onNewer = vi.fn();
        const onGiveUp = vi.fn();
        new BusyPoller(load, () => shown, onNewer, onGiveUp).start();

        await vi.advanceTimersByTimeAsync(55000);
        expect(onGiveUp).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(5000);

        expect(load).toHaveBeenCalledTimes(12);
        expect(onGiveUp).toHaveBeenCalledOnce();
        expect(onNewer).not.toHaveBeenCalled();
    });
});
