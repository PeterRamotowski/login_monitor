<?php

namespace Drupal\login_monitor\Command;

use Drupal\login_monitor\Service\LoginReportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to send login statistics email reports.
 */
#[AsCommand(
  name: 'login-monitor:send-reports',
  description: 'Send login statistics email report',
  aliases: ['sr'],
)]
final class SendReportsCommand extends Command {

  public function __construct(
    private readonly LoginReportService $reportService,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('Processing login reports...');

    try {
      $this->reportService->processScheduledReports();
      $output->writeln('<info>Successfully processed login reports.</info>');
      return self::SUCCESS;
    }
    catch (\Exception $e) {
      $output->writeln('<error>Error processing reports: ' . $e->getMessage() . '</error>');
      return self::FAILURE;
    }
  }

}
