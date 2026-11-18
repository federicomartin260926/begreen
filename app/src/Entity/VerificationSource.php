<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'verification_source')]
class VerificationSource
{
    use TimestampableTrait;

    /** @var array<string, string> */
    public const CANONICAL_SOURCES = [
        'evi-acta' => 'Acta', 'evi-acuerdo' => 'Acuerdo', 'evi-albaran' => 'Albarán',
        'evi-analitica' => 'Analítica', 'evi-animatic' => 'Animatic', 'evi-aprobacion' => 'Aprobación',
        'evi-auditoria' => 'Auditoría', 'evi-autorizacion' => 'Autorización', 'evi-billete' => 'Billete',
        'evi-calculo' => 'Cálculo', 'evi-calendario' => 'Calendario', 'evi-captura' => 'Captura',
        'evi-carta' => 'Carta', 'evi-cartel' => 'Cartel', 'evi-certificacion' => 'Certificación',
        'evi-certificado' => 'Certificado', 'evi-checklist' => 'Checklist', 'evi-comparativa' => 'Comparativa',
        'evi-contrato' => 'Contrato', 'evi-convocatoria' => 'Convocatoria', 'evi-cuestionario' => 'Cuestionario',
        'evi-declaracion' => 'Declaración', 'evi-dosier' => 'Dosier', 'evi-email' => 'Email',
        'evi-encuesta' => 'Encuesta', 'evi-estimacion' => 'Estimación', 'evi-etiqueta' => 'Etiqueta',
        'evi-evaluacion' => 'Evaluación', 'evi-exportacion' => 'Exportación', 'evi-extracto' => 'Extracto',
        'evi-factura' => 'Factura', 'evi-ficha' => 'Ficha', 'evi-fichaje' => 'Fichaje',
        'evi-foto' => 'Foto', 'evi-grabacion' => 'Grabación', 'evi-guia' => 'Guía',
        'evi-guion' => 'Guion', 'evi-historial' => 'Historial', 'evi-informe' => 'Informe',
        'evi-inspeccion' => 'Inspección', 'evi-inventario' => 'Inventario', 'evi-justificante' => 'Justificante',
        'evi-listado' => 'Listado', 'evi-mapa' => 'Mapa', 'evi-matriz' => 'Matriz',
        'evi-medicion' => 'Medición', 'evi-memoria' => 'Memoria', 'evi-montaje' => 'Montaje',
        'evi-muestra' => 'Muestra', 'evi-nombramiento' => 'Nombramiento', 'evi-nomina' => 'Nómina',
        'evi-orden' => 'Orden', 'evi-parte' => 'Parte', 'evi-plan' => 'Plan',
        'evi-plano' => 'Plano', 'evi-politica' => 'Política', 'evi-presupuesto' => 'Presupuesto',
        'evi-protocolo' => 'Protocolo', 'evi-registro' => 'Registro', 'evi-reserva' => 'Reserva',
        'evi-respuesta' => 'Respuesta', 'evi-solicitud' => 'Solicitud', 'evi-storyboard' => 'Storyboard',
        'evi-video' => 'Vídeo',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
