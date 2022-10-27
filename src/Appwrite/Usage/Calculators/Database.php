<?php

namespace Appwrite\Usage\Calculators;

use Exception;
use Appwrite\Usage\Calculator;
use DateTime;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\Registry\Registry;

class Database extends Calculator
{
    protected Registry $register;
    protected array $periods = [
        [
            'key' => '30m',
            'multiplier' => 1800,
        ],
        [
            'key' => '1d',
            'multiplier' => 86400,
        ],
    ];

    public function __construct(UtopiaDatabase $database, callable $getProjectDB, Registry $register, callable $errorHandler = null)
    {
        $this->register = $register;
        $this->database = $database;
        $this->getProjectDB = $getProjectDB;
        $this->errorHandler = $errorHandler;
    }

    /**
     * Create Per Period Metric
     *
     * Create given metric for each defined period
     *
     * @param UtopiaDatabase $database
     * @param Document $project
     * @param string $metric
     * @param int $value
     * @param bool $monthly
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    protected function createPerPeriodMetric(UtopiaDatabase $database, string $projectId, string $metric, int $value, bool $monthly = false): void
    {
        foreach ($this->periods as $options) {
            $period = $options['key'];
            $date = new \DateTime();
            if ($period === '30m') {
                $minutes = $date->format('i') >= '30' ? "30" : "00";
                $time = $date->format('Y-m-d H:' . $minutes . ':00');
            } elseif ($period === '1d') {
                $time = $date->format('Y-m-d 00:00:00');
            } else {
                throw new Exception("Period type not found", 500);
            }
            $this->createOrUpdateMetric($database, $projectId, $metric, $period, $time, $value);
        }

        // Required for billing
        if ($monthly) {
            $time = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-01\T00:00:00.000'))->format(DateTime::RFC3339);
            $this->createOrUpdateMetric($database, $projectId, $metric, '1mo', $time, $value);
        }
    }

    /**
     * Create or Update Metric
     *
     * Create or update each metric in the stats collection for the given project
     *
     * @param UtopiaDatabase $database
     * @param String $projectId
     * @param string $metric
     * @param string $period
     * @param string $time
     * @param int $value
     *
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    protected function createOrUpdateMetric(UtopiaDatabase $database, string $projectId, string $metric, string $period, string $time, int $value): void
    {
        $id = \md5("{$time}_{$period}_{$metric}");

        try {
            $document = $database->getDocument('stats', $id);
            if ($document->isEmpty()) {
                $database->createDocument('stats', new Document([
                    '$id' => $id,
                    'period' => $period,
                    'time' => $time,
                    'metric' => $metric,
                    'value' => $value,
                    'type' => 2, // these are cumulative metrics
                ]));
            } else {
                $database->updateDocument(
                    'stats',
                    $document->getId(),
                    $document->setAttribute('value', $value)
                );
            }
        } catch (\Exception$e) { // if projects are deleted this might fail
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "sync_project_{$projectId}_metric_{$metric}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Foreach Document
     *
     * Call provided callback for each document in the collection
     *
     * @param Document $project
     * @param string $collection
     * @param array $queries
     * @param callable $callback
     *
     * @return void
     * @throws Exception
     */
    protected function foreachDocument(Document $project, string $collection, array $queries, callable $callback): void
    {
        $limit = 50;
        $results = [];
        $sum = $limit;
        $latestDocument = null;
        $database = $project->getId() == 'console' ? $this->database : call_user_func($this->getProjectDB, $project);

        while ($sum === $limit) {
            try {
                $paginationQueries = [Query::limit($limit)];
                if ($latestDocument !== null) {
                    $paginationQueries[] =  Query::cursorAfter($latestDocument);
                }
                $results = $database->find($collection, \array_merge($paginationQueries, $queries));
            } catch (\Exception $e) {
                if (is_callable($this->errorHandler)) {
                    call_user_func($this->errorHandler, $e, "fetch_documents_project_{$project->getId()}_collection_{$collection}");
                    return;
                } else {
                    throw $e;
                }
            }
            if (empty($results)) {
                return;
            }

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
            $latestDocument = $results[array_key_last($results)];
        }
    }

