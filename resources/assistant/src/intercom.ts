type IntercomFn = (command: string, arg?: unknown) => void;

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

/** Opens Help with the question already in the composer. */
export function askHelp(question: string): boolean {
    const fn = intercom();

    if (!fn) {
        return false;
    }

    fn('update', { hide_default_launcher: false });
    fn('showNewMessage', question);

    return true;
}
