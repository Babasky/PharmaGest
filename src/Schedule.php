<?php

namespace App;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tâches planifiées, exécutées par le worker : `messenger:consume scheduler_default`.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Rappels d'échéance d'abonnement (J-30, J-15, J-7), chaque matin à 7 h 50, heure de Bamako.
            ->add(RecurringMessage::cron('50 7 * * *', new RunCommandMessage('app:abonnements:rappels'), new \DateTimeZone(Kernel::TIMEZONE)))
        ;
    }
}
