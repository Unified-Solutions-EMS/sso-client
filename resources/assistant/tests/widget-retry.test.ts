import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [] };
const sent: unknown[] = [];

const thread = (messages: unknown[]): unknown => ({ enabled: true, viewer: 'v1', conversation: { id: 7, title: null, messages }, conversations: [], suggestions: [] });

function respond(reply: Reply): Response {
    return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
}

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

function composer(): HTMLTextAreaElement {
    return shadow().querySelector('textarea')!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

async function type(text: string): Promise<void> {
    composer().value = text;
    composer().dispatchEvent(new Event('input'));
    shadow().querySelector<HTMLButtonElement>('.send')!.click();
    await flush();
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : 'messages';

        if (key === 'messages') {
            sent.push(JSON.parse(String(init.body)));
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
    sessionStorage.setItem('unified-assistant:v1:draft:7', 'Add Sam to C shift');
    replies.bootstrap.push({ status: 200, body: thread([{ id: 1, role: 'assistant', content: 'Hello' }]) });

    await import('../src/index');
    await flush();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('widget: a message that got no answer', () => {
    it('restores the unsent draft for the thread on load', () => {
        expect(composer().value).toBe('Add Sam to C shift');
    });

    it('keeps the text, shows Try again and Dismiss, and Try again sends the same message', async () => {
        replies.messages.push({ status: 504, body: {} });
        await type('Add Sam to C shift');

        const failed = shadow().querySelector('.turn.failed')!;
        expect(failed.querySelector('.bubble.error')?.textContent).toContain('not reachable');
        expect(composer().value).toBe('Add Sam to C shift');
        expect(sessionStorage.getItem('unified-assistant:v1:draft:7')).toBe('Add Sam to C shift');

        replies.messages.push({ status: 200, body: { reply: 'Added Sam to C shift.', message_id: 3 } });
        failed.querySelector<HTMLButtonElement>('.retry')!.click();
        await flush();

        expect(sent).toHaveLength(2);
        expect((sent[1] as { message: string }).message).toBe('Add Sam to C shift');
        expect(shadow().querySelector('.turn.failed')).toBeNull();
        expect(shadow().querySelectorAll('.turn.user')).toHaveLength(1);
        expect(shadow().textContent).toContain('Added Sam to C shift.');
        expect(composer().value).toBe('');
        expect(sessionStorage.getItem('unified-assistant:v1:draft:7')).toBeNull();
    });

    it('treats an error-only answer as a failed turn and Dismiss leaves the text to edit', async () => {
        replies.messages.push({ status: 200, body: { reply: null, error: 'The assistant is not available right now.' } });
        await type('Open a new shift');

        const failed = shadow().querySelector('.turn.failed')!;
        expect(failed.textContent).toContain('not available right now');

        failed.querySelector<HTMLButtonElement>('.dismiss')!.click();

        expect(shadow().querySelector('.turn.failed')).toBeNull();
        expect(composer().value).toBe('Open a new shift');
        expect(shadow().textContent).not.toContain('Open a new shift');
    });

    it('watches a busy thread and shows the answer once it lands', async () => {
        vi.useFakeTimers();
        composer().value = '';
        replies.messages.push({ status: 409, body: { message: 'I am still working on your last message.' } });
        await type('Add Lee');

        const failed = shadow().querySelector('.turn.failed')!;
        expect(failed.querySelector('.checking')?.textContent).toBe('Checking again…');

        replies.bootstrap.push({
            status: 200,
            body: thread([
                { id: 1, role: 'assistant', content: 'Hello' },
                { id: 2, role: 'user', content: 'Add Sam to C shift' },
                { id: 3, role: 'assistant', content: 'Added Sam to C shift.' },
                { id: 4, role: 'user', content: 'Review the file' },
                { id: 5, role: 'assistant', content: 'I reviewed your file.' },
            ]),
        });
        await vi.advanceTimersByTimeAsync(5000);
        await flush();

        expect(shadow().querySelector('.turn.failed')).toBeNull();
        expect(shadow().textContent).toContain('I reviewed your file.');
        expect(composer().value).toBe('Add Lee');
    });

    it('updates the waiting line on a long turn', async () => {
        vi.useFakeTimers();
        let release: (r: Response) => void = () => undefined;
        vi.stubGlobal('fetch', () => new Promise<Response>((resolve) => (release = resolve)));

        composer().value = 'Review my file';
        shadow().querySelector<HTMLButtonElement>('.send')!.click();
        await flush();

        const label = (): string | null | undefined => shadow().querySelector('.waiting-label')?.textContent;
        expect(label()).toBe('Working on it');
        await vi.advanceTimersByTimeAsync(8000);
        expect(label()).toBe('Still working… this one is taking a little longer');
        await vi.advanceTimersByTimeAsync(12000);
        expect(label()).toBe('Almost there');

        release(respond({ status: 200, body: { reply: 'Done.', message_id: 9 } }));
        await flush();
        expect(shadow().querySelector('.waiting-label')).toBeNull();
    });
});
