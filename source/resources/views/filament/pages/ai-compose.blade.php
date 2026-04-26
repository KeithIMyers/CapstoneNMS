<x-filament-panels::page>
    <form wire:submit.prevent="compose">
        {{ $this->form }}
    </form>

    <x-filament::section style="margin-top:1rem;">
        <x-slot name="heading">How this works</x-slot>
        <ol style="margin:0;padding-left:1.25rem;line-height:1.6;font-size:0.92rem;color:#475569;">
            <li>The agent fetches each source URL with the safe <code>fetch_url</code> tool (10s timeout, 200KB cap, refuses internal hosts).</li>
            <li>It builds a fact matrix per source — claims, named entities, dates, numbers, quotes — then cross-references which facts are corroborated across sources, single-sourced, or contested.</li>
            <li>It composes in inverted-pyramid AP style with <strong>inline attribution</strong>: corroborated facts lead, single-sourced claims read "X reports that…", contested facts present both sides.</li>
            <li>It calls <code>create_draft</code> — the result lands in <strong>draft</strong> status under your byline. Nothing is published.</li>
            <li>Every URL that fetched successfully is auto-cited via <code>add_source</code> — the article's Citations tab populates without a separate step.</li>
            <li>You're redirected to the new draft. The agent's final output includes a <strong>synthesis note</strong> (shown in the success toast) flagging source balance, contested facts, and limitations.</li>
        </ol>
        <p style="margin-top:0.85rem;font-size:0.88rem;color:#64748b;">
            <strong>Tip:</strong> Use <em>Preview sources</em> first to confirm every URL loads — it costs nothing and catches paywalls or typos before the agent burns tokens.
        </p>
    </x-filament::section>
</x-filament-panels::page>
