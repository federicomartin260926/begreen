<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\MeasureBlockAjaxController;
use App\Entity\MeasureBlock;
use App\Entity\Protocol;
use App\Repository\MeasureBlockRepository;
use App\Repository\ProtocolRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class MeasureBlockAjaxControllerTest extends KernelTestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;
        self::ensureKernelShutdown();
    }

    public function testByProtocolReturnsOnlyActiveBlocksForSelectedProtocol(): void
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        /** @var ProtocolRepository $protocolRepository */
        $protocolRepository = $container->get(ProtocolRepository::class);
        /** @var MeasureBlockRepository $measureBlockRepository */
        $measureBlockRepository = $container->get(MeasureBlockRepository::class);
        /** @var TranslatableListener $listener */
        $listener = $this->createMock(TranslatableListener::class);
        $listener->expects(self::once())->method('setTranslatableLocale')->with('es');

        $protocol = (new Protocol())
            ->setCode('bgmf-test')
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE);
        $em->persist($protocol);

        $active = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('bgmf__biodiversity')
            ->setName('Biodiversidad')
            ->setSortOrder(99)
            ->setActive(true);
        $em->persist($active);

        $early = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('bgmf__animals')
            ->setName('Animales')
            ->setSortOrder(1)
            ->setActive(true);
        $em->persist($early);

        $inactive = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode('bgmf__inactive')
            ->setName('Inactiva')
            ->setSortOrder(2)
            ->setActive(false);
        $em->persist($inactive);
        $em->flush();

        $controller = new MeasureBlockAjaxController();
        $controller->setContainer($container);
        $request = Request::create('/admin/measure-blocks/by-protocol?id=' . (int) $protocol->getId(), 'GET');
        $request->setLocale('es');

        $response = $controller->byProtocol($request, $protocolRepository, $measureBlockRepository, $listener);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '[{"id":' . $early->getId() . ',"name":"Animales"},{"id":' . $active->getId() . ',"name":"Biodiversidad"}]',
            $response->getContent()
        );
    }
}
