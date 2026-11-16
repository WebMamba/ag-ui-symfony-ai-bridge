CHANGELOG
=========

0.1
---

 * Add `AgUi`, which runs any Symfony AI agent over AG-UI: text, reasoning and tool calls
 * Add frontend tools: the run stops when the model calls a tool the client declared
 * Add interrupts with `ResumableTool` and `Interrupt`; their ids are signed with the secret `AgUi` takes
 * Add typed shared state: a state class filled from the run agent input, its changes sent as state deltas
 * Add `Activity`, a tool result with a sentence for the model and content for the client
 * Add `RunContext`, agent context facts, and `ToolResultMapping` for extra events
