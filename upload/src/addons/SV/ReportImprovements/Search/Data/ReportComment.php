<?php

namespace SV\ReportImprovements\Search\Data;

use SV\ReportImprovements\Globals;
use SV\ReportImprovements\Report\ReportSearchFormInterface;
use SV\ReportImprovements\Search\QueryAccessor;
use SV\ReportImprovements\XF\Repository\Report as ReportRepo;
use SV\ReportImprovements\XF\Entity\ReportComment as ReportCommentEntity;
use SV\SearchImprovements\Search\DiscussionTrait;
use SV\SearchImprovements\Search\Features\SearchOrder;
use SV\SearchImprovements\Util\Arr;
use SV\SearchImprovements\XF\Search\Query\Constraints\AndConstraint;
use SV\SearchImprovements\XF\Search\Query\Constraints\OrConstraint;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\ArrayCollection;
use XF\Mvc\Entity\Entity;
use XF\Search\Data\AbstractData;
use XF\Search\IndexRecord;
use XF\Search\MetadataStructure;
use XF\Search\Query\MetadataConstraint;
use XF\Search\Query\Query;
use XF\Search\Query\TableReference;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function assert;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function reset;

/**
 * Class ReportComment
 *
 * @package SV\ReportImprovements\Search\Data
 */
class ReportComment extends AbstractData
{
    protected static $svDiscussionEntity = \XF\Entity\Report::class;
    use DiscussionTrait;

    public const REPORT_TYPE_COMMENT = 0;
    public const REPORT_TYPE_USER_REPORT = 1;
    public const REPORT_TYPE_IS_REPORT = 2;
    public const REPORT_TYPE_WARNING = 3;
    public const REPORT_TYPE_REPLY_BAN = 4;

    /** @var ReportRepo */
    protected $reportRepo;
    /** @var bool */
    protected $isUsingElasticSearch;

    /**
     * @param string            $contentType
     * @param \XF\Search\Search $searcher
     */
    public function __construct($contentType, \XF\Search\Search $searcher)
    {
        parent::__construct($contentType, $searcher);

        $this->reportRepo = \XF::repository('XF:Report');
        $this->isUsingElasticSearch = \SV\SearchImprovements\Globals::repo()->isUsingElasticSearch();
    }

    public function canViewContent(Entity $entity, &$error = null): bool
    {
        assert($entity instanceof ReportCommentEntity);
        $report = $entity->Report;
        return $report && $report->canView();
    }

    /**
     * @param int|int[] $id
     * @param bool $forView
     * @return AbstractCollection|array|Entity|null
     */
    public function getContent($id, $forView = false)
    {
        $reportRepo = \XF::repository('XF:Report');
        if (!($reportRepo instanceof ReportRepo))
        {
            // This function may be invoked when the add-on is disabled, just return nothing
            return is_array($id) ? [] : null;
        }

        $entities = parent::getContent($id, $forView);

        if ($entities instanceof AbstractCollection)
        {
            $reportRepo->svPreloadReportComments($entities);
        }

        return $entities;
    }

    /**
     * @param int $lastId
     * @param int $amount
     * @param bool $forView
     * @return AbstractCollection
     */
    public function getContentInRange($lastId, $amount, $forView = false): AbstractCollection
    {
        $reportRepo = \XF::repository('XF:Report');
        if (!($reportRepo instanceof ReportRepo))
        {
            // This function may be invoked when the add-on is disabled, just return nothing
            return new ArrayCollection([]);
        }

        $contents = parent::getContentInRange($lastId, $amount, $forView);

        $reportRepo->svPreloadReportComments($contents);

        return $contents;
    }

    public function getEntityWith($forView = false): array
    {
        $get = ['Report', 'User', 'WarningLog'];

        if ($forView)
        {
            $visitor = \XF::visitor();
            $get[] = 'Report.Permissions|' . $visitor->permission_combination_id;
        }

        return $get;
    }

    public function getResultDate(Entity $entity): int
    {
        assert($entity instanceof ReportCommentEntity);
        return $entity->comment_date;
    }

    public function getIndexData(Entity $entity): ?IndexRecord
    {
        if (!($entity instanceof ReportCommentEntity))
        {
            // This function may be invoked when the add-on is disabled, just return nothing to index
            return null;
        }

        $warningLog = $entity->WarningLog;
        if ($warningLog !== null)
        {
            return $this->searcher->handler('warning')->getIndexData($warningLog);
        }

        /** @var \SV\ReportImprovements\XF\Entity\Report $report */
        $report = $entity->Report;
        if ($report === null)
        {
            return null;
        }

        return IndexRecord::create('report_comment', $entity->report_comment_id, [
            'title'         => $report->title_string,
            'message'       => $this->getMessage($entity->message),
            'date'          => $entity->comment_date,
            'user_id'       => $entity->user_id,
            'discussion_id' => $entity->report_id,
            'metadata'      => $this->getMetaData($entity),
        ]);
    }

