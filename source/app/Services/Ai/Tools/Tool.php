<?php

namespace App\Services\Ai\Tools;

/**
 * Contract for every agent-callable tool. The runner serializes the list
 * of available tools into the agent's system prompt; when the model
 * responds with a tool_call, the runner looks it up by key() and invokes
 * execute() with the model-provided arguments.
 *
 * Every tool MUST be safe under untrusted input — the model is treated
 * as an adversarial caller. Tools that mutate state (CreateDraftTool,
 * AssignTopicTool) should validate aggressively and bail with a clear
 * error string the model can correct on the next iteration.
 */
interface Tool
{
    /** Stable identifier the model uses in tool_call.name. */
    public function key(): string;

    /** One-line description shown to the model. */
    public function description(): string;

    /**
     * JSON-schema-style argument descriptor for the model. Keys are
     * argument names; values describe type + purpose in plain English.
     *
     * @return array<string, string>
     */
    public function arguments(): array;

    /**
     * Run the tool. Returns a string the runner appends to the
     * conversation as the next "OBSERVATION". Errors should be returned
     * as strings prefixed with "ERROR:" so the model can read them and
     * adjust — never thrown.
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): string;
}
