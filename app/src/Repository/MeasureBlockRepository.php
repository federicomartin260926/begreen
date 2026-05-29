<?php

namespace App\Repository;

use App\Entity\MeasureBlock;
use App\Entity\Protocol;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MeasureBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeasureBlock::class);
    }

    /**
     * @return MeasureBlock[]
     */
    public function findActiveByProtocol(Protocol $protocol): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.protocol = :protocol')
            ->andWhere('b.active = true')
            ->setParameter('protocol', $protocol)
            ->orderBy('b.name', 'ASC')
            ->addOrderBy('b.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByProtocolAndCode(Protocol $protocol, string $code): ?MeasureBlock
    {
        return $this->findOneBy([
            'protocol' => $protocol,
            'code' => $code,
        ]);
    }

    public function findOneByProtocolAndName(Protocol $protocol, string $name): ?MeasureBlock
    {
        return $this->findOneBy([
            'protocol' => $protocol,
            'name' => $name,
        ]);
    }

    public function findEquivalentByProtocol(Protocol $protocol, string $value): ?MeasureBlock
    {
        $needle = $this->normalize($value);
        if ($needle === '') {
            return null;
        }

        foreach ($this->createQueryBuilder('b')
            ->andWhere('b.protocol = :protocol')
            ->setParameter('protocol', $protocol)
            ->orderBy('b.name', 'ASC')
            ->addOrderBy('b.code', 'ASC')
            ->getQuery()
            ->getResult() as $block) {
            if (!$block instanceof MeasureBlock) {
                continue;
            }

            if ($this->normalize((string) $block->getCode()) === $needle || $this->normalize((string) $block->getName()) === $needle) {
                return $block;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }

        $ascii = mb_strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/u', '', $ascii);

        return $ascii ?? '';
    }
}
