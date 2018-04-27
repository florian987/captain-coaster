<?php

namespace BddBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RatingUpdateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('rating:update')
            ->setDescription('Update average rating and total ratings calculated values for all coasters');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stopwatch = $this->getContainer()->get('debug.stopwatch');
        $stopwatch->start('rating');

        $output->writeln('Start updating ratings.');

        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $coasters = $em->getRepository('BddBundle:Coaster')->findAll();

        $ratingManager = $this->getContainer()->get('BddBundle\Service\RatingService');

        foreach ($coasters as $coaster) {
            $initialValue = $coaster->getAverageRating();
            $newValue = $ratingManager->manageRatings($coaster);

            if ($initialValue != $newValue) {
                $output->writeln(
                    sprintf('Updated %s [%s to %s]', $coaster->getName(), $initialValue, $newValue)
                );
            }
        }

        $output->writeln('End of update.');

        $event = $stopwatch->stop('rating');
        $output->writeln((round($event->getDuration() / 1000)).' seconds');
        $output->writeln((round($event->getMemory() / (1000 * 1000))).' Mo');
    }
}
