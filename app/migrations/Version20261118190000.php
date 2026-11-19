<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BEGREEN #98: translated implementation and follow-up field on measure';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('measure') && !$schema->getTable('measure')->hasColumn('implementation_follow_up')) {
            $this->addSql('ALTER TABLE measure ADD implementation_follow_up LONGTEXT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('measure') && $schema->getTable('measure')->hasColumn('implementation_follow_up')) {
            $this->addSql('ALTER TABLE measure DROP implementation_follow_up');
        }
    }
}
