<?php

namespace App\Command;

use App\Mailjet\MailjetApi;
use App\Mailjet\Model\CreateCampaignDraft\CreateCampaignDraftRequest;
use App\Mailjet\Model\CreateCampaignDraftContent\CreateCampaignDraftContentRequest;
use App\Mailjet\Model\SendCampaignDraft\SendCampaignDraftRequest;
use App\Mailjet\Model\TestCampaignDraft\Recipient;
use App\Mailjet\Model\TestCampaignDraft\TestCampaignDraftRequest;
use App\Repository\JobRepository;
use App\Sentry\CheckInRequest;
use App\Sentry\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Zenstruck\ScheduleBundle\Schedule\SelfSchedulingCommand;
use Zenstruck\ScheduleBundle\Schedule\Task\CommandTask;

#[AsCommand(
    name: 'app:send-jobsletter',
    description: 'Send the weekly jobs-letter to subscribers.',
)]
final class SendWeeklyJobsLetterCommand extends Command implements SelfSchedulingCommand
{
    public function __construct(
        private readonly Environment $twig,
        private readonly JobRepository $jobRepository,
        private readonly MailjetApi $mailjetApi,
        #[Autowire('%env(int:MAILJET_CONTACT_LIST_ID)%')]
        private readonly int $mailjetContactListId,
        #[Autowire('%env(MAILJET_SENDER_ID)%')]
        private readonly string $mailjetSenderId,
        private readonly RouterInterface $router,
        #[Autowire('%env(COMMAND_ROUTER_HOST)%')]
        private readonly string $commandRouterHost,
        #[Autowire('%env(COMMAND_ROUTER_SCHEME)%')]
        private readonly string $commandRouterScheme,
        private readonly Client $sentryClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('test', null, InputOption::VALUE_REQUIRED, 'Send test to email address')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = $this->jobRepository->findLastWeekJobs();

        if ([] === $jobs) {
            $output->writeln('No jobs found');

            return Command::SUCCESS;
        }

        $context = $this->router->getContext();
        $context->setHost($this->commandRouterHost);
        $context->setScheme($this->commandRouterScheme);

        $response = $this->mailjetApi->createCampaignDraft(new CreateCampaignDraftRequest(
            sprintf('[%s] Weekly jobs letter', (new \DateTime())->format('W')),
            $this->mailjetContactListId,
            'en_US',
            'hello@jobbsy.dev',
            'Quentin from Jobbsy',
            'Weekly Symfony jobs 🚀',
            $this->mailjetSenderId,
        ));

        if (null === $response) {
            return Command::FAILURE;
        }

        if (false === isset($response->data[0]['ID'])) {
            return Command::FAILURE;
        }

        $id = $response->data[0]['ID'];

        $html = $this->twig->render('email/weekly_jobsletter.html.twig', [
            'jobs' => $jobs,
        ]);
        $this->mailjetApi->createCampaignDraftContent(new CreateCampaignDraftContentRequest($id, $html));

        $test = $input->getOption('test');

        if (null === $test) {
            $this->mailjetApi->sendCampaignDraft(new SendCampaignDraftRequest($id));

            return Command::SUCCESS;
        }

        if (false === \is_string($test)) {
            return Command::FAILURE;
        }

        $response = $this->mailjetApi->testCampaignDraft(new TestCampaignDraftRequest($id, [new Recipient($test)]));

        if (null === $response) {
            return Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $io->info('Test send. Campaign status : '.$response->data[0]['Status']);

        return Command::SUCCESS;
    }

    public function schedule(CommandTask $task): void
    {
        $monitorSlug = 'jobs-letter';

        $task->before(function () use ($monitorSlug) {
            $this->sentryClient->checkIns(CheckInRequest::createInProgress($monitorSlug));
        });

        $task->onSuccess(function () use ($monitorSlug) {
            $this->sentryClient->checkIns(CheckInRequest::createOk($monitorSlug));
        });

        $task->onFailure(function () use ($monitorSlug) {
            $this->sentryClient->checkIns(CheckInRequest::createError($monitorSlug));
        });

        $task
            ->mondays()
            ->at('12:40')
        ;
    }
}
