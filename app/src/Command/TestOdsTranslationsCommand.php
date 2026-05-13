<?php

namespace App\Command;

use App\Entity\Ods;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\TranslatableListener;
use Gedmo\Translatable\Entity\Translation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:test-ods-translations', description: 'Crea un ODS y guarda traducciones en ext_translations')]
class TestOdsTranslationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        // Aliased por Stof: "stof_doctrine_extensions.listener.translatable"
        private TranslatableListener $translatableListener,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Creando ODS en ES (locale por defecto)…</info>');

        // 1) Crear ODS en ES (default_locale, p.ej. "es")
        $ods = new Ods();
        $ods->setCode('SDG7');
        $ods->setName('Energía asequible y no contaminante');
        $ods->setDescription('Descripción en español');
        $this->em->persist($ods);
        $this->em->flush();

        // 2) Cambiar listener a EN y asignar traducciones
        $this->translatableListener->setTranslatableLocale('en');

        // Opción A: asignar en el propio objeto (el listener lo volcará a ext_translations)
        $ods->setName('Affordable and Clean Energy');
        $ods->setDescription('Description in English');
        $this->em->flush();

        // (Opcional) Opción B: usar el repositorio Translation (equivalente):
        // $tr = $this->em->getRepository(Translation::class);
        // $tr->translate($ods, 'name', 'en', 'Affordable and Clean Energy');
        // $tr->translate($ods, 'description', 'en', 'Description in English');
        // $this->em->flush();

        // 3) Leer en ES
        $this->translatableListener->setTranslatableLocale('es');
        $odsEs = $this->em->getRepository(Ods::class)->find($ods->getId());
        $output->writeln("\n<comment>Lectura ES:</comment>");
        $output->writeln('Name: ' . $odsEs->getName());
        $output->writeln('Desc: ' . $odsEs->getDescription());

        // 4) Leer en EN
        $this->translatableListener->setTranslatableLocale('en');
        $odsEn = $this->em->getRepository(Ods::class)->find($ods->getId());
        $output->writeln("\n<comment>Lectura EN:</comment>");
        $output->writeln('Name: ' . $odsEn->getName());
        $output->writeln('Desc: ' . $odsEn->getDescription());

        // 5) Mostrar filas crudas en ext_translations
        $output->writeln("\n<info>Filas en ext_translations:</info>");
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT locale, object_class, field, foreign_key, content
             FROM ext_translations
             WHERE object_class = :cls AND foreign_key = :fk
             ORDER BY field, locale",
            ['cls' => Ods::class, 'fk' => (string)$ods->getId()]
        );
        foreach ($rows as $r) {
            $output->writeln(sprintf(
                '- [%s] %s.%s (%s) => %s',
                $r['locale'],
                $r['object_class'],
                $r['field'],
                $r['foreign_key'],
                $r['content']
            ));
        }

        $output->writeln("\n<info>OK ✅</info>");
        return Command::SUCCESS;
    }
}
