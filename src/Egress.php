<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\Socket\listen;

/**
 * The way out for everything `agy` sends. The address of Google's API cannot be replaced in `agy`, so there is no
 * relay in front of it: when the service has a route (VLESS or a proxy), the child gets this proxy as HTTPS_PROXY, and
 * the proxy opens tunnels through that route. It takes CONNECT only, and only to the listed hosts of Google on port
 * 443. TLS ends at Google: the proxy sees no token. The proxy is a route, not a wall: a process that ignores
 * HTTPS_PROXY still has the network of the container.
 *
 * spec: services/antigravity/BRIEF.md#сеть
 */
final class Egress {
    /**
     * The hosts `agy` was seen to name: the sign-in, the exchange of its code, the return after it, the API. Whole
     * domains of Google are not opened: buckets and documents of anybody live there. A host `agy` needs and the list
     * lacks is refused and written to the log by name; ANTIGRAVITY_EGRESS_HOSTS adds exact names.
     */
    private const HOSTS = ['accounts.google.com', 'oauth2.googleapis.com', 'www.googleapis.com', 'cloudcode-pa.googleapis.com', 'daily-cloudcode-pa.googleapis.com', 'antigravity.google', 'www.antigravity.google'];
    private const HEAD_BYTES = 8192;
    private const HEAD_SECONDS = 10.0;
    private const CONNECT_SECONDS = 20.0;

    private ?ResourceServerSocket $server = null;

    /** @var array<string, true> */
    private readonly array $hosts;

    /** @param list<string> $more exact names of hosts the owner added */
    public function __construct(
        private readonly SocketConnector $route,
        private readonly LoggerInterface $logger,
        array $more = [],
    ) {
        $hosts = array_fill_keys(self::HOSTS, true);
        foreach ($more as $host) {
            $host = strtolower(trim($host));
            if (preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$~', $host) === 1) {
                $hosts[$host] = true;
            }
        }
        $this->hosts = $hosts;
    }

    /** Starts to listen on the loopback and gives the address for HTTPS_PROXY. */
    public function start(): string {
        if ($this->server === null) {
            $server = listen('127.0.0.1:0');
            $this->server = $server;
            async(function () use ($server): void {
                while (($client = $server->accept()) !== null) {
                    async($this->serve(...), $client)->ignore();
                }
            })->ignore();
        }
        return 'http://' . $this->server->getAddress()->toString();
    }

    public function stop(): void {
        $this->server?->close();
        $this->server = null;
    }

    private function serve(Socket $client): void {
        $remote = null;
        try {
            $head = '';
            $wait = new TimeoutCancellation(self::HEAD_SECONDS);
            while (!str_contains($head, "\r\n\r\n")) {
                $chunk = $client->read($wait);
                if ($chunk === null || strlen($head) + strlen($chunk) > self::HEAD_BYTES) {
                    return;
                }
                $head .= $chunk;
            }
            $rest = substr($head, (int) strpos($head, "\r\n\r\n") + 4);
            $first = substr($head, 0, (int) strpos($head, "\r\n"));
            if (preg_match('~^CONNECT ([A-Za-z0-9.-]+):(\d+) HTTP/1\.[01]$~', $first, $match) !== 1) {
                $client->write("HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\n\r\n");
                return;
            }
            $host = strtolower($match[1]);
            if ($match[2] !== '443' || !$this->allowed($host)) {
                // the name of a host is no secret, and the list is tuned by it
                $this->logger->warning('antigravity: a tunnel to a host outside Google was refused', ['host' => $host, 'port' => $match[2]]);
                $client->write("HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
                return;
            }
            try {
                $remote = $this->route->connect('tcp://' . $host . ':443', (new ConnectContext())->withConnectTimeout(self::CONNECT_SECONDS), new TimeoutCancellation(self::CONNECT_SECONDS));
            } catch (Throwable $e) {
                $this->logger->warning('antigravity: the route did not open a tunnel', ['host' => $host, 'error' => $e::class]);
                $client->write("HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
                return;
            }
            $client->write("HTTP/1.1 200 Connection Established\r\n\r\n");
            if ($rest !== '') {
                $remote->write($rest);
            }
            $up = async(self::carry(...), $client, $remote, null);
            self::carry($remote, $client, null);
            $up->await();
        } catch (Throwable) {
            // one side went away: the other is closed below
        } finally {
            $client->close();
            $remote?->close();
        }
    }

    /** Carries bytes one way until the source ends, then closes the other side so its reader ends too. */
    private static function carry(Socket $from, Socket $to, ?Cancellation $cancellation): void {
        try {
            pipe($from, $to, $cancellation);
        } catch (Throwable) {
            // a closed side ends the tunnel
        } finally {
            $to->close();
            $from->close();
        }
    }

    public function allowed(string $host): bool {
        return isset($this->hosts[strtolower($host)]);
    }
}
