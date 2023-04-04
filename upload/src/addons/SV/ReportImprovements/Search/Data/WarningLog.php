<?php

namespace SV\ReportImprovements\Search\Data;

use SV\ReportImprovements\Entity\WarningLog as WarningLogEntity;
use SV\ReportImprovements\Enums\ReportType;
use SV\ReportImprovements\Enums\WarningType;
use SV\ReportImprovements\Report\ReportSearchFormInterface;
use SV\SearchImprovements\Search\DiscussionTrait;
use SV\SearchImprovements\Util\Arr;
use SV\SearchImprovements\XF\Search\Query\Constraints\ExistsConstraint;
use SV\SearchImprovements\XF\Search\Query\Constraints\NotConstraint;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Entity;
use XF\Search\Data\AbstractData;
use XF\Search\IndexRecord;
use XF\Search\MetadataStructure;
use XF\Search\Query\MetadataConstraint;
use XF\Search\Query\Query;
use XF\Search\Query\TableReference;
use function array_merge_recursive;
use function assert;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function reset;

/**
 * Class Warning
 *
 * @package SV\ReportImprovements\Search\Data
 */
class WarningLog extends AbstractData
{
    protected static $svDiscussionEntity = \XF\Entity\Report::class;
    use DiscussionTrait;
    use SearchDataSetupTrait;

    public function canViewContent(Entity $entity, &$error = null): bool
    {
        assert($entity instanceof WarningLogEntity);

        return $entity->canView();
    }

    /**
     * @param int|int[] $id
     * @param bool $forView
     * @return AbstractCollection|array|Entity|null
     */
    public function getContent($id, $forView = false)
    {
        if (!$this->isAddonFullyActive)
        {
            // This function may be invoked when the add-on is disabled, just return nothing
            return is_array($id) ? [] : null;
        }

        $entities = parent::getContent($id, $forView);

        if ($entities instanceof AbstractCollection)
        {
            $this->reportRepo->svPreloadReportComments($entities);
        }

        return $entities;
    }

    /**
     * @param int $lastId
     * @param int $amount
     * @param bool $forView
     * @return \XF\Mvc\Entity\AbstractCollection
     */
    public function getContentInRange($lastId, $amount, $forView = false): \XF\Mvc\Entity\AbstractCollection
    {
        if (!$this->isAddonFullyActive)
        {
            // This function may be invoked when the add-on is disabled, just return nothing
            return new ArrayCollection([]);
        }

        $entityId = \XF::app()->getContentTypeFieldValue($this->contentType, 'entity');
        if (!$entityId)
        {
            throw new \LogicException("Content type {$this->contentType} must define an 'entity' value");
        }

        $em = \XF::em();
        $key = $em->getEntityStructure($entityId)->primaryKey;
        if (is_array($key))
        {
            if (count($key) > 1)
            {
                throw new \LogicException("Entity $entityId must only have a single primary key");
            }
            $key = reset($key);
        }

        $finder = $em->getFinder($entityId)
                     ->where($key, '>', $lastId)
                     ->with('ReportComment', true)
            //->where('ReportComment.warning_log_id', '<>', null)
                     ->order($key)
                     ->with($this->getEntityWith($forView));

        $contents = $finder->fetch($amount);

        $this->reportRepo->svPreloadReportComments($contents);

        return $contents;
    }

    public function getEntityWith($forView = false): array
    {
        $get = ['ReportComment.Report', 'ReportComment.User'];

        if ($forView)
        {
            $visitor = \XF::visitor();
            $get[] = 'ReportComment.Report.Permissions|' . $visitor->permission_combination_id;
        }

        return $get;
    }

    public function getResultDate(Entity $entity): int
    {
        assert($entity instanceof WarningLogEntity);
        return $entity->ReportComment->comment_date;
    }

    public function getIndexData(Entity $entity): ?IndexRecord
    {
        if (!$this->isAddonFullyActive)
        {
            // This function may be invoked when the add-on is disabled, just return nothing to index
            return null;
        }

        assert($entity instanceof WarningLogEntity);
        $reportComment = $entity->ReportComment;
        if ($reportComment === null || $reportComment->Report === null)
        {
            return null;
        }

        return IndexRecord::create('warning_log', $entity->warning_log_id, [
            'title'         => $entity->title,
            'message'       => $this->getMessage($entity),
            'date'          => $reportComment->comment_date,
            'user_id'       => $reportComment->user_id,
            'discussion_id' => $reportComment->report_id,
            'metadata'      => $this->getMetaData($entity),
        ]);
    }

