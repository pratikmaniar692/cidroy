<?php

declare(strict_types=1);

namespace Forgeline\Domain;

/**
 * The stubs are explicit: "Money is a decimal string ... Never a float."
 * This class is the boundary that enforces that rule in code, not just in
 * a comment. Every amount that enters the system from Sellaxis, the ERP, or
 * ShipBridge passes through here before it's stored or compared.
 *
 * Internally we keep the amount as a string and do arithmetic with bcmath,
 * so we never round-trip through IEEE-754 float representation, which is
 * exactly the kind of silent precision loss that would violate the
 * settlement-accuracy requirement this whole design is built around.
 */
final class Money
{
    private string $amount; // canonical decimal string, e.g. "1245.50"
    private string $currency;

    private const SCALE = 2;

    public function __construct(string $amount, string $currency)
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new \InvalidArgumentException("Amount '{$amount}' is not a valid decimal string");
        }
        if (!in_array($currency, ['INR', 'EUR'], true)) {
            throw new \InvalidArgumentException("Unsupported currency '{$currency}'");
        }
        $this->amount = bcadd($amount, '0', self::SCALE);
        $this->currency = $currency;
    }

    public static function fromString(string $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function multiply(int $qty): self
    {
        return new self(bcmul($this->amount, (string) $qty, self::SCALE), $this->currency);
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function toString(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency} without a pinned FX rate "
                . "(the stubs are explicit: there is no FX rate in the feed; rate sourcing is ours to decide, "
                . "and this codebase deliberately does not invent one — see README, FX note)."
            );
        }
    }
}
