import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AssistantApi } from '../src/api';
import { SEEN_DEBOUNCE_MS, SeenTracker } from '../src/seen';

const settings = {
    bootstrap: '/assistant/bootstrap',
    messages: '/assistant/messages',
    uploads: '/assistant/uploads',
    undo: '/assistant/actions/{action}/undo',
    review: '/assistant/review/{plan}/{verb}',
    conversation: '/assistant/conversation',
    csrf: null,
};

function reply(id: number): HTMLElement {
    const element = document.createElement('div');
    element.dataset.id = String(id);
    document.body.append(element);

    return element;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

describe('SeenTracker without IntersectionObserver', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('IntersectionObserver', undefined);
        document.body.replaceChildren();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('counts a rendered reply as on screen and reports it once after the debounce', async () => {
        const sent: number[] = [];
        const tracker = new SeenTracker(document.body, { send: async (id) => sent.push(id), visible: () => true });
        tracker.reset(null);

        tracker.watch(reply(3));
        await flush();
        await vi.advanceTimersByTimeAsync(SEEN_DEBOUNCE_MS);
        tracker.check();
        await vi.advanceTimersByTimeAsync(SEEN_DEBOUNCE_MS);

        expect(sent).toEqual([3]);
    });

    it('never reports what SSO already has, nor a reply heard live without an id yet', async () => {
        const sent: number[] = [];
        const tracker = new SeenTracker(document.body, { send: async (id) => sent.push(id), visible: () => true });
        tracker.reset(8);

        tracker.watch(reply(8));
        const live = document.createElement('div');
        live.dataset.id = 'crm-41';
        document.body.append(live);
        tracker.watch(live);
        await flush();
        await vi.advanceTimersByTimeAsync(SEEN_DEBOUNCE_MS * 2);
        expect(sent).toEqual([]);

        live.dataset.id = '9';
        tracker.check();
        await vi.advanceTimersByTimeAsync(SEEN_DEBOUNCE_MS);
        expect(sent).toEqual([9]);
    });

    it('drops a pending receipt when the thread goes out of sight before the debounce ends', async () => {
        const sent: number[] = [];
        let visible = true;
        const tracker = new SeenTracker(document.body, { send: async (id) => sent.push(id), visible: () => visible });
        tracker.reset(0);

        tracker.watch(reply(2));
        await flush();
        visible = false;
        tracker.check();
        await vi.advanceTimersByTimeAsync(SEEN_DEBOUNCE_MS * 2);
        expect(sent).toEqual([]);
    });
});

describe('AssistantApi read receipts', () => {
    it('sends none from an app whose component has no seen route', () => {
        expect(new AssistantApi(settings).canSendSeen()).toBe(false);
        expect(new AssistantApi({ ...settings, seen: '/assistant/seen' }).canSendSeen()).toBe(true);
    });
});
