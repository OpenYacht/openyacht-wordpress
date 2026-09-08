<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\PushDelivery;
use OpenYacht\Federation\PushDeliveryRepository;

final class InMemoryPushDeliveryRepository implements PushDeliveryRepository
{
    /** @var array<int, PushDelivery> */
    public array $deliveries = [];

    private int $nextId = 1;

    public function enqueue(int $partnerId, int $listingId, string $nowStored): void
    {
        foreach ($this->deliveries as $id => $delivery) {
            if ($delivery->partnerId === $partnerId && $delivery->listingId === $listingId) {
                $this->deliveries[$id] = $this->with($delivery, ['changedAt' => $nowStored]);

                return;
            }
        }

        $id = $this->nextId++;
        $this->deliveries[$id] = new PushDelivery($id, $partnerId, $listingId, $nowStored, $nowStored, $nowStored, 0, null);
    }

    public function due(string $nowStored, int $limit): array
    {
        $due = array_values(array_filter($this->deliveries, static fn (PushDelivery $d): bool => $d->nextAttemptAt <= $nowStored));
        usort($due, static fn (PushDelivery $a, PushDelivery $b): int => [$a->nextAttemptAt, $a->id] <=> [$b->nextAttemptAt, $b->id]);

        return array_slice($due, 0, $limit);
    }

    public function all(): array
    {
        return array_values($this->deliveries);
    }

    public function complete(int $id, string $changedAtStored): bool
    {
        $delivery = $this->deliveries[$id] ?? null;

        if ($delivery === null || $delivery->changedAt !== $changedAtStored) {
            return false;
        }

        unset($this->deliveries[$id]);

        return true;
    }

    public function delete(int $id): void
    {
        unset($this->deliveries[$id]);
    }

    public function deleteForPartner(int $partnerId): void
    {
        $this->deliveries = array_filter($this->deliveries, static fn (PushDelivery $d): bool => $d->partnerId !== $partnerId);
    }

    public function recordFailure(int $id, int $attempts, string $nextAttemptAtStored, string $error): void
    {
        if (isset($this->deliveries[$id])) {
            $this->deliveries[$id] = $this->with($this->deliveries[$id], ['attempts' => $attempts, 'nextAttemptAt' => $nextAttemptAtStored, 'lastError' => $error]);
        }
    }

    public function reschedule(int $id, string $nextAttemptAtStored): void
    {
        if (isset($this->deliveries[$id])) {
            $this->deliveries[$id] = $this->with($this->deliveries[$id], ['nextAttemptAt' => $nextAttemptAtStored]);
        }
    }

    public function earliestNextAttempt(): ?string
    {
        $earliest = null;

        foreach ($this->deliveries as $delivery) {
            if ($earliest === null || $delivery->nextAttemptAt < $earliest) {
                $earliest = $delivery->nextAttemptAt;
            }
        }

        return $earliest;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(PushDelivery $delivery, array $changes): PushDelivery
    {
        return new PushDelivery(
            $delivery->id,
            $delivery->partnerId,
            $delivery->listingId,
            $delivery->queuedAt,
            $changes['changedAt'] ?? $delivery->changedAt,
            $changes['nextAttemptAt'] ?? $delivery->nextAttemptAt,
            $changes['attempts'] ?? $delivery->attempts,
            array_key_exists('lastError', $changes) ? $changes['lastError'] : $delivery->lastError,
        );
    }
}
