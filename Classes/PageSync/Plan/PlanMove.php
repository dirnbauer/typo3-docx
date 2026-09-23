<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * An existing element that is in another place in Word than in TYPO3.
 */
final readonly class PlanMove
{
    public function __construct(
        public int $uid,
        public int $colPos,
        /** After this element, or 0 for the top of the column. */
        public int $afterUid,
        public bool $columnChanged = false,
    ) {}

    /**
     * @return array{uid: int, colPos: int, afterUid: int, columnChanged: bool}
     */
    public function toArray(): array
    {
        return ['uid' => $this->uid, 'colPos' => $this->colPos, 'afterUid' => $this->afterUid, 'columnChanged' => $this->columnChanged];
    }
}
