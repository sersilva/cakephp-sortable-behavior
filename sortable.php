<?php
/**
 * Sortable Behavior class file
 *
 * TODO:
 * - Use $model->order to set default order field
 * - Un after find o algo así que guarde en algún sitio del modelo el order list para luego pillarlo desde un sortable helper
 *
 * @copyright     Estudio Ditigal km-0
 * @link          http://www.km-0.com
 * @author        Sergio Siva (ssilva at km-0.com)
 *
 *
 * Examples:
 * 
 * $var actsAs = array( 'Sortable' ) // No parent field, order field is "order"
 * $var actsAs = array( 'Sortable' => 'sort' ) // No parent field, order field is "sort"
 * $var actsAs = array( 'Sortable' => array( 'orderField' => 'sort', 'parentField' => 'category_id') ) // parent field is "category_id", order field is "sort"
 * $var actsAs = array( 'Sortable' => array( 'parentField' => array( 'category_id', 'country_id' ) ) // parent fields are "category_id" and "country_id", order field is "order"
 *
 */
class SortableBehavior extends ModelBehavior {

/**
 * Defaults
 *
 * @var array
 * @access protected
 */
	var $_defaults = array(
		'orderField' => 'order',
		'parentField' => false,
		'conditions' => false //TODO: Allow other find conditions
	);
	
/**
 * Initiate Sortable behavior
 *
 * @param array $config
 * @return void
 * @access public
 */
	function setup(&$model, $config = array()) {
		if( !is_array($config) ) {
			$config = array( 'orderField' => $config );
		}

		$settings = array_merge($this->_defaults, $config);

		$checkFields = array( $settings['orderField'] );
		if( !empty( $settings['parentField'] ) ) {
			$checkFields = array_merge( $checkFields, (array) $settings['parentField'] );
		}
		
		foreach( $checkFields as $checkField ) {
			if( !$model->hasField($checkField) ) {
				trigger_error(
					sprintf(__d('app', 'Sortable Behavior error: The model "%s" does not have a field "%s"', true), $model->alias, $checkField),
					E_USER_ERROR
				);
				return false;
			}
		}
		
		$this->settings[$model->alias] = $settings;
	}

/**
 * Before save callback
 *
 * If creating stablish max order value + 1
 * If updating, reorder consistently
 *
 * @return boolean true to continue, false to abort the save
 * @access public
 */
	function beforeSave(&$model) {
		//TODO: check valid value ( in before validate? )

		$order_field = $this->settings[$model->alias]['orderField'];
		$parent_field = $this->settings[$model->alias]['parentField'];
		$options = array();

		if( ! $model->exists() ) {
		/**
		 * Creating...
		 */
			if( !empty($parent_field) ) {
				if( !is_array( $parent_field ) ) {
					$parent_field = array( $parent_field );
				}
				$options['conditions'] = array();
				foreach( $parent_field as $_parent_field ) {
					if( !empty( $model->data[$model->alias][$_parent_field] ) ) {
						$options['conditions'][$model->alias . '.' . $_parent_field] = $model->data[$model->alias][$_parent_field];
					} else {
						$options['conditions'][$model->alias . '.' . $_parent_field] = null;
					}
				}
			}
			
    		$model->data[$model->alias][$order_field] = $model->find('count', $options)+1;
    		return true;
    		
		} else {
    	/**
    	 * Updating...
    	 */
			if( !empty($parent_field) ) {
				
				if( !is_array( $parent_field ) ) {
					$parent_field = array( $parent_field );
				}
				//Check if any parent_field value has changed
				$changed_parent = false;
				foreach( $parent_field as $_parent_field ) {
					if( isset( $model->data[$model->alias][$_parent_field]) ) {
						if( $model->field($_parent_field) != $model->data[$model->alias][$_parent_field]) {
							$changed_parent = true;
							break;
						}
					}
				}
				if( $changed_parent ) {
					$options['conditions'] = array();
					foreach( $parent_field as $_parent_field ) {
						$options['conditions'][$model->alias . '.' . $_parent_field] = $model->data[$model->alias][$_parent_field];
					}
					
					$model->data[$model->alias][$order_field] = $model->find('count', $options)+1;
					
					$options['conditions'] = array();
					foreach( $parent_field as $_parent_field ) {
						$options['conditions'][$model->alias . '.' . $_parent_field] = $model->field($_parent_field);
					}
					$cur_order = $model->field($order_field);
					$max_order = $model->find('count', $options)+1;
					$this->_changeOrder($model, $cur_order, $max_order);
					
					return true;
				}
				
			}
			
			if( !empty( $model->data[$model->alias][$order_field]) ) {
				$cur_order = $model->field($order_field);
				$new_order = $model->data[$model->alias][$order_field];
				return $this->_changeOrder($model, $cur_order, $new_order);
			}
			
		}
		return true;
	}
/**
 * Before delete callback.
 *
 *
 * @return boolean true to continue, false to abort the save
 * @access public
 */
	function beforeDelete(&$model) {
		$order_field = $this->settings[$model->alias]['orderField'];
		$parent_field = $this->settings[$model->alias]['parentField'];
		$options = $this->_getParentConditions( $model );

		$order = $model->field( $order_field );

		return $model->updateAll(
			array( $model->alias . '.' . $order_field => $model->alias . '.' . $order_field . ' - 1'),
			array( $model->alias . '.' . $order_field . ' >' => $order, $options )
		);
	}
/**
 * resetOrder
 *
 *
 * @return boolean true if ok
 * @access public
 */
	function resetOrder(&$model) {
		//TODO
	}
/**
 * setOrder
 *
 * Establish order for current element
 *
 * @param integer $new_order The new order
 * @return boolean true if ok
 * @access public
 */
	
