<?php

namespace App\Import\SLM;

use App\Import\SLM\Document\Child;
use App\Import\SLM\Document\KPI;
use App\Import\SLM\Document\SLA;
use EMS\CommonBundle\Elasticsearch\Client;
use EMS\CommonBundle\Elasticsearch\Exception\NotFoundException;
use EMS\CommonBundle\Service\ElasticaService;

class CSVImporter
{
    /** @var Client */
    private $client;
    /** @var string */
    private $index;
    /** @var int */
    private $year;
    /** @var resource */
    private $handle;
    /** @var Child[] */
    private $children = [];
    private $missingSLAs = [];

    private ElasticaService $elasticaService;

    public function __construct(Client $client, ImportDocument $importDocument, string $index, ElasticaService $elasticaService)
    {
        $this->client = $client;
        $this->index = $index;
        $this->elasticaService = $elasticaService;
        $this->year = $importDocument->getYear();
        $this->handle = \fopen($importDocument->getCSVFile(), 'r');

        if (false === $this->handle) {
            throw new \Exception('Could not open csv file!');
        }
    }

    public function __destruct()
    {
        \fclose($this->handle);
    }

    /** @return Child[] */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getMissingSLAs(): array
    {
        $missingSLAs = $this->missingSLAs;
        \ksort($missingSLAs);

        return $missingSLAs;
    }

    /**
     * @return KPI[]
     */
    public function getKPIs()
    {
        foreach ($this->read() as $data) {
            if ('J' !== $data['valid_check'] || null == $data['kpi_type'] || '' == \trim($data['kpi_id'])) {
                continue;
            }

            if (!$this->existsSLA($data['sla_id'])) {
                $this->missingSLAs[$data['sla_id']] = [$data['sla_id'], $data['service'], $data['customer']];
                continue;
            }

            yield $this->createKPI($data);
        }
    }

    private function createKPI(array $data): KPI
    {
        $kpi = new KPI($data, $this->year);

        foreach ($kpi->getChildren() as $child) {
            if (!\array_key_exists($child->getEMSId(), $this->children)) {
                $this->children[$child->getType()][$child->getEMSId()] = $child;
            }
        }

        return $kpi;
    }

    public function existsSLA(int $id): bool
    {
        try {
            $this->elasticaService->getDocument($this->index, null, SLA::createID($id));

            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }

    private function read(): \Generator
    {
        $keys = [];
        $row = 0;

        while (($rawData = \fgetcsv($this->handle, 10000, ';')) !== false) {
            if (++$row < 11) {
                continue;
            }
            if (11 === $row) {
                $keys = \array_map(function ($heading) {
                    return \trim($heading);
                }, $rawData);
                continue;
            }

            yield \array_combine($keys, \array_map('trim', \array_map('utf8_encode', $rawData)));
        }
    }
}
