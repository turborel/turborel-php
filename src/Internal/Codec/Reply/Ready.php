<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Ready 'R': the client is authenticated [PROTOCOL Ready 'R'].
 *
 * @internal
 */
final readonly class Ready implements Reply
{
    /**
     * The reply of its fields.
     *
     * @param list<string> $capabilities
     */
    public function __construct(
        /** The session's authorization identifier. */
        public string $authorization,
        /** The 16 bytes that cancel the session's statements, the client's secret. */
        #[\SensitiveParameter]
        public string $key,
        /** The capabilities granted, in the order of the spec's table. */
        public array $capabilities,
        /** The server's product, as `turborel 0.0.0 (alpha)`. */
        public string $server,
    ) {}

    /**
     * Its fields as var_dump() shows them, the key left out, as a secret is (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['authorization' => $this->authorization, 'key' => '(secret)', 'capabilities' => $this->capabilities, 'server' => $this->server];
    }
}
