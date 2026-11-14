import { h } from './dom';
import type { PlanChange, ReviewPlan } from './types';

export const CHOICE_COPY = {
    leaveOut: 'Leave it out',
    saving: 'Saving your choice',
};

/** Rows still waiting for the admin to pick a code. */
export function pendingChoices(plan: ReviewPlan): number {
    return plan.changes.filter((change) => Boolean(change.choice)).length;
}

export function stillNeedText(count: number): string {
    return count === 1 ? '1 still needs a choice' : `${count} still need a choice`;
}

/**
 * The candidates as a radio group plus "Leave it out". Arrow keys move
 * between the options without picking; Enter, Space or a click picks, because
 * every pick is sent straight away.
 */
export function renderChoice(planId: string, change: PlanChange, onPick: (code: string | null) => void): HTMLElement {
    const candidates = change.choice?.candidates ?? [];
    const group = h('div', { class: 'choices', role: 'radiogroup', 'aria-label': change.title });
    const options: HTMLButtonElement[] = [];

    candidates.forEach((candidate, position) => {
        const option = h(
            'button',
            {
                type: 'button',
                class: 'choice',
                role: 'radio',
                'aria-checked': 'false',
                tabindex: position === 0 ? '0' : '-1',
                'data-code': candidate.code,
                id: `ua-choice-${planId}-${change.index}-${position}`,
            },
            h('span', { class: 'dot', 'aria-hidden': 'true' }),
            h('span', { class: 'choice-text' }, h('span', { class: 'choice-label' }, candidate.label), candidate.detail ? h('span', { class: 'choice-detail' }, candidate.detail) : null),
        );

        option.addEventListener('click', () => {
            options.forEach((other) => other.setAttribute('aria-checked', other === option ? 'true' : 'false'));
            onPick(candidate.code);
        });
        option.addEventListener('keydown', (event: KeyboardEvent) => {
            const step = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? 1 : event.key === 'ArrowUp' || event.key === 'ArrowLeft' ? -1 : 0;

            if (step === 0) {
                return;
            }

            event.preventDefault();
            const next = options[(position + step + options.length) % options.length];
            options.forEach((other) => other.setAttribute('tabindex', other === next ? '0' : '-1'));
            next.focus();
        });

        options.push(option);
        group.append(option);
    });

    const leaveOut = h('button', { type: 'button', class: 'btn ghost leave-out', 'aria-label': `${CHOICE_COPY.leaveOut}: ${change.title}` }, CHOICE_COPY.leaveOut);
    leaveOut.addEventListener('click', () => onPick(null));

    return h('div', { class: 'choice-block' }, group, leaveOut);
}

export function setRowBusy(row: HTMLElement, busy: boolean): void {
    row.classList.toggle('busy', busy);
    row.setAttribute('aria-busy', busy ? 'true' : 'false');
    row.querySelectorAll('button').forEach((button) => (button.disabled = busy));
    row.querySelector('.choice-status')?.remove();

    if (busy) {
        row.querySelector('.body')?.append(h('div', { class: 'choice-status', role: 'status' }, CHOICE_COPY.saving));
    }
}

export function showRowError(row: HTMLElement, message: string): void {
    row.querySelector('.row-error')?.remove();
    row.querySelector('.body')?.append(h('div', { class: 'row-error', role: 'alert' }, message));
}
