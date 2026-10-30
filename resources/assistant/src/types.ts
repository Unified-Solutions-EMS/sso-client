/** What the Blade component puts in data-unified-assistant. */
export interface WidgetSettings {
    bootstrap: string;
    messages: string;
    uploads: string;
    /** Template with {action}. */
    undo: string;
    /** Template with {plan} and {verb} (approve | remove | set-aside). */
    review: string;
    conversation: string;
    csrf: string | null;
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

export interface Message {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    at?: string | null;
    activity?: string[] | null;
    actions?: Action[] | null;
    review?: ReviewPlan | null;
    handoff?: Handoff | null;
    tone?: 'error' | 'info' | null;
}

/** POST /assistant/messages answer (contract §4.1). */
export interface TurnResponse {
    reply?: string | null;
    activity?: string[] | null;
    actions?: Action[] | null;
    review?: ReviewPlan | null;
    handoff?: Handoff | null;
    error?: string | null;
    notice?: string | null;
    message_id?: number | string | null;
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
}

/**
 * GET /assistant/bootstrap answer (optionally ?conversation=ID to reopen a
 * thread) and DELETE /assistant/conversation answer (a new empty thread).
 * 204 means "not for this session".
 */
export interface BootstrapResponse {
    enabled: boolean;
    conversation?: Conversation | null;
    conversations?: ConversationSummary[] | null;
    suggestions?: Suggestion[] | null;
    review?: ReviewPlan | null;
    notice?: string | null;
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

export interface UploadResponse {
    ok?: boolean;
    name?: string | null;
    error?: string | null;
}
