
<?php
/**
 * Comments library
 * Contains the loading of specific comments,
 * overall comments, answers and commenting
 * @author  movabo
 */
class Comments {
	
	private $_module;
	private $_identifier;
	private $_CI;
	private $_users;

	/**
	 * Magic Method
	 * __construct()
	 *
	 * @param  string module
	 * @param string identifier
	 */
	public function __construct($module = array(0 => NULL), $identifier = NULL)
	{
		$this->_module     = $module[0];
		$this->_identifier = $identifier;
		$this->_CI         =& get_instance();
		$this->_CI->load->model('shared/comments_model');
		$this->_CI->load->library('auth');
		$this->_CI->load->library('rating');
	}

    /**
	 * Magic Method __call(); Pass-trough to the comments_model
	 * @param string $method Method in the comments_model
	 * @param array $arguments The arguments to pass trough
	 * @return mixed The return of $method-function
	 */
	public function __call($method, $arguments)
	{
		if (!method_exists( $this->_CI->comments_model, $method) )
		{
			throw new Exception('Undefined method Comments::' . $method . '() called');
		}

		return call_user_func_array(array($this->_CI->comments_model, $method), $arguments);
	}


	/**
	 * Setter for identifier
	 * @param string $identifier
	 */
	public function set_identifier($identifier)
	{
		$this->_identifier = $identifier;
	}

	/**
	 * Getter for identifier
	 * @return string the identifier
	 */
	public function get_identifier()
	{
		return $this->_identifier;
	}

	/**
	 * Setter for module
	 * @param string $module module-name
	 */
	public function set_module($module)
	{
		$this->_module = $module;
	}

	/**
	 * Getter for the module-name
	 * @return string module-name
	 */
	public function get_module()
	{
		return $this->_module;
	}

	/**
	 * gets comments and answerth (depth: count($limit))
	 * @param  array  $o options-array containing the following possibilities:
	 *         -----------------------------------------------------------------------------
	 *         | key               | function                                              |
	 *         -----------------------------------------------------------------------------
	 *         | order             | set the order for comments, DESC or ASC               |
	 *         |                   |                                                       |
	 *         | answer-to         | if get answers to a comment: parent-comment-id        |
	 *         |                   |                                                       |
	 *         | limit             | array of limits of comments and answer (number of     |
	 *         |                   | elements is depth of answers)                         |
	 *         |                   |                                                       |
	 *         | offset            | array of offsets according to the limits. default = 0 |
	 *         |                   |                                                       |
	 *         | include-comment   | makes shure every parent-comment and the comment      |
	 *         |                   | itself will be included.                              |
	 *         |                   |                                                       |
	 *         | answers-to        | id of the post to make shure to get the answers to    |
	 *         |                   | makes shure all parents of this are included.         |
	 *         |                   |                                                       |
	 *         | answers-to-limit  | limit of the answers to "answers-to"                  |
	 *         |                   |                                                       |
	 *         | answers-to-offset | offset of the answers to "answers-to"                 |
	 *         |                   |                                                       |
	 *         | module            | the module to be used (default: instance-variable)    |
	 *         |                   |                                                       |
	 *         | identifier        | the identifier to be used (default: instance-variable)|
	 *         -----------------------------------------------------------------------------
	 * @param  boolean $validate enables/disables validation of the $o-array
	 * @param  array   $included_posts includes the id's of all answers which will be loaded.
	 * @return array            comments (as objects in array)
	 */
	public function get_comments($o = array(), $validate = true, &$answers_to = array())
	{
		if($validate)
			$this->_check_comments_options($o);

		$defaults = array(
			"order" => "DESC",
			"answer-to" => null,
			"limit" => array(30, 5, 2),
			"offset" => array(),
			"include-comment" => false,
			"answers-to" => false,
			"answers-to-limit" => 10,
			"answers-to-offset" => 0,
			"module" => $this->_module,
			"identifier" => $this->_identifier
			);
		$o = array_merge($defaults, $o);

		if($o['answers-to'])
			$answers_to = $this->_CI->comments_model->path_to($answer_to);

		if(!isset($o['offset'][0])) $o['offset'][0] = 0;

		if(isint($o['answer-to']))
			$comments = $this->_CI->comments_model->get_answers($o['answer-to'], $o['order'], array_shift($o['limit']), array_shift($o['offset']));
		else
			$comments = $this->_CI->comments_model->get_comments($o['module'], $o['identifier'], $o['order'], array_shift($o['limit']), array_shift($o['offset']));

		if(count($o['limit']) > 0)
		{
			$check_answers = !(count($answers_to) == 0);

			$c = count($comments);
			$checkid = false;
			for($i = 0; $i < $c; $i++)
			{
				$get_answers = $o;
				$get_answers['answer-to'] = $comments[$i]->id;
				$get_answers['order'] = 'ASC';
				$get_answers['answers-to'] = false;
				if($check_answers && $answers_to[0] == $comments[$i]->id)
				{
					array_shift($answers_to);
					$checkid = $answers_to[0];
				}
				$comments[$i]->answers = $this->get_comments($get_answers, false, $answers_to);
				if($checkid)
				{
					if($checkid == $answers_to[0])
						$this->add_recursive_answers($comments->answers, $answers_to);
					
					$checkid = false;
				}
				if(count($o['limit']) == 1)
					$comments[$i]->answers = $this->additional_info($comments[$i]->answers);

				$comments[$i] = $this->additional_info($comments[$i]);
			}

		}

		if($include_comment)
			$this->_include_path($comments, $include_comment);

		if($answers_to)
		{
			$this->_include_path($comments, $answers_to, $o['answers-to-limit'], $o['answers-to-offset']);
		}

		return $comments;
	}

