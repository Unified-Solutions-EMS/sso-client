import { beforeAll, describe, expect, it, vi } from 'vitest';

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], conversation: [], escalate: [], updates: [] };
const escalated: Array<Record<string, unknown>> = [];

const support = (status: string): unknown => ({ enabled: true, status, last_message_id: 0 });

const MEMBER_PILLS = [
    { label: 'How do I ...', prompt: 'I have a question about how something works.' },
    { label: 'Something is not working', prompt: 'Something is not working for me.' },
    { label: 'Talk to our team', prompt: '', kind: 'team' },
];

const thread = (id: number, reach: string, status = 'assistant', suggestions: unknown[] = MEMBER_PILLS, extra: Record<string, unknown> = {}): unknown => ({
    enabled: true,
    viewer: 'v-reach',
    conversation: { id, title: null, messages: [] },
    conversations: [],
    suggestions,
    support: support(status),
    reach,
    ...extra,
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

function attach(): HTMLButtonElement {
    return shadow().querySelector<HTMLButtonElement>(`.composer button[aria-label="Add a file"]`)!;
}

function pillLabels(): string[] {
    return Array.from(shadow().querySelectorAll('.suggestions .pill')).map((pill) => pill.textContent ?? '');
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

beforeAll(async () => {
    vi.stubGlobal('fetch', async (url: string, init: RequestInit) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/escalate') ? 'escalate' : url.includes('/updates') ? 'updates' : url.endsWith('/conversation') ? 'conversation' : 'messages';

        if (key === 'escalate') {
            escalated.push(JSON.parse(String(init.body)));
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
        escalate_message: true,
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({
        status: 200,
        body: thread(11, 'none', 'assistant', MEMBER_PILLS, { intro: { show: true, title: 'Need a hand? Ask here.', body: 'Ask how something works, or talk to our team.', cta: 'Click here', badge: 1 }, tour: null }),
    });

    await import('../src/index');
    await flush();
});

describe('widget: the drawer for every member of the agency', () => {
    it("shows SSO's member callout copy", async () => {
        await new Promise((resolve) => setTimeout(resolve, 1600));

        const callout = shadow().querySelector<HTMLElement>('.callout')!;
        expect(callout.hidden).toBe(false);
        expect(callout.textContent).toContain('Need a hand? Ask here.');
        expect(callout.textContent).toContain('Ask how something works, or talk to our team.');

        window.UnifiedAssistant!.open();
        await flush();
    });

    it('asks questions instead of offering to do tasks, and has no file button', () => {
        expect(shadow().querySelector('.header .subtitle')!.textContent).toBe('Ask how something works, or talk to our team.');
        expect(shadow().querySelector('.turn.assistant .bubble')!.textContent).toContain('Ask me how something works in your apps');
        expect(composer().placeholder).toBe('Ask a question');
        expect(attach().hidden).toBe(true);
    });

    it('shows the three pills with Talk to our team once, where SSO put it', () => {
        expect(pillLabels()).toEqual(['How do I ...', 'Something is not working', 'Talk to our team']);
        expect(shadow().querySelectorAll('.suggestions .pill.team')).toHaveLength(1);
    });

    it('the Talk to our team pill starts a message for our team', async () => {
        shadow().querySelector<HTMLButtonElement>('.pill.team')!.click();
        await flush();

        expect(shadow().querySelector<HTMLElement>('.team-mode')!.hidden).toBe(false);
        expect(attach().hidden).toBe(true);
        expect(escalated).toHaveLength(0);
    });

    it('offers the file button once our team has the conversation', async () => {
        replies.escalate.push({ status: 200, body: thread(11, 'none', 'needs_teammate', [], { conversation: { id: 11, title: null, messages: [{ id: 5, role: 'user', content: 'My login keeps failing' }] } }) });
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', messages: [], last_message_id: 5 } });
        composer().value = 'My login keeps failing';
        composer().dispatchEvent(new Event('input'));
        shadow().querySelector<HTMLButtonElement>('.send')!.click();
        await flush();

        expect(escalated).toEqual([expect.objectContaining({ message: 'My login keeps failing' })]);
        expect(attach().hidden).toBe(false);
    });

    it('goes back to the administrator drawer when SSO says full reach', async () => {
        replies.conversation.push({ status: 200, body: thread(12, 'full', 'assistant', [{ label: 'Add a person', prompt: 'Add a person to my agency.' }]) });
        shadow().querySelector<HTMLButtonElement>('.new-conversation')!.click();
        await flush();

        expect(shadow().querySelector('.header .subtitle')!.textContent).toBe('Does tasks for you. For questions about how something works, use Help.');
        expect(composer().placeholder).toBe('Tell me what to do');
        expect(attach().hidden).toBe(false);
        expect(pillLabels()).toEqual(['Add a person', 'Talk to our team']);
    });

    it('keeps the old wording when no team is behind the Assistant', async () => {
        replies.conversation.push({ status: 200, body: { ...(thread(13, 'none', 'assistant', MEMBER_PILLS.slice(0, 2)) as object), support: { enabled: false, status: 'assistant', last_message_id: 0 } } });
        shadow().querySelector<HTMLButtonElement>('.new-conversation')!.click();
        await flush();

        expect(shadow().querySelector('.header .subtitle')!.textContent).toBe('Ask how something works. For anything else, use Help.');
        expect(pillLabels()).toEqual(['How do I ...', 'Something is not working']);
        expect(attach().hidden).toBe(true);
    });
});
