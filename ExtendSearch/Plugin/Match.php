<?php
/**
 * @package     Solutionexcel_ExtendSearch
 * @author      SolutionExcel - https://www.solutionexcel.com/ - info@solutionexcel.com
 * @copyright   Copyright © 2018 SolutionExcel. All rights reserved.
 * @license     https://opensource.org/licenses/AFL-3.0  Academic Free License 3.0 | Open Source Initiative
**/

namespace Solutionexcel\ExtendSearch\Plugin;

use Magento\Framework\DB\Helper\Mysql\Fulltext;
use Magento\Framework\DB\Select;
use Magento\Framework\Search\Adapter\Mysql\Field\FieldInterface;
use Magento\Framework\Search\Adapter\Mysql\Field\ResolverInterface;
use Magento\Framework\Search\Adapter\Mysql\ScoreBuilder;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\Search\Request\QueryInterface as RequestQueryInterface;
use Magento\Framework\Search\Adapter\Preprocessor\PreprocessorInterface;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext as FulltextResource;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as AttributeCollection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Solutionexcel\ExtendSearch\Helper\Data as ExtendSearch;

class Match extends \Magento\Framework\Search\Adapter\Mysql\Query\Builder\Match {

    const SPECIAL_CHARACTERS = '-+~/\\<>\'":*$#@()!,.?`=%&^';
    const MINIMAL_CHARACTER_LENGTH = 3;

    /**
     * @var string[]
     */
    private $replaceSymbols = [];

    /**
     * @var ResolverInterface
     */
    private $resolver;

    /**
     * @var Fulltext
     */
    private $fulltextHelper;

    /**
     * @var string
     */
    private $fulltextSearchMode;

    /**
     * @var PreprocessorInterface[]
     * @since 100.1.0
     */
    protected $preprocessors;

    /**
     * @var AttributeCollection
     */
    private $attributeCollection;

    /**
     * @var FulltextResource
     */
    private $fulltextResource;
	/**
     * @var StoreManager
     */
    private $storeManager;
	/**
     * @var _Resource
     */
    private $_resource;

    /**
     * @var \Solutionexcel\ExtendSearch\Helper\Data
     */
    protected $dataHelper;
    
    /**
     * @param ResolverInterface $resolver
     * @param Fulltext $fulltextHelper
     * @param string $fulltextSearchMode
     * @param PreprocessorInterface[] $preprocessors
     */
    public function __construct(
		ExtendSearch $dataHelper,
		AttributeCollection $attributeCollection, 
		FulltextResource $fulltextResource, 
		StoreManagerInterface $storeManage, 
		ResourceConnection $resource, 
		ResolverInterface $resolver, 
		Fulltext $fulltextHelper, 
		$fulltextSearchMode = Fulltext::FULLTEXT_MODE_BOOLEAN, 
		array $preprocessors = []
    ) {
        
        $this->resolver = $resolver;
        $this->replaceSymbols = str_split(self::SPECIAL_CHARACTERS, 1);
        $this->fulltextHelper = $fulltextHelper;
        $this->fulltextSearchMode = $fulltextSearchMode;
        $this->preprocessors = $preprocessors;
        $this->attributeCollection = $attributeCollection;
        $this->fulltextResource = $fulltextResource;
        $this->storeManager = $storeManage;
        $this->_resource = $resource;
        $this->dataHelper = $dataHelper;
        parent::__construct($resolver,$fulltextHelper, $fulltextSearchMode, $preprocessors);
    }

    public function aroundBuild($matcher, $build, $scoreBuilder, $select, $query, $conditionType) 
	{
        /** @var $query \Magento\Framework\Search\Request\Query\Match */
        $resultSku = 0;
        $resultName = 0;
		
		$queryValue = $this->prepareQuery($query->getValue(), $conditionType);
        $fieldList = [];
        foreach ($query->getMatches() as $match) {
            $fieldList[] = $match['field'];
        }
        
		$resolvedFieldList = $this->resolver->resolve($fieldList);
        $fieldIds = [];
        $columns = [];
        foreach ($resolvedFieldList as $field) {
            if ($field->getType() === FieldInterface::TYPE_FULLTEXT && $field->getAttributeId()) {
                $fieldIds[] = $field->getAttributeId();
            }

            $column = $field->getColumn();
            $columns[$column] = $column;
        }
        if ($this->dataHelper->allowExtension()) {
			$attribute = $this->attributeCollection->getItemByColumnValue('attribute_code', 'sku');
			$attributeId = $attribute ? $attribute->getId() : 0;
			if ($attributeId != 0) {
				$connection = $this->_resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
				$mainFullTextScope = $this->fulltextResource->getMainTable() . "_scope" . $this->storeManager->getStore()->getId();
				$tblFullTextScope = $connection->getTableName($mainFullTextScope);
				$resultSku = $connection->fetchOne("SELECT count(entity_id) FROM `" . $tblFullTextScope . "` WHERE attribute_id=" . $attributeId . " And data_index LIKE '%" . $query->getValue() . "%'");
			}
			
			if ($resultSku == 0) {
				$attribute = $this->attributeCollection->getItemByColumnValue('attribute_code', 'name');
				$attributeId = $attribute ? $attribute->getId() : 0;
				if ($attributeId != 0) {
					$connection = $this->_resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
					$mainFullTextScope = $this->fulltextResource->getMainTable() . "_scope" . $this->storeManager->getStore()->getId();
					$tblFullTextScope = $connection->getTableName($mainFullTextScope);
					$resultName = $connection->fetchOne("SELECT count(entity_id) FROM `" . $tblFullTextScope . "` WHERE attribute_id=" . $attributeId . " And data_index LIKE '%" . $query->getValue() . "%'");
				}
			}
        }
		
		if (($resultSku == 1) || ($resultName == 1)) {
            $matchQuery = "data_index LIKE '%" . $query->getValue() . "%'";
        }else{
            $matchQuery = $this->fulltextHelper->getMatchQuery(
                    $columns, $queryValue, $this->fulltextSearchMode
            );     
        }
		
		$scoreBuilder->addCondition($matchQuery, true);

        if ($fieldIds) {
            $matchQuery = sprintf('(%s AND search_index.attribute_id IN (%s))', $matchQuery, implode(',', $fieldIds));
        }

        $select->where($matchQuery);
        return $select;
    }
}