<?php

namespace Drupal\cool_calendar_extras\Plugin\Block;

//BlockBase implements the interface BlockPluginInterface
//Extending from BlockBase avoid us to have to implement several required methods
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Views;
use Drupal\views\Entity\View;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
* It provides a block where it appears a legend with a list of terms of taxonomy with the colors for each term of taxonomy
*
*  The block is a plugin that is registered through Annotations, because the system will be able to know its existence,
*  so this section/block of commentaries is mandatory and has to contain the "Block" directive.
*
*  - id: unique identifier, we will use it as a module name prefix.
*  - admin_label: administrative label of the block. It corresponds with the block name on the admin blocks list and the its default title.
*  - category: the block category name, into the admin list. If it isn't defined it will correspond the module name where is defined the block.
* 
* @Block(
*         id = "cool_calendar_extras_block_legend",
*         admin_label = @Translation("Block legend"),
*         category = @Translation("Cool"),
*       )
*
*/
class LegendCalendarBlock extends BlockBase implements ContainerFactoryPluginInterface {
	
	use StringTranslationTrait;
	
	//Property that it will save the service (by injection) of taxonomies and we can use in this class
	protected $entityTypeManager;
	protected $logger;
	
	//Constructor
	public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactoryInterface $loggerChannelFactory) {
		
		parent::__construct($configuration, $plugin_id, $plugin_definition);
		
		$this->entityTypeManager = $entityTypeManager;
		$this->logger = $loggerChannelFactory;
		
	}
	
	public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
		
		return new static(
			$configuration,
			$plugin_id,
			$plugin_definition,
			$container->get('entity_type.manager'),
			$container->get('logger.factory')
		);
		
	}
	
	//Function that allows us get all terms of taxonomy that belongs a specific vocabulary
	private function getVocabularyTerms($vid) {
		
		//We check if arrive a vocabulary identifier, in other case, we return an empty array
		if (empty($vid)) {
			return [];
		}
		
		//We get all terms (it gets from into the cache)
		$terms = &drupal_static(__FUNCTION__);
		
		//We get all terms of taxonomy from the database (if their are not into the cache)
		if (!isset($terms[$vid])) {
			$query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
			$query->condition('vid', $vid)->accessCheck(TRUE);
			$tids = $query->execute();
			$terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
		}
		
		return $terms;
	
	}
	
	//Function that allows us retrieve the vocabulary name from its identifier
	private function getVocabularyName($vid) {
		
		//We check if it has arrived a vocabulary identifier, in other case, we return an empty string
		if (empty($vid)) {
			return "";
		}
		
		//We get the vocabulary name
		$vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
		
		return $vocabulary->label();
	
	}
	
	//Unique mandatory method to implement that it returns a rendered array that it contains the block content (similar as pages controller)
	public function build() {
			
			//We try to get the block configuration
			$config_str = $this->configuration['cool_calendar_extras_source_legend_calendar'];
			
			//We check that the configuration exists
			if (!isset($config_str)) {
				return [
					'#markup' => '<span>' . $this->t('No configuration set. Check the block configuration.') . '</span>',
				];
			}
			
			//We retrieve the block configuration (inside the configuration there are two values that are separated by a &)
			$config = explode('&', $config_str);
			
			//We check that exist at least two params into the config variable
			if (sizeof($config) < 2) {
				return [
					'#markup' => '<span>' . $this->t('No configuration set. Check the block configuration.') . '</span>',
				];
			}
			
			//We have found the two necessary params (the view name and the presentation name), we save them into variables
			$config_view = $config[0];
			$config_display = $config[1];
			
			//We get the view and the view presentation to obtain the colors configured into the view
			$view = $this->entityTypeManager->getStorage('view')->load($config_view);
			$display = $view->getDisplay($config_display);
			
			//We get the vocabulary identifier that we want to load their terms into an array
			$vid = $display['display_options']['style']['options']['vocabularies'];
			
			//We check if already exists a color configuration for the vocabulary terms
			if (!array_key_exists('color_taxonomies', $display['display_options']['style']['options'])){
				$this->logger->get('cool_calendar_extras')->warning($this->t('No colors has found for each term of the vocabulary. Check the display view configuration.'));
			} else {
				//We get an array that it contains the color of vocabulary terms
				$colors = $display['display_options']['style']['options']['color_taxonomies'];
			}
			
			//We get the taxonomy terms that belongs a vocabulary
			$terms = $this->getVocabularyTerms($vid);
			
			//We iterate taxonomy terms to generate the legend table rows
			foreach($terms as $term_id) {
				if(array_key_exists($term_id->id(), $colors)) {
					//If the taxonomy term has defined a color
					$table_rows[] = [$term_id->getName(), $colors[$term_id->id()]];
				} else {
					//If the taxonomy term has not defined a color
					$table_rows[] = [$term_id->getName(), '#ffffff'];
				}
			}
			
			
			//We get the vocabulary name to put on the column header
			$vocabulary_name = $this->getVocabularyName($vid);
			
			//We set the value of columns headers
			$table_header = [$vocabulary_name, $this->t('Color')];
			
			if (sizeof($terms) == 0) {
				$this->logger->get('cool_calendar_extras')->warning($this->t('The selected vocabulary has not terms.'));
				$build = ['#markup' => '<span>' . $this->t('The selected vocabulary has not terms.') . '</span>',];
			} else {
				$build['legend_calendar_block_table'] = [
					'#theme' => 'cool_calendar_extras_legend_calendar_block_table',
					'#headers' => $table_header,
					'#rows' => $table_rows,
				];
			}
			
			//We return the HTML content generated
			return [
				$build
			];
			
	}
	
	//Overwriting this method allow us to modify the default values that it will have the block configuration
	public function defaultConfiguration() {
	
		return [
			'label' => 'Legend calendar', //Name by default that we want the block have, if we don't specify, it will get the admin_label from class Annotations
			'label_display' => FALSE, //FALSE that the title won't be visible by default, to make the title visible is not correct the TRUE value, the correct way is using the BlockInterface::BLOCK_LABEL_VISIBLE constant with "use Drupal\block\BlockInterface"
		];
	
	}
	
	//Method that allows us to alter (adding fields) into the block configuration form
	public function blockForm($form, FormStateInterface $form_state) {
		
		//We get an array with all active views
		$views = Views::getEnabledViews();
		
		//We iterate the list of views
		foreach ($views as $view) {
			
			if ($view->get('display') != null) {
				
				$displays = $view->get('display');
				
				//We iterate all presentations of the current view
				foreach ($displays as $display) {
					
					//We only include into the list those presentations of the views that are in "Full Calendar Display" format
					if (array_key_exists('style', $display['display_options']) ) {
						
						if ($display['display_options']['style']['type'] == 'fullcalendar_view_display') {
							$lViews[$view->id() . '&' . $display['id']] = $view->label() . ' - ' . $display['display_title'];
						}
						
					}
					
				}
				
			}
			
		}
		
		$source_legend_calendar = null;
		
		//We get the configuration value in case that it exist
		if (array_key_exists('cool_calendar_extras_source_legend_calendar', $this->configuration) ) {
			$source_legend_calendar = $this->configuration['cool_calendar_extras_source_legend_calendar'];
		}
		
		//We add a select field where it will show all views that are selectable
		$form['cool_calendar_extras_fullcalendar_view_display_source'] = [
			'#type' => 'select',
			'#title' => $this->t('Fullcalendar View - Presentation'),
			'#description' => t('To see here your view: the format of the presentation should be Full Calendar Display and the format of the presentation should be overwritten.'),
			'#required' => true,
			'#default_value' => $source_legend_calendar,
			'#options' => $lViews,
		];
		
		return $form;
		
	}
	
	//This method has the mission to save the form values if previously has passed the blockValidate method validations and also form base validations
	public function blockSubmit($form, FormStateInterface $form_state) {
		
		//We read the fields values and we save them into configuration vars
		$this->configuration['cool_calendar_extras_source_legend_calendar'] = $form_state->getValue('cool_calendar_extras_fullcalendar_view_display_source');
		
	}
	
}
