type IntercomFn = (command: string, arg?: unknown) => void;

const REFILL_DELAY_MS = 900;

function intercom(): IntercomFn | null {
    const fn = (window as unknown as { Intercom?: IntercomFn }).Intercom;

    return typeof fn === 'function' ? fn : null;
}

export function hasIntercom(): boolean {
    return intercom() !== null;
}

/** Reporting's pattern: hide the launcher while the drawer is open. */
export function setIntercomHidden(hidden: boolean): void {
    intercom()?.('update', { hide_default_launcher: hidden });
}

/**
 * Opens Help with the question already in the composer.
 *
 * The Messenger drops the text of the `showNewMessage` call that opens it
 * (an empty draft wins on first load), and only inserts text once it is
 * already showing. So the first call opens it and a second, one-shot call
 * fills the composer: from `onShow` when the Messenger reports itself open,
 * or from a timer when it was open already and `onShow` never fires.
 * Callers must not send `Intercom('update', …)` after this: an update resets
 * the composer.
 */
export function askHelp(question: string): boolean {
    const fn = intercom();

    if (!fn) {
        return false;
    }

    fn('update', { hide_default_launcher: false });

    if (question === '') {
        fn('showNewMessage', '');

        return true;
    }

    let filled = false;
    const fill = (): void => {
        if (filled) {
            return;
        }

        filled = true;
        fn('showNewMessage', question);
    };

    fn('onShow', fill);
    fn('showNewMessage', question);
    window.setTimeout(fill, REFILL_DELAY_MS);

    return true;
}
