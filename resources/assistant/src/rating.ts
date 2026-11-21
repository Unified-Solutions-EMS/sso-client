import { ApiError } from './api';
import { h } from './dom';
import type { BestTime, CallbackResponse, Rating, RatingResponse } from './types';

/** Rendered UI (§18): plain, no em dashes, never "AI", "bot", "chat" or "ticket". Same strings as Unified Mobile. The sentence names no scale: each face shows its number. */
export const RATING_COPY = {
    title: 'Thanks for talking with us. If you can, rate this conversation and leave a note.',
    groupLabel: 'Rate this conversation from 1 to 5',
    notePlaceholder: 'Anything you want to tell us? (optional)',
    send: 'Send',
    skip: 'Skip',
    sending: 'Sending',
    thanks: 'Thanks, glad it helped.',
    rated: (score: number): string => `You rated this ${score} of 5.`,
    offer: 'Sorry about that. Want someone from our team to call you?',
    yesCall: 'Yes, call me',
    noThanks: 'No thanks',
    noTeam: 'Thanks for telling us. Our team will read this conversation.',
    phone: 'Phone number',
    phoneError: 'Enter a US phone number, like (814) 555-0100.',
    bestTime: 'Best time',
    requestCall: 'Request a call',
    cancel: 'Cancel',
    failed: 'That did not go through. Try again.',
};

export const FACES: ReadonlyArray<{ score: number; emoji: string; label: string }> = [
    { score: 1, emoji: '\u{1F61E}', label: '1 of 5, very poor' },
    { score: 2, emoji: '\u{1F615}', label: '2 of 5, poor' },
    { score: 3, emoji: '\u{1F610}', label: '3 of 5, okay' },
    { score: 4, emoji: '\u{1F642}', label: '4 of 5, good' },
    { score: 5, emoji: '\u{1F604}', label: '5 of 5, great' },
];

export const BEST_TIMES: ReadonlyArray<{ value: BestTime; label: string }> = [
    { value: 'now', label: 'Now' },
    { value: 'this_morning', label: 'This morning' },
    { value: 'this_afternoon', label: 'This afternoon' },
    { value: 'tomorrow_morning', label: 'Tomorrow morning' },
];

/** How long "Thanks, glad it helped." stays before only the rated line is left. */
export const THANKS_SHOWN_MS = 3000;

/** A rating at or below this offers a call from our team. */
export const OFFERS_CALL_AT = 3;

/**
 * A US number as ten digits, or null: an optional leading 1, and an area code
 * and exchange that do not start with 0 or 1 (SSO checks the same).
 */
export function usPhoneDigits(raw: string): string | null {
    if (/[a-z]/i.test(raw)) {
        return null;
    }

    let digits = raw.replace(/\D/g, '');

    if (digits.length === 11 && digits.startsWith('1')) {
        digits = digits.slice(1);
    }

    return /^[2-9]\d{2}[2-9]\d{6}$/.test(digits) ? digits : null;
}

/** "(814) 555-0100" */
export function formatUsPhone(digits: string): string {
    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
}

/** The nodes that are there, for replaceChildren. */
function nodes(...items: Array<Node | null>): Node[] {
    return items.filter((item): item is Node => item !== null);
}

export interface RatingCardOptions {
    rating: Rating;
    /** Our team takes calls (support on and the component sends the route). */
    callOffered: boolean;
    rate(score: number, note: string | null): Promise<RatingResponse>;
    requestCall(phone: string, bestTime: BestTime, note: string | null): Promise<CallbackResponse>;
    /** Skip, a 4 or 5 sent, or No thanks: the card is done (the widget may go on to a new conversation). */
    finished(outcome: 'skipped' | 'rated' | 'declined', rating: Rating | null): void;
    /** The call was requested: the widget applies the answer. */
    called(answer: CallbackResponse): void;
    changed(): void;
}

/**
 * The closing card at the end of a conversation: five faces, an optional note,
 * Send and Skip. A 4 or 5 is thanked; 3 or less offers a call from our team
 * and its form. Never a modal; any step can be left.
 */
export class RatingCard {
    readonly element: HTMLElement;

    private readonly card: HTMLElement;

    private score: number | null = null;

    private note = '';

    private settled = false;

    constructor(private readonly options: RatingCardOptions) {
        this.card = h('div', { class: 'card rating-card' });
        this.element = h('div', { class: 'turn assistant rating' }, this.card);
        this.renderAsk();
    }

    /** True once the person sent a rating, skipped or answered the call offer. */
    isSettled(): boolean {
        return this.settled;
    }

