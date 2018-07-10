<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2015-2018
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Adds catalog and product performance records
 */
class CatalogAddPerfData extends \Aimeos\MW\Setup\Task\Base
{
	private $numCatLevels;
	private $numCategories;
	private $numCatProducts;
	private $numProdVariants;
	private $attributes = [];
	private $typeIds = [];


	/**
	 * Initializes the task object.
	 *
	 * @param \Aimeos\MW\Setup\DBSchema\Iface $schema Database schema object
	 * @param \Aimeos\MW\DB\Connection\Iface $conn Database connection
	 * @param mixed $additional Additionally provided information for the setup tasks if required
	 * @param array $paths List of paths of the setup tasks ordered by dependencies
	 */
	public function __construct( \Aimeos\MW\Setup\DBSchema\Iface $schema, \Aimeos\MW\DB\Connection\Iface $conn,
		$additional = null, array $paths = [] )
	{
		$iface = '\\Aimeos\\MShop\\Context\\Item\\Iface';
		if( !( $additional instanceof $iface ) ) {
			throw new \Aimeos\MW\Setup\Exception( sprintf( 'Additionally provided object is not of type "%1$s"', $iface ) );
		}

		parent::__construct( $schema, $conn, $additional, $paths );
	}


	public function __clone()
	{
		$this->additional = clone $this->additional;
		$this->additional->__sleep();
	}


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return ['MShopAddCodeDataUnitperf', 'AttributeAddPerfData', 'LocaleAddPerfData'];
	}


	/**
	 * Returns the list of task names which depends on this task.
	 *
	 * @return string[] List of task names
	 */
	public function getPostDependencies()
	{
		return ['CatalogRebuildPerfIndex'];
	}


	/**
	 * Insert catalog nodes and product/catalog relations.
	 */
	public function migrate()
	{
		$this->msg( 'Adding catalog performance data', 0 );


		$fcn = function( \Aimeos\MW\Setup\Task\Iface $self, $parentId, $num, $idx ) {

			\Aimeos\MShop\Factory::clear();

			$treeFcn = function( array $parents, $parentId, $label, $level ) use ( &$treeFcn, $self, $num ) {

				$catItem = $self->addCatalogItem( $label, $parentId );
				array_unshift( $parents, $catItem );

				if( $level > 0 )
				{
					for( $i = 0; $i < $num; $i++ ) {
						$treeFcn( $parents, $catItem->getId(), $label . '/' . $i, $level - 1 );
					}
				}
				else
				{
					$self->addProductItems( $parents, $label );
				}

				$self->save( 'catalog', $catItem );
			};

			$treeFcn( [], $parentId, $idx, $this->numCatLevels - 1 );
		};


		$this->init();

		$config = $this->additional->getConfig();
		$this->numCatLevels = $config->get( 'setup/unitperf/num-catlevels', 1 );
		$this->numCategories = $config->get( 'setup/unitperf/num-categories', 10 );
		$this->numCatProducts = $config->get( 'setup/unitperf/num-catproducts', 100 );
		$this->numProdVariants = $config->get( 'setup/unitperf/num-prodvariants', 10 );

		$process = $this->additional->getProcess();
		$catalogRootItem = $this->addCatalogItem( 'home' );

		$num = round( pow( $this->numCategories, 1 / $this->numCatLevels ) / 5 ) * 5;

		for( $i = 1; $i <= round( $this->numCategories / pow( $num, $this->numCatLevels - 1 ) ); $i++ ) {
			$process->start( $fcn, [$this, $catalogRootItem->getId(), $num, $i] );
		}

		$process->wait();


		$this->status( 'done' );
	}


	public function addCatalogItem( $code, $parentId = null )
	{
		$catalogManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog' );

		$item = $catalogManager->createItem()
			->setLabel( 'category-' . $code )
			->setCode( 'cat-' . $code )
			->setStatus( 1 );

		$item = $this->addCatalogTexts( $item, $code );
		$item = $catalogManager->insertItem( $item, $parentId );

		return $item;
	}


	protected function addCatalogProduct( array $catItems, \Aimeos\MShop\Product\Item\Iface $prodItem, $i )
	{
		$catalogListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog/lists' );

		$promoListItem = $catalogListManager->createItem()
			->setTypeId( $this->getTypeId( 'catalog/lists/type', 'product', 'promotion' ) );

		$defListItem = $catalogListManager->createItem()
			->setTypeId( $this->getTypeId( 'catalog/lists/type', 'product', 'default' ) );

		$promo = round( $this->numCatProducts / 10 ) ?: 1;

		foreach( $catItems as $idx => $catItem )
		{
			if( $i % pow( 10, $idx ) === 0 ) {
				$catItem->addListItem( 'product', (clone $defListItem)->setRefId( $prodItem->getId() ) );
			}

			if( ($i + $idx) % $promo === 0 ) {
				$catItem->addListItem( 'product', (clone $promoListItem)->setRefId( $prodItem->getId() ) );
			}
		}
	}


	protected function addCatalogTexts( \Aimeos\MShop\Catalog\Item\Iface $catItem, $label )
	{
		$textManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'text' );
		$catalogListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'catalog/lists' );

		$textItem = $textManager->createItem()
			->setTypeId( $this->getTypeId( 'text/type', 'product', 'name' ) )
			->setContent( str_replace( '-', ' ', $label ) )
			->setLanguageId( 'en' )
			->setLabel( $label )
			->setStatus( 1 );

		$listItem = $catalogListManager->createItem()
			->setTypeId( $this->getTypeId( 'catalog/lists/type', 'product', 'default' ) );

		return $catItem->addListItem( 'text', $listItem, $textItem );
	}


	protected function addProductAttributes( \Aimeos\MShop\Product\Item\Iface $prodItem, array $attrIds )
	{
		$productListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$listItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'attribute', 'default' ) );

		foreach( $attrIds as $attrId ) {
			$prodItem->addListItem( 'attribute', (clone $listItem)->setRefId( $attrId ) );
		}

		$listItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'attribute', 'config' ) );

		foreach( $this->attributes['config'] as $attrId ) {
			$prodItem->addListItem( 'attribute', (clone $listItem)->setRefId( $attrId ) );
		}

		return $prodItem;
	}


	public function addProductItems( array $catItems = [], $label )
	{
		$articles = array(
			'shirt', 'skirt', 'jacket', 'pants', 'socks', 'blouse', 'slip', 'sweater', 'dress', 'top',
			'anorak', 'babydoll', 'swimsuit', 'trunks', 'bathrobe', 'beret', 'bra', 'bikini', 'blazer', 'bodysuit',
			'bolero', 'bowler', 'trousers', 'bustier', 'cape', 'catsuit', 'chucks', 'corduroys', 'corsage', 'cutaway',
			'lingerie', 'tricorn', 'bow tie', 'tails', 'leggings', 'galoshes', 'string', 'belt', 'hotpants', 'hat',
			'jumpsuit', 'jumper', 'caftan', 'hood', 'kimono', 'headscarf', 'scarf', 'corset', 'costume', 'tie',
			'cummerbund', 'robe', 'underpants', 'dungarees', 'undershirt', 'camisole', 'mantle', 'bodice', 'topless', 'moonboots',
			'cap', 'nightie', 'negligee', 'overalls', 'parka', 'poncho', 'bloomers', 'pumps', 'pajamas', 'farthingale',
			'sari', 'veil', 'apron', 'swimsuit', 'shorts', 'tuxedo', 'stocking', 'suspender', 'tanga', 'tankini',
			'toga', 'tunic', 'turban', 'jerkin', 'coat', 'suit', 'vest', 'gloves', 'bag', 'briefcase',
			'shoes', 'sandals', 'flip-flops', 'ballerinas', 'slingbacks', 'clogs', 'moccasins', 'sneakers', 'boots', 'slippers',
		);

		$productManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );
		$productManager->begin();

		$type = $this->numProdVariants > 0 ? 'select' : 'default';

		$modifier = $this->attributes['modifier'];
		$material = $this->attributes['material'];

		for( $i = 0; $i < $this->numCatProducts; $i++ )
		{
			$text = key( $modifier ) . ' ' . key( $material ) . ' ' . current( $articles );

			$item = $productManager->createItem()
				->setTypeId( $this->getTypeId( 'product/type', 'product', $type ) )
				->setLabel( $text . ' (' . $label . ')' )
				->setCode( 'prod-' . $i . ':' . $label )
				->setStatus( 1 );

			$item = $this->addProductAttributes( $item, [current( $modifier ), current( $material )] );
			$item = $this->addProductTexts( $item, $text );
			$item = $this->addProductMedia( $item, $i );
			$item = $this->addProductPrices( $item, $i );
			$item = $this->addProductVariants( $item, $i );
			$item = $this->addProductSuggestions( $item, $catItems );

			$item = $productManager->saveItem( $item );

			$this->addCatalogProduct( $catItems, $item, $i );

			next( $articles );
			if( current( $articles ) === false )
			{
				reset( $articles ); next( $modifier );

				if( current( $modifier ) === false )
				{
					reset( $modifier ); next( $material );

					if( current( $material ) === false ) {
						reset( $material );
					}
				}
			}
		}

		$productManager->commit();
	}


	protected function addProductMedia( \Aimeos\MShop\Product\Item\Iface $prodItem, $idx )
	{
		$prefix = 'https://demo.aimeos.org/media/';

		$mediaManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'media' );
		$productListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$listItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'media', 'default' ) );

		for( $i = 0; $i < 4; $i++ )
		{
			$mediaItem = $mediaManager->createItem()
				->setLabel( ($i+1) . '. picture for ' . $prodItem->getLabel() )
				->setTypeId( $this->getTypeId( 'media/type', 'product', 'default' ) )
				->setPreview( $prefix . 'unitperf/' . ( ( $idx + $i ) % 4 + 1 ) . '.jpg' )
				->setUrl( $prefix . 'unitperf/' . ( ( $idx + $i ) % 4 + 1 ) . '-big.jpg' )
				->setMimeType( 'image/jpeg' )
				->setStatus( 1 );

			$prodItem->addListItem( 'media', clone $listItem, $mediaItem );
		}

		$mediaItem = $mediaManager->createItem()
			->setTypeId( $this->getTypeId( 'media/type', 'product', 'default' ) )
			->setPreview( $prefix . 'unitperf/download-preview.jpg' )
			->setUrl( $prefix . 'unitperf/download.pdf' )
			->setMimeType( 'application/pdf' )
			->setLabel( 'PDF download' )
			->setStatus( 1 );

		$listItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'media', 'download' ) );

		return $prodItem->addListItem( 'media', $listItem, $mediaItem );
	}


	protected function addProductPrices( \Aimeos\MShop\Product\Item\Iface $prodItem, $idx )
	{
		$priceManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'price' );
		$productListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$listItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'price', 'default' ) );

		for( $i = 0; $i < 3; $i++ )
		{
			$priceItem = $priceManager->createItem()
				->setTypeId( $this->getTypeId( 'price/type', 'product', 'default' ) )
				->setLabel( $prodItem->getLabel() . ': from ' . ( 1 + $i * 5 ) )
				->setValue( 100 + ($idx % 900) - $i * 10 )
				->setQuantity( 1 + $i * 5 )
				->setRebate( $i * 10 )
				->setStatus( 1 );

			$prodItem->addListItem( 'price', clone $listItem, $priceItem );
		}

		return $prodItem;
	}


	protected function addProductSuggestions( \Aimeos\MShop\Product\Item\Iface $prodItem, array $catItems )
	{
		if( ( $catItem = reset( $catItems ) ) !== false )
		{
			$productListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

			$listItem = $productListManager->createItem()
				->setTypeId( $this->getTypeId( 'product/lists/type', 'product', 'suggestion' ) );

			$listItems = $catItem->getListItems( 'product' );
			$ids = []; $num = 5;

			while( ( $litem = array_pop( $listItems ) ) !== null && $num > 0 )
			{
				if( !in_array( $litem->getRefId(), $ids ) )
				{
					$prodItem->addListItem( 'product', (clone $listItem)->setRefId( $litem->getRefId() ) );
					$ids[] = $litem->getRefId();
					$num--;
				}
			}
		}

		return $prodItem;
	}


	protected function addProductTexts( \Aimeos\MShop\Product\Item\Iface $prodItem, $label )
	{
		$textManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'text' );
		$productListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$listItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'text', 'default' ) );

		$textItem = $textManager->createItem()
			->setTypeId( $this->getTypeId( 'text/type', 'product', 'name' ) )
			->setLanguageId( 'en' )
			->setContent( $label )
			->setLabel( $label )
			->setStatus( 1 );

		$prodItem->addListItem( 'text', clone $listItem, $textItem );

		$textItem = $textManager->createItem()
			->setTypeId( $this->getTypeId( 'text/type', 'product', 'short' ) )
			->setContent( 'Short description for ' . $label )
			->setLabel( $label . '(short)' )
			->setLanguageId( 'en' )
			->setStatus( 1 );

		$prodItem->addListItem( 'text', clone $listItem, $textItem );

		$textItem = $textManager->createItem()
			->setTypeId( $this->getTypeId( 'text/type', 'product', 'long' ) )
			->setContent( 'Long description for ' . $label . '. This may include some "lorem ipsum" text' )
			->setLabel( $label . '(long)' )
			->setLanguageId( 'en' )
			->setStatus( 1 );

		$prodItem->addListItem( 'text', clone $listItem, $textItem );

		return $prodItem;
	}


	protected function addProductVariants( \Aimeos\MShop\Product\Item\Iface $prodItem, $idx)
	{
		$productManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product' );
		$productListManager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$defListItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'product', 'default' ) );

		$varListItem = $productListManager->createItem()
			->setTypeId( $this->getTypeId( 'product/lists/type', 'attribute', 'variant' ) );

		$length = $this->attributes['length'];
		$width = $this->attributes['width'];
		$size = $this->attributes['size'];

		for( $i = 0; $i < $this->numProdVariants; $i++ )
		{
			$text = key( $length ) . ', ' . key( $width ) . ' ' . $prodItem->getLabel() . ' (' . key( $size ) . ')';

			$item = $productManager->createItem()
				->setTypeId( $this->getTypeId( 'product/type', 'product', 'default' ) )
				->setCode( 'variant-' . $idx . '/' . $i . ':' . $prodItem->getCode() )
				->setLabel( $text )
				->setStatus( 1 );

			$item->addListItem( 'attribute', (clone $varListItem)->setRefId( current( $length ) ) );
			$item->addListItem( 'attribute', (clone $varListItem)->setRefId( current( $width ) ) );
			$item->addListItem( 'attribute', (clone $varListItem)->setRefId( current( $size ) ) );

			$prodItem->addListItem( 'product', clone $defListItem, $item );

			next( $length );
			if( current( $length ) === false )
			{
				reset( $length ); next( $width );

				if( current( $width ) === false )
				{
					reset( $width ); next( $size );

					if( current( $size ) === false ) {
						reset( $size );
					}
				}
			}
		}

		return $prodItem;
	}


	protected function getTypeId( $path, $domain, $code )
	{
		if( !isset( $this->typeIds[$path][$domain][$code] ) ) {
			throw new \RuntimeException( sprintf( 'No "%1$s" ID for "%2$s" and "%3$s" available', $path, $domain, $code ) );
		}

		return $this->typeIds[$path][$domain][$code];
	}


	protected function init()
	{
		$paths = ['catalog/lists/type', 'product/type', 'product/lists/type', 'attribute/type', 'media/type', 'price/type', 'text/type'];

		foreach( $paths as $path )
		{
			$manager = \Aimeos\MShop\Factory::createManager( $this->additional, $path );
			$search = $manager->createSearch()->setSlice( 0, 0x7fffffff );

			foreach( $manager->searchItems( $search ) as $id => $item ) {
				$this->typeIds[$path][$item->getDomain()][$item->getCode()] = $id;
			}
		}


		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'attribute' );
		$search = $manager->createSearch()->setSlice( 0, 0x7fffffff );

		foreach( $manager->searchItems( $search ) as $id => $item ) {
			$this->attributes[$item->getType()][$item->getCode()] = $id;
		}
	}


	public function save( $domain, $item )
	{
		return \Aimeos\MShop\Factory::createManager( $this->additional, $domain )->saveItem( $item );
	}
}