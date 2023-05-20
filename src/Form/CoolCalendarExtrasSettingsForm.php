<?php

/**
* @file
* Contains \Drupal\cool_calendar_extras\Form\CoolCalendarExtrasSettingsForm.
*/

namespace Drupal\cool_calendar_extras\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\token\TreeBuilderInterface;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
* Implements the CoolCalendarExtrasSettingsForm form controller.
*
* @see \Drupal\Core\Form\ConfigFormBase
*/
class CoolCalendarExtrasSettingsForm extends ConfigFormBase {
	
	//Property that it saves the service (by injection) and we can use in this class
	protected $entityFieldManager;
	protected $treeBuilder;
	
	public function __construct(EntityFieldManagerInterface $entityFieldManager, TreeBuilderInterface $treeBuilder) {
		$this->entityFieldManager = $entityFieldManager;
		$this->treeBuilder = $treeBuilder;
	}
	
	public static function create(ContainerInterface $container) {
		return new static(
			$container->get('entity_field.manager'),
			$container->get('token.tree_builder')
		);
	}
	
	//Function that it returns the form identifier
	public function getFormId() {
		return 'cool_calendar_extras_settings_form';
	}
	
	//Function that returns the configuration object name that will be used for reading and saving the module configuration
	//To have access in the configuration object of module: $config = $this->config('cool_calendar_extras.settings');
	//To read the configuration: $config->get('allowed_types');
	//To write the configuration: $config->set('allowed_types', $allowed_types);
	protected function getEditableConfigNames() {
		return ['cool_calendar_extras.settings'];
	}
	
