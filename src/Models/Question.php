<?php

namespace Osos\Gaith\Sdk\Models;

/**
 * A clarifying question with clickable answer options, asked by a chatbot that has
 * follow-up questions enabled (GAITH's present_options tool). The client shows the
 * options as buttons; the clicked label is sent as the user's next message.
 */
final class Question
{
    /** @var string */
    private $question;
    /** @var string[] */
    private $options;

    /**
     * @param string[] $options
     */
    public function __construct(string $question, array $options)
    {
        $this->question = $question;
        $this->options = $options;
    }

    /**
     * @param array<string, mixed> $data `{question, options}` as GAITH sends or saves it
     * @return self|null null when there is no question or fewer than two usable options
     */
    public static function fromArray(array $data): ?self
    {
        $question = isset($data['question']) && is_string($data['question']) ? trim($data['question']) : '';
        $options = [];
        foreach ((array) ($data['options'] ?? []) as $option) {
            if (is_string($option) && trim($option) !== '' && !in_array(trim($option), $options, true)) {
                $options[] = trim($option);
            }
        }

        return $question === '' || count($options) < 2 ? null : new self($question, $options);
    }

    public function question(): string { return $this->question; }

    /** @return string[] */
    public function options(): array { return $this->options; }
}
