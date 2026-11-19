<?php

namespace Unified\SsoClient\Assistant;

/**
 * A tool that also works for an anonymous website visitor
 * ($actor->isVisitor(): no user, no company, nobody signed in). Every other
 * tool is left out of a visitor's catalog, and calling one as a visitor
 * answers failed('Sign in to do that.') without running it, so existing app
 * tools that read $actor->user stay safe.
 */
interface VisitorAssistantTool extends AssistantTool {}
