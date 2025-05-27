<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\WebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cybersource:delete-webhook')]
class DeleteWebhookCommand extends Command
{
    private WebhookService $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        parent::__construct();
        $this->webhookService = $webhookService;
    }

    protected function configure(): void
    {
        $this->setDescription('Deletes an existing CyberSource webhook.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $success = $this->webhookService->deleteWebhook($io);
        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}