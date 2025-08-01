<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\WebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cybersource:update-status-webhook')]
class UpdateStatusWebhookCommand extends Command
{
    private WebhookService $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        parent::__construct();
        $this->webhookService = $webhookService;
    }

    protected function configure(): void
    {
        $this->setDescription('Updates the status of an existing CyberSource webhook.');
        $this->addOption(
            'active',
            null,
            InputOption::VALUE_REQUIRED,
            'Set webhook status to ACTIVE (true) or INACTIVE (false)',
            'true'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $active = filter_var($input->getOption('active'), FILTER_VALIDATE_BOOLEAN);
        $success = $this->webhookService->updateWebhookStatus($active, $io);
        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
