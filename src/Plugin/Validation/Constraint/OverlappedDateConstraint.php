<?php

namespace Drupal\cool_calendar_extras\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * It checks that it be the only scheduled event for a same date and time
 *
 * @Constraint(
 *   id = "OverlappedDate",
 *   label = @Translation("Unique Date", context = "Validation"),
 *   type = "string"
 * )
 */
class OverlappedDateConstraint extends Constraint {

	//Message that it will appear if date overlapping exists
	public $eventOverlapped = 'The configuration that you have set overlaps with other elements:';

}
