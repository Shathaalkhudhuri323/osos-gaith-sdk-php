<?php

namespace Osos\Gaith\Sdk\Streaming;

/**
 * GAITH needs the user to connect their own account to one of the chatbot's tools (an MCP
 * server, such as the QHSE MCP) before it can answer. It sends the name of that service and
 * the link where the user signs in and approves the connection.
 *
 * The reply's text, which also arrives, usually explains this in words. The chat box shows a
 * "Connect" button for the link.
 */
final class ConnectNeededEvent extends ChatEvent
{
    /** @var string */
    private $server;

    /** @var string */
    private $url;

    public function __construct(string $server, string $url)
    {
        $this->server = $server;
        $this->url = $url;
    }

    /** The name to show the user, e.g. "QHSE". */
    public function server(): string { return $this->server; }

    /** The link that starts the connection. */
    public function url(): string { return $this->url; }
}
