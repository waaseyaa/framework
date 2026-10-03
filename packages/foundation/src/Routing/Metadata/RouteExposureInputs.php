<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** Kernel-local boot publication of API exposure decisions, never a policy resolver. @api */
final class RouteExposureInputs
{
    private ?array $publication = null;
    private bool $sealed = false;
    private bool $failed = false;

    /** @param array<array-key, mixed> $effectiveMap The existing policy's scalar map. */
    public function publish(array $effectiveMap): void
    {
        if ($this->sealed) {
            $this->failed = true;
            throw $this->unavailable();
        }
        if ($this->failed || $this->publication !== null) {
            $this->failed = true;
            return;
        }
        try {
            foreach ($effectiveMap as $id => $exposed) {
                if (!is_string($id) || !is_bool($exposed)) {
                    throw new \UnexpectedValueException();
                }
                ScalarRouteMetadata::identifier($id);
            }
            $this->publication = ScalarRouteMetadata::copy($effectiveMap);
        } catch (\Throwable) {
            // Preserve ordinary boot; canonical input admission owns this refusal.
            $this->failed = true;
        }
    }

    /**
     * @param array<array-key, mixed> $entityIds The finalized registered roster.
     * @return array<string, bool>
     */
    public function freeze(array $entityIds, bool $apiPresent): array
    {
        if ($this->sealed || $this->failed) {
            $this->failed = true;
            throw $this->unavailable();
        }
        $this->sealed = true;
        try {
            if (!array_is_list($entityIds)) {
                throw new \UnexpectedValueException();
            }
            foreach ($entityIds as $id) {
                if (!is_string($id)) {
                    throw new \UnexpectedValueException();
                }
                ScalarRouteMetadata::identifier($id);
            }
            if (count(array_unique($entityIds)) !== count($entityIds)) {
                throw new \UnexpectedValueException();
            }
            if (!$apiPresent) {
                if ($this->publication !== null) {
                    throw new \UnexpectedValueException();
                }
                return array_fill_keys($entityIds, false);
            }
            if ($this->publication === null) {
                throw new \UnexpectedValueException();
            }
            $publishedIds = array_keys($this->publication);
            sort($entityIds, SORT_STRING);
            sort($publishedIds, SORT_STRING);
            if ($entityIds !== $publishedIds) {
                throw new \UnexpectedValueException();
            }
            return $this->publication;
        } catch (\Throwable) {
            $this->failed = true;
            throw $this->unavailable();
        }
    }

    /** Check custody on every kernel access, including after contexts were cached. */
    public function assertReady(): void
    {
        if (!$this->sealed || $this->failed) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): RouteCompositionException
    {
        return new RouteCompositionException('inputs-unavailable', 'Finalized route exposure publication is unavailable.');
    }
}
