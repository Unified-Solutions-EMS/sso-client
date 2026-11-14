import { afterEach, describe, expect, it, vi } from 'vitest';
import { AssistantApi, chooseBody } from '../src/api';
import { pendingChoices, renderChoice, stillNeedText } from '../src/choice';
import type { PlanChange, ReviewPlan } from '../src/types';

const change: PlanChange = {
    index: 3,
    kind: 'medication',
    title: 'Choose the code for: Fentanyl 100 mcg',
    from: null,
    to: '',
    why: '',
    needs_confirmation: false,
    step: 2,
    choice: {
        candidates: [
            { code: '4337', label: 'fentanyl', detail: 'Schedule II' },
            { code: '1736854', label: 'fentanyl citrate 0.05 MG/ML Injectable Solution', detail: null },
        ],
        search: true,
    },
};

const plan: ReviewPlan = {
    id: 'plan-1',
    status: 'pending',
    plan_hash: 'hash-1',
    changes: [change, { ...change, index: 4, choice: null, title: 'Add Aspirin' }],
};

afterEach(() => vi.restoreAllMocks());

describe('renderChoice', () => {
    it('renders one radio per candidate with its detail line, plus Leave it out', () => {
        const el = renderChoice(plan.id, change, () => undefined);
        const radios = [...el.querySelectorAll<HTMLButtonElement>('[role="radio"]')];

        expect(el.querySelector('[role="radiogroup"]')?.getAttribute('aria-label')).toBe(change.title);
        expect(radios.map((radio) => radio.dataset.code)).toEqual(['4337', '1736854']);
        expect(radios[0].querySelector('.choice-label')?.textContent).toBe('fentanyl');
        expect(radios[0].querySelector('.choice-detail')?.textContent).toBe('Schedule II');
        expect(radios[1].querySelector('.choice-detail')).toBeNull();
        expect(radios.map((radio) => radio.getAttribute('tabindex'))).toEqual(['0', '-1']);
        expect(el.querySelector('.leave-out')?.textContent).toBe('Leave it out');
    });

    it('picks on click, leaves out with null, and moves focus with arrow keys without picking', () => {
        const picks: Array<string | null> = [];
        const el = renderChoice(plan.id, change, (code) => picks.push(code));
        document.body.append(el);
        const radios = [...el.querySelectorAll<HTMLButtonElement>('[role="radio"]')];

        radios[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        expect(picks).toEqual([]);
        expect(radios[1].getAttribute('tabindex')).toBe('0');

        radios[1].click();
        expect(picks).toEqual(['1736854']);
        expect(radios[1].getAttribute('aria-checked')).toBe('true');
        expect(radios[0].getAttribute('aria-checked')).toBe('false');

        el.querySelector<HTMLButtonElement>('.leave-out')?.click();
        expect(picks).toEqual(['1736854', null]);
        el.remove();
    });
});

describe('approve gate copy', () => {
    it('counts rows that still need a choice', () => {
        expect(pendingChoices(plan)).toBe(1);
        expect(stillNeedText(1)).toBe('1 still needs a choice');
        expect(stillNeedText(3)).toBe('3 still need a choice');
    });
});

describe('choose request', () => {
    it('posts plan_hash, index and code to the choose route', async () => {
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ review: plan }), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);
        const api = new AssistantApi({
            bootstrap: '/assistant/bootstrap',
            messages: '/assistant/messages',
            uploads: '/assistant/uploads',
            undo: '/assistant/actions/{action}/undo',
            review: '/assistant/review/{plan}/{verb}',
            conversation: '/assistant/conversation',
            csrf: 'token',
        });

        await api.choose('plan-1', 'hash-1', 3, '4337');
        await api.choose('plan-1', 'hash-1', 3, null);

        const calls = fetchMock.mock.calls as unknown as Array<[string, RequestInit]>;
        expect(calls[0][0]).toBe('/assistant/review/plan-1/choose');
        expect(calls[0][1].method).toBe('POST');
        expect(JSON.parse(String(calls[0][1].body))).toEqual({ plan_hash: 'hash-1', index: 3, code: '4337' });
        expect(JSON.parse(String(calls[1][1].body))).toEqual({ plan_hash: 'hash-1', index: 3, code: null });
        expect(chooseBody('h', 1, null)).toEqual({ plan_hash: 'h', index: 1, code: null });
        vi.unstubAllGlobals();
    });
});
