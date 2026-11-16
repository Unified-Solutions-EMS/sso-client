import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], uploads: [], status: [] };
const sent: Array<{ message: string }> = [];
const asked: string[] = [];

function respond(reply: Reply): Response {
    return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
}

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

async function pick(name: string): Promise<void> {
    const input = shadow().querySelector<HTMLInputElement>('input[type=file]')!;
    Object.defineProperty(input, 'files', { configurable: true, value: [new File(['a,b'], name, { type: 'text/csv' })] });
    input.dispatchEvent(new Event('change'));
    await flush();
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : /\/uploads\/\d+$/.test(url) ? 'status' : url.endsWith('/uploads') ? 'uploads' : 'messages';

        if (key === 'messages') {
            sent.push(JSON.parse(String(init.body)));
        }

        if (key === 'status') {
            asked.push(url);
        }

        const reply = replies[key].shift();

        if (!reply) {
            throw new TypeError('Failed to fetch');
        }

        return respond(reply);
    });

    const script = document.createElement('script');
    script.dataset.unifiedAssistant = JSON.stringify({
        bootstrap: '/assistant/bootstrap',
        messages: '/assistant/messages',
        uploads: '/assistant/uploads',
        undo: '/assistant/actions/{action}/undo',
        review: '/assistant/review/{plan}/{verb}',
        conversation: '/assistant/conversation',
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: { enabled: true, viewer: 'v1', conversation: { id: 7, title: null, messages: [] }, conversations: [], suggestions: [] } });

    await import('../src/index');
    await flush();
});

afterEach(() => {
    vi.useRealTimers();
    sent.length = 0;
    asked.length = 0;
});

describe('widget: a file SSO reads in the background', () => {
    it('shows the file as Reading file… and sends the file N message once it is ready', async () => {
        vi.useFakeTimers();
        replies.uploads.push({ status: 200, body: { ok: true, name: 'roster.csv', upload: { id: 17, status: 'reading' } } });
        await pick('roster.csv');

        const chip = shadow().querySelector('.chip.file.reading')!;
        expect(chip.textContent).toContain('roster.csv');
        expect(chip.textContent).toContain('Reading file…');
        expect(sent).toHaveLength(0);

        replies.status.push({ status: 200, body: { upload: { id: 17, status: 'reading' } } });
        await vi.advanceTimersByTimeAsync(2000);
        expect(asked).toEqual(['/assistant/uploads/17']);
        expect(sent).toHaveLength(0);

        replies.status.push({ status: 200, body: { upload: { id: 17, status: 'ready', summary: 'A roster.', counts: { people: 2 } } } });
        replies.messages.push({ status: 200, body: { reply: 'Your roster has two people.', message_id: 3 } });
        await vi.advanceTimersByTimeAsync(2000);
        await flush();

        expect(sent).toEqual([expect.objectContaining({ message: 'I added a file (file 17): roster.csv' })]);
        expect(shadow().querySelector('.chip.file')).toBeNull();
        expect(shadow().textContent).toContain('Your roster has two people.');
    });

    it('shows why a file could not be read and sends nothing', async () => {
        vi.useFakeTimers();
        replies.uploads.push({ status: 200, body: { ok: true, name: 'photo.heic', upload: { id: 18, status: 'reading' } } });
        await pick('photo.heic');

        replies.status.push({ status: 200, body: { upload: { id: 18, status: 'failed', error: "We couldn't open that iPhone photo." } } });
        await vi.advanceTimersByTimeAsync(2000);
        await flush();

        const chip = shadow().querySelector('.chip.file.failed')!;
        expect(chip.textContent).toContain("We couldn't open that iPhone photo.");
        expect(sent).toHaveLength(0);
        expect(shadow().querySelector<HTMLButtonElement>('.send')!.disabled).toBe(false);
    });

    it('sends the message at once when SSO already read the file (older SSO)', async () => {
        replies.uploads.push({ status: 200, body: { ok: true, name: 'list.csv', upload: { id: 19, summary: 'A list.', counts: { people: 1 } } } });
        replies.messages.push({ status: 200, body: { reply: 'Got it.', message_id: 4 } });
        await pick('list.csv');
        await flush();

        expect(asked).toHaveLength(0);
        expect(sent).toEqual([expect.objectContaining({ message: 'I added a file (file 19): list.csv' })]);
    });
});
