import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { fileSize } from '../src/dom';

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
    replies.bootstrap.push({ status: 200, body: thread([]) });

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

describe('widget: our team behind the Assistant', () => {
    it('offers Talk to our team on a handoff, hands the conversation over and shows the line', async () => {
        replies.messages.push({ status: 200, body: { reply: "I can't see the state export from here.", message_id: 2, handoff: { question: 'How do I export?' }, support: support('assistant') } });
        await type('export last month');

        const card = shadow().querySelector('.card')!;
        expect(card.textContent).toContain("I can't finish this one, but our team can.");
        expect(card.textContent).not.toContain('support@unified-solutions.io');

        vi.useFakeTimers();
        replies.escalate.push({
            status: 200,
            body: thread(
                [
                    { id: 1, role: 'user', content: 'export last month' },
                    { id: 2, role: 'assistant', content: "I can't see the state export from here." },
                    { id: 3, role: 'system', content: 'Handed to our team' },
                ],
                'needs_teammate',
            ),
        });
        card.querySelector<HTMLButtonElement>('button')!.click();
        await flush();

        expect(escalated).toEqual([{ question: 'How do I export?', conversation_id: 7 }]);
        expect(shadow().querySelector('.system-line')!.textContent).toBe('Handed to our team');

        replies.updates.push({
            status: 200,
            body: {
                status: 'needs_teammate',
                last_message_id: 4,
                messages: [{ id: 4, role: 'staff', author: 'James Kenworthy', content: 'I sent it for you.', attachments: [{ name: 'quote.pdf', size: 120034, url: 'https://crm.test/attachments/9/sig' }] }],
            },
        });
        await vi.advanceTimersByTimeAsync(5000);
        await flush();

        expect(polled).toEqual(['/assistant/updates?conversation=7&after=3']);
        const staff = shadow().querySelector('.turn.staff')!;
        expect(staff.querySelector('.avatar')!.textContent).toBe('JK');
        expect(staff.querySelector('.staff-name')!.textContent).toBe('James Kenworthy');
        expect(staff.querySelector('.tag')!.textContent).toBe('Unified');
        const link = staff.querySelector<HTMLAnchorElement>('a.file-chip')!;
        expect(link.getAttribute('href')).toBe('https://crm.test/attachments/9/sig');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.textContent).toContain('quote.pdf');
        expect(link.textContent).toContain('117 KB');

        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 4, messages: [{ id: 4, role: 'staff', author: 'James Kenworthy', content: 'I sent it for you.' }] } });
        await vi.advanceTimersByTimeAsync(5000);
        await flush();
        expect(polled[1]).toBe('/assistant/updates?conversation=7&after=4');
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(1);

        replies.updates.push({ status: 200, body: { status: 'resolved', last_message_id: 4, messages: [] } });
        await vi.advanceTimersByTimeAsync(5000);
        await flush();
        await vi.advanceTimersByTimeAsync(30000);
        expect(polled).toHaveLength(3);
    });

    it('fetches the handover line at once when a turn hands over, then polls every 20 seconds while closed', async () => {
        vi.useFakeTimers();
        replies.messages.push({ status: 200, body: { reply: "I can't do that one either.", message_id: 20, support: support('needs_teammate') } });
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 21, messages: [{ id: 21, role: 'system', content: 'Handed to our team' }] } });
        await type('change the export schedule');
        await vi.advanceTimersByTimeAsync(0);
        await flush();

        expect(polled).toEqual(['/assistant/updates?conversation=7&after=20']);
        expect(Array.from(shadow().querySelectorAll('.system-line')).pop()!.textContent).toBe('Handed to our team');

        window.UnifiedAssistant!.close();
        replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 21, messages: [] } });
        replies.updates.push({ status: 200, body: { status: 'resolved', last_message_id: 21, messages: [] } });
        await vi.advanceTimersByTimeAsync(5000);
        expect(polled).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(15000);
        await flush();
        expect(polled).toHaveLength(2);

        window.UnifiedAssistant!.open();
        await vi.advanceTimersByTimeAsync(5000);
        await flush();
        expect(polled).toHaveLength(3);
    });

    it('keeps an added file as a chip and sends it with the next message', async () => {
        replies.uploads.push({ status: 200, body: { ok: true, name: 'screen.png', upload: { id: 21, status: 'ready' } } });
        await pick('screen.png', 'png-bytes');

        expect(sent).toHaveLength(0);
        const chip = shadow().querySelector('.pending-files .file-chip')!;
        expect(chip.textContent).toContain('screen.png');
        expect(chip.textContent).toContain('9 B');

        replies.messages.push({ status: 200, body: { reply: AssistantTeamReply, message_id: 9, support: support('needs_teammate') } });
        await type('Here is the error');

        expect(sent).toEqual([expect.objectContaining({ message: 'Here is the error', attachments: [21], conversation_id: 7 })]);
        expect(shadow().querySelector<HTMLElement>('.pending-files')!.hidden).toBe(true);
        const userTurn = Array.from(shadow().querySelectorAll('.turn.user')).pop()!;
        expect(userTurn.querySelector('.file-chip')!.textContent).toContain('screen.png');
    });

    it('sends a file on its own with a short line', async () => {
        replies.uploads.push({ status: 200, body: { ok: true, name: 'roster.csv', upload: { id: 22, status: 'ready' } } });
        await pick('roster.csv', 'a,b');
        replies.messages.push({ status: 200, body: { reply: '', message_id: 10, support: support('needs_teammate') } });
        await type('');

        expect(sent).toEqual([expect.objectContaining({ message: 'I added a file: roster.csv', attachments: [22] })]);
    });

    it('formats file sizes', () => {
        expect(fileSize(512)).toBe('512 B');
        expect(fileSize(18234)).toBe('18 KB');
        expect(fileSize(2_500_000)).toBe('2.4 MB');
        expect(fileSize(null)).toBe('');
    });
});

const AssistantTeamReply = 'Our team has your message. Replies show up here.';