	private function _include_path(&$comments, $path_array, $answers_limit = 0, $answers_offset = 0, $order = 'ASC')
	{
		if(isint($path_array))
		{
			$path_array = $this->_CI->comments_model->path_to($path_array);
		}

		$id = array_shift($path_array);
		if(!isset($comments[$id]))
		{
			$comments[$id] = $this->additional_info($this->_CI->comments_model->get($id));
			$comments[$id]->answers = array();
		}

		if(!empty($path_array))
			$this->_include_path($comments[$id]->answers, $path_array, $answers_limit, $answers_offset);
		elseif($answers_limit > 0)
		{
			$comments[$id]->answers_offset = $answers_offset;
			$comments[$id]->answers = $this->get_comments(array(
				'answer-to' => $id,
				'limit'     => array($answers_limit),
				'offset'    => array($answers_offset),
				'order'     => $order
			));
		}

		return $comments;
	}


	/**
	 * Adds creator's info (from auth-lib) to a comments array or one comment (object)
	 * saved in comment->creator
	 * @param Array $comments array containing the comments-array or the comment-object
	 */
	public function additional_info($comments)
	{

		if($comments instanceof stdClass)
		{
			$comments->creator = $this->get_user($comments->user_id);
			$comments->count_answers = $this->_CI->comments_model->answers_to($comments->id);
			$comments->ratings = $this->_CI->rating->get_ratings('comments', $comments->id);
			return $comments;
		}

		foreach($comments as $i => $c)
		{
			$comments[$i] = $this->additional_info($comments[$i]);
		}

		return $comments;
	}


	/**
	 * Adds all answers in $path to $array. $path has to be in the correct order (child at the end, parent at the beginning).
	 * @param array &$array The array to which the answers should be added
	 * @param array $path   Array containing the path
	 */
	public function add_recursive_answers(&$array, $path)
	{
		if(empty($path))
			return;
	
		$post = $this->_CI->comments_model->get_by_id(array_shift($path));
		$post->answers = array();
		$post = $this->additional_info($post);
		$this->add_recursive_answers($post->answers, $path);
		array_push($array[count($array)-1]->answers, $path);
	}


	public function get_user($id)
	{
		if(!isset($this->_users[$id]))
			$this->_users[$id] = $this->_CI->auth->user_info($id, true);

		return $this->_users[$id];
	}


	/**
	 * Answers to a comment.
	 * @param string $userid    the user id who submits
	 * @param string $text      the comments
	 * @param int    $commentid the comment to answer to
	 */
	public function answer($userid, $text, $commentid)
	{
		return $this->_CI->comments_model->answer($userid, $text, $commentid);
	}


	/**
	 * Creates a comment
	 * @param int $userid  id of the user who comments
	 * @param string $text the comment
	 */
	public function comment($userid, $text)
	{
		return $this->_CI->comments_model->comment($this->_module, $this->_identifier, $userid, $text);
	}

