<?php

namespace SV\ReportImprovements\Search\Data;

use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use XF\Search\Data\AbstractData;
use XF\Search\IndexRecord;
use XF\Search\MetadataStructure;

/**
 * Class Report
 *
 * @package SV\ReportImprovements\Search\Data
 */
class Report extends AbstractData
{
    /**
     * @param Entity|\SV\ReportImprovements\XF\Entity\Report $entity
     * @param null                                           $error
     * @return bool
     */
    public function canViewContent(Entity $entity, &$error = null)
    {
        return $entity->canView();
    }

    public function getContent($id, $forView = false)
    {
        $entities = parent::getContent($id, $forView);

        if ($entities instanceof AbstractCollection)
        {
            /** @var \SV\ReportImprovements\XF\Repository\Report $reportRepo */
            $reportRepo = \XF::repository('XF:Report');
            $reportRepo->svPreloadReports($entities);
        }


        return $entities;
    }

    public function getContentInRange($lastId, $amount, $forView = false)
    {
        $contents = parent::getContentInRange($lastId, $amount, $forView);
        /** @var \SV\ReportImprovements\XF\Repository\Report $reportRepo */
        $reportRepo = \XF::repository('XF:Report');
        $reportRepo->svPreloadReports($contents);

        return $contents;
    }

    /**
     * @param bool $forView
     * @return array
     */
    public function getEntityWith($forView = false)
    {
        $get = [];

        if ($forView)
        {
            $visitor = \XF::visitor();
            $get[] = 'Permissions|' . $visitor->permission_combination_id;
        }

        return $get;
    }

    /**
     * @param Entity|\SV\ReportImprovements\XF\Entity\Report $entity
     * @return int
     */
    public function getResultDate(Entity $entity)
    {
        return $entity->first_report_date;
    }

    /**
     * @param Entity $entity
     * @return IndexRecord|null
     */
    public function getIndexData(Entity $entity)
    {
        /** @var \SV\ReportImprovements\XF\Entity\Report $entity */
        if (!$entity->Content)
        {
            return null;
        }

        $handler = $entity->getHandler();
        if (!$handler)
        {
            return null;
        }

        try
        {
            $message = $handler->getContentMessage($entity);
        }
        catch (\Exception $e)
        {
            \XF::logException($e, false, 'Error accessing reported content for report ('.$entity->report_id.')');
            $message = '';
        }

        return IndexRecord::create('report', $entity->report_id, [
            'title'         => $entity->title_string,
            'message'       => $message,
            'date'          => $entity->first_report_date,
            'user_id'       => $entity->content_user_id,
            'discussion_id' => $entity->report_id,
            'metadata'      => $this->getMetaData($entity),
        ]);
    }

    /**
     * @param \XF\Entity\Report|\SV\ReportImprovements\XF\Entity\Report $entity
     * @return array
     */
    protected function getMetaData(\XF\Entity\Report $entity)
    {
        $metaData = [
            'report'              => $entity->report_id,
            'report_state'        => $entity->report_state,
            'assigned_user'       => $entity->assigned_user_id,
            'is_report'           => ReportComment::REPORT_TYPE_IS_REPORT,
            'report_content_type' => $entity->content_type,
        ];

        if (isset($entity->content_info['thread_id']))
        {
            $metaData['thread'] = $entity->content_info['thread_id'];
        }

        return $metaData;
    }

    /**
     * @param Entity $entity
     * @param array  $options
     * @return array
     */
    public function getTemplateData(Entity $entity, array $options = [])
    {
        return [
            'report'  => $entity,
            'options' => $options,
        ];
    }

    /**
     * @param MetadataStructure $structure
     */
    public function setupMetadataStructure(MetadataStructure $structure)
    {
        $structure->addField('thread', MetadataStructure::INT);
        $structure->addField('report', MetadataStructure::INT);
        $structure->addField('report_state', MetadataStructure::KEYWORD);
        $structure->addField('report_content_type', MetadataStructure::KEYWORD);
        $structure->addField('assigned_user', MetadataStructure::INT);
        // must be an int, as ElasticSearch single index has this all mapped to the same type
        $structure->addField('is_report', MetadataStructure::INT);
    }
}