	function setOrder(&$model, $new_order) {
		$order_field = $this->settings[$model->alias]['orderField'];

		$cur_order = $model->field($order_field);

		$new_order = $this->_checkMaxMin( $model, $new_order );
		
		$this->_changeOrder($model, $cur_order, $new_order);

		//$model->beforeSave();
		$r =  $model->updateAll(
				array( $model->alias.'.'.$order_field => $new_order),
				array( $model->alias.'.id' => $model->id )
			);
		$created = false;

		$model->afterSave( $model );
		
		return $r;
	}
	
/**
 * change the order
 * 
 *
 * @param integer $cur_order
 * @param integer $new_order
 * @return boolean true if everything ok
 * @access protected
 */
	function _changeOrder(&$model, $cur_order, $new_order) {
		$order_field = $this->settings[$model->alias]['orderField'];
		$parent_field = $this->settings[$model->alias]['parentField'];
		
		$options = $this->_getParentConditions( $model );
		
		if( $cur_order > $new_order ) {
			return $model->updateAll(
				array( $model->alias.'.'.$order_field => $model->alias.'.'.$order_field.' + 1'),
				array( $model->alias.'.'.$order_field.' BETWEEN ? AND ?' => array($new_order, $cur_order), $options )
			);
		} else if( $cur_order < $new_order ) {
			return $model->updateAll(
				array( $model->alias.'.'.$order_field => $model->alias.'.'.$order_field . ' - 1'),
				array( $model->alias.'.'.$order_field . ' BETWEEN ? AND ?' => array($cur_order, $new_order), $options )
			);
		}
		return true;
	}
	
/**
 * Check if order value is in range
 *
 */
	function _checkMaxMin( &$model, $value ) {
		if( $value < 1 ) {
			return '1';
		}
		
		$options = $this->_getParentConditions( $model );
		
		$max_order = $model->find('count', array( 'conditions' => $options));
		if( $value > $max_order ) {
			return $max_order;
		} else {
			return $value;
		}
		 
	}
	
	function _getParentConditions( &$model ) {
		$order_field = $this->settings[$model->alias]['orderField'];
		$parent_field = $this->settings[$model->alias]['parentField'];
		$options = array();

		if( !empty($parent_field) ) {
			if( !is_array( $parent_field ) ) {
					$parent_field = array( $parent_field );
			}
			foreach( $parent_field as $_parent_field ) {
				$_parent = $model->field( $_parent_field );
				$options[ $model->alias . '.' . $_parent_field ] =  $_parent;
			}
		}
		
		return $options;
	}
}
