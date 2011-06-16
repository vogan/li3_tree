<?php
namespace li3_tree\extensions;

/**
 * Basic Model for a Tree Behaviour in li3
 * 
 * @author: vogan
 */
class Model extends \lithium\data\Model {
	
	/**
	 * default tree configuration
	 * @var Array
	 */
	protected static $_tree_defaults = array(
		'parent' => 'parent', 'left' => 'lft', 'right' => 'rght', 'recursive' => false
	);
	
	/**
	 * tree config
	 * @var Array holding Arrays of Configuration Arrays
	 */
	protected static $_tree_config = array();
	
	/**
	 * init
	 * 
	 * init Tree Callback Methods
	 */
	public static function __init() {
		parent::__init();
		
		$class = get_called_class();
		static::$_tree_config[$class] = array_merge(static::$_tree_defaults, static::$_tree_config);
		
		static::applyFilter('save', function($self,$params,$chain){
        	if($self::beforeSave($self,$params)){
	        	return $chain->next($self,$params,$chain);
        	}
        });
        
        static::applyFilter('delete', function($self,$params,$chain){
        	if($self::beforeDelete($self,$params)){
	        	return $chain->next($self,$params,$chain);
        	}
        });
	}

	/**
	 * countChildren
	 * 
	 * returns number of children, either direct child elements only (recursive = false)
	 * or the absolute number of child elements, if recursive is set to true;
	 * 
	 * @param Integer $id the id to fetch
	 * @param Boolean $rec recursive overrides configured recursive flag
	 */
	public static function countChildren($id,$rec = null){
    	$self = get_called_class();
		extract(static::$_tree_config[$self]);
		
		if($rec !== null){
			$recursive = $rec;
		}
		
		$node = static::getById($self, $id);
		if($recursive){
			return ($node->data($right) - 1 - $node->data($left)) / 2;
		}else{
			$count = $self::find('count',array('conditions'=>array($left => array ('>'=>$node->data($left)), $right => array('<' => $node->data($right)), $parent => $node->data($self::meta('key')))));
			return $count;
		}
    }
    
    /**
     * getChildren
     * 
     * returns all children of given element (including subchildren if $rec is set to true or recursive is configured true)
     * 
     * @param Integer $id the NodeID of the Node to fetch the Children of
     * @param Boolean $rec overrides configured recursive param for this method
     */
    public static function getChildren($id,$rec = null){
    	$self = get_called_class();
		extract(static::$_tree_config[$self]);
		
   	 	if($rec !== null){
			$recursive = $rec;
		}
		
		if($recursive){
			$node = static::getById($self, $id);
			return $self::find('all',array('conditions'=>array($left => array('>' => $node->data($left)), $right => array('<' => $node->data($right))),'order'=>array($left => 'asc')));
		}else{
			return $self::find('all',array('conditions'=>array($parent => $id),'order'=>array($left => 'asc')));
		}
    }
    
    /**
     * getPath
     * 
     * returns an array containing all elements from the tree root node to the node with given id (including this node) which
     * have a parent/child relationship
     * 
     * @param Integer $id
     */
    public static function getPath($id){
    	$self = get_called_class();
		extract(static::$_tree_config[$self]);
		
		$path = array();
		$element = static::getById($self, $id);
		while($element->data($parent) != null){
			$path[] = $element;
			$element = static::getById($self, $element->data($parent));
		}
		$path[] = $element;
		$path = array_reverse($path);
		return $path;
    }
	
	/**
     * beforeSave
     * 
     * this method is called befor each save
     * 
     * @param \lithium\data\Model $self
	 * @param Array $params
     */
    public static function beforeSave($self,$params){
    	extract(static::$_tree_config[$self]);
    	$entity = $params['entity'];
    	if (!$entity->data('id')){
			if($entity->data($parent)){
				static::insertParent($self,$entity);
			}else{
				$max = static::getMax($self);
				$entity->set(
					array(
						$left => $max + 1,
						$right => $max + 2
					)
				);
			}
		}elseif($entity->data($parent)){
			if($entity->data($parent) == $entity->data($self::meta('key'))){
				return false;
			}
			static::updateNode($self, $entity);
		}
    	return true;
    }
    
    /**
     * beforeDelete
     * 
     * this method is called befor each save
     * 
     * @param \lithium\data\Model $self
	 * @param Array $params
     */
    public static function beforeDelete($self,$params){
    	static::deleteFromTree($self, $params['entity']);
    	return true;
    }

	/**
	 * insertParent
	 * 
	 * inserts a node at given last position of parent set in $entity
	 * 
	 * @param \lithium\data\Model $self
	 * @param \lithium\data\Entity $entity
	 */
	private static function insertParent($self,$entity){
		extract(static::$_tree_config[$self]);
		$parentNode = static::getById($self, $entity->data($parent));
		if($parentNode) {
			$r = $parentNode->data($right);
			static::updateNodesIndices($self,$r);
			$entity->set(
				array(
					$left => $r,
					$right => $r + 1
				)
			);
		}
	}
	
