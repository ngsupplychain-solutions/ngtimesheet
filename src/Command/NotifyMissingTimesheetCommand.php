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
    description: 'Send a email to the manager with the list of users who did not submit today’s timesheet.'
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
        
        $missingEmployees = $this->timesheetRepository->findUsersMissingTodayEntry();

        if (!empty($missingEmployees)) {

            $userList = [];
            foreach ($missingEmployees as $user) {
                // Format: username (email)
                $userList[] = sprintf('%s (%s)', $user->getAlias(), $user->getEmail());
            }

            $userListString = implode('<br>', $userList);

            $email = (new Email())
                ->from('contactngsupplychain@gmail.com')
                ->to(...$this->managerEmails)
                ->subject('Daily Timesheet Submission Report')
                ->html(sprintf(
                    '<p>The following users did not submit their timesheet for today:</p><p>%s</p>',
                    $userListString
                ));

            $this->mailer->send($email);
            // $output->writeln(sprintf('Summary email sent to manager: %s', ...$this->managerEmail));
            $output->writeln(sprintf('Summary email sent to manager(s): %s', implode(', ', $this->managerEmails)));

            return Command::SUCCESS;
        } else {
            $output->writeln('No missing timesheet entries found.');
        }

        return Command::SUCCESS;
    }
}
