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
final class Version20250522170550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // $this->abortIf(true, 'Migration was auto-generated, adapt to your needs before running it.');

        $this->addSql('ALTER TABLE kimai2_activities ADD label_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD label_symbol VARCHAR(5) DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_timesheet ADD work_place VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // $this->abortIf(true, 'Migration was auto-generated, adapt to your needs before running it.');

        $this->addSql('ALTER TABLE kimai2_activities DROP label_enabled, DROP label_symbol');
        $this->addSql('ALTER TABLE kimai2_timesheet DROP work_place');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
