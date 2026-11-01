<?php

namespace Osos\Gaith\Sdk\Streaming;

abstract class ChatEvent
{
    public function isTerminal(): bool
    {
        return false;
    }

    public static function fromFrame(SseFrame $frame): ?self
    {
        if ($frame->event === null) {
            return null;
        }

        $data = json_decode($frame->data, true);
        if (!is_array($data)) {
            $data = [];
        }

        switch ($frame->event) {
            case 'meta':
                return new MetaEvent($data['conversation_id'], $data['user_message_id'], $data['stream_id']);
            case 'token':
                return new TokenEvent($data['content']);
            case 'tool_call':
                return new ToolCallEvent($data['id'], $data['tool']);
            case 'tool_result':
                return new ToolResultEvent($data['id'], $data['tool'], (bool) $data['ok'], $data['status'] ?? null);
            case 'file':
                return new FileEvent(\Osos\Gaith\Sdk\Models\FileRef::fromArray($data));
            case 'limit':
                return new LimitEvent($data['reason']);
            case 'done':
                return new DoneEvent(
                    $data['message_id'],
                    $data['conversation_id'],
                    $data['finish_reason'] ?? null,
                    \Osos\Gaith\Sdk\Models\Usage::fromArray($data['usage'])
                );
            case 'error':
                return new ErrorEvent($data['code'], $data['message']);
            case 'safety_block':
                return new SafetyBlockEvent();
            case 'question':
                $question = \Osos\Gaith\Sdk\Models\Question::fromArray($data);
                // A malformed question is skipped like an unknown event; its text still arrived as tokens.
                return $question === null ? null : new QuestionEvent((string) ($data['id'] ?? ''), $question);
            case 'connect_needed':
                // Without a link there is nothing the user could click, so it is skipped.
                $server = $data['server'] ?? null;
                $url = $data['url'] ?? null;
                if (!is_string($url) || trim($url) === '') {
                    return null;
                }
                return new ConnectNeededEvent(is_string($server) ? trim($server) : '', trim($url));
            default:
                return null;
        }
    }
}