    protected function getMessage(WarningLogEntity $entity): string
    {
        $message = $this->searchRepo->getEntityToMessage($entity);
        $message .= $this->searchRepo->getEntityToMessage($entity->ReportComment);

        return $message;
    }

    protected function getMetaData(WarningLogEntity $warningLog): array
    {
        $reportComment = $warningLog->ReportComment;
        $report = $reportComment->Report;
        $metaData = $this->reportRepo->getReportSearchMetaData($report);
        $this->populateDiscussionMetaData($report, $metaData);
        $metaData += [
            // shared with ReportComment::getMetaData
            'state_change' => $reportComment->state_change ?: '',
            'report_type'  => $reportComment->getReportType(),
            // distinct for WarningLog
            'warning_type' => $warningLog->operation_type,
            'issuer_user'  => $warningLog->warning_user_id,
        ];

        if ($warningLog->expiry_date)
        {
            $metaData['expiry_date'] = $warningLog->expiry_date;
        }

        if ($warningLog->warning_id)
        {
            $metaData['points'] = $warningLog->points;
        }

        if ($warningLog->reply_ban_thread_id)
        {
            $metaData['thread_reply_ban'] = $warningLog->reply_ban_thread_id;
        }

        if ($warningLog->reply_ban_post_id)
        {
            $metaData['post_reply_ban'] = $warningLog->reply_ban_post_id;
        }

        if ($warningLog->is_latest_version)
        {
            $metaData['is_latest_version'] = true;
        }

        return $metaData;
    }

    public function getTemplateData(Entity $entity, array $options = []): array
    {
        assert($entity instanceof WarningLogEntity);
        return [
            'warningLog'    => $entity,
            'reportComment' => $entity->ReportComment,
            'report'        => $entity->ReportComment->Report,
            'options'       => $options,
        ];
    }

    public function getSearchableContentTypes(): array
    {
        return ['warning_log'];
    }

    public function getGroupByType(): string
    {
        return 'report';
    }

    public function setupMetadataStructure(MetadataStructure $structure): void
    {
        $this->reportRepo->setupMetadataStructureForReport($structure);
        $this->setupDiscussionMetadataStructure($structure);

        // warning bits
        $structure->addField('warning_type', MetadataStructure::KEYWORD);
        $structure->addField('points', MetadataStructure::INT);
        $structure->addField('expiry_date', MetadataStructure::INT);
        $structure->addField('issuer_user', MetadataStructure::INT);
        $structure->addField('thread_reply_ban', MetadataStructure::INT);
        $structure->addField('post_reply_ban', MetadataStructure::INT);
        $structure->addField('is_latest_version', MetadataStructure::BOOL);
    }

    public function getSearchFormTab(): ?array
    {
        $visitor = \XF::visitor();
        if (!($visitor instanceof \SV\ReportImprovements\XF\Entity\User))
        {
            // This function may be invoked when the add-on is disabled, just return nothing to show
            return null;
        }

        if (!$visitor->canReportSearch())
        {
            return null;
        }

        return [
            'title' => \XF::phrase('svReportImprov_search.warnings'),
            'order' => 255,
        ];
    }

    protected function getSvSortOrders(): array
    {
        if (!$this->isUsingElasticSearch)
        {
            return [];
        }

        return [
            'expiry_date' => \XF::phrase('svSearchOrder.expiry_date'),
            'points' =>  \XF::phrase('svSearchOrder.points'),
        ];
    }

    public function getSearchFormData(): array
    {
        assert($this->isAddonFullyActive);
        $form = parent::getSearchFormData();

        $handlers = $this->reportRepo->getReportHandlers();
        foreach ($handlers as $handler)
        {
            if ($handler instanceof ReportSearchFormInterface)
            {
                $form = array_merge_recursive($form, $handler->getSearchFormData());
            }
        }
        $form['sortOrders'] = $this->getSvSortOrders();
        $form['reportStates'] = $this->reportRepo->getReportStatePairs();
        $form['reportHandlers'] = $handlers;
        $form['reportTypes'] = ReportType::getPairs();
        $form['warningTypes'] = WarningType::getPairs();

        return $form;
    }

