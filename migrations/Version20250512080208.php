<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * @version 2.x
 */
final class Version20250512080208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // $this->abortIf(true, 'Migration was auto-generated, adapt to your needs before running it.');

        $this->addSql('ALTER TABLE kimai2_activities CHANGE comment comment LONGTEXT DEFAULT NULL, CHANGE visible visible TINYINT(1) DEFAULT 1 NOT NULL, CHANGE time_budget time_budget INT NOT NULL, CHANGE budget budget DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE kimai2_activities_rates CHANGE activity_id activity_id INT NOT NULL');
        $this->addSql('ALTER TABLE kimai2_customers CHANGE comment comment LONGTEXT DEFAULT NULL, CHANGE address address LONGTEXT DEFAULT NULL, CHANGE time_budget time_budget INT NOT NULL, CHANGE budget budget DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE kimai2_customers_rates CHANGE customer_id customer_id INT NOT NULL');
        $this->addSql('ALTER TABLE kimai2_invoice_templates CHANGE address address LONGTEXT DEFAULT NULL, CHANGE vat vat DOUBLE PRECISION NOT NULL, CHANGE payment_terms payment_terms LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_projects CHANGE comment comment LONGTEXT DEFAULT NULL, CHANGE budget budget DOUBLE PRECISION NOT NULL, CHANGE time_budget time_budget INT NOT NULL');
        $this->addSql('ALTER TABLE kimai2_projects_rates CHANGE project_id project_id INT NOT NULL');
        $this->addSql('ALTER TABLE kimai2_tags CHANGE visible visible TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE kimai2_timesheet ADD team_id INT DEFAULT NULL, CHANGE description description LONGTEXT DEFAULT NULL, CHANGE billable billable TINYINT(1) DEFAULT 1 NOT NULL, CHANGE modified_at modified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE date_tz date_tz DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE jira_ids jira_ids LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_timesheet ADD CONSTRAINT FK_4F60C6B1296CD8AE FOREIGN KEY (team_id) REFERENCES kimai2_teams (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4F60C6B1296CD8AE ON kimai2_timesheet (team_id)');
        $this->addSql('ALTER TABLE kimai2_timesheet_tags RENAME INDEX idx_e3284efeabdd46be TO IDX_732EECA9ABDD46BE');
        $this->addSql('ALTER TABLE kimai2_timesheet_tags RENAME INDEX idx_e3284efebad26311 TO IDX_732EECA9BAD26311');
        $this->addSql('ALTER TABLE kimai2_user_preferences CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE kimai2_users CHANGE password_requested_at password_requested_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE kimai2_working_times CHANGE approved_at approved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE kimai2_sessions CHANGE id id VARBINARY(128) NOT NULL, CHANGE data data LONGBLOB NOT NULL');
        $this->addSql('CREATE INDEX lifetime_idx ON kimai2_sessions (lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Migration was auto-generated, adapt to your needs before running it.');

        $this->addSql('ALTER TABLE kimai2_activities CHANGE comment comment TEXT DEFAULT NULL, CHANGE visible visible TINYINT(1) NOT NULL, CHANGE budget budget DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE time_budget time_budget INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE kimai2_activities_rates CHANGE activity_id activity_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_customers CHANGE comment comment TEXT DEFAULT NULL, CHANGE address address TEXT DEFAULT NULL, CHANGE budget budget DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE time_budget time_budget INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE kimai2_customers_rates CHANGE customer_id customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_invoice_templates CHANGE address address TEXT DEFAULT NULL, CHANGE vat vat DOUBLE PRECISION DEFAULT \'0\', CHANGE payment_terms payment_terms TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_projects CHANGE comment comment TEXT DEFAULT NULL, CHANGE budget budget DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE time_budget time_budget INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE kimai2_projects_rates CHANGE project_id project_id INT DEFAULT NULL');
        $this->addSql('DROP INDEX lifetime_idx ON kimai2_sessions');
        $this->addSql('ALTER TABLE kimai2_sessions CHANGE id id VARCHAR(128) NOT NULL, CHANGE data data BLOB NOT NULL');
        $this->addSql('ALTER TABLE kimai2_tags CHANGE visible visible TINYINT(1) DEFAULT 1');
        $this->addSql('ALTER TABLE kimai2_timesheet DROP FOREIGN KEY FK_4F60C6B1296CD8AE');
        $this->addSql('DROP INDEX IDX_4F60C6B1296CD8AE ON kimai2_timesheet');
        $this->addSql('ALTER TABLE kimai2_timesheet DROP team_id, CHANGE date_tz date_tz DATE NOT NULL, CHANGE jira_ids jira_ids TEXT DEFAULT NULL, CHANGE description description TEXT DEFAULT NULL, CHANGE billable billable TINYINT(1) DEFAULT 1, CHANGE created_at created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE modified_at modified_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_timesheet_tags RENAME INDEX idx_732eeca9abdd46be TO IDX_E3284EFEABDD46BE');
        $this->addSql('ALTER TABLE kimai2_timesheet_tags RENAME INDEX idx_732eeca9bad26311 TO IDX_E3284EFEBAD26311');
        $this->addSql('ALTER TABLE kimai2_user_preferences CHANGE user_id user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_users CHANGE password_requested_at password_requested_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_working_times CHANGE approved_at approved_at DATETIME DEFAULT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