    private renderAsk(error: string | null = null): void {
        const faces: HTMLButtonElement[] = [];
        const send = h('button', { class: 'btn primary sm rating-send', type: 'button', disabled: this.score === null }, RATING_COPY.send) as HTMLButtonElement;
        const note = h('input', {
            class: 'rating-note',
            type: 'text',
            maxlength: '1000',
            placeholder: RATING_COPY.notePlaceholder,
            'aria-label': RATING_COPY.notePlaceholder,
        }) as HTMLInputElement;
        note.value = this.note;
        note.addEventListener('input', () => (this.note = note.value));
        note.addEventListener('keydown', (event: KeyboardEvent) => {
            if (event.key === 'Enter' && this.score !== null) {
                event.preventDefault();
                void this.submit();
            }
        });

        const pick = (score: number, focus: boolean) => {
            this.score = score;
            faces.forEach((face) => {
                const on = Number(face.dataset.score) === score;
                face.setAttribute('aria-checked', on ? 'true' : 'false');
                face.setAttribute('tabindex', on ? '0' : '-1');

                if (on && focus) {
                    face.focus();
                }
            });
            send.disabled = false;
        };

        const group = h('div', { class: 'faces', role: 'radiogroup', 'aria-label': RATING_COPY.groupLabel });

        FACES.forEach((face, position) => {
            const checked = this.score === face.score;
            const button = h(
                'button',
                {
                    class: 'face',
                    type: 'button',
                    role: 'radio',
                    'aria-checked': checked ? 'true' : 'false',
                    'aria-label': face.label,
                    title: face.label,
                    tabindex: checked || (this.score === null && position === 0) ? '0' : '-1',
                    'data-score': String(face.score),
                },
                h('span', { class: 'emoji', 'aria-hidden': 'true' }, face.emoji),
                h('span', { class: 'face-number', 'aria-hidden': 'true' }, String(face.score)),
            ) as HTMLButtonElement;

            button.addEventListener('click', () => pick(face.score, false));
            button.addEventListener('keydown', (event: KeyboardEvent) => {
                const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;

                if (step === 0) {
                    return;
                }

                event.preventDefault();
                pick(FACES[(position + step + FACES.length) % FACES.length].score, true);
            });

            faces.push(button);
            group.append(button);
        });

        send.addEventListener('click', () => void this.submit());
        const skip = h('button', { class: 'btn ghost sm rating-skip', type: 'button' }, RATING_COPY.skip);
        skip.addEventListener('click', () => {
            this.settled = true;
            this.element.remove();
            this.options.finished('skipped', null);
        });

        this.card.replaceChildren(
            ...nodes(
                h('div', { class: 'question' }, RATING_COPY.title),
                group,
                note,
                error ? h('div', { class: 'row-error', role: 'alert' }, error) : null,
                h('div', { class: 'row-actions' }, send, skip),
            ),
        );
        this.options.changed();
    }

    private async submit(): Promise<void> {
        const score = this.score;

        if (score === null) {
            return;
        }

        this.setBusy(true);

        let answer: RatingResponse;

        try {
            answer = await this.options.rate(score, this.note.trim() === '' ? null : this.note.trim());
        } catch (error) {
            this.renderAsk(error instanceof ApiError ? error.message : RATING_COPY.failed);

            return;
        }

        const rating = answer.rating ?? { ...this.options.rating, score, note: this.note.trim() || null };

        if (score > OFFERS_CALL_AT) {
            this.collapse(RATING_COPY.thanks, score);
            this.options.finished('rated', rating);

            return;
        }

        if (!this.options.callOffered) {
            this.collapse(RATING_COPY.noTeam, score);
            this.options.finished('rated', rating);

            return;
        }

        this.renderOffer(score, rating);
    }

    private renderOffer(score: number, rating: Rating): void {
        const yes = h('button', { class: 'btn primary sm call-yes', type: 'button' }, RATING_COPY.yesCall);
        const no = h('button', { class: 'btn secondary sm call-no', type: 'button' }, RATING_COPY.noThanks);
        yes.addEventListener('click', () => this.renderCallForm(score, rating));
        no.addEventListener('click', () => {
            this.collapse(null, score);
            this.options.finished('declined', rating);
        });

        this.card.replaceChildren(h('div', { class: 'question' }, RATING_COPY.offer), h('div', { class: 'row-actions' }, yes, no));
        this.options.changed();
        yes.focus();
    }