    protected function getMessage(ReportCommentEntity $entity): string
    {
        $message = $entity->message;

        if ($entity->alertComment !== null)
        {
            $message .= "\n".$entity->alertComment;
        }

        return $message;
    }

    protected function getMetaData(ReportCommentEntity $entity): array
    {
        $report = $entity->Report;
        $metaData = [
            'report'              => $entity->report_id,
            'report_state'        => $report->report_state,
            'report_content_type' => $report->content_type,
            'state_change'        => $entity->state_change ?: '',
            'is_report'           => $entity->is_report ? static::REPORT_TYPE_USER_REPORT : static::REPORT_TYPE_COMMENT, // must be an int
            'report_user'         => $report->content_user_id,
        ];

        if ($report->assigner_user_id)
        {
            $metaData['assigner_user'] = $report->assigner_user_id;
        }

        if ($report->assigned_user_id)
        {
            $metaData['assigned_user'] = $report->assigned_user_id;
        }

        $reportHandler = $this->reportRepo->getReportHandler($report->content_type, null);
        if ($reportHandler instanceof ReportSearchFormInterface)
        {
            $reportHandler->populateMetaData($report, $metaData);
        }

        $this->populateDiscussionMetaData($entity, $metaData);

        return $metaData;
    }

    public function getTemplateData(Entity $entity, array $options = []): array
    {
        assert($entity instanceof ReportCommentEntity);
        return [
            'report'        => $entity->Report,
            'reportComment' => $entity,
            'options'       => $options,
        ];
    }

    public function getSearchableContentTypes(): array
    {
        return ['report_comment', 'warning', 'report'];
    }

    public function getGroupByType(): string
    {
        return 'report';
    }

    public function setupMetadataStructure(MetadataStructure $structure)
    {
        $structure->addField('report_user', MetadataStructure::INT);
        // shared with Report
        foreach ($this->reportRepo->getReportHandlers() as $handler)
        {
            if ($handler instanceof ReportSearchFormInterface)
            {
                $handler->setupMetadataStructure($structure);
            }
        }
        $structure->addField('report', MetadataStructure::INT);
        $structure->addField('state_change', MetadataStructure::KEYWORD);
        $structure->addField('report_state', MetadataStructure::KEYWORD);
        $structure->addField('report_content_type', MetadataStructure::KEYWORD);
        $structure->addField('assigned_user', MetadataStructure::INT);
        $structure->addField('assigner_user', MetadataStructure::INT);
        // must be an int, as ElasticSearch single index has this all mapped to the same type
        $structure->addField('is_report', MetadataStructure::INT);

        $this->setupDiscussionMetadataStructure($structure);
    }

