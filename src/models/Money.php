<?php

/**
 * Value Object — no identity, immutable, validates in the constructor, and its
 * equality is based on value. Represents a monetary amount in a currency.
 */
final class Money {
    private float $amount;
    private string $currency;

    public function __construct(float $amount, string $currency = 'USD')
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative');
        }
        $this->amount   = $amount;
        $this->currency = strtoupper($currency);
    }

    public function getAmount(): float    { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }

    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /** "$1,234" (no decimals) — the dashboard's headline format. */
    public function format(int $decimals = 0): string
    {
        $symbol = $this->currency === 'USD' ? '$' : ($this->currency . ' ');
        return $symbol . number_format($this->amount, $decimals);
    }

    public function __toString(): string { return $this->format(); }
}