    /**
     * Sum
     *
     * Calculate sum of an attribute of documents in collection
     *
     * @param UtopiaDatabase $database
     * @param string $projectId
     * @param string $collection
     * @param string $attribute
     * @param string|null $metric
     * @param int $multiplier
     * @return int
     * @throws Exception
     */
    private function sum(UtopiaDatabase $database, string $projectId, string $collection, string $attribute, string $metric = null, int $multiplier = 1): int
    {

        try {
            $sum = $database->sum($collection, $attribute);
            $sum = (int) ($sum * $multiplier);

            if (!is_null($metric)) {
                $this->createPerPeriodMetric($database, $projectId, $metric, $sum);
            }
            return $sum;
        } catch (Exception $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "fetch_sum_project_{$projectId}_collection_{$collection}");
            } else {
                throw $e;
            }
        }
        return 0;
    }

    /**
     * Count
     *
     * Count number of documents in collection
     *
     * @param UtopiaDatabase $database
     * @param string $projectId
     * @param string $collection
     * @param ?string $metric
     *
     * @return int
     * @throws Exception
     */
    private function count(UtopiaDatabase $database, string $projectId, string $collection, ?string $metric = null): int
    {
        try {
            $count = $database->count($collection);
            if (!is_null($metric)) {
                $this->createPerPeriodMetric($database, $projectId, (string) $metric, $count);
            }
            return $count;
        } catch (Exception $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "fetch_count_project_{$projectId}_collection_{$collection}");
            } else {
                throw $e;
            }
        }
        return 0;
    }

    /**
     * Deployments Total
     *
     * Total sum of storage used by deployments
     *
     * @param UtopiaDatabase $database
     * @param string $projectId
     *
     * @return int
     * @throws Exception
     */
    private function deploymentsTotal(UtopiaDatabase $database, string $projectId): int
    {
        return $this->sum($database, $projectId, 'deployments', 'size', 'deployments.$all.storage.size');
    }

    /**
     * Users Stats
     *
     * Metric: users.count
     *
     * @param UtopiaDatabase $database
     * @param string $projectId
     *
     * @return void
     * @throws Exception
     */
    private function usersStats(UtopiaDatabase $database, string $projectId): void
    {
        $this->count($database, $projectId, 'users', 'users.$all.count.total');
    }

    /**
     * Storage Stats
     *
     * Metrics: buckets.$all.count.total, files.$all.count.total, files.bucketId,count.total,
     * files.$all.storage.size, files.bucketId.storage.size, project.$all.storage.size
     *
     * @param UtopiaDatabase $database
     * @param Document $project
     *
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    private function storageStats(UtopiaDatabase $database, Document $project): void
    {
        $projectFilesTotal = 0;
        $projectFilesCount = 0;

        $metric = 'buckets.$all.count.total';
        $this->count($database, $project->getId(), 'buckets', $metric);

        $this->foreachDocument($project, 'buckets', [], function ($bucket) use (&$projectFilesCount, &$projectFilesTotal, $project, $database) {
            $metric = "files.{$bucket->getId()}.count.total";
            $count = $this->count($database, $project->getId(), 'bucket_' . $bucket->getInternalId(), $metric);
            $projectFilesCount += $count;

            $metric = "files.{$bucket->getId()}.storage.size";
            $sum = $this->sum($database, $project->getId(), 'bucket_' . $bucket->getInternalId(), 'sizeOriginal', $metric);
            $projectFilesTotal += $sum;
        });

        $this->createPerPeriodMetric($database, $project->getId(), 'files.$all.count.total', $projectFilesCount);
        $this->createPerPeriodMetric($database, $project->getId(), 'files.$all.storage.size', $projectFilesTotal);

        $deploymentsTotal = $this->deploymentsTotal($database, $project->getId());
        $this->createPerPeriodMetric($database, $project->getId(), 'project.$all.storage.size', $projectFilesTotal + $deploymentsTotal);
    }

    /**
     * Database Stats
     *
     * Collect all database stats
     * Metrics: databases.$all.count.total, collections.$all.count.total, collections.databaseId.count.total,
     * documents.$all.count.all, documents.databaseId.count.total, documents.databaseId/collectionId.count.total
     *
     * @param UtopiaDatabase $database
     * @param Document $project
     *
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    private function databaseStats(UtopiaDatabase $database, Document $project): void
    {
        $projectDocumentsCount = 0;
        $projectCollectionsCount = 0;

        $this->count($database, $project->getId(), 'databases', 'databases.$all.count.total');

        $this->foreachDocument($project, 'databases', [], function ($db) use (&$projectDocumentsCount, &$projectCollectionsCount, $project, $database) {
            $metric = "collections.{$db->getId()}.count.total";
            $count = $this->count($database, $project->getId(), 'database_' . $db->getInternalId(), $metric);
            $projectCollectionsCount += $count;
            $databaseDocumentsCount = 0;

            $this->foreachDocument($project, 'database_' . $db->getInternalId(), [], function ($collection) use (&$projectDocumentsCount, &$databaseDocumentsCount, $project, $db, $database) {
                $metric = "documents.{$db->getId()}/{$collection->getId()}.count.total";

                $count = $this->count($database, $project->getId(), 'database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId(), $metric);
                $projectDocumentsCount += $count;
                $databaseDocumentsCount += $count;
            });

            $this->createPerPeriodMetric($database, $project->getId(), "documents.{$db->getId()}.count.total", $databaseDocumentsCount);
        });

        $this->createPerPeriodMetric($database, $project->getId(), 'collections.$all.count.total', $projectCollectionsCount);
        $this->createPerPeriodMetric($database, $project->getId(), 'documents.$all.count.total', $projectDocumentsCount);
    }

    /**
     * Collect Stats
     *
     * Collect all database related stats
     *
     * @return void
     * @throws Exception
     */
    public function collect(): void
    {
        $this->foreachDocument(new Document(['$id' => 'console']), 'projects', [], function (Document $project) {
            $database = call_user_func($this->getProjectDB, $project);
            $this->usersStats($database, $project->getId());
            $this->databaseStats($database, $project);
            $this->storageStats($database, $project);
            $this->register->get('pools')->reclaim();
        });
    }
}
