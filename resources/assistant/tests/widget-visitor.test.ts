import { beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [] };
const sent: Array<Record<string, unknown>> = [];

const PILLS = [
    { label: 'What does CloudPCR cost?', prompt: 'What does CloudPCR cost?' },
    { label: 'Which products do you have for fire departments?', prompt: 'Which products do you have for fire departments?' },
    { label: 'Start a free test drive', prompt: 'Start a free test drive' },
    { label: 'Talk to someone on your team', prompt: 'Talk to someone on your team' },
];

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
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

        return new Response(JSON.stringify(reply.body), { status: reply.status, headers: { 'Content-Type': 'application/json' } });
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
        escalate_message: true,
        setup_app: 'crew-scheduling',
        visitor: true,
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({
        status: 200,
        body: {
            enabled: true,
            viewer: 'visitor-hash',
            conversation: { id: 31, title: null, messages: [] },
            conversations: [],
            suggestions: PILLS,
            support: { enabled: true, status: 'assistant', last_message_id: 0 },
            reach: 'visitor',
            intro: { show: true, title: 'Ask us anything', body: 'Pricing, what each product does, or a test drive. Ask here or talk to our team.', cta: 'Try it', badge: 1 },
            tour: null,
        },
    });

    await import('../src/index');
    await flush();
});

describe('widget: a visitor on the public website', () => {
    it("shows SSO's visitor callout", async () => {
        await new Promise((resolve) => setTimeout(resolve, 1600));

        const callout = shadow().querySelector<HTMLElement>('.callout')!;
        expect(callout.hidden).toBe(false);
        expect(callout.textContent).toContain('Ask us anything');
        expect(callout.textContent).toContain('Try it');

        window.UnifiedAssistant!.open();
        await flush();
    });

    it('talks about products, pricing and a test drive, never doing tasks', () => {
        expect(shadow().querySelector('#ua-title')!.textContent).toBe('Unified Assistant');
        expect(shadow().querySelector('.header .subtitle')!.textContent).toBe('Ask about our products, pricing, or a test drive.');
        expect(shadow().textContent).not.toContain('Does tasks for you');
        expect(shadow().querySelector('.turn.assistant .bubble')!.textContent).toContain('Ask me about our products, pricing, or a test drive');
        expect(shadow().querySelector('textarea')!.placeholder).toBe('Ask a question');
    });

    it('keeps the suggestions, Talk to our team, the Conversations menu and the file button', () => {
        const labels = Array.from(shadow().querySelectorAll('.suggestions .pill')).map((pill) => pill.textContent ?? '');
        expect(labels).toEqual([...PILLS.map((pill) => pill.label), 'Talk to our team']);
        expect(shadow().querySelector('button[aria-label="Conversations"]')).not.toBeNull();
        expect(shadow().querySelector<HTMLButtonElement>('.composer button[aria-label="Add a file"]')!.hidden).toBe(false);
    });

    it('sends no setup app, and shows no undo and no review', async () => {
        replies.messages.push({
            status: 200,
            body: {
                message_id: 2,
                reply: 'Saved your details.',
                actions: [{ id: 9, app: null, summary: 'Saved: Jane Doe, jane@agency.org', undoable: true, undone_at: null, at: null }],
                review: { id: 'plan-1', status: 'proposed', plan_hash: 'h', title: 'Plan', changes: [], steps: [] },
                turn_state: 'complete',
            },
        });

        const composer = shadow().querySelector('textarea')!;
        composer.value = 'I am Jane at Gotham EMS, jane@agency.org';
        composer.dispatchEvent(new Event('input'));
        shadow().querySelector<HTMLButtonElement>('.send')!.click();
        await flush();

        expect(sent).toHaveLength(1);
        expect(sent[0].setup_app ?? null).toBeNull();
        expect(shadow().textContent).toContain('Saved: Jane Doe, jane@agency.org');
        expect(shadow().querySelector('.chip button')).toBeNull();
        expect(shadow().querySelector('.card.review')).toBeNull();
    });
});
