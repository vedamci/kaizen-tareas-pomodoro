#!/usr/bin/env python3
"""Give Codex stable IDs and a brief instruction for Kaizen task tracking.

The hook sends no network request and does not copy the user's prompt.
Kaizen remains the only place where an accepted task is persisted.
"""

import json
import sys
from datetime import datetime, timezone


def main() -> int:
    try:
        event = json.load(sys.stdin)
    except (ValueError, OSError):
        return 0
    if event.get("hook_event_name") != "UserPromptSubmit":
        return 0
    turn_id = event.get("turn_id")
    session_id = event.get("session_id")
    if not isinstance(turn_id, str) or not isinstance(session_id, str):
        return 0
    received_at = datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")
    context = (
        "Kaizen assistant task tracking is enabled for this local Codex session. "
        f"session_id={session_id}; turn_id={turn_id}; received_at={received_at}. "
        "When this user message delegates a concrete task, create or update its record in "
        "Kaizen with assistant_run_upsert using the narrow assistant-runs write permission. "
        "Use source='codex', a stable external_id based on the original task's turn_id, "
        "and a new deterministic idempotency_key for each distinct update. "
        "Record a concise title, actual status, verified progress, next step, and real deliverables. "
        "Use received_at as delegated_at only for a new task from this message. "
        "Follow-up instructions for an existing task update that same external_id. "
        "Questions, status requests, corrections, and casual conversation by themselves do not create tasks. "
        "Choose a workspace only from an authenticated Kaizen lookup or prior verified selection; never guess its ID. "
        "Before the final answer, update the record to completed only if the requested work is actually done; "
        "when completed, include completed_at with the verified completion time. "
        "otherwise record the current in_progress or blocked state. "
        "Do not include the full user prompt or secrets in Kaizen. "
        "If the Kaizen connection or permission is missing, say that automatic registration did not happen."
    )
    json.dump({"hookSpecificOutput": {"hookEventName": "UserPromptSubmit", "additionalContext": context}}, sys.stdout)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
