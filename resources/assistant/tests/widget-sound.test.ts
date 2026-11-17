import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

type Handler = (data: unknown) => void;

const fake = vi.hoisted(() => {
    class FakeChannel {
        handlers = new Map<string, Handler[]>();
        triggered: Array<[string, unknown]> = [];

        constructor(public name: string) {}

        bind(event: string, handler: Handler): this {
            this.handlers.set(event, [...(this.handlers.get(event) ?? []), handler]);

            return this;
        }

        emit(event: string, data: unknown = {}): void {
            for (const handler of this.handlers.get(event) ?? []) {
                handler(data);
            }
        }

        trigger(event: string, data: unknown): boolean {
            this.triggered.push([event, data]);

            return true;
        }
    }

    class FakePusher {
        static instances: FakePusher[] = [];
        channels = new Map<string, FakeChannel>();
        disconnected = false;
        stateHandlers: Handler[] = [];
        connection = {
            bind: (event: string, handler: Handler) => {
                if (event === 'state_change') {
                    this.stateHandlers.push(handler);
                }
            },
        };

        constructor(
            public key: string,
            public options: Record<string, any>,
        ) {
            FakePusher.instances.push(this);
        }

        subscribe(name: string): FakeChannel {
            const channel = new FakeChannel(name);
            this.channels.set(name, channel);

            return channel;
        }

        channel(name: string): FakeChannel | undefined {
            return this.channels.get(name);
        }

        disconnect(): void {
            this.disconnected = true;
        }

        state(current: string): void {
            for (const handler of this.stateHandlers) {
                handler({ previous: 'connecting', current });
            }
        }

        only(): FakeChannel {
            return Array.from(this.channels.values())[0];
        }
    }

    return { FakePusher };
});

vi.mock('pusher-js', () => ({ default: fake.FakePusher }));

type Reply = { status: number; body: unknown };

const replies: Record<string, Reply[]> = { bootstrap: [], messages: [], updates: [], conversation: [] };
const polled: string[] = [];

const realtime = (token = 'token-1', channel = 'support.conversation.77'): unknown => ({
    host: 'ws-test-reverb.laravel.cloud',
    port: 443,
    scheme: 'https',
    key: 'reverb-key',
    channel,
    auth_url: 'https://crm.test/broadcasting/auth/customer',
    token,
    name: 'Jane Doe',
});

const support = (status: string, live: unknown = realtime()): unknown => ({ enabled: true, status, last_message_id: 4, realtime: live });

const thread = (id: number, messages: unknown[], status: string, live: unknown = realtime()): unknown => ({
    enabled: true,
    viewer: 'v1',
    conversation: { id, title: null, messages },
    conversations: [],
    suggestions: [],
    support: support(status, live),
});

const waiting = [
    { id: 1, role: 'user', content: 'export last month' },
    { id: 2, role: 'assistant', content: "I can't see the state export from here." },
    { id: 3, role: 'system', content: 'Handed to our team' },
    { id: 4, role: 'staff', author: 'Val Ramos', content: 'Looking now.', crm_message_id: 550 },
];

const played: HTMLMediaElement[] = [];
let refuse = false;

function shadow(): ShadowRoot {
    return document.querySelector('unified-assistant')!.shadowRoot!;
}

async function flush(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
}

async function wait(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flush();
}

function pusher(): InstanceType<typeof fake.FakePusher> {
    return fake.FakePusher.instances[fake.FakePusher.instances.length - 1];
}

function goLive(): void {
    pusher().state('connected');
    pusher().only().emit('pusher:subscription_succeeded');
}

function quiet(live: unknown = realtime()): void {
    replies.updates.push({ status: 200, body: { status: 'needs_teammate', last_message_id: 4, messages: [], support: support('needs_teammate', live) } });
}

