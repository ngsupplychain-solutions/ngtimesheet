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
    name: 'app:notify-missing-timesheet',
    description: 'Sends email notifications for missing timesheet entries.'
)]
class NotifyMissingTimesheetCommand extends Command
{
    private array $managerEmails;

    public function __construct(
        private TimesheetRepository $timesheetRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private EntityManagerInterface $em,
        ?string $managerEmails = null // ✅ Allow null and check later
    ) {
        parent::__construct();
        
        // If $managerEmails is null, use an empty array to prevent errors
        $this->managerEmails = $managerEmails ? array_map('trim', explode(',', $managerEmails)) : [];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $thresholdTime = new \DateTime('today 09:32', new \DateTimeZone('Asia/Kolkata'));

        if (new \DateTime() < $thresholdTime) {
            $output->writeln('It is not yet time to send notifications.');
            return Command::SUCCESS;
        }

        $missingEmployees = $this->timesheetRepository->findUsersMissingTodayEntry();

        if (!empty($missingEmployees)) {
            $employeeNames = implode(', ', array_map(fn($emp) => $emp->getUsername(), $missingEmployees));

            // ✅ Ensure we have recipients before sending
            if (!empty($this->managerEmails)) {
                $email = (new Email())
                    ->from('miltonvino5@gmail.com')
                    ->to(...$this->managerEmails)
                    ->subject('Missing Timesheet Entries for Today')
                    ->html("<p>The following employees have not submitted their timesheet entries today: <strong>$employeeNames</strong></p>");

                $this->mailer->send($email);
                $output->writeln(sprintf('Email sent to: %s', implode(', ', $this->managerEmails)));
            } else {
                $output->writeln('No manager emails configured.');
            }
        } else {
            $output->writeln('No missing timesheet entries found.');
        }

        return Command::SUCCESS;
    }
}