    /**
     * @param Query            $query
     * @param \XF\Http\Request $request
     * @param array            $urlConstraints
     */
    public function applyTypeConstraintsFromInput(Query $query, \XF\Http\Request $request, array &$urlConstraints): void
    {
        $this->searcher->handler('report_comment')->applyTypeConstraintsFromInput($query, $request, $urlConstraints);

        $constraints = $request->filter([
            'c.warning.latest'       => '?bool',
            'c.warning.type'         => 'array-str',
            'c.warning.mod'          => 'str',
            'c.warning.points.lower' => 'uint',
            'c.warning.points.upper' => '?uint,empty-str-to-null',
            'c.warning.expiry_type'  => 'str',
            'c.warning.expiry.lower' => 'datetime',
            'c.warning.expiry.upper' => '?datetime,empty-str-to-null',
        ]);

        $repo = \SV\SearchImprovements\Globals::repo();

        if ($constraints['c.warning.latest'] !== null)
        {
            if ($constraints['c.warning.latest'])
            {
                $query->withMetadata(new ExistsConstraint('is_latest_version'));
            }
            else
            {
                $query->withMetadata(new NotConstraint(new ExistsConstraint('is_latest_version')));
            }
        }
        else
        {
            Arr::unsetUrlConstraint($urlConstraints, 'c.warning.latest');
        }

        $rawWarningTypes = $constraints['c.warning.type'];
        assert(is_array($rawWarningTypes));
        if (count($rawWarningTypes) !== 0)
        {
            $warningTypes = [];
            $types = WarningType::get();
            foreach ($rawWarningTypes as $value)
            {
                if (in_array($value, $types, true))
                {
                    $warningTypes[] = $value;
                }
            }
            if (count($warningTypes) !== 0  && count($warningTypes) < count($types))
            {
                $query->withMetadata('warning_type', $warningTypes);
                Arr::setUrlConstraint($urlConstraints, 'c.warning.type', $warningTypes);
            }
            else
            {
                Arr::unsetUrlConstraint($urlConstraints, 'c.warning.type');
            }
        }
        else
        {
            Arr::unsetUrlConstraint($urlConstraints, 'c.warning.type');
        }

        $repo->applyUserConstraint($query, $constraints, $urlConstraints,
            'c.warning.mod', 'issuer_user'
        );
        $repo->applyRangeConstraint($query, $constraints, $urlConstraints,
            'c.warning.points.lower', 'c.warning.points.upper', 'points',
            $this->getWarningLogQueryTableReference(), 'warning_log'
        );

        $expired = $constraints['c.warning.expiry_type'];
        assert(is_string($expired));
        switch ($expired)
        {
            case 'active':
                $constraints['c.warning.expiry.lower'] = \XF::$time;
                $constraints['c.warning.expiry.upper'] = null;
                break;
            case 'active_never_expires':
                $query->withMetadata(new NotConstraint(new ExistsConstraint('expiry_date')));
                // disable these options
                $constraints['c.warning.expiry.lower'] = 0;
                $constraints['c.warning.expiry.upper'] = null;
                break;
            case 'expired':
                $constraints['c.warning.expiry.lower'] = 0;
                $constraints['c.warning.expiry.upper'] = \XF::$time;
                break;
            case 'date':
                break;
            case '':
            default:
                $constraints['c.warning.expiry.lower'] = 0;
                $constraints['c.warning.expiry.upper'] = null;
                Arr::unsetUrlConstraint($urlConstraints, 'c.warning.expiry_type');
        }
        $repo->applyDateRangeConstraint($query, $constraints, $urlConstraints,
            'c.warning.expiry.lower', 'c.warning.expiry.upper', 'expiry_date',
            $this->getWarningLogQueryTableReference(), 'warning_log'
        );
    }

    /**
     * @param Query $query
     * @param bool  $isOnlyType
     * @return MetadataConstraint[]
     */
    public function getTypePermissionConstraints(Query $query, $isOnlyType): array
    {
        return $this->searcher->handler('report_comment')->getTypePermissionConstraints($query, $isOnlyType);
    }

    /**
     * @return TableReference[]
     */
    protected function getWarningLogQueryTableReference(): array
    {
        return [
            new TableReference(
                'report_comment',
                'xf_report_comment',
                'report_comment.report_comment_id = search_index.content_id'
            ),
            new TableReference(
                'warning_log',
                'xf_sv_warning_log',
                'warning_log.warning_log_id = report_comment.warning_log_id'
            ),
        ];
    }
}