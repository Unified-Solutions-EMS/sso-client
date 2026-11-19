/** What the Blade component puts in data-unified-assistant. */
export interface WidgetSettings {
    bootstrap: string;
    messages: string;
    uploads: string;
    /** Template with {action}. */
    undo: string;
    /** Template with {plan} and {verb} (approve | remove | choose | set-aside). */
    review: string;
    conversation: string;
    /** Still sent by the Blade component; the widget no longer calls it (SSO records the first turn itself). */
    intro_dismiss?: string | null;
    /** POST: "Did I handle that correctly?" answers. Absent from an older component: the card is never shown. */
    feedback?: string | null;
    /** Template with {feedback}: the follow-up answer after a no. */
    feedback_follow_up?: string | null;
    /** POST: "Talk to our team". Absent from an older component: derived from `messages`. */
    escalate?: string | null;
    /** GET: our team's replies, polled while a teammate has the conversation. Absent: derived from `messages`. */
    updates?: string | null;
    /**
     * True when this component's proxy forwards `message` (and `attachments`)
     * on escalate, so "Talk to our team" goes in one call. Absent from an older
     * component: the widget hands over first and sends the text as a turn.
     */
    escalate_message?: boolean | null;
    /** POST: the 1 to 5 rating at the end of a conversation. Absent from an older component: no rating card. */
    rating?: string | null;
    /** POST: "Yes, call me" after a low rating. Absent: the call is not offered. */
    callback?: string | null;
    /** POST: read receipts for our team's replies. Absent from an older component: none are sent. */
    seen?: string | null;
    /** The public website's drawer for a guest (sso.assistant.visitor). Absent: a signed-in member. */
    visitor?: boolean | null;
    csrf: string | null;
}

/**
 * A file on a message. The administrator's own carry an `id` (SSO's file
 * number); a teammate's carry the CRM's `url` to download it from.
 */
export interface MessageAttachment {
    id?: number | null;
    name: string;
    mime?: string | null;
    size?: number | null;
    url?: string | null;
}

/**
 * Our team behind the Assistant. `enabled` gives the handoff card Talk to our
 * team (otherwise it offers Copy and the support email address); `needs_teammate` means a teammate has the
 * conversation and the widget polls for their replies. Absent from an older
 * SSO: no team, and an added file is sent at once as before.
 */
export interface Support {
    enabled: boolean;
    status: 'assistant' | 'needs_teammate' | 'resolved';
    last_message_id: number;
    /** Where our team's replies arrive live; null or absent: polling only. */
    realtime?: Realtime | null;
    /** How far the person has read our team's replies (absent from an older SSO). Only used to skip a receipt already sent. */
    seen?: Seen | null;
}

export interface Seen {
    message_id: number;
    at: string | null;
}

/**
 * The CRM's Reverb cluster and the conversation's channel
 * (REALTIME-CONTRACT.md). `token` authorizes that one channel for an hour;
 * every answer carries a fresh one.
 */
export interface Realtime {
    host: string;
    port: number;
    scheme: string;
    key: string;
    channel: string;
    auth_url: string;
    token: string;
    /** The person's own name, for our team's "{name} is typing". */
    name?: string | null;
}

/** GET /assistant/updates answer. */
export interface UpdatesResponse {
    status?: Support['status'] | null;
    messages?: Message[] | null;
    last_message_id?: number | null;
    /** Carries a fresh realtime token; absent from an older SSO. */
    support?: Support | null;
    rating?: Rating | null;
}

export interface Action {
    id: number;
    app: string | null;
    summary: string;
    undoable: boolean;
    undone_at: string | null;
    at: string | null;
}

export interface PlanChange {
    index: number;
    kind: string;
    title: string;
    from: string | null;
    to: string;
    why: string;
    needs_confirmation: boolean;
    risk?: string | null;
    step: number | null;
    /**
     * Set while the row needs the admin to pick a code (a medication name that
     * matched several, or none). Picking one, or leaving the row out, answers
     * with the review again and this row's choice gone.
     */
    choice?: ReviewChoice | null;
}

export interface ReviewCandidate {
    code: string;
    label: string;
    /** A short second line, such as "Schedule II". */
    detail?: string | null;
}

export interface ReviewChoice {
    candidates: ReviewCandidate[];
    search?: boolean;
}

export interface ReviewPlan {
    id: string;
    status: string;
    plan_hash: string;
    changes: PlanChange[];
}

export interface Handoff {
    question: string;
}

/**
 * A page a tool offered to open with the reply ("Open in Analytics"), shown
 * as a link chip under the message. Absent from an older SSO.
 */
export interface MessageLink {
    label: string;
    url: string;
}

export interface Message {
    id: number | string;
    role: 'user' | 'assistant' | 'staff' | 'system';
    content: string;
    /** A teammate's name (role staff). */
    author?: string | null;
    /** The CRM's id for a teammate's reply, so one heard live and the same one polled later render once. */
    crm_message_id?: number | null;
    /** Heard on the socket and not yet confirmed by a poll: never counts toward the poll's `after`. */
    live?: boolean;
    attachments?: MessageAttachment[] | null;
    at?: string | null;
    activity?: string[] | null;
    actions?: Action[] | null;
    review?: ReviewPlan | null;
    handoff?: Handoff | null;
    links?: MessageLink[] | null;
    tone?: 'error' | 'info' | null;
}