	//Method that generates/builds form fields
	public function buildForm(array $form, FormStateInterface $form_state) {
		
		//We get the object that it will be useful for retrieving the module configuration values for filling the fields
		$config = $this->config('cool_calendar_extras.settings');
		
		//List (array key/value) with all types of content available in this site (this function is included into the Drupal API)
		$types_of_content = node_type_get_names();
		
		//The form control that it will group all form elements (all fields of all tabs)
		$form['type_of_content'] = [
			'#type' => 'vertical_tabs',
		];
		
		//For each type of content we are going to generate a tab with its configuration fields
		foreach ($types_of_content as $type_id => $type_name) {
			
			//We generate the form field names
			$group_id = 'cool_calendar_extras_' . $type_id;
			
			$constraint = '_constraint';
			$reminder = '_reminder';
			
			$field_fieldset_constraint = $group_id . $constraint . '_fieldset';
			$field_smartdate_id = $group_id . $constraint . '_smartdate';
			$field_taxonomy_id = $group_id . $constraint . '_taxonomy';
			$field_item = $group_id . $constraint . '_item';
			
			$field_fieldset_reminder = $group_id . $reminder . '_fieldset_reminder';
			$field_reminder_check = $group_id . $reminder . '_check';
			$field_reminder_user = $group_id . $reminder . '_user';
			$field_reminder_subject = $group_id . $reminder . '_subject';
			$field_reminder_message = $group_id . $reminder . '_message';
			
			$field_reminder_details = $group_id . $reminder . '_tokens_details';
			$field_reminder_tree = $group_id . $reminder . '_tokens_tree';
			
			//We get the fields configuration of types of content
			$entity_fields_config = $this->entityFieldManager->getFieldDefinitions('node', $type_id);
			
			//We initialize the arrays where we will save the available options
			$arr_fields_smartdate = array();
			$arr_fields_taxonomy = array();
			$arr_fields_user = array();
			
			//We load into the arrays the available options
			foreach ($entity_fields_config as $field_id => $field_config) {
				
				if ($field_config instanceof \Drupal\field\Entity\FieldConfig && $field_config->getType() == 'smartdate') {
					//In case that is a smartdate field
					$arr_fields_smartdate[$field_id] = $field_config->label();
				} else if ($field_config instanceof \Drupal\field\Entity\FieldConfig && $field_config->getType() == 'entity_reference') {
					//In case that is a reference field...
					if ($field_config->getSettings()['target_type'] == 'user') {
						//Is a user reference field
						$arr_fields_user[$field_id] = $field_config->label();
					} else {
						//Is a taxonomy reference field
						$arr_fields_taxonomy[$field_id] = $field_config->label();
					}
				}
				
			}
			
			
			//We create a new tab
			$form[$group_id] = [
				'#type' => 'details',
				'#title' => $type_name,
				'#description' => $type_name,
				'#group' => 'type_of_content',
			];
			
			//If at least the type of content has one SmartDate field we will show to user the fields because he will be able to configure the restriction
			if (sizeof($arr_fields_smartdate) > 0) {
				
				//We add a extra item into the arrays that it will serve to indicate that we don't want restrictions
				$arr_fields_smartdate = array('none' => '- ' . $this->t('Without constraint') . ' -') + $arr_fields_smartdate;
				$arr_fields_taxonomy = array('none' => '- ' . $this->t('Without constraint') . ' -') + $arr_fields_taxonomy;
				$arr_fields_user = array('none' => '- ' . $this->t('The author of the content') . ' -' ) + $arr_fields_user;
				
				$form[$group_id][$field_fieldset_constraint] = [
					'#type' => 'fieldset',
					'#title' => $this->t('Constraint'),
				];
				
				$form[$group_id][$field_fieldset_constraint][$field_smartdate_id] = [
					'#type' => 'select',
					'#title' => $this->t('SmartDate field'),
					'#default_value' => $config->get($field_smartdate_id),
					'#options' => $arr_fields_smartdate,
					'#description' => t('Select the SmartDate field of the type of content that you want to check no overlapping dates.'),
				];
				
				$form[$group_id][$field_fieldset_constraint][$field_taxonomy_id] = [
					'#type' => 'select',
					'#title' => $this->t('Taxonomy field'),
					'#default_value' => $config->get($field_taxonomy_id),
					'#options' => $arr_fields_taxonomy,
					'#description' => t('It only checks overlapping dates between contents that they have the same taxonomy term.'),
				];
				
				$form[$group_id][$field_fieldset_reminder] = [
					'#type' => 'fieldset',
					'#title' => $this->t('Reminders'),
				];
				
				$form[$group_id][$field_fieldset_reminder][$field_reminder_check] = [
					'#type' => 'checkbox',
					'#title' => $this->t('Send reminders with cron.'),
					'#default_value' => $config->get($field_reminder_check),
					'#description' => $this->t('Each time the cron executes, it sends a reminder to users.'),
				];
				
				$form[$group_id][$field_fieldset_reminder][$field_reminder_user] = [
					'#type' => 'select',
					'#title' => $this->t('Which user will receive reminders'),
					'#default_value' => $config->get($field_reminder_user),
					'#options' => $arr_fields_user,
					'#description' => t('Who you want to receive reminders.'),
				];
				
				$form[$group_id][$field_fieldset_reminder][$field_reminder_subject] = [
					'#type' => 'textfield',
					'#title' => $this->t('Subject'),
					'#required' => TRUE,
					'#size' => 200,
					'#maxlength' => 200,
					'#default_value' => $config->get($field_reminder_subject),
					'#description' => t('The subject for reminders.'),
				];
				
				$form[$group_id][$field_fieldset_reminder][$field_reminder_message] = [
					'#type' => 'textarea',
					'#title' => $this->t('Body message'),
					'#required' => TRUE,
					'#cols' => 60,
					'#rows' => 5,
					'#default_value' => $config->get($field_reminder_message),
					'#token_types' => array('user', 'node'),
					'#description' => t('The body message for reminders. Use [node:reservations_for_today] token to obtain the date-time reservations for today.'),
				];
				
				$form[$group_id][$field_reminder_details] = [
					'#type' => 'details',
					'#title' => $this->t('Available tokens'),
					'#description' => $this->t('Select the fields that you need to include into the subject and the body message.'),
					'#open' => FALSE,
				];
				
				$form[$group_id][$field_reminder_details][$field_reminder_tree] = $this->treeBuilder->buildRenderable(['node', 'user']);
				
			} else {
				
				//If the type of content doesn't have none SmartDate field, we will only show an informative text
				$form[$group_id][$field_item] = [
					'#title' => $this->t('No configuration available'),
					'#type' => 'item',
					'#markup' => $this->t('This type of content doesn\'t have a SmartDate field.<br>If you need a SmartDate field in this type of content, you will see some configuration here.'),
				];
				
			}
			
		}
		
		//As opposed to normal forms, in configuration forms is not necessary adding a submit button
		//About that is already handled by buildForm() method from parent class
		return parent::buildForm($form, $form_state);
	
	}
	
	//Method that do the fields validation just after the user click the submit button
	public function validateForm(array &$form, FormStateInterface $form_state) {
		
		//No validation will be performed
		parent::validateForm($form, $form_state);
		
	}
	
	//Method that do the necessary operations/managements after the form has passed the validations successfully
	public function submitForm(array &$form, FormStateInterface $form_state) {
		
		//We get the object that it will be useful to save the module configuration values
		$config = $this->config('cool_calendar_extras.settings');
		
		//We get the values filled on the form
		$fields_and_values = $form_state->cleanValues()->getValues();
		
		//We iterate all values received and we save them
		foreach ($fields_and_values as $field => $value) {
			
			//We will not consider the 'item' or 'tree' type fields
			if (!str_ends_with($field,'_item') && !str_ends_with($field,'_tree')) {
				$config->set($field, $value);
			}
			
		}
		
		//We save the changes persistently into database
		$config->save();
		
		//We submit the form
		parent::submitForm($form, $form_state);
		
	}
	
}
