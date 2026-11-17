import Pusher from 'pusher-js';
import type { Message, MessageAttachment, Realtime, Support } from './types';

/** `message.created` on the conversation channel (REALTIME-CONTRACT.md). */
export interface LiveMessage {
    id: number;
    author_type: 'customer' | 'staff' | 'assistant' | 'system';
    author_name?: string | null;
    text_html?: string | null;
    text?: string | null;
    attachments?: Array<{ name: string; size?: number | null; url?: string | null }> | null;
    created_at?: string | null;
    sso_message_id?: number | null;
}

/** `conversation.updated` on the conversation channel. */
export interface LiveConversation {
    status?: string | null;
    support_status?: Support['status'] | null;
    assigned_user_id?: number | null;
    assignee_name?: string | null;
}

export interface LiveTyping {
    who: 'staff' | 'customer';
    name?: string | null;
}

export interface SocketHandlers {
    /** Connected and subscribed (true), or not any more (false). */
    live(live: boolean): void;
    message(message: LiveMessage): void;
    conversation(update: LiveConversation): void;
    typing(typing: LiveTyping): void;
}

/** One `client-typing` at most every 3 s while the person types. */
export const TYPING_THROTTLE_MS = 3000;

/**
 * A staff or system line heard on the socket, as the widget renders it.
 * Its id is SSO's when the CRM already knows it, otherwise `crm-<id>`; either
 * way it is marked `live` so it never moves the poll's `after` past a
 * message the widget has not been given yet.
 */
export function liveToMessage(live: LiveMessage): Message | null {
    if (live.author_type !== 'staff' && live.author_type !== 'system') {
        return null;
    }

    const attachments: MessageAttachment[] = (live.attachments ?? []).map((file) => ({ name: file.name, size: file.size ?? null, url: file.url ?? null }));

    return {
        id: typeof live.sso_message_id === 'number' ? live.sso_message_id : `crm-${live.id}`,
        role: live.author_type,
        content: (live.text ?? '').trim(),
        author: live.author_type === 'staff' ? (live.author_name ?? null) : null,
        attachments: attachments.length > 0 ? attachments : null,
        at: live.created_at ?? null,
        crm_message_id: live.id,
        live: true,
    };
}

/**
 * The CRM's Reverb cluster, spoken to with pusher-js. Polling stays the
 * source of truth; this only makes our team's replies show the moment they
 * are written. An authorization failure gives up on the channel until the
 * widget is given another one (polling carries on at its normal pace).
 */
export class SupportSocket {
    private pusher: Pusher | null = null;

    private config: Realtime | null = null;

    private connected = false;

    private subscribed = false;

    private refused = false;

    private lastTyping = 0;

    constructor(private handlers: SocketHandlers) {}

    isLive(): boolean {
        return this.connected && this.subscribed;
    }

    /** Connects to the channel, or only takes the fresher token when it is the same one. */
    connect(config: Realtime): void {
        if (this.config && this.pusher && sameChannel(this.config, config)) {
            this.config = config;

            return;
        }

        if (this.config && this.refused && sameChannel(this.config, config)) {
            this.config = config;

            return;
        }

        this.close();
        this.config = config;
        this.refused = false;

        const pusher = new Pusher(config.key, {
            cluster: '',
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            forceTLS: config.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            channelAuthorization: {
                transport: 'ajax',
                endpoint: config.auth_url,
                headersProvider: () => ({ 'X-Support-Token': this.config?.token ?? '' }),
            },
        });

        this.pusher = pusher;

        pusher.connection.bind('state_change', (states: { current: string }) => {
            if (this.pusher !== pusher) {
                return;
            }

            this.connected = states.current === 'connected';

            if (!this.connected) {
                this.subscribed = false;
            }

            this.handlers.live(this.isLive());
        });

        const channel = pusher.subscribe(`private-${config.channel}`);

        channel.bind('pusher:subscription_succeeded', () => {
            if (this.pusher !== pusher) {
                return;
            }

            this.subscribed = true;
            this.handlers.live(this.isLive());
        });

        channel.bind('pusher:subscription_error', () => {
            if (this.pusher !== pusher) {
                return;
            }

            this.refused = true;
            this.shutDown();
        });

        channel.bind('message.created', (data: { message?: LiveMessage } | null) => {
            if (this.pusher === pusher && data?.message) {
                this.handlers.message(data.message);
            }
        });

        channel.bind('conversation.updated', (data: LiveConversation | null) => {
            if (this.pusher === pusher && data) {
                this.handlers.conversation(data);
            }
        });

        channel.bind('client-typing', (data: LiveTyping | null) => {
            if (this.pusher === pusher && data?.who === 'staff') {
                this.handlers.typing(data);
            }
        });
    }

    /** Leaves the channel (a new conversation, a resolved one, or our team handed it back). */
    close(): void {
        this.config = null;
        this.refused = false;
        this.shutDown();
    }

    /** Tells our team the person is typing, at most once every 3 s. */
    typing(): void {
        if (!this.pusher || !this.config || !this.isLive()) {
            return;
        }

        const now = Date.now();

        if (now - this.lastTyping < TYPING_THROTTLE_MS) {
            return;
        }

        this.lastTyping = now;
        this.pusher.channel(`private-${this.config.channel}`)?.trigger('client-typing', { who: 'customer', name: this.config.name ?? '' });
    }

    private shutDown(): void {
        const pusher = this.pusher;
        const wasLive = this.isLive();

        this.pusher = null;
        this.connected = false;
        this.subscribed = false;
        this.lastTyping = 0;

        if (pusher) {
            pusher.disconnect();
        }

        if (wasLive || pusher) {
            this.handlers.live(false);
        }
    }
}

function sameChannel(a: Realtime, b: Realtime): boolean {
    return a.channel === b.channel && a.host === b.host && a.key === b.key && a.port === b.port && a.scheme === b.scheme && a.auth_url === b.auth_url;
}