    private renderCallForm(score: number, rating: Rating, phoneValue: string | null = null, bestTime: BestTime = 'now', phoneError: string | null = null, formError: string | null = null): void {
        const phone = h('input', {
            class: 'call-phone',
            id: 'ua-call-phone',
            type: 'tel',
            inputmode: 'tel',
            autocomplete: 'tel',
            required: true,
            maxlength: '32',
            'aria-invalid': phoneError ? 'true' : 'false',
            'aria-describedby': phoneError ? 'ua-call-phone-error' : null,
        }) as HTMLInputElement;
        phone.value = phoneValue ?? this.options.rating.phone ?? '';

        let chosen: BestTime = bestTime;
        const times = h('div', { class: 'best-times', role: 'radiogroup', 'aria-labelledby': 'ua-best-time-label' });
        const timeButtons: HTMLButtonElement[] = [];

        BEST_TIMES.forEach((time, position) => {
            const button = h(
                'button',
                {
                    class: 'chip best-time',
                    type: 'button',
                    role: 'radio',
                    'aria-checked': time.value === chosen ? 'true' : 'false',
                    tabindex: time.value === chosen ? '0' : '-1',
                    'data-value': time.value,
                },
                time.label,
            ) as HTMLButtonElement;
            const select = (focus: boolean) => {
                chosen = time.value;
                timeButtons.forEach((other) => {
                    const on = other === button;
                    other.setAttribute('aria-checked', on ? 'true' : 'false');
                    other.setAttribute('tabindex', on ? '0' : '-1');
                });

                if (focus) {
                    button.focus();
                }
            };
            button.addEventListener('click', () => select(false));
            button.addEventListener('keydown', (event: KeyboardEvent) => {
                const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;

                if (step !== 0) {
                    event.preventDefault();
                    const next = timeButtons[(position + step + timeButtons.length) % timeButtons.length];
                    next.click();
                    next.focus();
                }
            });
            timeButtons.push(button);
            times.append(button);
        });

        const submit = h('button', { class: 'btn primary sm call-submit', type: 'button' }, RATING_COPY.requestCall);
        const cancel = h('button', { class: 'btn ghost sm call-cancel', type: 'button' }, RATING_COPY.cancel);
        cancel.addEventListener('click', () => this.renderOffer(score, rating));

        const send = async () => {
            const digits = usPhoneDigits(phone.value);

            if (digits === null) {
                this.renderCallForm(score, rating, phone.value, chosen, RATING_COPY.phoneError);
                this.card.querySelector<HTMLInputElement>('.call-phone')?.focus();

                return;
            }

            this.setBusy(true);

            try {
                const answer = await this.options.requestCall(formatUsPhone(digits), chosen, this.note.trim() === '' ? null : this.note.trim());
                this.settled = true;
                this.options.called(answer);
            } catch (error) {
                const fieldError = error instanceof ApiError ? (error.errors.phone?.[0] ?? null) : null;
                this.renderCallForm(score, rating, phone.value, chosen, fieldError, fieldError ? null : error instanceof ApiError ? error.message : RATING_COPY.failed);
            }
        };

        submit.addEventListener('click', () => void send());
        phone.addEventListener('keydown', (event: KeyboardEvent) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                void send();
            }
        });

        this.card.replaceChildren(
            ...nodes(
                h('label', { class: 'field-label', for: 'ua-call-phone' }, RATING_COPY.phone),
                phone,
                phoneError ? h('div', { class: 'row-error', id: 'ua-call-phone-error', role: 'alert' }, phoneError) : null,
                h('div', { class: 'field-label', id: 'ua-best-time-label' }, RATING_COPY.bestTime),
                times,
                formError ? h('div', { class: 'row-error', role: 'alert' }, formError) : null,
                h('div', { class: 'row-actions' }, submit, cancel),
            ),
        );
        this.options.changed();

        if (phoneError === null && formError === null) {
            phone.focus();
        }
    }

    private collapse(text: string | null, score: number): void {
        this.settled = true;
        this.card.classList.add('answered');
        const thanks = text ? h('div', { class: 'hint' }, text) : null;
        this.card.replaceChildren(...nodes(thanks, h('div', { class: 'rated-note' }, RATING_COPY.rated(score))));
        this.options.changed();

        if (thanks) {
            window.setTimeout(() => thanks.remove(), THANKS_SHOWN_MS);
        }
    }

    private setBusy(busy: boolean): void {
        this.card.setAttribute('aria-busy', busy ? 'true' : 'false');
        this.card.querySelectorAll<HTMLButtonElement | HTMLInputElement>('button, input').forEach((el) => (el.disabled = busy));
    }
}