/** POST /assistant/messages answer (contract §4.1). */
export interface TurnResponse {
    reply?: string | null;
    activity?: string[] | null;
    actions?: Action[] | null;
    review?: ReviewPlan | null;
    handoff?: Handoff | null;
    links?: MessageLink[] | null;
    error?: string | null;
    notice?: string | null;
    message_id?: number | string | null;
    /**
     * Whether the exchange is finished or the admin's answer is next (the
     * reply asks something, a tool stopped to confirm, a review list is open).
     * Missing from an older SSO: treated as complete.
     */
    turn_state?: 'complete' | 'awaiting_user' | null;
    support?: Support | null;
}

export interface Conversation {
    id: number | string;
    title: string | null;
    started_at?: string | null;
    messages?: Message[] | null;
}

/** A previous thread as listed in the Conversations panel. */
export interface ConversationSummary {
    id: number | string;
    title: string | null;
    last_activity_at: string | null;
    message_count: number;
}

/** A "Things I can do" pill: the label is shown, the prompt is sent. */
export interface Suggestion {
    label: string;
    prompt: string;
    /**
     * `team`: the Talk to our team pill, placed where SSO put it (its prompt is
     * empty, so an older widget drops it and adds its own at the end).
     */
    kind?: 'team' | null;
}

/**
 * How far the Assistant goes for the signed-in person (SSO's AssistantReach):
 * `full` for an agency administrator or staff, `apps` for an administrator
 * inside some apps only, `none` for every other member of the agency (how-do-I
 * questions and Talk to our team, nothing changed for them). Absent from an
 * older SSO: treated as `full`, the drawer as before. `visitor` is an anonymous
 * person on the public website (sales questions, a test drive, our team).
 */
export type Reach = 'full' | 'apps' | 'none' | 'visitor';

/**
 * First-run callout above the launcher. SSO sends show=true on every login
 * until the admin sends a first message; the widget only hides it per tab.
 */
export interface Intro {
    show: boolean;
    title?: string | null;
    body?: string | null;
    cta?: string | null;
    badge?: number | null;
}

/**
 * One walkthrough turn: a short assistant-style line plus the pills to try
 * under it. A `question` step moves on after a complete reply; an `action`
 * step only once a reply carries a change (an action chip or an approved
 * review). Missing kind: question.
 */
export interface TourStep {
    text: string;
    kind?: 'question' | 'action' | null;
    suggestions: Suggestion[];
}

/**
 * Curated walkthrough SSO sends alongside intro.show. Only "Try it" on the
 * callout starts it; the widget never starts it by itself.
 */
export interface Tour {
    steps: TourStep[];
    closing: string;
}

/**
 * GET /assistant/bootstrap answer (optionally ?conversation=ID to reopen a
 * thread) and DELETE /assistant/conversation answer (a new empty thread).
 * 204 means "not for this session".
 */
export interface BootstrapResponse {
    enabled: boolean;
    /**
     * Opaque token for the signed-in person and their current login (SSO hashes
     * the user id with their latest sign-in). The widget scopes its
     * sessionStorage keys to it so one tab serves a new login or another user
     * with a clean slate. Absent from an older SSO: keys are then unscoped.
     */
    viewer?: string | null;
    conversation?: Conversation | null;
    conversations?: ConversationSummary[] | null;
    suggestions?: Suggestion[] | null;
    review?: ReviewPlan | null;
    notice?: string | null;
    intro?: Intro | null;
    tour?: Tour | null;
    /**
     * Whether to ask "Did I handle that correctly?" after a completed task.
     * Absent from an older SSO: never asked.
     */
    feedback?: { ask: boolean } | null;
    support?: Support | null;
    /** The end-of-conversation rating. Absent from an older SSO: never asked. */
    rating?: Rating | null;
    reach?: Reach | null;
}

/** The 1 to 5 rating given at the end of a conversation (RATING-CALLBACK-CONTRACT.md). */
export interface Rating {
    enabled: boolean;
    score: number | null;
    note: string | null;
    rated_at: string | null;
    /** The person's phone on file, formatted, to prefill the call form. */
    phone: string | null;
}

export interface RatingResponse {
    ok?: boolean;
    rating?: Rating | null;
}

export type BestTime = 'now' | 'this_morning' | 'this_afternoon' | 'tomorrow_morning';

/** The answer to "Request a call": the bootstrap shape plus what to say about the call. */
export interface CallbackResponse extends BootstrapResponse {
    callback?: { phone: string; phone_display: string; best_time: BestTime; best_time_label: string; message: string } | null;
}

export interface UndoResponse {
    ok?: boolean;
    undone_at?: string | null;
    error?: string | null;
    message?: string | null;
}

export interface ReviewResponse {
    review?: ReviewPlan | null;
    reply?: string | null;
    error?: string | null;
    notice?: string | null;
}

/** A file SSO is reading or has read. An older SSO sends only `id` (read already). */
export interface UploadStatus {
    id: number;
    status?: 'reading' | 'ready' | 'failed' | null;
    summary?: string | null;
    counts?: Record<string, number> | null;
    error?: string | null;
}

export interface UploadResponse {
    ok?: boolean;
    name?: string | null;
    upload?: UploadStatus | null;
    error?: string | null;
}

/** GET /assistant/uploads/{id} answer. */
export interface UploadStatusResponse {
    upload?: UploadStatus | null;
    error?: string | null;
}

/** POST /assistant/feedback and …/feedback/{id}/follow-up answer. */
export interface FeedbackResponse {
    ok?: boolean;
    id?: number | null;
    helpful?: boolean | null;
    follow_up?: boolean | null;
    error?: string | null;
}
