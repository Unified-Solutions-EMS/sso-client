import { beforeAll, describe, expect, it, vi } from 'vitest';

/** An app on an older sso-client sends no `feedback` route: the answer cannot be sent, so the person is asked to reply. */
beforeAll(async () => {
    vi.stubGlobal('fetch', async () =>
        new Response(
            JSON.stringify({
                enabled: true,
                viewer: 'v1',
                conversation: {
                    id: 7,
                    title: null,
                    messages: [
                        { id: 1, role: 'user', content: 'The crew list prints blank.' },
                        { id: 501, role: 'staff', author: 'Halligan', crm_message_id: 88140, content: 'Hi Jane,\n\nWe fixed it.\n\nIs it working for you now?', kind: 'automated', confirm: { state: 'pending', answered_at: null } },
                    ],
                },
                conversations: [],
                suggestions: [],
                support: { enabled: true, status: 'resolved', last_message_id: 501 },
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
    );

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

    await import('../src/index');
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
    window.UnifiedAssistant!.open();
});

describe('widget: an automated update with an older component', () => {
    it('shows the reply line instead of buttons without the feedback route', () => {
        const turn = document.querySelector('unified-assistant')!.shadowRoot!.querySelector<HTMLElement>('.turn.staff.automated')!;

        expect(turn.querySelector('.tag')!.textContent).toBe('AI agent');
        expect(turn.querySelectorAll('button')).toHaveLength(0);
        expect(turn.querySelector('.confirm-hint')!.textContent).toBe('Reply here to tell us if it is working.');
    });
});
