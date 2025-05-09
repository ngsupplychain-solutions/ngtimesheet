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

        $timezone = new \DateTimeZone('Asia/Kolkata');
        $localTime = (new \DateTime('now', new \DateTimeZone('UTC')))->setTimezone($timezone);
        $output->writeln(sprintf('[%s] Sending Users List who missed timesheet submission:', $localTime->format('Y-m-d H:i:s')));

        if (!empty($missingEmployees)) {

            $userList = [];
            foreach ($missingEmployees as $user) {
                // Format: username (email)
                $userList[] = sprintf(
                    '<tr>
                        <td style="border: 1px solid #ccc; padding: 4px; text-align: left;">%s</td>
                        <td style="border: 1px solid #ccc; padding: 4px; text-align: left;">%s</td>
                    </tr>',
                    htmlspecialchars($user->getAlias()),
                    htmlspecialchars($user->getEmail())
                );
            }

            $userTable = sprintf('
                <div style="background:#fff; border:1px solid #ccc; width:600px; margin:0 auto; padding:8px; font-family:Arial,sans-serif; font-size:14px;">
                    <div style="padding-bottom:8px; border-bottom:1px solid #ccc;">
                        <strong style="color: #0073b1;">Missing Timesheets for %s</strong><br>
                        <small style="color: #0073b1;">The following users didn’t submit today’s entry</small>
                    </div>
                    <table style="border-collapse: collapse; width:100%%; margin-top:8px;">
                        <thead>
                            <tr>
                                <th style="border: 1px solid #ccc; padding: 4px; background:#f5f5f5; text-align:left;">Name</th>
                                <th style="border: 1px solid #ccc; padding: 4px; background:#f5f5f5; text-align:left;">Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            %s
                        </tbody>
                    </table>
                </div>
            ', $localTime->format('Y-m-d H:i:s'), implode("\n", $userList));

            $email = (new Email())
                ->from('no-reply@example.com')
                ->to(...$this->managerEmails)
                ->subject('Daily Timesheet Submission Report')
                ->html($userTable);

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
