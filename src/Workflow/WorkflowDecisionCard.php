<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

/**
 * Typed decision card for one decision_required gate. Every field is read from
 * owner state; facts the owner does not hold are listed under unknown instead of
 * being guessed. Presentation only: it never records or implies a decision.
 */
final readonly class WorkflowDecisionCard
{
    /**
     * @param array<string, mixed> $subject
     * @param array{kind: string, summary: string, changes: list<string>} $whyNow
     * @param list<string> $mayChange
     * @param list<string> $outOfScope
     * @param array{validation: array{state: string, detail: string|null, planned: list<string>}, review: array{state: string, detail: string|null}} $proof
     * @param list<array{id: string, label: string, effect: string, command: string|null}> $choices
     * @param list<string> $unknown
     */
    public function __construct(
        public string $type,
        public string $language,
        public string $requestedLanguage,
        public string $title,
        public string $authorizes,
        public array $subject,
        public array $whyNow,
        public array $mayChange,
        public array $outOfScope,
        public array $proof,
        public array $choices,
        public array $unknown,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'type' => $this->type,
            // The language the text is written in; requested_language is what the session or project asked for.
            'language' => $this->language,
            'requested_language' => $this->requestedLanguage,
            'title' => $this->title,
            'subject' => $this->subject,
            'why_now' => $this->whyNow,
            'may_change' => $this->mayChange,
            'out_of_scope' => $this->outOfScope,
            'proof' => $this->proof,
            'authorizes' => $this->authorizes,
            'choices' => $this->choices,
            'unknown' => $this->unknown,
        ];
    }
}
