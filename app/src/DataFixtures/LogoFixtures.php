<?php

namespace App\DataFixtures;

use App\Entity\Logo;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\HttpKernel\KernelInterface;

class LogoFixtures extends Fixture
{
    private string $projectDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
    }

    public function load(ObjectManager $manager): void
    {
        $data = [
            ['ABB', '/uploads/logos_carrusel/1.png', null, 1, 1],
            ['ADP', '/uploads/logos_carrusel/2.png', null, 1, 2],
            ['Adecco', '/uploads/logos_carrusel/3.png', null, 1, 3],
            ['Amundi', '/uploads/logos_carrusel/4.png', null, 1, 4],
            ['Apple', '/uploads/logos_carrusel/5.png', 'https://www.apple.com/', 1, 5],
            ['As', '/uploads/logos_carrusel/6.png', 'https://as.com/', 1, 6],
            ['Axa', '/uploads/logos_carrusel/7.png', 'https://www.axa.es/', 1, 7],
            ['Bacus', '/uploads/logos_carrusel/8.png', null, 1, 8],
            ['Babu', '/uploads/logos_carrusel/9.png', null, 1, 9],
            ['BancaMarch', '/uploads/logos_carrusel/10.png', null, 1, 10],
            ['B', '/uploads/logos_carrusel/11.png', null, 1, 11],
            ['Beon', '/uploads/logos_carrusel/12.png', null, 1, 12],
            ['Biogen', '/uploads/logos_carrusel/13.png', null, 1, 13],
            ['bofrost', '/uploads/logos_carrusel/14.png', null, 1, 14],
            ['Boston Scientific', '/uploads/logos_carrusel/15.png', null, 1, 15],
            ['-', '/uploads/logos_carrusel/16.png', null, 1, 16],
            ['-', '/uploads/logos_carrusel/17.png', null, 1, 17],
            ['-', '/uploads/logos_carrusel/18.png', null, 1, 18],
            ['-', '/uploads/logos_carrusel/19.png', null, 1, 19],
            ['-', '/uploads/logos_carrusel/20.png', null, 1, 20],
            ['-', '/uploads/logos_carrusel/21.png', null, 1, 21],
            ['-', '/uploads/logos_carrusel/22.png', null, 1, 22],
            ['-', '/uploads/logos_carrusel/23.png', null, 1, 23],
            ['-', '/uploads/logos_carrusel/24.png', null, 1, 24],
            ['-', '/uploads/logos_carrusel/25.png', null, 1, 25],
            ['-', '/uploads/logos_carrusel/26.png', null, 1, 26],
            ['-', '/uploads/logos_carrusel/27.png', null, 1, 27],
            ['-', '/uploads/logos_carrusel/28.png', null, 1, 28],
            ['-', '/uploads/logos_carrusel/29.png', null, 1, 29],
            ['-', '/uploads/logos_carrusel/30.png', null, 1, 30],
            ['-', '/uploads/logos_carrusel/31.png', null, 1, 31],
            ['-', '/uploads/logos_carrusel/32.png', null, 1, 32],
            ['-', '/uploads/logos_carrusel/33.png', null, 1, 33],
            ['-', '/uploads/logos_carrusel/34.png', null, 1, 34],
            ['-', '/uploads/logos_carrusel/35.png', null, 1, 35],
            ['-', '/uploads/logos_carrusel/36.png', null, 1, 36],
            ['-', '/uploads/logos_carrusel/37.png', null, 1, 37],
            ['-', '/uploads/logos_carrusel/38.png', null, 1, 38],
            ['-', '/uploads/logos_carrusel/39.png', null, 1, 39],
            ['-', '/uploads/logos_carrusel/40.png', null, 1, 40],
            ['-', '/uploads/logos_carrusel/41.png', null, 1, 41],
            ['-', '/uploads/logos_carrusel/42.png', null, 1, 42],
            ['-', '/uploads/logos_carrusel/43.png', null, 1, 43],
            ['-', '/uploads/logos_carrusel/44.png', null, 1, 44],
            ['-', '/uploads/logos_carrusel/45.png', null, 1, 45],
            ['-', '/uploads/logos_carrusel/46.png', null, 1, 46],
            ['-', '/uploads/logos_carrusel/47.png', null, 1, 47],
            ['-', '/uploads/logos_carrusel/48.png', null, 1, 48],
            ['-', '/uploads/logos_carrusel/49.png', null, 1, 49],
            ['-', '/uploads/logos_carrusel/50.png', null, 1, 50],
            ['-', '/uploads/logos_carrusel/51.png', null, 1, 51],
            ['-', '/uploads/logos_carrusel/52.png', null, 1, 52],
            ['-', '/uploads/logos_carrusel/53.png', null, 1, 53],
            ['-', '/uploads/logos_carrusel/54.png', null, 1, 54],
            ['-', '/uploads/logos_carrusel/55.png', null, 1, 55],
            ['-', '/uploads/logos_carrusel/56.png', null, 1, 56],
            ['-', '/uploads/logos_carrusel/57.png', null, 1, 57],
            ['-', '/uploads/logos_carrusel/58.png', null, 1, 58],
            ['-', '/uploads/logos_carrusel/59.png', null, 1, 59],
            ['-', '/uploads/logos_carrusel/60.png', null, 1, 60],
            ['-', '/uploads/logos_carrusel/61.png', null, 1, 61],
            ['-', '/uploads/logos_carrusel/62.png', null, 1, 62],
            ['-', '/uploads/logos_carrusel/63.png', null, 1, 63],
            ['-', '/uploads/logos_carrusel/64.png', null, 1, 64],
            ['-', '/uploads/logos_carrusel/65.png', null, 1, 65],
            ['-', '/uploads/logos_carrusel/66.png', null, 1, 66],
            // ['-', '/uploads/logos_carrusel/67.png', null, 1, 67], //Logo en blanco
            ['-', '/uploads/logos_carrusel/68.png', null, 1, 68],
            ['-', '/uploads/logos_carrusel/69.png', null, 1, 69],
            ['-', '/uploads/logos_carrusel/70.png', null, 1, 70],
            ['-', '/uploads/logos_carrusel/71.png', null, 1, 71],
            ['-', '/uploads/logos_carrusel/72.png', null, 1, 72],
            ['-', '/uploads/logos_carrusel/73.png', null, 1, 73],
            ['-', '/uploads/logos_carrusel/74.png', null, 1, 74],
            ['-', '/uploads/logos_carrusel/75.png', null, 1, 75],
            ['-', '/uploads/logos_carrusel/76.png', null, 1, 76],
            ['-', '/uploads/logos_carrusel/77.png', null, 1, 77],
            ['-', '/uploads/logos_carrusel/78.png', null, 1, 78],
            ['-', '/uploads/logos_carrusel/79.png', null, 1, 79],
            ['-', '/uploads/logos_carrusel/80.png', null, 1, 80],
            ['-', '/uploads/logos_carrusel/81.png', null, 1, 81],
            ['-', '/uploads/logos_carrusel/82.png', null, 1, 82],
            ['-', '/uploads/logos_carrusel/83.png', null, 1, 83],
            ['-', '/uploads/logos_carrusel/84.png', null, 1, 84],
            ['-', '/uploads/logos_carrusel/85.png', null, 1, 85],
            ['-', '/uploads/logos_carrusel/86.png', null, 1, 86],
            ['-', '/uploads/logos_carrusel/87.png', null, 1, 87],
            ['-', '/uploads/logos_carrusel/88.png', null, 1, 88],
            ['-', '/uploads/logos_carrusel/89.png', null, 1, 89],
            ['-', '/uploads/logos_carrusel/90.png', null, 1, 90],
            ['-', '/uploads/logos_carrusel/91.png', null, 1, 91],
            ['-', '/uploads/logos_carrusel/92.png', null, 1, 92],
            ['-', '/uploads/logos_carrusel/93.png', null, 1, 93],
            ['-', '/uploads/logos_carrusel/94.png', null, 1, 94],
            ['-', '/uploads/logos_carrusel/95.png', null, 1, 95],
            ['-', '/uploads/logos_carrusel/96.png', null, 1, 96],
            ['-', '/uploads/logos_carrusel/97.png', null, 1, 97],
            ['-', '/uploads/logos_carrusel/98.png', null, 1, 98],
            ['-', '/uploads/logos_carrusel/99.png', null, 1, 99],
            ['-', '/uploads/logos_carrusel/100.png', null, 1, 100],
            ['-', '/uploads/logos_carrusel/101.png', null, 1, 101],
            ['-', '/uploads/logos_carrusel/102.png', null, 1, 102],
            ['-', '/uploads/logos_carrusel/103.png', null, 1, 103],
            ['-', '/uploads/logos_carrusel/104.png', null, 1, 104],
            ['-', '/uploads/logos_carrusel/105.png', null, 1, 105],
            ['-', '/uploads/logos_carrusel/106.png', null, 1, 106],
            ['-', '/uploads/logos_carrusel/107.png', null, 1, 107],
        ];

        $publicPrefix = rtrim($this->projectDir, '/').'/public';
        $fixtureSourceDir = $publicPrefix.'/fixtures/logos_carrusel';
        $uploadTargetDir = $publicPrefix.'/uploads/logos_carrusel';

        if (!is_dir($uploadTargetDir) && !mkdir($uploadTargetDir, 0775, true) && !is_dir($uploadTargetDir)) {
            echo "[LogoFixtures] No se pudo crear el directorio destino: {$uploadTargetDir}\n";
        }

        foreach ($data as [$name, $path, $url, $active, $order]) {
            $filename = basename($path);
            $source = $fixtureSourceDir.'/'.$filename;
            $target = $publicPrefix.$path;

            $exists = false;

            if (!is_file($source)) {
                echo "[LogoFixtures] Archivo fixture no encontrado, se omite: {$source}\n";
            } elseif (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                echo "[LogoFixtures] No se pudo crear el directorio destino: ".dirname($target)."\n";
            } elseif (!copy($source, $target)) {
                echo "[LogoFixtures] No se pudo copiar logo fixture: {$source} -> {$target}\n";
            } else {
                $exists = is_file($target);
            }

            $logo = (new Logo())
                ->setName($name)
                ->setImagePath($path)
                ->setUrl($url)
                ->setIsActive((bool)$active && $exists)
                ->setSortOrder($order);

            $manager->persist($logo);
        }

        $manager->flush();
    }
}
