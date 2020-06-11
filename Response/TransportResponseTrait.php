<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Response;

use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Internal\ClientState;

/**
 * Implements common logic for transport-level response classes.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
trait TransportResponseTrait
{
    private $headers = [];
    private $info = [
        'response_headers' => [],
        'http_code' => 0,
        'error' => null,
        'canceled' => false,
    ];

    /** @var resource */
    private $handle;
    private $id;
    private $timeout = 0;
    private $inflate;
    private $finalInfo;
    private $logger;

    /**
     * {@inheritdoc}
     */
    public function getStatusCode(): int
    {
        if ($this->initializer) {
            self::initialize($this);
        }

        return $this->info['http_code'];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(bool $throw = true): array
    {
        if ($this->initializer) {
            self::initialize($this);
        }

        if ($throw) {
            $this->checkStatusCode();
        }

        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(): void
    {
        $this->info['canceled'] = true;
        $this->info['error'] = 'Response has been canceled.';
        $this->close();
    }

    /**
     * Adds pending responses to the activity list.
     */
    abstract protected static function schedule(self $response, array &$runningResponses): void;

    /**
     * Performs all pending non-blocking operations.
     */
    abstract protected static function perform(ClientState $multi, array &$responses): void;

    /**
     * Waits for network activity.
     */
    abstract protected static function select(ClientState $multi, float $timeout): int;

    private static function addResponseHeaders(array $responseHeaders, array &$info, array &$headers, string &$debug = ''): void
    {
        foreach ($responseHeaders as $h) {
            if (11 <= \strlen($h) && '/' === $h[4] && preg_match('#^HTTP/\d+(?:\.\d+)? ([1-9]\d\d)(?: |$)#', $h, $m)) {
                if ($headers) {
                    $debug .= "< \r\n";
                    $headers = [];
                }
                $info['http_code'] = (int) $m[1];
            } elseif (2 === \count($m = explode(':', $h, 2))) {
                $headers[strtolower($m[0])][] = ltrim($m[1]);
            }

            $debug .= "< {$h}\r\n";
            $info['response_headers'][] = $h;
        }

        $debug .= "< \r\n";

        if (!$info['http_code']) {
            throw new TransportException('Invalid or missing HTTP status line.');
        }
    }

    /**
     * Ensures the request is always sent and that the response code was checked.
     */
    private function doDestruct()
    {
        $this->shouldBuffer = true;

        if ($this->initializer && null === $this->info['error']) {
            self::initialize($this);
            $this->checkStatusCode();
        }
    }

    /**
     * Implements an event loop based on a buffer activity queue.
     *
     * @internal
     */
    public static function stream(iterable $responses, float $timeout = null): \Generator
    {
        $runningResponses = [];

        foreach ($responses as $response) {
            self::schedule($response, $runningResponses);
        }

        $lastActivity = microtime(true);
        $enlapsedTimeout = 0;

        while (true) {
            $hasActivity = false;
            $timeoutMax = 0;
            $timeoutMin = $timeout ?? INF;

            /** @var ClientState $multi */
            foreach ($runningResponses as $i => [$multi]) {
                $responses = &$runningResponses[$i][1];
                self::perform($multi, $responses);

                foreach ($responses as $j => $response) {
                    $timeoutMax = $timeout ?? max($timeoutMax, $response->timeout);
                    $timeoutMin = min($timeoutMin, $response->timeout, 1);
                    $chunk = false;

                    if (isset($multi->handlesActivity[$j])) {
                        // no-op
                    } elseif (!isset($multi->openHandles[$j])) {
                        unset($responses[$j]);
                        continue;
                    } elseif ($enlapsedTimeout >= $timeoutMax) {
                        $multi->handlesActivity[$j] = [new ErrorChunk($response->offset, sprintf('Idle timeout reached for "%s".', $response->getInfo('url')))];
                    } else {
                        continue;
                    }

                    while ($multi->handlesActivity[$j] ?? false) {
                        $hasActivity = true;
                        $enlapsedTimeout = 0;

                        if (\is_string($chunk = array_shift($multi->handlesActivity[$j]))) {
                            if (null !== $response->inflate && false === $chunk = @inflate_add($response->inflate, $chunk)) {
                                $multi->handlesActivity[$j] = [null, new TransportException('Error while processing content unencoding.')];
                                continue;
                            }

                            if ('' !== $chunk && null !== $response->content && \strlen($chunk) !== fwrite($response->content, $chunk)) {
                                $multi->handlesActivity[$j] = [null, new TransportException(sprintf('Failed writing %d bytes to the response buffer.', \strlen($chunk)))];
                                continue;
                            }

                            $chunkLen = \strlen($chunk);
                            $chunk = new DataChunk($response->offset, $chunk);
                            $response->offset += $chunkLen;
                        } elseif (null === $chunk) {
                            $e = $multi->handlesActivity[$j][0];
                            unset($responses[$j], $multi->handlesActivity[$j]);
                            $response->close();

                            if (null !== $e) {
                                $response->info['error'] = $e->getMessage();

                                if ($e instanceof \Error) {
                                    throw $e;
                                }

                                $chunk = new ErrorChunk($response->offset, $e);
                            } else {
                                $chunk = new LastChunk($response->offset);
                            }
                        } elseif ($chunk instanceof ErrorChunk) {
                            unset($responses[$j]);
                            $enlapsedTimeout = $timeoutMax;
                        } elseif ($chunk instanceof FirstChunk) {
                            if ($response->logger) {
                                $info = $response->getInfo();
                                $response->logger->info(sprintf('Response: "%s %s"', $info['http_code'], $info['url']));
                            }

                            $response->inflate = \extension_loaded('zlib') && $response->inflate && 'gzip' === ($response->headers['content-encoding'][0] ?? null) ? inflate_init(ZLIB_ENCODING_GZIP) : null;

                            if ($response->shouldBuffer instanceof \Closure) {
                                try {
                                    $response->shouldBuffer = ($response->shouldBuffer)($response->headers);

                                    if (null !== $response->info['error']) {
                                        throw new TransportException($response->info['error']);
                                    }
                                } catch (\Throwable $e) {
                                    $response->close();
                                    $multi->handlesActivity[$j] = [null, $e];
                                }
                            }

                            if (true === $response->shouldBuffer) {
                                $response->content = fopen('php://temp', 'w+');
                            } elseif (\is_resource($response->shouldBuffer)) {
                                $response->content = $response->shouldBuffer;
                            }
                            $response->shouldBuffer = null;

                            yield $response => $chunk;

                            if ($response->initializer && null === $response->info['error']) {
                                // Ensure the HTTP status code is always checked
                                $response->getHeaders(true);
                            }

                            continue;
                        }

                        yield $response => $chunk;
                    }

                    unset($multi->handlesActivity[$j]);

                    if ($chunk instanceof ErrorChunk && !$chunk->didThrow()) {
                        // Ensure transport exceptions are always thrown
                        $chunk->getContent();
                    }
                }

                if (!$responses) {
                    unset($runningResponses[$i]);
                }

                // Prevent memory leaks
                $multi->handlesActivity = $multi->handlesActivity ?: [];
                $multi->openHandles = $multi->openHandles ?: [];
            }

            if (!$runningResponses) {
                break;
            }

            if ($hasActivity) {
                $lastActivity = microtime(true);
                continue;
            }

            if (-1 === self::select($multi, min($timeoutMin, $timeoutMax - $enlapsedTimeout))) {
                usleep(min(500, 1E6 * $timeoutMin));
            }

            $enlapsedTimeout = microtime(true) - $lastActivity;
        }
    }
}
