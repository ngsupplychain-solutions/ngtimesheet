<?php
namespace App\Command;

use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'app:notify-missing-timesheet-users',
    description: 'Send an individual reminder email to each user who did not submit their timesheet for today.'
)]
class NotifyMissingTimesheetUsersCommand extends Command
{
    public function __construct(
        private TimesheetRepository $timesheetRepository,
        private MailerInterface $mailer
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Retrieve the list of users who have not submitted their timesheet today
        $missingEmployees = $this->timesheetRepository->findUsersMissingTodayEntry();

        if (empty($missingEmployees)) {
            $output->writeln('All users have submitted their timesheets today.');
            return Command::SUCCESS;
        }

        foreach ($missingEmployees as $user) {
            // Build the reminder email for each user
            $email = (new Email())
                ->from('no-reply@example.com')
                ->to($user->getEmail())
                ->subject('Reminder: Please submit your timesheet')
                ->html(sprintf(
                    '<p>Dear %s,</p><p>Our records indicate that you have not submitted your timesheet for today. Please log in and submit it as soon as possible.</p>',
                    $user->getAlias()
                ));

            try {
                $this->mailer->send($email);
                $output->writeln(sprintf('Reminder sent to %s (%s)', $user->getAlias(), $user->getEmail()));
            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    'Failed to send email to %s (%s): %s',
                    $user->getAlias(),
                    $user->getEmail(),
                    $e->getMessage()
                ));
            }
        }

        return Command::SUCCESS;
    }
}