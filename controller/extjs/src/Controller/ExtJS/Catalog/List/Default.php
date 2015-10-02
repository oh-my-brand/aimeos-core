<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Metaways Infosystems GmbH, 2011
 * @copyright Aimeos (aimeos.org), 2015
 * @package Controller
 * @subpackage ExtJS
 */


/**
 * ExtJS catalog list controller for admin interfaces.
 *
 * @package Controller
 * @subpackage ExtJS
 */
class Controller_ExtJS_Catalog_List_Default
	extends Controller_ExtJS_Abstract
	implements Controller_ExtJS_Common_Interface
{
	private $manager = null;


	/**
	 * Initializes the catalog list controller.
	 *
	 * @param MShop_Context_Item_Interface $context MShop context object
	 */
	public function __construct( MShop_Context_Item_Interface $context )
	{
		parent::__construct( $context, 'Catalog_List' );
	}


	/**
	 * Deletes an item or a list of items.
	 *
	 * @param stdClass $params Associative list of parameters
	 * @return array Associative list with success value
	 */
	public function deleteItems( stdClass $params )
	{
		$this->checkParams( $params, array( 'site', 'items' ) );
		$this->setLocale( $params->site );

		$refIds = array();
		$ids = (array) $params->items;
		$manager = $this->getManager();

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', $this->getPrefix() . '.id', $ids ) );
		$search->setSlice( 0, count( $ids ) );

		foreach( $manager->searchItems( $search ) as $item ) {
			$refIds[$item->getDomain()][] = $item->getRefId();
		}

		$manager->deleteItems( $ids );

		if( isset( $refIds['product'] ) )
		{
			$this->rebuildIndex( (array) $refIds['product'] );
			$this->clearCache( (array) $refIds['product'], 'product' );
		}

		return array(
			'items' => $params->items,
			'success' => true,
		);
	}


	/**
	 * Creates a new list item or updates an existing one or a list thereof.
	 *
	 * @param stdClass $params Associative array containing the item properties
	 */
	public function saveItems( stdClass $params )
	{
		$this->checkParams( $params, array( 'site', 'items' ) );
		$this->setLocale( $params->site );

		$ids = $refIds = array();
		$manager = $this->getManager();
		$items = ( !is_array( $params->items ) ? array( $params->items ) : $params->items );

		foreach( $items as $entry )
		{
			$item = $manager->createItem();
			$item->fromArray( (array) $this->transformValues( $entry ) );
			$manager->saveItem( $item );

			$refIds[$item->getDomain()][] = $item->getRefId();
			$ids[] = $item->getId();
		}

		if( isset( $refIds['product'] ) )
		{
			$this->rebuildIndex( (array) $refIds['product'] );
			$this->clearCache( (array) $refIds['product'], 'product' );
		}

		return $this->getItems( $ids, $this->getPrefix() );
	}


	/**
	 * Retrieves all items matching the given criteria.
	 *
	 * @param stdClass $params Associative array containing the parameters
	 * @return array List of associative arrays with item properties, total number of items and success property
	 */
	public function searchItems( stdClass $params )
	{
		$this->checkParams( $params, array( 'site' ) );
		$this->setLocale( $params->site );

		$totalList = 0;
		$search = $this->initCriteria( $this->getManager()->createSearch(), $params );
		$result = $this->getManager()->searchItems( $search, array(), $totalList );

		$idLists = array();
		$listItems = array();

		foreach( $result as $item )
		{
			if( ( $domain = $item->getDomain() ) != '' ) {
				$idLists[$domain][] = $item->getRefId();
			}
			$listItems[] = (object) $item->toArray();
		}

		return array(
			'items' => $listItems,
			'total' => $totalList,
			'graph' => $this->getDomainItems( $idLists ),
			'success' => true,
		);
	}


	/**
	 * Returns the manager the controller is using.
	 *
	 * @return MShop_Common_Manager_Interface Manager object
	 */
	protected function getManager()
	{
		if( $this->manager === null ) {
			$this->manager = MShop_Factory::createManager( $this->getContext(), 'catalog/list' );
		}

		return $this->manager;
	}


	/**
	 * Returns the prefix for searching items
	 *
	 * @return string MShop search key prefix
	 */
	protected function getPrefix()
	{
		return 'catalog.list';
	}


	/**
	 * Rebuild the index for the given product IDs
	 *
	 * @param array $prodIds List of product IDs
	 */
	protected function rebuildIndex( array $prodIds )
	{
		$context = $this->getContext();
		$productManager = MShop_Factory::createManager( $context, 'product' );

		$search = $productManager->createSearch();
		$search->setConditions( $search->compare( '==', 'product.id', $prodIds ) );
		$search->setSlice( 0, count( $prodIds ) );

		$indexManager = MShop_Factory::createManager( $context, 'catalog/index' );
		$indexManager->rebuildIndex( $productManager->searchItems( $search ) );
	}


	/**
	 * Transforms ExtJS values to be suitable for storing them
	 *
	 * @param stdClass $entry Entry object from ExtJS
	 * @return stdClass Modified object
	 */
	protected function transformValues( stdClass $entry )
	{
		if( isset( $entry->{'catalog.list.datestart'} ) && $entry->{'catalog.list.datestart'} != '' ) {
			$entry->{'catalog.list.datestart'} = str_replace( 'T', ' ', $entry->{'catalog.list.datestart'} );
		} else {
			$entry->{'catalog.list.datestart'} = null;
		}

		if( isset( $entry->{'catalog.list.dateend'} ) && $entry->{'catalog.list.dateend'} != '' ) {
			$entry->{'catalog.list.dateend'} = str_replace( 'T', ' ', $entry->{'catalog.list.dateend'} );
		} else {
			$entry->{'catalog.list.dateend'} = null;
		}

		if( isset( $entry->{'catalog.list.config'} ) ) {
			$entry->{'catalog.list.config'} = (array) $entry->{'catalog.list.config'};
		}

		return $entry;
	}
}