    public function applyTypeConstraintsFromInput(Query $query, \XF\Http\Request $request, array &$urlConstraints): void
    {
        $constraints = $request->filter([
            'c.assigned'         => 'str',
            'c.assigner'         => 'str',
            'c.participants'     => 'str',

            'c.replies.lower'       => 'uint',
            'c.replies.upper'       => '?uint,empty-str-to-null',

            'c.report.type'         => 'array-str',
            'c.report.state'        => 'array-str',
            'c.report.contents'     => 'bool',
            'c.report.comments'     => 'bool',
            'c.report.user_reports' => 'bool',
            'c.report.warnings'     => 'bool',
            'c.report.reply_bans'   => 'bool',
        ]);

        $isReport = [];
        $isReportFlags = [
            'c.report.comments' => static::REPORT_TYPE_COMMENT,
            'c.report.user_reports' => static::REPORT_TYPE_USER_REPORT,
            'c.report.contents' => static::REPORT_TYPE_IS_REPORT,
            'c.report.warnings' => static::REPORT_TYPE_WARNING,
            'c.report.reply_bans' => static::REPORT_TYPE_REPLY_BAN,
        ];
        foreach ($isReportFlags as $key => $value)
        {
            if ($constraints[$key] ?? false)
            {
                $isReport[] = $value;
            }
        }

        if (count($isReport) !== 0 && count($isReport) !== count($isReportFlags))
        {
            $query->withMetadata('is_report', $isReport);
        }

        $reportStates = $constraints['c.report.state'];
        assert(is_array($reportStates));
        if (count($reportStates) !== 0 && !in_array('0', $reportStates, true))
        {
            $reportStates = array_unique($reportStates);

            $states = $this->reportRepo->getReportStatePairs();
            $badReportStates = array_filter($reportStates, function(string $state) use(&$states) : bool {
                return !array_key_exists($state, $states);
            });
            if (count($badReportStates) !== 0)
            {
                $query->error('report.state', \XF::phrase('svReportImprov_unknown_report_states', ['values' => implode(', ', $badReportStates)]));
            }
            else
            {
                $query->withMetadata('report_state', $reportStates);
                Arr::setUrlConstraint($urlConstraints, 'c.report.state', $reportStates);
            }
        }
        else
        {
            Arr::unsetUrlConstraint($urlConstraints, 'c.report.state');
        }

        $reportTypes = $constraints['c.report.type'];
        assert(is_array($reportTypes));
        if (count($reportTypes) !== 0)
        {
            // MySQL backend doesn't support composing multiple queries atm
            if (!$this->isUsingElasticSearch && count($reportTypes) > 1)
            {
                $query->error('c.report.type', \XF::phrase('svReportImprov_only_single_report_type_permitted'));
                $reportTypes = [];
            }

            $types = [];
            $handlers = $this->reportRepo->getReportHandlers();
            foreach ($reportTypes as $reportType)
            {
                $handler = $handlers[$reportType] ?? null;
                if ($handler instanceof ReportSearchFormInterface)
                {
                    $oldConstraints = $query->getMetadataConstraints();
                    QueryAccessor::setMetadataConstraints($query, []);

                    $handler->applySearchTypeConstraintsFromInput($query, $request, $urlConstraints);
                    $types[$reportType] = $query->getMetadataConstraints();

                    QueryAccessor::setMetadataConstraints($query, $oldConstraints);
                }
                else
                {
                    $types[$reportType] = [];
                }
            }

            if (count($types) !== 0)
            {
                if (count($types) > 1)
                {
                    $constraints = [];
                    foreach ($types as $contentType => $nestedConstraints)
                    {
                        $constraints[] = new AndConstraint(
                            new MetadataConstraint('report_content_type', $contentType),
                            ...$nestedConstraints
                        );
                    }
                    $query->withMetadata(new OrConstraint(...$constraints));
                }
                else
                {
                    $constraint = reset($types);
                    $query->withMetadata('report_content_type', array_keys($types));
                    if (count($constraint) !== 0)
                    {
                        $query->withMetadata($constraint);
                    }
                }

                Arr::setUrlConstraint($urlConstraints, 'c.report.type', array_keys($types));
            }
            else
            {
                Arr::unsetUrlConstraint($urlConstraints, 'c.report.type');
            }
        }

        $repo = \SV\SearchImprovements\Globals::repo();

        $repo->applyUserConstraint($query, $constraints, $urlConstraints,
            'c.report_user', 'report_user'
        );
        $repo->applyUserConstraint($query, $constraints, $urlConstraints,
            'c.assigned', 'assigned_user'
        );
        $repo->applyUserConstraint($query, $constraints, $urlConstraints,
            'c.assigner', 'assigner_user'
        );
        $repo->applyUserConstraint($query, $constraints, $urlConstraints,
            'c.participants', 'discussion_user'
        );
        $repo->applyRangeConstraint($query, $constraints, $urlConstraints,
            'c.replies.lower', 'c.replies.upper', 'replies',
            [$this->getReportQueryTableReference()]
        );
    }

    /**

     * @param Query $query
     * @param bool  $isOnlyType
     * @return MetadataConstraint[]
     */
    public function getTypePermissionConstraints(Query $query, $isOnlyType): array
    {
        if (!Globals::$reportInAccountPostings)
        {
            return [
                new MetadataConstraint('type', 'report', 'none'),
            ];
        }

        // if a visitor can't view the username of a reporter, just prevent searching for reports by users
        /** @var \SV\ReportImprovements\XF\Entity\User $visitor */
        $visitor = \XF::visitor();
        if (!$visitor->canViewReporter())
        {
            return [
                new MetadataConstraint('is_report', [static::REPORT_TYPE_USER_REPORT], 'none'),
            ];
        }

        return [];
    }

    protected function getReportQueryTableReference(): TableReference
    {
        return new TableReference(
            'report',
            'xf_report',
            'report.report_id = search_index.discussion_id'
        );
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
            'title' => \XF::phrase('svReportImprov_search.reports'),
            'order' => 250,
        ];
    }

    /**
     * @param string $order
     * @return string|SearchOrder|\XF\Search\Query\SqlOrder|null
     */
    public function getTypeOrder($order)
    {
        assert(is_string($order));
        if (array_key_exists($order, $this->getSortOrders()))
        {
            return new SearchOrder([$order, 'date']);
        }

        return parent::getTypeOrder($order);
    }

    protected function getSortOrders(): array
    {
        if (!$this->isUsingElasticSearch)
        {
            return [];
        }

        return [
            'replies' =>  \XF::phrase('svReportImpov_sort_order.comment_count'),
        ];
    }

    public function getSearchFormData(): array
    {
        $form = parent::getSearchFormData();

        $reportRepo = \XF::repository('XF:Report');
        assert($reportRepo instanceof ReportRepo);

        $form['sortOrders'] = $this->getSortOrders();
        $form['reportStates'] = $reportRepo->getReportStatePairs();
        $form['reportTypes'] = $reportRepo->getReportTypes();
        foreach ($form['reportTypes'] as $rec)
        {
            $handler = $rec['handler'];
            if ($handler instanceof ReportSearchFormInterface)
            {
                $form = array_merge($form, $handler->getSearchFormData());
            }
        }

        return $form;
    }
}