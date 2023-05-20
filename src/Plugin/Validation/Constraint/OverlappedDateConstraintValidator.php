<?php

namespace Drupal\cool_calendar_extras\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\Core\Entity\EntityTypeManagerInterface;

use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\Constraint\AfterConstraint;
use Recurr\Transformer\Constraint\BeforeConstraint;
use Recurr\Transformer\Constraint\BetweenConstraint;
use Recurr\Transformer\TextTransformer;

use Drupal\smart_date_recur\Entity\SmartDateRule;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Class that has the mission to validate the OverlappedDate constraint (defined in OverlappedDateConstraint.php)
 */
class OverlappedDateConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {
	
	//Properties that they will save the service (by injection) and we will use in this class
	protected $currentRouteMatch;
	protected $entityTypeManager;
	protected $ConfigFactory;
	
	public function __construct(CurrentRouteMatch $currentRouteMatch, EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory) {
		$this->currentRouteMatch = $currentRouteMatch;
		$this->entityTypeManager = $entityTypeManager;
		$this->configFactory = $configFactory;
	}
	
	public static function create(ContainerInterface $container) {
		return new static(
			$container->get('current_route_match'),
			$container->get('entity_type.manager'),
			$container->get('config.factory')
		);
	}
	
	/**
	* This function will execute every time that the user save a entity/node
	*/
	public function validate($value, Constraint $constraint) {
		
		// $value --> in this variable we have the node
		// $constraint --> the class information OverlappedDateConstraint.php
		
		//We check that it is a node
		if ($value->getEntityTypeId() != 'node') {
			//Is not a node, we don't continue the validation
			return;
		}
		
		//We get the configuration that it has set into administration section
		$config = $this->configFactory->get('cool_calendar_extras.settings');
		
		//We check if it could get the module configuration
		if (is_null($config)) {
			//No module configuration exist, we don't continue the validation
			return;
		}
		
		//We generate the fields names that they should have
		$name_module = 'cool_calendar_extras';
		
		$config_smartdate = $name_module . '_' . $value->bundle() . '_constraint' . '_smartdate';
		$config_taxonomy = $name_module . '_' . $value->bundle() . '_constraint' . '_taxonomy';
		
		//We check if it exists some restriction configured for this type of content
		if (is_null($config->get($config_smartdate)) || $config->get($config_smartdate) == 'none') {
			//No restriction present, we don't continue the validation
			return;
		}
		
		//We get an existing schedule list/array between dates indicated
		$nodes_overlapped = $this->getOverlappedEvents($value, $config->get($config_smartdate), $config->get($config_taxonomy));
		
		//If we have got at least one overlapped element...
		// - If the number of nodes found is  > 0 --> we return TRUE (we have found some overlapped elements)
		// - If the number of nodes found is   = 0 --> FALSE (no overlapped elements we have found)
		if (sizeof($nodes_overlapped) > 0) {
			
			//We generate a text with all elements that exist overlapping/conflict
			$nodes_overlapped_str = "<br>";
			
			foreach($nodes_overlapped as $node){
				$nodes_overlapped_str .= "- <a href='" . $node->toUrl()->toString() . "' target='_blank' >" . $node->get('title')->getString() . "</a><br>";
			}
			
			//We concat the error message with the overlapped elements list
			$violation_str = t('The configuration that you have set overlaps with other elements:') . $nodes_overlapped_str;
			
			//There is overlapping dates, we inform about this fact and we don't allow to save until the user modify the current node values
			$this->context->addViolation($violation_str);
			
		}
		
	}
	
	/**
	* This function gets the entities/nodes that the dates are overlapped with current node
	*/
	private function getOverlappedEvents($node, $smart_date_field_name, $taxonomy_field_name) {
		
		//We get the entity type name 
		$node_entity_type = $node->getEntityTypeId();
		
		//We get the bundle
		$node_bundle = $node->bundle();
		
		//We get the smart_date field value
		$smart_date_field_value = $node->get($smart_date_field_name)->getValue();
		
		//We get the mart_date field configuration, the limit of months that it will be able to generate the occurrences
		$smart_date_field_config = $this->entityTypeManager->getStorage('field_config')->load($node_entity_type .'.' . $node_bundle . '.' . $smart_date_field_name);
		$month_limit = $smart_date_field_config->getThirdPartySetting('smart_date_recur', 'month_limit', 12); //If the value doesn't exist or is NULL, by default we will set 12 months
		
		//We will use next function (from smart_date_recur.module) that it will return us an array with all occurrences of the field that we are saving (what we want to save and not what it is already saved for this node)
		$recurrences = smart_date_recur_generate_rows($smart_date_field_value, $node_entity_type, $node_bundle, $smart_date_field_name, $month_limit);
		
		//We get all nodes que belongs at same entity as node that we are doing the validation and also they have the same taxonomy term
		$query = $this->entityTypeManager->getStorage('node')->getQuery();
		
		$query->accessCheck(FALSE); //TRUE = that only take into account visible results for user, FALSE = all results
		$query->condition('status', 1); //Only which are registered
		$query->condition('type', $node_bundle); //That the type of content be a specific
		
		
		//We check that if exist a taxonomy restriction configuration for this type of content
		if (!is_null($taxonomy_field_name) && $taxonomy_field_name != 'none') {
			
			//We get the taxonomy term identifier that user has set in the node
			$node_term_id = $node->get($taxonomy_field_name)->getString();
			
			//Exists a taxonomy restriction, so, we add a  condition into the query
			$query->condition($taxonomy_field_name, $node_term_id); //Only nodes that have a vocabulary field and into them has a specific term id
			
		}
		
		//If we are in a scenario where we are modifying an existing node, we have the node id
		if (!is_null($node->id())) {
			$query->condition('nid', $node->id(), '<>'); //No tenir en compte la programació del propi node que estem validant
		}
		
		//Array where we will save all node identifiers that an overlapping conflict has been found
		$nodes_id = array();
		
		//For each recurrence we will generate a query from $query as base ($query has all common conditions for all queries that we will execute)
		foreach($recurrences as $recurrence){
			
			//We will clone the base query to make a query for this iteration with specific values
			$sub_query = clone $query;
			
			//We get the start and end timestamp
			$date_start = $recurrence['value'];
			$date_end = $recurrence['end_value'];
			
			//We create a AND condition
			$andGroup = $sub_query->andConditionGroup();
			
			//We add in the AND condition the following conditions: .end_value > $data_start AND .value < $date_end
			$andGroup->condition($smart_date_field_name . '.end_value', $date_start, '>');
			$andGroup->condition($smart_date_field_name . '.value', $date_end, '<');
			
			//We add in the AND condition into the recurrence query
			$sub_query->condition($andGroup);
			
			//We show at screen the query result
			//dpm($sub_query->__toString());
			
			//We execute the query for the current occurrence and we add the results (overlapped nodes) into the array
			$nodes_id = array_merge($nodes_id, $sub_query->execute());
			
		}
		
		//We load the objects (nodes) from the ids that we got on previous query
		$nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nodes_id);
		
		return $nodes;
		
	}
	
}
