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

        $timezone = new \DateTimeZone('Asia/Kolkata');
        $localTime = (new \DateTime('now', new \DateTimeZone('UTC')))->setTimezone($timezone);
        $output->writeln(sprintf('[%s] Sending reminders to users who missed timesheet submission:', $localTime->format('Y-m-d H:i:s')));

        foreach ($missingEmployees as $user) {
            // Build the reminder email for each user
            $email = (new Email())
                ->from('no-reply@example.com')
                ->to($user->getEmail())
                ->subject('Reminder: Please submit your timesheet')
                ->html(sprintf('
                    <div style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 30px;">
                        <div style="max-width: 600px; background: #ffffff; margin: auto; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                            
                            <p style="font-size: 16px; color: #333;">
                                Hi <strong style="color: #0073b1;">%s</strong>,
                            </p>
                            
                            <p style="font-size: 16px; color: #333;">
                                Based on our records indicate that you have not submitted your timesheet for today
                            </p>
                            
                            <p style="font-size: 16px; color: #333;">
                                Please log in and submit it as soon as possible. 
                            </p>
                            
                           
                            <p style="text-align: center; margin: 30px 0;">
                                <a href="https://timesheet.ngwms.com/" 
                                style="background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                                    Go to Timesheet
                                </a>
                            </p>
                        </div>
                        
                        <div style="max-width: 600px; margin: 20px auto 0; padding: 15px 30px; background-color: #e9ecef; border-radius: 0 0 8px 8px; text-align: center;">
                            <p style="font-size: 13px; color: #555; margin: 0;">
                                This is an automated reminder from your Timesheet System.
                            </p>
                        </div>
                    </div>', htmlspecialchars($user->getAlias())));

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