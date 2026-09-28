<?php

declare(strict_types=1);

/*
 * (c) INSPIRED MINDS
 */

namespace InspiredMinds\ContaoEventRegistration\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use InspiredMinds\ContaoEventRegistration\EventRegistration;
use InspiredMinds\ContaoEventRegistration\WaitingListChecker;
use Symfony\Component\Lock\LockFactory;

#[AsCronJob('hourly')]
class ExpiredRegistrationsCronJob
{
    public function __construct(
        private readonly Connection $db,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(): void
    {
        $lock = $this->lockFactory->createLock(WaitingListChecker::class);
        $lock->acquire(true);

        try {
            $now = time();
            $cutoff = $now - EventRegistration::CONFIRMATION_EXPIRATION_SECONDS;

            $rows = $this->db->fetchAllAssociative(<<<'SQL'
                SELECT r.id
                FROM tl_event_registration r
                INNER JOIN tl_calendar_events e ON e.id = r.pid
                WHERE r.confirmed != 1
                  AND r.cancelled != 1
                  AND r.expired_at IS NULL
                  AND e.reg_requireConfirm = 1
                  AND r.created <= ?
            SQL, [$cutoff]);

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

            if ([] === $ids) {
                return;
            }

            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $this->db->executeQuery(
                'UPDATE tl_event_registration SET expired_at = ?, tstamp = ? WHERE id IN ('.$placeholders.')',
                [$now, $now, ...$ids],
            );
        } finally {
            $lock->release();
        }
    }
}
