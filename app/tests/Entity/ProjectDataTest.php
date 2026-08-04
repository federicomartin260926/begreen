<?php

namespace App\Tests\Entity;

use App\Entity\Project;
use App\Entity\ProjectCompany;
use App\Entity\ProjectFundingSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectDataTest extends KernelTestCase
{
    public function testNormalizeStateClearsIncompatibleFields(): void
    {
        $project = (new Project())
            ->setName('Proyecto test')
            ->setType('evento')
            ->setCountry('ES')
            ->setFilmingType('tv_series')
            ->setFilmingGenre('ficcion')
            ->setDistributionMedia(['tv', 'internet'])
            ->setEventTypePrimary('cultural')
            ->setEventModality('virtual')
            ->setEventAttendeesCount(120)
            ->setEventOnlineConnections(42)
            ->setEpisodios(8)
            ->setDuracionEpisodio(50);

        $project->normalizeState();

        self::assertNull($project->getFilmingType());
        self::assertNull($project->getFilmingGenre());
        self::assertSame([], $project->getDistributionMedia());
        self::assertNull($project->getEpisodios());
        self::assertNull($project->getDuracionEpisodio());
        self::assertSame('cultural', $project->getEventTypePrimary());
        self::assertSame('virtual', $project->getEventModality());
        self::assertNull($project->getEventAttendeesCount());
        self::assertSame(42, $project->getEventOnlineConnections());
    }

    public function testNormalizeStateClearsEpisodeFieldsForNonSeriesFilmingTypes(): void
    {
        $project = (new Project())
            ->setName('Proyecto rodaje')
            ->setType('rodaje')
            ->setCountry('ES')
            ->setFilmingType('feature')
            ->setFilmingGenre('ficcion')
            ->setDistributionMedia(['cinema', 'tv'])
            ->setEpisodios(4)
            ->setDuracionEpisodio(30);

        $project->normalizeState();

        self::assertSame(['cinema', 'tv'], $project->getDistributionMedia());
        self::assertNull($project->getEpisodios());
        self::assertNull($project->getDuracionEpisodio());
    }

    public function testValidateConditionalFieldsRequiresRodajeFields(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto rodaje')
            ->setType('rodaje')
            ->setCountry('ES');

        $violations = $validator->validate($project);
        $messages = array_map(static fn ($violation) => $violation->getMessageTemplate(), iterator_to_array($violations));

        self::assertContains('backend.projects.form.validation.filming_type_required', $messages);
        self::assertContains('backend.projects.form.validation.distribution_media_required', $messages);
    }

    public function testValidateConditionalFieldsRequiresEventFields(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto evento')
            ->setType('evento')
            ->setCountry('ES')
            ->setEventModality('hibrido');

        $violations = $validator->validate($project);
        $messages = array_map(static fn ($violation) => $violation->getMessageTemplate(), iterator_to_array($violations));

        self::assertContains('backend.projects.form.validation.event_type_primary_required', $messages);
        self::assertContains('backend.projects.form.validation.attendees_required', $messages);
        self::assertContains('backend.projects.form.validation.online_connections_required', $messages);
    }

    public function testValidateConditionalFieldsRequiresEventModality(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto evento sin modalidad')
            ->setType('evento')
            ->setCountry('ES');

        $violations = $validator->validate($project);
        $messages = array_map(static fn ($violation) => $violation->getMessageTemplate(), iterator_to_array($violations));

        self::assertContains('backend.projects.form.validation.event_modality_required', $messages);
    }

    public function testFundingSourcesMustSumToOneHundredPercent(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto financiación')
            ->setType('rodaje')
            ->setCountry('ES');

        $project->addProjectFundingSource(
            (new ProjectFundingSource())
                ->setType('client')
                ->setName('Cliente A')
                ->setPercentage('60.00')
        );
        $project->addProjectFundingSource(
            (new ProjectFundingSource())
                ->setType('grant')
                ->setName('Ayuda pública')
                ->setPercentage('60.00')
        );

        $violations = $validator->validate($project);
        $messages = array_map(static fn ($violation) => $violation->getMessageTemplate(), iterator_to_array($violations));

        self::assertContains('backend.projects.form.validation.funding_total_invalid', $messages);
    }

    public function testProjectCompaniesCannotRepeatNormalizedNames(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto con empresas duplicadas')
            ->setType('rodaje')
            ->setCountry('ES');
        $project->addProjectCompany(
            (new ProjectCompany())->setType('production_company')->setName('Fantasy')
        );
        $project->addProjectCompany(
            (new ProjectCompany())->setType('client')->setName(' fantasy ')
        );

        $messages = array_map(
            static fn ($violation) => $violation->getMessageTemplate(),
            iterator_to_array($validator->validate($project))
        );

        self::assertContains('backend.projects.form.validation.project_company_duplicate', $messages);
    }

    public function testCompanyFundingMustReferenceACompanyWithMatchingType(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto con financiación incoherente')
            ->setType('rodaje')
            ->setCountry('ES');
        $project->addProjectCompany(
            (new ProjectCompany())->setType('production_company')->setName('Fantasy')
        );
        $project->addProjectFundingSource(
            (new ProjectFundingSource())->setType('client')->setName('Fantasy')->setPercentage('50.00')
        );
        $project->addProjectFundingSource(
            (new ProjectFundingSource())->setType('agency')->setName('Empresa ausente')->setPercentage('50.00')
        );

        $messages = array_map(
            static fn ($violation) => $violation->getMessageTemplate(),
            iterator_to_array($validator->validate($project))
        );

        self::assertContains('backend.projects.form.validation.funding_company_type_mismatch', $messages);
        self::assertContains('backend.projects.form.validation.funding_company_unknown', $messages);
        self::assertNotContains('backend.projects.form.validation.funding_total_invalid', $messages);
    }

    public function testCompanyCannotFundProjectTwice(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $project = (new Project())
            ->setName('Proyecto con financiación duplicada')
            ->setType('rodaje')
            ->setCountry('ES');
        $project->addProjectCompany(
            (new ProjectCompany())->setType('production_company')->setName('Fantasy')
        );
        $project->addProjectFundingSource(
            (new ProjectFundingSource())->setType('production_company')->setName('Fantasy')->setPercentage('50.00')
        );
        $project->addProjectFundingSource(
            (new ProjectFundingSource())->setType('production_company')->setName(' fantasy ')->setPercentage('50.00')
        );

        $messages = array_map(
            static fn ($violation) => $violation->getMessageTemplate(),
            iterator_to_array($validator->validate($project))
        );

        self::assertContains('backend.projects.form.validation.funding_company_duplicate', $messages);
    }
}
