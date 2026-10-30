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

/** GET /assistant/bootstrap answer. 204 means "not for this session". */
export interface BootstrapResponse {
    enabled: boolean;
    conversation?: { messages?: Message[] | null } | null;
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