	/**
	 * update a Node (when parent Id is changed)
	 * 
	 * all the "move an element with all its children" magic happens here!
	 * first we calculate movements (shiftX, shiftY), afterwards shifting of ranges is done,
	 * where rangeX is is the range of the element to move and rangeY the area between rangeX
	 * and the new position of rangeX.
	 * to avoid double shifting of already shifted data rangex first is shifted in area < 0 
	 * (which is always empty), after correcting rangeY's left and rights we move it to its
	 * designated position.
	 * 
	 * @param \lithium\data\Model $self the model using this behavior
	 * @param \lithium\data\Entity $entity updated tree element
	 */
	private static function updateNode($self,$entity){
		extract(static::$_tree_config[$self]);

		$newParent = static::getById($self,$entity->data($parent));

		$span = $entity->data($right) - $entity->data($left);
		$spanToZero = $entity->data($right);
		
		$rangeX = array('floor'=>$entity->data($left),'ceiling'=>$entity->data($right));
		$shiftX = 0;
					
		static::updateNodesIndicesBetween($self, $rangeX, '-', $spanToZero);
		if($entity->data($right) < $newParent->data($right)){
			$rangeY = array('floor'=>$entity->data($right)+1,'ceiling'=>$newParent->data($right)-1);
			$shiftY = $span + 1;
			static::updateNodesIndicesBetween($self, $rangeY, '-', $shiftY);
			$shiftX = $newParent->data($right) - $entity->data($right) -1;
		}else{
			$rangeY = array('floor'=>$newParent->data($right),'ceiling'=>$entity->data($left)-1);
			$shiftY = ($newParent->data($left) - $entity->data($left) + 1) * -1;
			static::updateNodesIndicesBetween($self, $rangeY, '+', $shiftY);
			$shiftX = $newParent->data($left) - $entity->data($left) + 1;
		}
		static::updateNodesIndicesBetween($self, array('floor'=> (0 - $span),'ceiling'=> 0), '+',$spanToZero+$shiftX);
		$entity->set(array($left=>$entity->data($left)+$shiftX, $right=>$entity->data($right)+$shiftX));

	}
	
	/**
	 * deleteFromTree
	 * 
	 * deletes a node (and its children) from the tree
	 * 
	 * @param \lithium\data\Model $self the model using this behavior
	 * @param \lithium\data\Entity $entity updated tree element
	 */
	private static function deleteFromTree($self,$entity){
		extract(static::$_tree_config[$self]);
		$span = 1;
		if($entity->data($right) - $entity->data($left) != 1){
			$span = $entity->data($right) - $entity->data($left);
			$connection = $self::connection();
			$connection->read('delete from '.$self::meta('source').' where '.$parent.'='.$entity->data($self::meta('key')),array('return'=>'resource'));
		}
		static::updateNodesIndices($self,$entity->data($right),'-',$span+1);
	}
	
	/**
	 * getById
	 * 
	 * returns the element with given id
	 * 
	 * @param \lithium\data\Model $self the model using this behavior
	 * @param int $id the id to fetch from db
	 */
	private static function getById($self,$id){
		return $self::find('first',array('conditions'=>array($self::meta('key')=>$id)));
	}
	
	/**
	 * updateNodeIndices
	 * 
	 * Updates the Indices in greater than $rght with given value.
	 * 
	 * @param \lithium\data\Model $self the model using this behavior
	 * @param Integer $rght the right index border to start indexing
	 * @param String $dir Direction +/- (defaults to +)
	 * @param Integer $span value to be added/subtracted (defaults to 2)
	 */
	private static function updateNodesIndices($self,$rght,$dir='+',$span=2){
		extract(static::$_tree_config[$self]);
		$connection = $self::connection();
		$connection->read('update '.$self::meta('source').' set '.$right.'='.$right.$dir.$span.' where '.$right.' >= '.$rght, array('return'=>'resource'));
		$connection->read('update '.$self::meta('source').' set '.$left.'='.$left.$dir.$span.' where '.$left.' > '.$rght, array('return'=>'resource'));
	}
	
	/**
	 * updateNodeIndicesBetween
	 * 
	 * Updates the Indices in given range with given value.
	 * 
	 * @param \lithium\data\Model $self the model using this behavior
	 * @param Array $range the range to be updated
	 * @param String $dir Direction +/- (defaults to +)
	 * @param Integer $span value to be added/subtracted (defaults to 2)
	 */
	private static function updateNodesIndicesBetween($self,$range,$dir='+',$span=2){
		extract(static::$_tree_config[$self]);
		$connection = $self::connection();
		$connection->read('update '.$self::meta('source').' set '.$right.'='.$right.$dir.$span.' where '.$right.' between '.$range['floor'].' and '.$range['ceiling'], array('return'=>'resource'));
		$connection->read('update '.$self::meta('source').' set '.$left.'='.$left.$dir.$span.' where '.$left.' between '.($range['floor']).' and '.$range['ceiling'], array('return'=>'resource'));
	}
	
	/**
	 * getMax
	 * 
	 * returns the highest 'right' - Index in Table
	 * 
	 * @param \lithium\data\Model $self the model using this behavior
	 */
	private static function getMax($self){
		extract(static::$_tree_config[$self]);
		$connection = $self::connection();
		$max = $connection->read('select max('.$right.') as max from '.$self::meta('source').';');
		if(sizeof($max) == 1){
			return $max[0]['max'];
		}
		return 0;
	}
}

?>