<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Command;

use CyberSource\Shopware6\Service\WebhookService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cybersource:create-webhook')]
class CreateWebhookCommand extends Command
{
    private WebhookService $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        parent::__construct();
        $this->webhookService = $webhookService;
    }

    protected function configure(): void
    {
        $this->setDescription('Creates a CyberSource webhook for payment notifications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = 'ShopwarePaymentWebhook' . time();
        $context = Context::createDefaultContext();
        $webhookUrl = $this->webhookService->getWebhookUrl($context);
        $healthCheckUrl = $this->webhookService->getHealthCheckUrl($context);

        $io->text('Webhook URL: ' . $webhookUrl);
        $io->text('Health Check URL: ' . $healthCheckUrl);

        $success = $this->webhookService->createWebhook($name, $webhookUrl, $healthCheckUrl, $io);
        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
