<?php

namespace Osos\Gaith\Sdk\Streaming;

final class SafetyBlockEvent extends ChatEvent
{
    public function isTerminal(): bool { return true; }
}
