<?php

namespace Osos\Gaith\Sdk\Streaming;

/**
 * A keep-alive the server sends during silence (an SSE comment, no event name).
 *
 * It carries no content and never ends a turn. It is surfaced rather than
 * swallowed so a consumer relaying the stream onward (e.g. the Laravel chat
 * widget controller) can keep its own downstream connection alive - otherwise a
 * long tool call looks like a dead socket to any proxy sitting in front of it.
 */
final class HeartbeatEvent extends ChatEvent
{
}
