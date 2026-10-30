<?php

namespace Unified\SsoClient\Assistant;

/**
 * A tool the model never sees, usually the reverse of a visible write
 * (`unassign_shift_by_assignment_id` behind `assign_shift`). Hidden tools are
 * left out of the catalog unless it is asked for with ?include_hidden=1, but
 * execute accepts them, which is how SSO replays a stored undo call.
 *
 * This marker interface is the one hidden-tool mechanism; there is no
 * `hidden` key in the definition.
 */
interface HiddenAssistantTool extends AssistantTool {}
