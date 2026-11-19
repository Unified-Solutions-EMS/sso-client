import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], uploads: [], status: [], escalate: [], updates: [] };
const sent: Array<Record<string, unknown>> = [];
const escalated: Array<Record<string, unknown>> = [];
const polled: string[] = [];

const support = (status: string, enabled = true): unknown => ({ enabled, status, last_message_id: 2 });

const thread = (messages: unknown[], status = 'assistant', enabled = true): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id: 7, title: null, messages },
    conversations: [],
    suggestions: [],
    support: support(status, enabled),
});

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

async function pick(name: string, body: string): Promise<void> {
    const input = shadow().querySelector<HTMLInputElement>('input[type=file]')!;
    Object.defineProperty(input, 'files', { configurable: true, value: [new File([body], name, { type: 'image/png' })] });
    input.dispatchEvent(new Event('change'));
    await flush();
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap')
            ? 'bootstrap'
            : url.includes('/escalate')
              ? 'escalate'
              : url.includes('/updates')
                ? 'updates'
                : /\/uploads\/\d+$/.test(url)
                  ? 'status'
                  : url.endsWith('/uploads')
                    ? 'uploads'
                    : 'messages';

        if (key === 'messages') {
            sent.push(JSON.parse(String(init.body)));
        }

        if (key === 'escalate') {
            escalated.push(JSON.parse(String(init.body)));
        }

        if (key === 'updates') {
            polled.push(url);
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
        escalate: '/assistant/escalate',
        updates: '/assistant/updates',
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: thread([], 'assistant', false) });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
});

afterEach(() => {
    vi.useRealTimers();
    sent.length = 0;
    escalated.length = 0;
    polled.length = 0;
});

describe('widget: our team switched off', () => {
    it('offers no Talk to our team pill or menu item', () => {
        expect(shadow().querySelector('.pill.team')).toBeNull();
        expect(shadow().querySelector<HTMLElement>('.team-entry')!.hidden).toBe(true);
        expect(shadow().querySelector('.talk-to-team')).toBeNull();
    });

    it('offers Copy and the support email on a handoff and never calls escalate or polls', async () => {
        vi.useFakeTimers();
        replies.messages.push({ status: 200, body: { reply: "I can't see that from here.", message_id: 2, handoff: { question: 'How do I export?' }, support: support('assistant', false) } });
        await type('export last month');

        const card = shadow().querySelector('.card')!;
        expect(card.textContent).toContain("I can't do that one, but our team can help.");
        expect(card.querySelector('.quote')!.textContent).toBe('How do I export?');
        expect(card.textContent).not.toContain('Talk to our team');
        expect(card.textContent).not.toMatch(/\bHelp\b/);

        const email = card.querySelector<HTMLAnchorElement>('a.email')!;
        expect(email.textContent).toBe('Email support@unified-solutions.io');
        expect(email.getAttribute('href')).toBe('mailto:support@unified-solutions.io?body=How%20do%20I%20export%3F');

        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const copy = [...card.querySelectorAll<HTMLButtonElement>('button')].find((button) => button.textContent === 'Copy')!;
        copy.click();
        await vi.advanceTimersByTimeAsync(0);
        expect(writeText).toHaveBeenCalledWith('How do I export?');
        expect(copy.textContent).toBe('Copied');

        await vi.advanceTimersByTimeAsync(30000);
        expect(escalated).toHaveLength(0);
        expect(polled).toHaveLength(0);
    });

    it('has no Help link in the footer', () => {
        expect(shadow().querySelector('.footer')!.textContent).not.toMatch(/\bHelp\b/);
    });
});
