<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Codec\Frames;

/**
 * What a connection asks for as it opens [CLIENT What a connection asks for]:
 * the capabilities, by default every one but compression, as
 * turborel-client asks; the connection's attributes, which every server
 * reads, shown in INFORMATION_SCHEMA.CONNECTION_ATTRIBUTES; and the
 * threshold past which a frame is sent compressed, once compression is
 * granted.
 */
final readonly class Offer
{
    /**
     * The offer of its parts; by default, every capability but compression.
     *
     * @param list<string>                $capabilities
     * @param list<array{string, string}> $attributes
     */
    public function __construct(
        /** @var list<string> $capabilities the capabilities asked for, by name */
        public array $capabilities = Defaults::CAPABILITIES,
        /** @var list<array{string, string}> $attributes the connection's attributes, each a name and a value */
        public array $attributes = [],
        /** The bytes of a frame, its header counted, from which it is sent compressed. */
        public int $compressFrom = Frames::DEFAULT_THRESHOLD,
    ) {}

    /** An offer of no capability: the protocol's first form, byte for byte [PROTOCOL The Native Protocol]. */
    public static function none(): self
    {
        return new self([]);
    }

    /** It, asking for compression too. */
    public function compressed(): self
    {
        $capabilities = \in_array(Capability::COMPRESSION, $this->capabilities, true)
            ? $this->capabilities : [...$this->capabilities, Capability::COMPRESSION];

        return new self($capabilities, $this->attributes, $this->compressFrom);
    }

    /** It, with the connection attribute `$name` of `$value`. */
    public function attribute(string $name, string $value): self
    {
        return new self($this->capabilities, [...$this->attributes, [$name, $value]], $this->compressFrom);
    }
}