	/**
	 * Submits automatically a comment via post
	 * @param  string|boolean $allowed_module     string: the allowed module, bool: check the instance-variable
	 * @param  string|boolean $allowed_identifier string: the allowed identifier, bool: check the instance-variable
	 * @param  boolean $force_allowed_module      force the module to be correct and disable further checking
	 * @param  boolean $force_allowed_identifier  force the identifier to be correct and disable further checking
	 * @return int|false|null                     id of new comment at success & submit, false at failure & submit, null if not submitted
	 */
	public function submit_comment($allowed_module = true, $allowed_identifier = true, $force_allowed_module = false, $force_allowed_identifier = false)
	{
		if(isset($_POST['comments-submit']))
		{
			if($_POST['duplicate-preventer'] == $this->_CI->session->userdata('duplicate-preventer'))
				return;
			$this->_CI->session->set_userdata('duplicate-preventer', $_POST['duplicate-preventer']);
			unset($_POST['comments-submit']); // Prevent multiple submitting
			$this->_CI->load->library('auth');
			$user = $this->_CI->auth->user();
			if($user->id)
			{
				$iscomment = (isset($_POST['comment']) && strlen($_POST['comment']) > 0);
				if(
					   $iscomment 
					&& isset($_POST['comment-answer-to']) && isint($_POST['comment-answer-to']) 
					&& $this->_CI->comments_model->comment_exists($_POST['comment-answer-to'])
				)
				{
					return $this->answer($_POST['comment-answer-to'], $user->id, $_POST['comment']);
				}
				elseif(
					   $iscomment 
					&& isset($_POST['comment-module']) && strlen($_POST['comment-module']) > 0 
					&& isset($_POST['comment-identifier'])	&& strlen($_POST['comment-identifier']) > 0
				) {
					$allowed_module = (($allowed_module == true && (!isset($this->_module) || $this->_module == $_POST['comment-module'])) || $allowed_module == $_POST['comment-module'] || $force_allowed_module);
					$allowed_identifier = (($allowed_identifier == true && (!isset($this->_identifier) || $this->_identifier == $_POST['comment-identifier'])) || $allowed_identifier == $_POST['comment-identifier'] || $force_allowed_identifier);
					if( $allowed_module && $allowed_identifier )
					{
						return $this->_CI->comments_model->comment($_POST['comment-module'], $_POST['comment-identifier'], $user->id, $_POST['comment']);
					}
				}
			}
			return false;
		}
		return null;
	}

	/**
	 * Returns a full options-array for get_comments
	 * @param  array   $o        the input options
	 * @param  boolean $validate if the array should be validated.
	 * @return array             merged and (if set) validated array
	 */
	private function _get_options($o, $validate = true)
	{
		if($validate)
			$this->_check_comments_options($o);

		$defaults = array(
			"order" => "DESC",
			"answer-to" => null,
			"limit" => array(30, 5, 2),
			"offset" => array(),
			"include-comment" => false,
			"answers-to" => false,
			"answers-to-limit" => 10,
			"answers-to-offset" => 0,
			"module" => $this->_module,
			"identifier" => $this->_identifier
		);
		$o = array_merge($defaults, $o);
		return $o;
	}

	/**
	 * Checks an input array for correct options-values and removes invalid
	 * @return boolean true if no option is invalid
	 */
	private function _check_comments_options(&$options)
	{
		$old_options = $options;

		if(isset($options['order']) && strtoupper($options['order']) != 'DESC' && strtoupper($options['order']) != 'ASC') unset($options['order']);
		if(isset($options['answer-to']) && !isint($options['answer-to'])) unset($options['answer-to']);
		if(isset($options['limit']) && is_array($options['limit']))
		{
			$l = count($options['limit']);
			for($i = 0; $i < $l; $i++)
			{
				if(!isint($options['limit'][$i]))
				{
					$options['limit'][$i] = 5;
				}
			}
		} elseif(isset($options['limit']))
			unset($options['limit']);

		if(isset($options['offset']) && is_array($options['offset']))
		{
			$l = count($options['offset']);
			for($i = 0; $i < $l; $i++)
			{
				if(!isint($options['offset'][$i]))
				{
					$options['offset'][$i] = 0;
				}
			}
		} elseif(isset($options['offset']))
			unset($options['offset']);

		if(isset($options['include-comment']) && !isint($options['include-comment'])) unset($options['include-comment']);
		if(isset($options['answers-to']) && !isint($options['answers-to'])) unset($options['answers-to']);
		if(isset($options['answers-to-limit']) && !isint($options['answers-to-limit'])) unset($options['answers-to-limit']);
		if(isset($options['answers-to-offset']) && !isint($options['answers-to-offset'])) unset($options['answers-to-offset']);
		if(isset($options['indentifier']) && strlen($options['identifier']) == 0) unset($options['identifier']);
		if(isset($options['module']) && strlen($options['module']) == 0) unset($options['module']);

		return ($old_options == $options);
	}
}
 