beforeAll(async () => {
    vi.useFakeTimers();
    localStorage.clear();
    // A plain stand-in, not a spy: a spy handles the promise it returns and would hide an unhandled rejection.
    HTMLMediaElement.prototype.play = function (this: HTMLMediaElement) {
        played.push(this);

        return refuse ? Promise.reject(new DOMException('play() failed', 'NotAllowedError')) : Promise.resolve();
    } as () => Promise<void>;
    vi.stubGlobal('fetch', async (url: string) => {
        const key = url.includes('bootstrap') ? 'bootstrap' : url.includes('/updates') ? 'updates' : url.endsWith('/conversation') ? 'conversation' : 'messages';

        if (key === 'updates') {
            polled.push(url);
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
        csrf: 'token',
    });
    document.body.append(script);
    replies.bootstrap.push({ status: 200, body: thread(7, waiting, 'needs_teammate') });

    await import('../src/index');
    await flush();
    window.UnifiedAssistant!.open();
    await flush();
});

afterAll(() => {
    vi.useRealTimers();
});

const staffLive = (id: number, text: string): unknown => ({ message: { id, author_type: 'staff', author_name: 'Val Ramos', text, attachments: [], created_at: '2026-11-16T15:00:00Z', sso_message_id: null } });

describe('widget: a soft sound when our team replies', () => {
    it('stays silent on load, for the history it was given, and before the person touches the page', async () => {
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(1);
        expect(played).toHaveLength(0);

        quiet();
        goLive();
        await wait(0);

        pusher().only().emit('message.created', staffLive(551, 'Still looking.'));
        await flush();
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(2);
        expect(played).toHaveLength(0);
    });

    it('chimes once for a reply heard on the socket, and not again when the poll brings the same reply', async () => {
        document.dispatchEvent(new Event('pointerdown'));
        await wait(3000);

        pusher().only().emit('message.created', staffLive(555, 'I sent it for you.'));
        await flush();
        expect(played).toHaveLength(1);
        expect(played[0].volume).toBeCloseTo(0.6);

        replies.updates.push({
            status: 200,
            body: {
                status: 'needs_teammate',
                last_message_id: 12,
                messages: [
                    { id: 11, role: 'staff', author: 'Val Ramos', content: 'Still looking.', crm_message_id: 551 },
                    { id: 12, role: 'staff', author: 'Val Ramos', content: 'I sent it for you.', crm_message_id: 555 },
                ],
                support: support('needs_teammate'),
            },
        });
        await wait(60000);
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(3);
        expect(played).toHaveLength(1);
    });

    it('chimes for a new reply that only the poll brings', async () => {
        replies.updates.push({
            status: 200,
            body: { status: 'needs_teammate', last_message_id: 13, messages: [{ id: 13, role: 'staff', author: 'Val Ramos', content: 'Done.', crm_message_id: 560 }], support: support('needs_teammate') },
        });
        await wait(60000);
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(4);
        expect(played).toHaveLength(2);
    });

    it('chimes once for two replies within 2 seconds', async () => {
        await wait(3000);
        pusher().only().emit('message.created', staffLive(561, 'One more thing.'));
        await wait(500);
        pusher().only().emit('message.created', staffLive(562, 'And another.'));
        await flush();

        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(6);
        expect(played).toHaveLength(3);
    });

    it('stays silent for the person’s own messages, Assistant replies and status lines', async () => {
        await wait(3000);
        pusher().only().emit('message.created', { message: { id: 570, author_type: 'customer', author_name: 'Jane Doe', text: 'Thanks', sso_message_id: null } });
        pusher().only().emit('message.created', { message: { id: 571, author_type: 'assistant', text: 'Anything else?', sso_message_id: null } });
        pusher().only().emit('message.created', { message: { id: 572, author_type: 'system', text: 'Handed to our team', sso_message_id: null } });
        pusher().only().emit('conversation.updated', { status: 'open', support_status: 'needs_teammate', assigned_user_id: 4, assignee_name: 'Val Ramos' });
        await flush();

        replies.updates.push({
            status: 200,
            body: {
                status: 'needs_teammate',
                last_message_id: 16,
                messages: [
                    { id: 14, role: 'user', content: 'Thanks' },
                    { id: 15, role: 'assistant', content: 'Anything else?' },
                    { id: 16, role: 'system', content: 'Handed to our team' },
                ],
                support: support('needs_teammate'),
            },
        });
        await wait(60000);

        expect(shadow().querySelectorAll('.turn.user').length).toBeGreaterThan(1);
        expect(played).toHaveLength(3);
    });

    it('has a Sounds switch in the Conversations panel that mutes and is remembered', async () => {
        shadow().querySelector<HTMLButtonElement>('.history')!.click();
        const toggle = shadow().querySelector<HTMLButtonElement>('.panel .sound-switch')!;
        expect(toggle.getAttribute('role')).toBe('switch');
        expect(toggle.getAttribute('aria-checked')).toBe('true');
        expect(toggle.textContent).toBe('SoundsOn');

        toggle.click();
        expect(toggle.getAttribute('aria-checked')).toBe('false');
        expect(toggle.textContent).toBe('SoundsOff');
        expect(localStorage.getItem('unified-assistant.sounds')).toBe('off');

        await wait(3000);
        pusher().only().emit('message.created', staffLive(580, 'Muted reply.'));
        await flush();
        expect(played).toHaveLength(3);

        toggle.click();
        expect(toggle.getAttribute('aria-checked')).toBe('true');
        expect(localStorage.getItem('unified-assistant.sounds')).toBe('on');
        shadow().querySelector<HTMLButtonElement>('.history')!.click();
    });

    it('carries on quietly when the browser refuses to play', async () => {
        refuse = true;
        const errors = vi.spyOn(console, 'error');
        const unhandled: unknown[] = [];
        const onUnhandled = (reason: unknown): void => {
            unhandled.push(reason);
        };
        process.on('unhandledRejection', onUnhandled);

        await wait(3000);
        pusher().only().emit('message.created', staffLive(590, 'Refused reply.'));
        await flush();
        vi.useRealTimers();
        await new Promise((resolve) => setTimeout(resolve, 20));
        vi.useFakeTimers();
        process.off('unhandledRejection', onUnhandled);

        expect(played).toHaveLength(4);
        expect(shadow().querySelectorAll('.turn.staff')).toHaveLength(8);
        expect(unhandled).toEqual([]);
        expect(errors).not.toHaveBeenCalled();
    });
});
