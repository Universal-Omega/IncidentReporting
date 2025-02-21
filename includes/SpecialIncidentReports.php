<?php

use MediaWiki\MediaWikiServices;

class SpecialIncidentReports extends SpecialPage {
	private $config = null;
	private $permissionManager = null;

	public function __construct() {
		parent::__construct( 'IncidentReports', 'viewincidents' );
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'incidentreporting' );
		$this->permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->checkPermissions();

		$par = explode( '/', $par );

		$dbw = wfGetDB( DB_MASTER, [], $this->config->get( 'IncidentReportingDatabase' ) );

		$inc = $dbw->selectRow(
			'incidents',
			'*',
			[
				'i_id' => (int)$par[0]
			]
		);

		$isPublished = ( $inc ) ? (bool)$inc->i_published : false;

		if ( $par[0] == '' || ( (int)$par[0] != 0 && !$inc ) ) {
			$this->showLanding( $dbw );
		} else {
			$edit = ( ( isset( $par[1] ) || (int)$par[0] == 0 ) && $this->permissionManager->userHasRight( $this->getContext()->getUser(), 'editincidents' ) );
			$this->showForm( (int)$par[0], $edit, $dbw, $isPublished );
		}
	}

	public function showForm(
		int $id,
		bool $edit,
		MaintainableDBConnRef $dbw,
		bool $isPublished
	) {
		if ( !$isPublished && !$this->permissionManager->userHasRight( $this->getContext()->getUser(), 'editincidents' ) ) {
			throw new PermissionsError( 'editincidents' );
		}

		$out = $this->getOutput();

		$out->addModules( 'ext.incidentreporting.oouiform' );

		$formFactory = new IncidentReportingFormFactory();
		$htmlForm = $formFactory->getForm( $id, $edit, $dbw, $this->getContext() );
		$sectionTitles = $htmlForm->getFormSections();

		$sectTabs = [];
		foreach( $sectionTitles as $key ) {
			$sectTabs[] = [
				'name' => $key,
				'label' => $htmlForm->getLegend( $key )
			];
		}

		$out->addJsConfigVars( 'wgIncidentReportingOOUIFormTabs', $sectTabs );

		$htmlForm->show();

	}

	public function showLanding( MaintainableDBConnRef $dbw ) {
		$type = $this->getRequest()->getText( 'type' );
		$component = $this->getRequest()->getText( 'component' );
		/* $published = $this->getRequest()->getText( 'published' );
		$stats = $this->getRequest()->getText( 'stats' );
		$selector = $this->getRequest()->getText( 'selector' );
		$quantity = $this->getRequest()->getText( 'quantity' ); */

		$types = [
			wfMessage( 'incidentreporting-label-human' )->text() => 'human',
			wfMessage( 'incidentreporting-label-technical' )->text() => 'technical',
			wfMessage( 'incidentreporting-label-upstream' )->text() => 'upstream',
		];

		$irServices = [];

		foreach ( $this->config->get( 'IncidentReportingServices' ) as $service => $url ) {
			$niceName = str_replace( ' ', '-', strtolower( $service ) );
			$irServices[$service] = $niceName;
		}

		$showAll = [ wfMessage( 'incidentreporting-table-all' )->text() => '' ];

		$formDescriptor = [
			'type' => [
				'type' => 'select',
				'label-message' => 'incidentreporting-table-cause',
				'options' => $types + $showAll,
				'default' => '',
				'name' => 'type'
			],
			'component' => [
				'type' => 'select',
				'label-message' => 'incidentreporting-table-service',
				'options' => $irServices + $showAll,
				'default' => '',
				'name' => 'component'
			],
			/* 'statistics' => [
				'type' => 'check',
				'label-message' => 'incidentreporting-stats',
				'default' => (bool)$stats,
				'name' => 'stats'
			],
			'statistics-selector' => [
				'type' => 'select',
				'options' => [
					wfMessage( 'incidentreporting-stats-type' )->text() => 'type',
					wfMessage( 'incidentreporting-stats-component' )->text() => 'component',
				],
				'hide-if' => [ '!==', 'stats', '1' ],
				'default' => $selector,
				'name' => 'selector'
			],
			'statistics-quantity' => [
				'type' => 'select',
				'options' => [
					wfMessage( 'incidentreporting-stats-number' )->text() => 'num',
					wfMessage( 'incidentreporting-stats-visible' )->text() => 'visible',
					wfMessage( 'incidentreporting-stats-total' )->text() => 'total'
				],
				'hide-if' => [ '!==', 'stats', '1' ],
				'default' => $quantity,
				'name' => 'quantity'
			],
			'statistics-published' => [
				'type' => 'date',
				'label-message' => 'incidentreporting-stats-published',
				'default' => '',
				'hide-if' => [ '!==', 'stats', '1' ],
				'default' => $published,
				'name' => 'published'
			] */
		];
		
		$pager = new IncidentReportingPager( $type, $component, $this->config->get( 'IncidentReportingServices' ) );


		/* switch ( $quantity ) {
			case 'num':
				$field = 'i_id';
				break;
			case 'visible':
				$field = 'i_outage_visible';
				break;
			case 'total':
				$field = 'i_outage_total';
				break;
			default:
				$field = false;
		}
        
		if ( $selector === 'type' ) {
			$where = 'i_cause';
			$foreach = $types;
			$all = ( $type === '' );
		} elseif ( $selector === 'component' ) {
			$where = 'i_service';
			$foreach = $irServices;
			$all = ( $component === '' );
		}

		if ( $field ) {
			if ( $all ) {
				foreach ( $foreach as $label => $key ) {
						$statsData = $dbw->selectFieldValues(
							'incidents',
							$field, [ 
								$where => $key,
								'i_published >= ' . ( $published == '' ? '0' : $dbw->timestamp( wfTimestamp( TS_RFC2822, "{$published}T00:00:00.000Z" ) ) )
							]
						);

						$formDescriptor += [
							"statistics-out-quantity-{$key}" => [
								'type' => 'info',
								'label' => $label,
							]
						];

						if ( $quantity === 'num' ) {
							$formDescriptor["statistics-out-quantity-{$key}"] += [ 'default' => count( $statsData ) ];
						} else {
							foreach ( $statsData as $value ) {
								$formDescriptor["statistics-out-quantity-{$key}"] += [ 'default' => @$value += $value ];
							}

							if ( !isset( $formDescriptor["statistics-out-quantity-{$key}"]['default'] ) ) {
								$formDescriptor["statistics-out-quantity-{$key}"] += [ 'default' => '0' ];
							}
						}
					}
			} else {
				if ( $selector === 'type' ) {
					$key = $type;
				} elseif ( $selector === 'component' ) {
					$key = $component;
				}

				if ( in_array( $key, $foreach ) ) {
					$statsData = $dbw->selectFieldValues(
						'incidents',
						$field, [ 
							$where => $key,
							'i_published >= ' . ( $published == '' ? '0' : $dbw->timestamp( wfTimestamp( TS_RFC2822, "{$published}T00:00:00.000Z" ) ) )
						]
					);

					$formDescriptor += [
						"statistics-out-quantity-{$key}" => [
							'type' => 'info',
							'label' => array_flip( $foreach )[$key],
						],
					];

					if ( $quantity === 'num' ) {
						$formDescriptor["statistics-out-quantity-{$key}"] += [ 'default' => count( $statsData ) ];
					} else {
						foreach ( $statsData as $value ) {
							$formDescriptor["statistics-out-quantity-{$key}"] += [ 'default' => @$value += $value ];
						}

						if ( !isset( $formDescriptor["statistics-out-quantity-{$key}"]['default'] ) ) {
							$formDescriptor["statistics-out-quantity-{$key}"] += [ 'default' => '0' ];
						}
					}
				}
			}
		} */

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'dummyProcess' ] )->setMethod( 'get' )->prepareForm()->show();

		$this->getOutput()->addParserOutputContent( $pager->getFullOutput() );

		if ( $this->permissionManager->userHasRight( $this->getContext()->getUser(), 'editincidents' ) ) {
			$createForm = HTMLForm::factory( 'ooui', [], $this->getContext() );
			$createForm->setMethod( 'post' )->setFormIdentifier( 'createForm' )->setSubmitTextMsg( 'incidentreporting-create' )->setSubmitCallback( [ $this, 'onSubmitRedirectToCreate' ] ) ->prepareForm()->show();
		}
	}

	public static function onSubmitRedirectToCreate( $formData ) {
		header( 'Location: ' . SpecialPage::getTitleFor( 'IncidentReports' )->getFullURL() . '/create' );

		return true;
	}

	public static function dummyProcess( $formData ) {
		return false;
	}
}
