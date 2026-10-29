<?php

namespace Osos\Gaith\Sdk\Streaming;

use Osos\Gaith\Sdk\Models\Question;

/**
 * The chatbot asks a clarifying question with clickable options (GAITH's
 * present_options tool). The question text also arrives as tokens; the turn then
 * ends with a DoneEvent whose finish reason is "question".
 */
final class QuestionEvent extends ChatEvent
{
    /** @var string */
    private $id;
    /** @var Question */
    private $question;

    public function __construct(string $id, Question $question)
    {
        $this->id = $id;
        $this->question = $question;
    }

    public function id(): string { return $this->id; }
    public function question(): Question { return $this->question; }
}
